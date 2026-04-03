<?php

namespace Masan27\LaravelRedisOM\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Masan27\LaravelRedisOM\IndexManager;

class MigrateCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'redis-om:migrate
                            {model? : Specific model class name (e.g. User)}
                            {--force : Drop and recreate existing indexes}';

    /**
     * The console command description.
     */
    protected $description = 'Create RediSearch indexes for Redis OM models';

    /**
     * Execute the console command.
     */
    public function handle(IndexManager $indexManager): void
    {
        $force     = $this->option('force');
        $modelArg  = $this->argument('model');
        $modelPath = config('redis_om.model_path', app_path('Models/Redis'));

        if (!$this->ensureConnection($indexManager)) {
            return;
        }

        $this->info('Redis OM Migrate' . ($force ? ' (--force)' : ''));
        $this->newLine();

        // Resolve which models to migrate
        if ($modelArg) {
            $models = $this->resolveModelByName($modelArg, $modelPath);

            if (empty($models)) {
                $this->error("Model '{$modelArg}' not found under {$modelPath}.");
                return;
            }
        } else {
            $models = $indexManager->discoverModels($modelPath);

            if (empty($models)) {
                $this->warn("No Redis OM models found under: {$modelPath}");
                $this->line("Make sure your models extend RedisOM and are in the correct path.");
                return;
            }
        }

        $this->line("Found " . count($models) . " model(s).");
        $this->newLine();

        $created = 0;
        $skipped = 0;
        $failed  = 0;

        foreach ($models as $modelClass) {
            $modelName = class_basename($modelClass);

            try {
                $result = $indexManager->createIndex($modelClass, $force);

                if ($result === true) {
                    $indexName = $indexManager->resolveIndexName($modelName);
                    $this->line("  <fg=green>✓</> <fg=white>{$modelName}</> → <fg=cyan>{$indexName}</>");
                    $created++;
                } else {
                    // false = already exists and not forced
                    $indexName = $indexManager->resolveIndexName($modelName);
                    $this->line("  <fg=yellow>–</> <fg=white>{$modelName}</> → <fg=yellow>already exists, skipped</> (use --force to recreate)");
                    $skipped++;
                }
            } catch (\Exception $e) {
                $this->line("  <fg=red>✗</> <fg=white>{$modelName}</> → <fg=red>{$e->getMessage()}</>");
                $failed++;
            }
        }

        $this->newLine();
        $this->line("Done. Created: <fg=green>{$created}</> | Skipped: <fg=yellow>{$skipped}</> | Failed: <fg=red>{$failed}</>");
    }

    /**
     * Try to find a model class by short name (e.g. "User" → "App\Models\Redis\User").
     */
    protected function resolveModelByName(string $name, string $modelPath): array
    {
        // Try fully qualified first
        if (class_exists($name)) {
            return [$name];
        }

        // Try common namespaces
        $candidates = [
            "App\\Models\\Redis\\{$name}",
            "App\\Models\\{$name}",
        ];

        foreach ($candidates as $candidate) {
            if (class_exists($candidate)) {
                return [$candidate];
            }
        }

        // Scan model path
        $indexManager = app(IndexManager::class);
        $all          = $indexManager->discoverModels($modelPath);

        return array_filter($all, fn($class) => class_basename($class) === $name);
    }
    /**
     * Ensure we can connect to Redis, or try fallback host.
     */
    protected function ensureConnection(IndexManager $indexManager): bool
    {
        $connectionName = config('redis_om.connection', 'default');
        
        try {
            // Test connection with a simple command
            Redis::connection($connectionName)->command('PING');
            return true;
        } catch (\Exception $e) {
            $host = config("database.redis.{$connectionName}.host");
            $newHost = null;

            if ($host === 'localhost' || $host === '127.0.0.1') {
                $newHost = 'host.docker.internal';
            } elseif ($host === 'host.docker.internal') {
                $newHost = '127.0.0.1';
            }

            if ($newHost) {
                $this->warn("Connection to Redis at '{$host}' failed. Trying fallback host '{$newHost}'...");
                
                // Dynamically update config
                config()->set("database.redis.{$connectionName}.host", $newHost);
                
                // Purge instance to force reconnect
                Redis::purge($connectionName);

                try {
                    Redis::connection($connectionName)->command('PING');
                    $this->info("Successfully connected to Redis using fallback host '{$newHost}'.");
                    return true;
                } catch (\Exception $e2) {
                    $this->error("Connection to Redis failed on both '{$host}' and '{$newHost}'.");
                    $this->error($e2->getMessage());
                    return false;
                }
            }

            $this->error("Connection to Redis at '{$host}' failed.");
            $this->error($e->getMessage());
            return false;
        }
    }
}
