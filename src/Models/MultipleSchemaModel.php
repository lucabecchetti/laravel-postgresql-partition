<?php

namespace Brokenice\LaravelPgsqlPartition\Models;

use Brokenice\LaravelPgsqlPartition\Schema\QueryBuilder;
use Illuminate\Database\Eloquent\Model;

class MultipleSchemaModel extends Model
{
    /**
     * Schema name to override inside a query.
     *
     * @var string|null
     */
    private $schemaName = null;

    /**
     * Save the model to the database using a specific schema.
     *
     * @param string $schema
     * @param array $options
     * @return bool
     */
    public function saveOnSchema($schema, $options = [])
    {
        $this->schemaName = $schema;
        return self::save($options);
    }

    /**
     * Get a new query builder that doesn't have any global scopes or eager loading.
     *
     * @return \Illuminate\Database\Eloquent\Builder|static
     */
    public function newModelQuery()
    {
        $queryBuilder = $this->newEloquentBuilder(
            $this->newBaseQueryBuilder()
        )->setModel($this);

        if ($this->schemaName !== null) {
            $queryBuilder->getQuery()->schema($this->schemaName);
        }

        return $queryBuilder;
    }

    /**
     * Set the schema for this model.
     *
     * @param string $schema
     * @return $this
     */
    public function setSchema($schema)
    {
        $this->schemaName = $schema;
        return $this;
    }

    /**
     * Get the current schema.
     *
     * @return string|null
     */
    public function getSchema()
    {
        return $this->schemaName;
    }

    /**
     * Query a specific partition directly.
     *
     * @param string $partition
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function partition($partition)
    {
        $instance = new static;
        return $instance->newQuery()->getQuery()->partition($partition);
    }

    /**
     * Query multiple partitions directly.
     *
     * @param array $partitions
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function partitions(array $partitions)
    {
        $instance = new static;
        return $instance->newQuery()->getQuery()->partitions($partitions);
    }
}
