<?php

namespace Brokenice\LaravelPgsqlPartition\Schema;

use Illuminate\Database\Query\Grammars\PostgresGrammar as IlluminatePostgresGrammar;
use Illuminate\Database\Query\Builder;

class PostgresGrammar extends IlluminatePostgresGrammar
{
    /**
     * Create a new grammar instance.
     *
     * @param  \Illuminate\Database\Connection|null  $connection
     */
    public function __construct($connection = null)
    {
        // In Laravel 12+, Grammar constructor requires the connection
        // In Laravel 10/11, constructor doesn't require connection
        // IlluminatePostgresGrammar doesn't have a constructor, so we need to handle this carefully
        
        $grammarReflection = new \ReflectionClass(\Illuminate\Database\Grammar::class);
        $grammarConstructor = $grammarReflection->getConstructor();
        $requiresConnection = $grammarConstructor && $grammarConstructor->getNumberOfRequiredParameters() > 0;
        
        if ($requiresConnection) {
            // Laravel 12+ - Grammar constructor requires connection
            if ($connection === null) {
                throw new \InvalidArgumentException('Connection is required in Laravel 12+. PostgresGrammar must be instantiated with a connection.');
            }
            // Since IlluminatePostgresGrammar has no constructor, we need to manually
            // initialize the connection property that Grammar expects
            // We'll use reflection to set the protected property
            if ($grammarReflection->hasProperty('connection')) {
                $connectionProperty = $grammarReflection->getProperty('connection');
                // setAccessible() is deprecated in PHP 8.5+ (has no effect); only call on older PHP
                if (\PHP_VERSION_ID < 80500) {
                    $connectionProperty->setAccessible(true);
                }
                $connectionProperty->setValue($this, $connection);
            } else {
                // Fallback: try to call parent, but it might not work
                // In this case, we'll let it fail with a clear error
                try {
                    parent::__construct($connection);
                } catch (\Error $e) {
                    throw new \RuntimeException('Failed to initialize PostgresGrammar. Grammar requires connection in Laravel 12+, but parent constructor call failed.', 0, $e);
                }
            }
        } else {
            // Laravel 10/11 - Grammar constructor doesn't require connection
            // Try parent::__construct() - it should work even without args
            try {
                if ($connection !== null) {
                    parent::__construct($connection);
                } else {
                    parent::__construct();
                }
            } catch (\ArgumentCountError $e) {
                // If parent doesn't accept connection, call without args
                parent::__construct();
            }
        }
    }

    /**
     * Compile an insert statement into SQL.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $values
     * @return string
     */
    public function compileInsert(Builder $query, array $values)
    {
        return str_replace('insert into ', 'insert into ' . $this->compileSchemaName($query), parent::compileInsert($query, $values));
    }

    /**
     * Compile the "from" portion of the query.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  string  $table
     * @return string
     */
    protected function compileFrom(Builder $query, $table)
    {
        $baseFrom = 'from ' . $this->compileSchemaName($query) . $this->wrapTable($table);

        // Note: PostgreSQL does not support querying specific partitions directly
        // like MySQL does with PARTITION clause. However, you can query partition
        // tables directly by their name.
        
        return $baseFrom;
    }

    /**
     * Get schema name if set.
     *
     * @param Builder $query
     * @return string
     */
    private function compileSchemaName(Builder $query)
    {
        if (method_exists($query, 'getSchema') && $query->getSchema() !== null) {
            return $this->wrap($query->getSchema()) . '.';
        }
        return '';
    }

    /**
     * Compile a select query into SQL.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return string
     */
    public function compileSelect(Builder $query)
    {
        // If a specific partition is set, we modify the table to point to that partition
        if (method_exists($query, 'hasPartitions') && $query->hasPartitions()) {
            $partitions = $query->getPartitions();
            
            if (count($partitions) === 1) {
                // Single partition - query the partition table directly
                $originalFrom = $query->from;
                $query->from = $partitions[0];
                $sql = parent::compileSelect($query);
                $query->from = $originalFrom;
                return $sql;
            } else {
                // Multiple partitions - use UNION ALL
                $originalFrom = $query->from;
                $queries = [];
                
                foreach ($partitions as $partition) {
                    $query->from = $partition;
                    $queries[] = parent::compileSelect($query);
                }
                
                $query->from = $originalFrom;
                return implode(' UNION ALL ', $queries);
            }
        }

        return parent::compileSelect($query);
    }
}
