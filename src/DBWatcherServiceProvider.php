<?php

namespace Andrew\StrongBondDBWatcher;

use Illuminate\Support\ServiceProvider;

class DBWatcherServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        //
        $this->publishes([
            __DIR__ . '/../config/dbwatcher.php' => config_path('dbwatcher.php'),
        ]);
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton('db-watcher', function ($app) {
            return new DBWatcher();
        });
    }
}
