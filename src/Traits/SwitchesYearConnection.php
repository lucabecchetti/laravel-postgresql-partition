<?php

namespace Brokenice\LaravelPgsqlPartition\Traits;

use Brokenice\LaravelPgsqlPartition\YearConnection;

trait SwitchesYearConnection
{
    /**
     * Application key where the current year context is bound (e.g. from a resource's initializeQuery).
     *
     * @var string
     */
    protected $yearContextKey = 'qualisys.year';

    /**
     * Prefix used to build the schema/database name: {prefix}{year} (e.g. qualisys_2025).
     * Null lets YearConnection use config pgsql-partition.schema_prefix or its default.
     *
     * @var string|null
     */
    protected $yearSchemaPrefix = null;

    /**
     * Resolve connection using the year from the application context so eager load and other queries use the same DB.
     *
     * @return \Illuminate\Database\Connection
     */
    public function getConnection()
    {
        if (app()->bound($this->yearContextKey)) {
            return YearConnection::connection(null, $this->connection, $this->yearSchemaPrefix, $this->yearContextKey);
        }
        return parent::getConnection();
    }
}
