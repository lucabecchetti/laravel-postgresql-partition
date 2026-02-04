<?php

namespace Brokenice\LaravelPgsqlPartition;

use Brokenice\LaravelPgsqlPartition\Schema\PostgresGrammar;
use Brokenice\LaravelPgsqlPartition\Schema\QueryBuilder;
use Illuminate\Database\PostgresConnection as IlluminatePostgresConnection;

class PostgresConnection extends IlluminatePostgresConnection
{
    /**
     * Get a new query builder instance.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function query()
    {
        return new QueryBuilder(
            $this,
            $this->getQueryGrammar(),
            $this->getPostProcessor()
        );
    }

    /**
     * Get the default query grammar instance.
     *
     * @return \Illuminate\Database\Query\Grammars\Grammar
     */
    protected function getDefaultQueryGrammar()
    {
        $grammar = new PostgresGrammar($this);
        if (method_exists($grammar, 'setConnection')) {
            $grammar->setConnection($this);
        }
        $this->setQueryGrammar($grammar)->setTablePrefix($this->tablePrefix);
        return $grammar;
    }
}
