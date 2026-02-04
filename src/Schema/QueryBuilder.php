<?php

namespace Brokenice\LaravelPgsqlPartition\Schema;

class QueryBuilder extends \Illuminate\Database\Query\Builder
{
    /**
     * The partitions which the query is targeting.
     *
     * In PostgreSQL, partitions are separate tables, so querying a partition
     * means querying that partition table directly.
     *
     * @var string[]
     */
    private $partitions = [];

    /**
     * Schema name to override inside a query.
     *
     * @var string|null
     */
    private $schemaName = null;

    /**
     * Add a "partition" clause to the query.
     *
     * In PostgreSQL, this will query the partition table directly.
     *
     * @param array $partitions Array of partition table names
     * @return $this
     */
    public function partitions($partitions)
    {
        $this->partitions = $partitions;
        return $this;
    }

    /**
     * Add a single "partition" clause to the query.
     *
     * @param string $partition Partition table name
     * @return $this
     */
    public function partition($partition)
    {
        $this->partitions = [$partition];
        return $this;
    }

    /**
     * Set schema name.
     *
     * @param string $name
     * @return $this
     */
    public function schema($name)
    {
        $this->schemaName = $name;
        return $this;
    }

    /**
     * Get schema name.
     *
     * @return string|null
     */
    public function getSchema()
    {
        return $this->schemaName;
    }

    /**
     * Get the partitions.
     *
     * @return string[]
     */
    public function getPartitions()
    {
        return $this->partitions;
    }

    /**
     * Check if partitions are set.
     *
     * @return bool
     */
    public function hasPartitions()
    {
        return count($this->partitions) > 0;
    }

    /**
     * Execute the query as a "select" statement.
     *
     * @param  array|string  $columns
     * @return \Illuminate\Support\Collection
     */
    public function get($columns = ['*'])
    {
        // If querying specific partitions, we need to handle it
        if ($this->hasPartitions()) {
            if (count($this->partitions) === 1) {
                // Single partition - query the partition table directly
                $originalFrom = $this->from;
                $this->from = $this->partitions[0];
                $result = parent::get($columns);
                $this->from = $originalFrom;
                return $result;
            } else {
                // Multiple partitions - union the results
                $originalFrom = $this->from;
                $results = collect();

                foreach ($this->partitions as $partition) {
                    $this->from = $partition;
                    $partitionResults = parent::get($columns);
                    $results = $results->merge($partitionResults);
                }

                $this->from = $originalFrom;
                return $results;
            }
        }

        return parent::get($columns);
    }

    /**
     * Get a new instance of the query builder.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function newQuery()
    {
        return new static($this->connection, $this->grammar, $this->processor);
    }
}
