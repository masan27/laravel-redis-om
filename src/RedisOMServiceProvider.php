<?php

namespace Masan27\LaravelRedisOM;

use Illuminate\Support\ServiceProvider;

class RedisOMServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/redis_om.php', 'redis_om');

        $this->app->scoped(RedisModel::class, function ($app) {
            return new RedisModel();
        });

        $this->app->scoped(IndexManager::class, function ($app) {
            return new IndexManager();
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/redis_om.php' => config_path('redis_om.php'),
            ], 'redis-om-config');

            $this->commands([
                Console\InstallCommand::class,
                Console\ModelMakeCommand::class,
                Console\MigrateCommand::class,
            ]);
        }
    }
}
