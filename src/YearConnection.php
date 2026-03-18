<?php

namespace Brokenice\LaravelPgsqlPartition;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Helper to bind the current year per request and resolve the partition DB connection.
 * Use in resources (e.g. initializeQuery) to set the year once, then models using
 * SwitchesYearConnection will use the same connection for eager loads and other queries.
 */
class YearConnection
{
    protected static string $defaultContextKey = 'qualisys.year';

    protected static string $defaultConnectionName = 'year';

    protected static string $defaultSchemaPrefix = 'qualisys_';

    /**
     * Application key where the current year context is bound.
     */
    public static function contextKey(?string $key = null): string
    {
        if ($key !== null) {
            static::$defaultContextKey = $key;
        }
        return static::$defaultContextKey;
    }

    /**
     * Store year once per request so getConnection() uses it. Does not overwrite if already bound.
     */
    public static function setYearForRequest(int $year, ?string $contextKey = null): void
    {
        $key = $contextKey ?? static::contextKey();
        if (! app()->bound($key)) {
            app()->instance($key, $year);
        }
    }

    /**
     * Resolve the partition connection for the given or bound year.
     * If $year is provided, sets it for the request (once) first.
     *
     * @param  int|null  $year  Optional; if null, uses year from app context (setYearForRequest).
     * @param  string|null  $connectionName  Config key under database.connections (default: year).
     * @param  string|null  $schemaPrefix  Prefix for DB name, e.g. qualisys_ (default: qualisys_).
     * @param  string|null  $contextKey  App key for year (default: qualisys.year).
     * @return \Illuminate\Database\Connection
     */
    public static function connection(
        ?int $year = null,
        ?string $connectionName = null,
        ?string $schemaPrefix = null,
        ?string $contextKey = null
    ) {
        $connName = $connectionName ?? static::$defaultConnectionName;
        $prefix = $schemaPrefix ?? static::$defaultSchemaPrefix;
        $key = $contextKey ?? static::contextKey();

        if ($year !== null) {
            static::setYearForRequest($year, $key);
        }

        $year = (int) app($key);
        $targetDatabase = $prefix . $year;
        $currentDatabase = Config::get('database.connections.' . $connName . '.database');

        if ($currentDatabase !== $targetDatabase) {
            Config::set('database.connections.' . $connName . '.database', $targetDatabase);
            DB::purge($connName);
        }

        return DB::connection($connName);
    }
}
