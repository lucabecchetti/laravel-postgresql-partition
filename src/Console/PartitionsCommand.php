<?php

namespace Brokenice\LaravelPgsqlPartition\Console;

use Brokenice\LaravelPgsqlPartition\Models\Partition;
use Brokenice\LaravelPgsqlPartition\Schema\Schema;
use Illuminate\Console\Command;

class PartitionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'laravel-pgsql-partition
                            {action : Action to perform (list, create, detach, attach, drop, truncate, vacuum, analyze, reindex)} 
                            {--schema=public} {--table=} {--method=} {--number=} {--excludeDefault} {--column=} {--partitions=*}
                            {--from=} {--to=} {--full}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manage PostgreSQL table partitions';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $action = $this->argument('action');

        switch ($action) {
            case 'list':
                $this->listPartitions();
                break;
            case 'create':
                $this->createPartitions();
                break;
            case 'detach':
                $this->detachPartitions();
                break;
            case 'attach':
                $this->attachPartition();
                break;
            case 'drop':
                $this->dropPartitions();
                break;
            case 'truncate':
                $this->truncatePartitions();
                break;
            case 'vacuum':
                $this->vacuumPartitions();
                break;
            case 'analyze':
                $this->analyzePartitions();
                break;
            case 'reindex':
                $this->reindexPartitions();
                break;
            default:
                $this->error("Unknown action: {$action}");
                $this->line('Available actions: list, create, detach, attach, drop, truncate, vacuum, analyze, reindex');
                break;
        }
    }

    /**
     * List partitions for a table.
     */
    protected function listPartitions()
    {
        $this->checkForOptions(['table']);

        $partitions = Schema::getPartitionNames(
            $this->option('schema') ?: 'public',
            $this->option('table')
        );

        if (empty($partitions)) {
            $this->info('No partitions found for table: ' . $this->option('table'));
            return;
        }

        $this->table(
            ['PARTITION_NAME', 'PARTITION_EXPRESSION', 'ESTIMATED_ROWS', 'PARTITION_METHOD'],
            collect($partitions)->map(function ($item) {
                return [
                    $item->partition_name,
                    $item->partition_expression,
                    number_format($item->estimated_rows),
                    $item->partition_method
                ];
            })
        );
    }

    /**
     * Create partitions on an existing partitioned table.
     */
    protected function createPartitions()
    {
        $this->checkForOptions(['table', 'method']);
        $schema = $this->option('schema') ?: 'public';

        switch (strtoupper($this->option('method'))) {
            case 'HASH':
                $this->checkForOptions(['number'], 'numeric');
                Schema::partitionByHash(
                    $this->option('table'),
                    $this->option('column') ?: 'id',
                    (int) $this->option('number'),
                    $schema
                );
                $this->info('HASH partitions created successfully!');
                break;

            case 'RANGE':
                $this->checkForOptions(['column']);
                $partitions = $this->askRangePartitions();
                Schema::partitionByRange(
                    $this->option('table'),
                    $this->option('column'),
                    $partitions,
                    !$this->option('excludeDefault'),
                    $schema
                );
                $this->info('RANGE partitions created successfully!');
                break;

            case 'YEAR':
                $this->checkForOptions(['column']);
                $yearRanges = $this->askForYearRange();
                Schema::partitionByYears(
                    $this->option('table'),
                    $this->option('column'),
                    $yearRanges[0],
                    $yearRanges[1] ?: date('Y'),
                    !$this->option('excludeDefault'),
                    $schema
                );
                $this->info('YEAR partitions created successfully!');
                break;

            case 'MONTH':
                $this->checkForOptions(['column']);
                Schema::partitionByMonths(
                    $this->option('table'),
                    $this->option('column'),
                    $schema
                );
                $this->info('MONTH partitions created successfully!');
                break;

            case 'YEAR_MONTH':
                $this->checkForOptions(['column']);
                $yearRanges = $this->askForYearRange();
                Schema::partitionByYearsAndMonths(
                    $this->option('table'),
                    $this->option('column'),
                    $yearRanges[0],
                    $yearRanges[1] ?: date('Y'),
                    !$this->option('excludeDefault'),
                    $schema
                );
                $this->info('YEAR_MONTH partitions created successfully!');
                break;

            case 'LIST':
                $this->checkForOptions(['column']);
                $partitions = $this->askListPartitions();
                Schema::partitionByList(
                    $this->option('table'),
                    $this->option('column'),
                    $partitions,
                    $schema
                );
                $this->info('LIST partitions created successfully!');
                break;

            default:
                $this->error('Unknown partition method: ' . $this->option('method'));
                $this->line('Available methods: HASH, RANGE, LIST, YEAR, MONTH, YEAR_MONTH');
                break;
        }
    }

    /**
     * Detach partitions from a table.
     */
    protected function detachPartitions()
    {
        $this->checkForOptions(['table']);
        $partitions = $this->option('partitions');

        if (empty($partitions)) {
            $this->error('Please specify partitions to detach using --partitions');
            return;
        }

        $schema = $this->option('schema') ?: 'public';

        foreach ($partitions as $partition) {
            Schema::detachPartition($this->option('table'), $partition, $schema);
            $this->info("Partition {$partition} detached successfully!");
        }
    }

    /**
     * Attach a partition to a table.
     */
    protected function attachPartition()
    {
        $this->checkForOptions(['table']);
        $partitions = $this->option('partitions');

        if (empty($partitions) || count($partitions) !== 1) {
            $this->error('Please specify exactly one partition to attach using --partitions');
            return;
        }

        $this->checkForOptions(['from', 'to']);

        $schema = $this->option('schema') ?: 'public';
        $partitionName = $partitions[0];
        $partitionDef = Partition::range($partitionName, $this->option('from'), $this->option('to'));

        Schema::attachPartition($this->option('table'), $partitionName, $partitionDef, $schema);
        $this->info("Partition {$partitionName} attached successfully!");
    }

    /**
     * Drop partitions.
     */
    protected function dropPartitions()
    {
        $partitions = $this->option('partitions');

        if (empty($partitions)) {
            $this->error('Please specify partitions to drop using --partitions');
            return;
        }

        $schema = $this->option('schema') ?: 'public';

        if (!$this->confirm('This will permanently delete the partition tables and their data. Continue?')) {
            return;
        }

        foreach ($partitions as $partition) {
            Schema::dropPartition($partition, $schema);
            $this->info("Partition {$partition} dropped successfully!");
        }
    }

    /**
     * Truncate partitions.
     */
    protected function truncatePartitions()
    {
        $partitions = $this->option('partitions');

        if (empty($partitions)) {
            $this->error('Please specify partitions to truncate using --partitions');
            return;
        }

        $schema = $this->option('schema') ?: 'public';

        Schema::truncatePartitions($partitions, $schema);
        $this->info('Partitions ' . implode(', ', $partitions) . ' truncated successfully!');
    }

    /**
     * Run VACUUM on partitions.
     */
    protected function vacuumPartitions()
    {
        $partitions = $this->option('partitions');

        if (empty($partitions)) {
            $this->error('Please specify partitions to vacuum using --partitions');
            return;
        }

        $schema = $this->option('schema') ?: 'public';
        $full = $this->option('full');

        Schema::vacuumPartitions($partitions, $full, $schema);
        $this->info('VACUUM completed on partitions: ' . implode(', ', $partitions));
    }

    /**
     * Run ANALYZE on partitions.
     */
    protected function analyzePartitions()
    {
        $partitions = $this->option('partitions');

        if (empty($partitions)) {
            $this->error('Please specify partitions to analyze using --partitions');
            return;
        }

        $schema = $this->option('schema') ?: 'public';

        Schema::analyzePartitions($partitions, $schema);
        $this->info('ANALYZE completed on partitions: ' . implode(', ', $partitions));
    }

    /**
     * Run REINDEX on partitions.
     */
    protected function reindexPartitions()
    {
        $partitions = $this->option('partitions');

        if (empty($partitions)) {
            $this->error('Please specify partitions to reindex using --partitions');
            return;
        }

        $schema = $this->option('schema') ?: 'public';

        Schema::reindexPartitions($partitions, $schema);
        $this->info('REINDEX completed on partitions: ' . implode(', ', $partitions));
    }

    /**
     * Check for required options.
     *
     * @param array $options
     * @param string $type
     */
    private function checkForOptions($options, $type = '')
    {
        foreach ($options as $option) {
            if (empty($this->option($option)) || $this->option($option) === null) {
                $this->error("\n Please provide the --{$option} option! \n");
                exit(1);
            }

            switch ($type) {
                case 'numeric':
                    if (!is_numeric($this->option($option))) {
                        $this->error("\n Error: --{$option} must be a number! \n");
                        exit(1);
                    }
                    break;
                case 'array':
                    if (count(explode(',', $this->option($option))) <= 0) {
                        $this->error("\n Error: --{$option} must be a comma-separated string! \n");
                        exit(1);
                    }
                    break;
                default:
                    if (!is_string($this->option($option))) {
                        $this->error("\n Error: --{$option} must be a string! \n");
                        exit(1);
                    }
                    break;
            }
        }
    }

    /**
     * Ask user to build LIST partitions.
     *
     * @return array
     */
    private function askListPartitions()
    {
        $partitions = [];

        do {
            $listNumber = $this->ask('How many partitions do you want to create?');
        } while (!is_numeric($listNumber));

        for ($i = 0; $i < $listNumber; $i++) {
            $partitionName = $this->ask("Enter name for partition {$i}") ?: "list{$i}";

            do {
                $items = explode(',', $this->ask("Enter comma-separated values for partition {$partitionName}"));
            } while (!is_array($items) || count($items) <= 0);

            $partitions[] = Partition::list($partitionName, array_map('trim', $items));
        }

        return $partitions;
    }

    /**
     * Ask user to build RANGE partitions.
     *
     * @return array
     */
    private function askRangePartitions()
    {
        $partitions = [];

        do {
            $rangeNumber = $this->ask('How many partitions do you want to create?');
        } while (!is_numeric($rangeNumber));

        for ($i = 0; $i < $rangeNumber; $i++) {
            $partitionName = $this->ask("Enter name for partition {$i}") ?: "range{$i}";
            $from = $this->ask("Enter FROM value for partition {$partitionName}");
            $to = $this->ask("Enter TO value for partition {$partitionName}");

            $partitions[] = Partition::range($partitionName, $from, $to);
        }

        return $partitions;
    }

    /**
     * Ask user for year range.
     *
     * @return array
     */
    private function askForYearRange()
    {
        do {
            $startYear = $this->ask('Enter start year for partition:');
        } while (!is_numeric($startYear));

        do {
            $endYear = $this->ask('Enter end year for partition (leave blank for current year):');
        } while (($endYear !== null && $endYear !== '' && !is_numeric($endYear)) || 
                 (is_numeric($endYear) && $endYear < $startYear));

        return [(int) $startYear, $endYear ? (int) $endYear : null];
    }
}
