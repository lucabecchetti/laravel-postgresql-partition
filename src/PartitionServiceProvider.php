<?php

namespace Brokenice\LaravelPgsqlPartition;

use Brokenice\LaravelPgsqlPartition\Connectors\ConnectionFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Support\Facades\Request;

/**
 * Class PartitionServiceProvider.
 */
class PartitionServiceProvider extends DatabaseServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        // The connection factory is used to create the actual connection instances on
        // the database. We will inject the factory into the manager so that it may
        // make the connections while they are actually needed and not of before.
        $this->app->singleton('db.factory', function ($app) {
            return new ConnectionFactory($app);
        });

        // The database manager is used to resolve various connections, since multiple
        // connections might be managed. It also implements the connection resolver
        // interface which may be used by other components requiring connections.
        $this->app->singleton('db', function ($app) {
            return new DatabaseManager($app, $app['db.factory']);
        });

        $this->registerCommands();
    }

    /**
     * Bootstrap any application services.
     * Replaces the current HTTP request with RequestCompat to avoid Symfony 7.4+
     * deprecation when any code calls Request::get() (e.g. other packages).
     *
     * @return void
     */
    public function boot()
    {
        if (! class_exists(\Illuminate\Http\Request::class)) {
            return;
        }

        if (! $this->app->has('request')) {
            return;
        }

        $current = $this->app->make('request');
        if (! $current instanceof \Illuminate\Http\Request) {
            return;
        }

        $this->app->instance('request', Request::createFrom($current));
    }

    /**
     * Setup the commands for the package.
     *
     * @return void
     */
    protected function registerCommands()
    {
        $this->commands([
            Console\PartitionsCommand::class
        ]);
    }
}
