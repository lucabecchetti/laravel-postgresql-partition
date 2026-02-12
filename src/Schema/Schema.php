<?php

namespace Brokenice\LaravelPgsqlPartition\Schema;

use Brokenice\LaravelPgsqlPartition\Exceptions\UnexpectedValueException;
use Brokenice\LaravelPgsqlPartition\Exceptions\UnsupportedPartitionException;
use Brokenice\LaravelPgsqlPartition\Models\Partition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema as IlluminateSchema;

/**
 * Class Schema - PostgreSQL Partition Helper.
 */
class Schema extends IlluminateSchema
{
    public static $have_partitioning = false;
    public static $already_checked = false;

    // Array of months
    protected static $month = [
        1 => 'jan',
        2 => 'feb',
        3 => 'mar',
        4 => 'apr',
        5 => 'may',
        6 => 'jun',
        7 => 'jul',
        8 => 'aug',
        9 => 'sep',
        10 => 'oct',
        11 => 'nov',
        12 => 'dec'
    ];

    /**
     * Returns array of partition names for a specific schema/table.
     *
     * @param string $schema Schema name (usually 'public')
     * @param string $table Table name
     * @return array
     */
    public static function getPartitionNames($schema, $table)
    {
        self::assertSupport();

        $query = "
            SELECT 
                c.relname AS partition_name,
                pg_get_expr(c.relpartbound, c.oid) AS partition_expression,
                c.reltuples AS estimated_rows,
                CASE 
                    WHEN c.relpartbound IS NULL THEN NULL
                    ELSE pg_get_partkeydef(p.oid)
                END AS partition_method
            FROM pg_class c
            JOIN pg_inherits i ON c.oid = i.inhrelid
            JOIN pg_class p ON i.inhparent = p.oid
            JOIN pg_namespace n ON p.relnamespace = n.oid
            WHERE n.nspname = ?
            AND p.relname = ?
            ORDER BY c.relname
        ";

        return DB::select($query, [$schema, $table]);
    }

    /**
     * Check if PostgreSQL version supports native partitioning (10+).
     *
     * @return boolean
     */
    public static function havePartitioning()
    {
        if (self::$already_checked) {
            return self::$have_partitioning;
        }

        $version = self::version();
        // PostgreSQL 10+ supports native declarative partitioning
        self::$have_partitioning = version_compare($version, '10.0', '>=');
        self::$already_checked = true;

        return self::$have_partitioning;
    }

    /**
     * Get PostgreSQL version.
     *
     * @return string
     */
    public static function version()
    {
        $pdo = DB::connection()->getPdo();
        $result = $pdo->query("SELECT version()")->fetchColumn();
        // Extract major.minor version from PostgreSQL version string
        // Example: "PostgreSQL 15.6 ..." -> "15.6"
        preg_match('/PostgreSQL (\d+(?:\.\d+)?)/', $result, $matches);
        return $matches[1] ?? $result;
    }

    /**
     * Create a partitioned table.
     *
     * @param string $table Table name
     * @param string $partitionColumn Column to partition by
     * @param string $partitionType RANGE, LIST, or HASH
     * @param \Closure $callback Blueprint callback
     * @param string|null $schema Schema name
     * @return void
     */
    public static function createPartitioned($table, $partitionColumn, $partitionType, \Closure $callback, $schema = null)
    {
        self::assertSupport();

        $schemaPrefix = $schema !== null ? "\"{$schema}\"." : '';
        $tableName = $schemaPrefix . "\"{$table}\"";

        // Get the connection first
        $connection = DB::connection();
        
        // Create the table using Laravel's Schema Blueprint
        // Blueprint constructor requires Connection as first parameter, table name as second
        $blueprint = new \Illuminate\Database\Schema\Blueprint($connection, $table);
        $callback($blueprint);

        // Get the SQL from blueprint
        $grammar = $connection->getSchemaGrammar();
        
        // Use reflection to call the protected getColumns method
        // This compiles all column definitions properly
        $reflection = new \ReflectionClass($grammar);
        $getColumnsMethod = $reflection->getMethod('getColumns');
        // setAccessible() is deprecated in PHP 8.5+ (has no effect); only call on older PHP
        if (\PHP_VERSION_ID < 80500) {
            $getColumnsMethod->setAccessible(true);
        }
        $columns = $getColumnsMethod->invoke($grammar, $blueprint);
        
        // Check if partition column is an expression (contains function call)
        $isExpression = preg_match('/\w+\s*\(/', $partitionColumn);
        
        // Handle primary keys - PostgreSQL doesn't support PRIMARY KEY when partition key is an expression
        $primaryKeyColumns = null;
        foreach ($blueprint->getCommands() as $command) {
            if ($command->name === 'primary') {
                if ($isExpression) {
                    // Can't add PRIMARY KEY in CREATE TABLE when using expression partition key
                    // We'll create it as UNIQUE constraint after table creation
                    $primaryKeyColumns = $command->columns;
                } else {
                    // Can add PRIMARY KEY normally
                    $columns[] = sprintf(
                        'PRIMARY KEY (%s)',
                        $grammar->columnize($command->columns)
                    );
                }
            }
        }
        
        // Handle indexes (they will be created separately, not in CREATE TABLE)
        // For now, we'll just include columns and primary keys (if not expression)

        // Build the CREATE TABLE statement with PARTITION BY
        $columnsSql = implode(', ', $columns);
        
        // Wrap the partition column name if it's not an expression
        $isExpression = preg_match('/\w+\s*\(/', $partitionColumn);
        $partitionKey = $isExpression ? $partitionColumn : $grammar->wrap($partitionColumn);
        
        $query = "CREATE TABLE {$tableName} ({$columnsSql}) PARTITION BY {$partitionType} ({$partitionKey})";

        DB::statement($query);
        
        // If we have a primary key that couldn't be added (due to expression partition key),
        // create it as a UNIQUE constraint instead
        if ($primaryKeyColumns !== null && $isExpression) {
            $uniqueName = "{$table}_pkey";
            $columnsList = $grammar->columnize($primaryKeyColumns);
            DB::statement("ALTER TABLE {$tableName} ADD CONSTRAINT {$uniqueName} UNIQUE ({$columnsList})");
        }
    }

    /**
     * Add a partition to an existing partitioned table.
     *
     * @param string $parentTable Parent table name
     * @param Partition $partition Partition definition
     * @param string|null $schema Schema name
     * @return void
     */
    public static function addPartition($parentTable, Partition $partition, $schema = null)
    {
        self::assertSupport();
        DB::statement($partition->toCreateSQL($parentTable, $schema));
    }

    /**
     * Add a RANGE partition.
     *
     * @param string $parentTable
     * @param string $partitionName
     * @param mixed $from
     * @param mixed $to
     * @param string|null $schema
     * @return void
     */
    public static function addRangePartition($parentTable, $partitionName, $from, $to, $schema = null)
    {
        $partition = Partition::range($partitionName, $from, $to);
        self::addPartition($parentTable, $partition, $schema);
    }

    /**
     * Add a LIST partition.
     *
     * @param string $parentTable
     * @param string $partitionName
     * @param array $values
     * @param string|null $schema
     * @return void
     */
    public static function addListPartition($parentTable, $partitionName, array $values, $schema = null)
    {
        $partition = Partition::list($partitionName, $values);
        self::addPartition($parentTable, $partition, $schema);
    }

    /**
     * Add a HASH partition.
     *
     * @param string $parentTable
     * @param string $partitionName
     * @param int $modulus
     * @param int $remainder
     * @param string|null $schema
     * @return void
     */
    public static function addHashPartition($parentTable, $partitionName, $modulus, $remainder, $schema = null)
    {
        $partition = Partition::hash($partitionName, $modulus, $remainder);
        self::addPartition($parentTable, $partition, $schema);
    }

    /**
     * Add a DEFAULT partition.
     *
     * @param string $parentTable
     * @param string $partitionName
     * @param string|null $schema
     * @return void
     */
    public static function addDefaultPartition($parentTable, $partitionName, $schema = null)
    {
        $partition = Partition::createDefault($partitionName);
        self::addPartition($parentTable, $partition, $schema);
    }

    /**
     * Partition table by RANGE and create partitions.
     *
     * @param string $table
     * @param string $column
     * @param Partition[] $partitions
     * @param bool $includeDefaultPartition
     * @param string|null $schema
     * @return void
     */
    public static function partitionByRange($table, $column, $partitions, $includeDefaultPartition = true, $schema = null)
    {
        self::assertSupport();

        // For PostgreSQL, if the table already exists without partitioning,
        // we need to recreate it. This method assumes the table is already partitioned
        // or creates partitions on an existing partitioned table.
        
        foreach ($partitions as $partition) {
            if ($partition->type !== Partition::RANGE_TYPE) {
                throw new UnexpectedValueException('All partitions must be of RANGE type for partitionByRange');
            }
            self::addPartition($table, $partition, $schema);
        }

        if ($includeDefaultPartition) {
            self::addDefaultPartition($table, $table . '_default', $schema);
        }
    }

    /**
     * Partition table by LIST and create partitions.
     *
     * @param string $table
     * @param string $column
     * @param Partition[] $partitions
     * @param string|null $schema
     * @return void
     */
    public static function partitionByList($table, $column, $partitions, $schema = null)
    {
        self::assertSupport();

        foreach ($partitions as $partition) {
            if ($partition->type !== Partition::LIST_TYPE) {
                throw new UnexpectedValueException('All partitions must be of LIST type for partitionByList');
            }
            self::addPartition($table, $partition, $schema);
        }
    }

    /**
     * Partition table by HASH.
     *
     * @param string $table
     * @param string $column
     * @param int $partitionsNumber
     * @param string|null $schema
     * @return void
     */
    public static function partitionByHash($table, $column, $partitionsNumber, $schema = null)
    {
        self::assertSupport();

        for ($i = 0; $i < $partitionsNumber; $i++) {
            $partitionName = "{$table}_p{$i}";
            self::addHashPartition($table, $partitionName, $partitionsNumber, $i, $schema);
        }
    }

    /**
     * Partition by months.
     *
     * @param string $table
     * @param string $column
     * @param string|null $schema
     * @return void
     */
    public static function partitionByMonths($table, $column, $schema = null)
    {
        self::assertSupport();

        // Extract year from database name (e.g., qualisys_2026 → 2026)
        $year = self::extractYearFromDatabaseName();
        
        // Create 12 monthly partitions with YYYY_MM format as per PostgreSQL best practices
        // Format: {table}_YYYY_MM (e.g., unit_reports_2026_01, unit_reports_2026_02)
        foreach (self::$month as $monthNum => $monthName) {
            $nextMonth = $monthNum + 1;
            $nextYear = $year;
            if ($nextMonth > 12) {
                $nextMonth = 1;
                $nextYear = $year + 1;
            }
            
            // Use date format that PostgreSQL understands for timestamp columns
            // Format: 'YYYY-MM-DD HH:MM:SS'
            $from = sprintf("'%d-%02d-01 00:00:00'", $year, $monthNum);
            $to = sprintf("'%d-%02d-01 00:00:00'", $nextYear, $nextMonth);
            
            // Use YYYY_MM format as per PostgreSQL best practices (always use padded month number)
            $monthPadded = str_pad($monthNum, 2, '0', STR_PAD_LEFT);
            $partitionName = "{$table}_{$year}_{$monthPadded}";
            
            // Lazy partitioning: only create if it doesn't exist
            if (!self::partitionExists($partitionName, $schema)) {
                self::addRangePartition(
                    $table,
                    $partitionName,
                    $from,
                    $to,
                    $schema
                );
            }
        }
    }
    
    /**
     * Check if a table is partitioned by an expression.
     *
     * @param string $table
     * @param string|null $schema
     * @return bool
     */
    private static function isPartitionedByExpression($table, $schema = null)
    {
        try {
            $result = DB::selectOne("
                SELECT pg_get_partkeydef(c.oid) as partition_key
                FROM pg_class c
                JOIN pg_namespace n ON c.relnamespace = n.oid
                WHERE n.nspname = ?
                AND c.relname = ?
                AND c.relkind = 'p'
            ", [$schema ?: 'public', $table]);
            
            if (!$result || !$result->partition_key) {
                // If we can't determine, assume it's by column (safer for PRIMARY KEY support)
                return false;
            }
            
            $partitionKey = $result->partition_key;
            
            // Check if partition key contains a function call (expression like EXTRACT, DATE_TRUNC, etc.)
            // Look for known functions, not just any parentheses
            // Pattern matches: FUNCTION_NAME( or FUNCTION_NAME (
            $knownFunctions = ['EXTRACT', 'DATE_TRUNC', 'TO_DATE', 'TO_TIMESTAMP', 'CAST', '::'];
            foreach ($knownFunctions as $func) {
                if (stripos($partitionKey, $func) !== false) {
                    return true;
                }
            }
            
            // Also check for generic function pattern but exclude simple RANGE/LIST/HASH keywords
            // A function call typically has a word followed by ( with something inside
            if (preg_match('/\b\w+\s*\([^)]+\)/', $partitionKey) && 
                !preg_match('/^(RANGE|LIST|HASH)\s*\(/i', $partitionKey)) {
                return true;
            }
            
            return false;
        } catch (\Exception $e) {
            // If query fails, assume it's by column (safer default)
            return false;
        }
    }

    /**
     * Partition by years.
     *
     * @param string $table
     * @param string $column
     * @param int $startYear
     * @param int|null $endYear
     * @param bool $includeDefaultPartition
     * @param string|null $schema
     * @return void
     */
    public static function partitionByYears($table, $column, $startYear, $endYear = null, $includeDefaultPartition = true, $schema = null)
    {
        self::assertSupport();

        $endYear = $endYear ?: date('Y');

        if ($startYear > $endYear) {
            throw new UnexpectedValueException("$startYear must be lower than $endYear");
        }

        $partitions = [];
        foreach (range($startYear, $endYear) as $year) {
            $partitions[] = Partition::range(
                "year{$year}",
                "{$year}-01-01",
                ($year + 1) . "-01-01"
            );
        }

        self::partitionByRange($table, $column, $partitions, $includeDefaultPartition, $schema);
    }

    /**
     * Partition by years and months (subpartitions).
     *
     * @param string $table
     * @param string $column
     * @param int $startYear
     * @param int|null $endYear
     * @param bool $includeDefaultPartition
     * @param string|null $schema
     * @return void
     */
    public static function partitionByYearsAndMonths($table, $column, $startYear, $endYear = null, $includeDefaultPartition = true, $schema = null)
    {
        self::assertSupport();

        $endYear = $endYear ?: date('Y');

        if ($startYear > $endYear) {
            throw new UnexpectedValueException("$startYear must be lower than $endYear");
        }

        foreach (range($startYear, $endYear) as $year) {
            foreach (self::$month as $monthNum => $monthName) {
                $monthPadded = str_pad($monthNum, 2, '0', STR_PAD_LEFT);
                
                // Calculate next month/year
                $nextMonth = $monthNum + 1;
                $nextYear = $year;
                if ($nextMonth > 12) {
                    $nextMonth = 1;
                    $nextYear = $year + 1;
                }
                $nextMonthPadded = str_pad($nextMonth, 2, '0', STR_PAD_LEFT);

                $from = "{$year}-{$monthPadded}-01";
                $to = "{$nextYear}-{$nextMonthPadded}-01";

                // Use YYYY_MM format as per PostgreSQL best practices
                self::addRangePartition(
                    $table,
                    "{$table}_{$year}_{$monthPadded}",
                    $from,
                    $to,
                    $schema
                );
            }
        }

        if ($includeDefaultPartition) {
            self::addDefaultPartition($table, "{$table}_default", $schema);
        }
    }

    /**
     * Detach a partition (keeps data, converts to standalone table).
     *
     * @param string $parentTable
     * @param string $partitionName
     * @param string|null $schema
     * @return void
     */
    public static function detachPartition($parentTable, $partitionName, $schema = null)
    {
        self::assertSupport();

        $schemaPrefix = $schema !== null ? "\"{$schema}\"." : '';
        $parent = $schemaPrefix . "\"{$parentTable}\"";
        $partition = $schemaPrefix . "\"{$partitionName}\"";

        DB::statement("ALTER TABLE {$parent} DETACH PARTITION {$partition}");
    }

    /**
     * Attach a table as a partition.
     *
     * @param string $parentTable
     * @param string $partitionName
     * @param Partition $partitionDef Partition definition with bounds
     * @param string|null $schema
     * @return void
     */
    public static function attachPartition($parentTable, $partitionName, Partition $partitionDef, $schema = null)
    {
        self::assertSupport();

        $schemaPrefix = $schema !== null ? "\"{$schema}\"." : '';
        $parent = $schemaPrefix . "\"{$parentTable}\"";
        $partition = $schemaPrefix . "\"{$partitionName}\"";

        DB::statement("ALTER TABLE {$parent} ATTACH PARTITION {$partition} {$partitionDef->toSQL()}");
    }

    /**
     * Drop a partition (deletes data).
     *
     * @param string $partitionName
     * @param string|null $schema
     * @return void
     */
    public static function dropPartition($partitionName, $schema = null)
    {
        $schemaPrefix = $schema !== null ? "\"{$schema}\"." : '';
        $partition = $schemaPrefix . "\"{$partitionName}\"";

        DB::statement("DROP TABLE {$partition}");
    }

    /**
     * Truncate a partition.
     *
     * @param string $partitionName
     * @param string|null $schema
     * @return void
     */
    public static function truncatePartition($partitionName, $schema = null)
    {
        $schemaPrefix = $schema !== null ? "\"{$schema}\"." : '';
        $partition = $schemaPrefix . "\"{$partitionName}\"";

        DB::statement("TRUNCATE TABLE {$partition}");
    }

    /**
     * Truncate multiple partitions.
     *
     * @param array $partitionNames
     * @param string|null $schema
     * @return void
     */
    public static function truncatePartitions($partitionNames, $schema = null)
    {
        foreach ($partitionNames as $partitionName) {
            self::truncatePartition($partitionName, $schema);
        }
    }

    /**
     * Run VACUUM on a partition (PostgreSQL equivalent of MySQL's OPTIMIZE).
     *
     * @param string $partitionName
     * @param bool $full Whether to run VACUUM FULL
     * @param string|null $schema
     * @return void
     */
    public static function vacuumPartition($partitionName, $full = false, $schema = null)
    {
        $schemaPrefix = $schema !== null ? "\"{$schema}\"." : '';
        $partition = $schemaPrefix . "\"{$partitionName}\"";
        $fullOption = $full ? 'FULL ' : '';

        DB::statement("VACUUM {$fullOption}{$partition}");
    }

    /**
     * Run VACUUM on multiple partitions.
     *
     * @param array $partitionNames
     * @param bool $full
     * @param string|null $schema
     * @return void
     */
    public static function vacuumPartitions($partitionNames, $full = false, $schema = null)
    {
        foreach ($partitionNames as $partitionName) {
            self::vacuumPartition($partitionName, $full, $schema);
        }
    }

    /**
     * Run ANALYZE on a partition.
     *
     * @param string $partitionName
     * @param string|null $schema
     * @return void
     */
    public static function analyzePartition($partitionName, $schema = null)
    {
        $schemaPrefix = $schema !== null ? "\"{$schema}\"." : '';
        $partition = $schemaPrefix . "\"{$partitionName}\"";

        DB::statement("ANALYZE {$partition}");
    }

    /**
     * Run ANALYZE on multiple partitions.
     *
     * @param array $partitionNames
     * @param string|null $schema
     * @return void
     */
    public static function analyzePartitions($partitionNames, $schema = null)
    {
        foreach ($partitionNames as $partitionName) {
            self::analyzePartition($partitionName, $schema);
        }
    }

    /**
     * Run REINDEX on a partition.
     *
     * @param string $partitionName
     * @param string|null $schema
     * @return void
     */
    public static function reindexPartition($partitionName, $schema = null)
    {
        $schemaPrefix = $schema !== null ? "\"{$schema}\"." : '';
        $partition = $schemaPrefix . "\"{$partitionName}\"";

        DB::statement("REINDEX TABLE {$partition}");
    }

    /**
     * Run REINDEX on multiple partitions.
     *
     * @param array $partitionNames
     * @param string|null $schema
     * @return void
     */
    public static function reindexPartitions($partitionNames, $schema = null)
    {
        foreach ($partitionNames as $partitionName) {
            self::reindexPartition($partitionName, $schema);
        }
    }

    /**
     * Check if a table is partitioned.
     *
     * @param string $table
     * @param string $schema
     * @return bool
     */
    public static function isPartitioned($table, $schema = 'public')
    {
        $result = DB::selectOne("
            SELECT c.relkind 
            FROM pg_class c
            JOIN pg_namespace n ON c.relnamespace = n.oid
            WHERE n.nspname = ?
            AND c.relname = ?
        ", [$schema, $table]);

        // 'p' = partitioned table
        return $result && $result->relkind === 'p';
    }

    /**
     * Get partition strategy for a table.
     *
     * @param string $table
     * @param string $schema
     * @return string|null
     */
    public static function getPartitionStrategy($table, $schema = 'public')
    {
        $result = DB::selectOne("
            SELECT pg_get_partkeydef(c.oid) as partition_key
            FROM pg_class c
            JOIN pg_namespace n ON c.relnamespace = n.oid
            WHERE n.nspname = ?
            AND c.relname = ?
            AND c.relkind = 'p'
        ", [$schema, $table]);

        return $result ? $result->partition_key : null;
    }

    /**
     * Extract year from database name.
     * 
     * Pattern: qualisys_YYYY → extract YYYY
     * 
     * @return int Year extracted from database name, or current year as fallback
     */
    private static function extractYearFromDatabaseName()
    {
        try {
            $dbName = DB::connection()->getDatabaseName();
            
            // Pattern: qualisys_YYYY or any pattern ending with _YYYY
            if (preg_match('/_(\d{4})$/', $dbName, $matches)) {
                $year = (int) $matches[1];
                // Validate year is reasonable (1900-2100)
                if ($year >= 1900 && $year <= 2100) {
                    return $year;
                }
            }
        } catch (\Exception $e) {
            // If we can't get database name, fall back to current year
        }
        
        // Fallback to current year
        return (int) date('Y');
    }
    
    /**
     * Check if a partition exists.
     *
     * @param string $partitionName
     * @param string|null $schema
     * @return bool
     */
    private static function partitionExists($partitionName, $schema = null)
    {
        try {
            $schemaName = $schema ?: 'public';
            $result = DB::selectOne("
                SELECT EXISTS (
                    SELECT 1 
                    FROM pg_class c
                    JOIN pg_namespace n ON c.relnamespace = n.oid
                    WHERE n.nspname = ?
                    AND c.relname = ?
                    AND c.relkind = 'r'
                ) as exists
            ", [$schemaName, $partitionName]);
            
            return $result && $result->exists === true;
        } catch (\Exception $e) {
            // If query fails, assume partition doesn't exist
            return false;
        }
    }

    /**
     * Assert support for partition.
     *
     * @throws UnsupportedPartitionException
     */
    private static function assertSupport()
    {
        if (!self::havePartitioning()) {
            throw new UnsupportedPartitionException('Partitioning requires PostgreSQL 10 or higher');
        }
    }

    /**
     * Get app version.
     *
     * @return string
     */
    private static function getAppVersion(): string
    {
        try {
            return method_exists(app(), 'version') ? app()->version() : '9.0.0';
        } catch (\Exception $exception) {
            return '';
        }
    }
}
