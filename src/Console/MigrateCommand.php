<?php

namespace Masan27\LaravelRedisOM\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
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
        $modelPath = app_path('Models/RedisOM');

        if (!$this->ensureConnection($indexManager)) {
            return;
        }

        $this->info('Redis OM Migrate' . ($force ? ' (--force)' : ''));
        $this->newLine();

        // Resolve which models to migrate
        if ($modelArg) {
            $models = $this->resolveModelByName($modelArg, $modelPath, $indexManager);

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
                    $indexName = $indexManager->resolveIndexName($modelClass);
                    $this->line("  <fg=green>✓</> <fg=white>{$modelName}</> → <fg=cyan>{$indexName}</>");
                    $created++;
                } else {
                    // false = already exists and not forced
                    $indexName = $indexManager->resolveIndexName($modelClass);
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
    protected function resolveModelByName(string $name, string $modelPath, IndexManager $indexManager): array
    {
        // Try fully qualified first
        if (class_exists($name)) {
            return [$name];
        }

        // Try common namespaces
        $candidates = [
            "App\\Models\\RedisOM\\{$name}",
            "App\\Models\\{$name}",
        ];

        foreach ($candidates as $candidate) {
            if (class_exists($candidate)) {
                return [$candidate];
            }
        }

        // Scan model path
        $all = $indexManager->discoverModels($modelPath);

        return array_filter($all, fn($class) => class_basename($class) === $name);
    }
    /**
     * Ensure we can connect to Redis, or try fallback host.
     */
     protected function ensureConnection(IndexManager $indexManager): bool
    {
        $connectionName = config('redis_om.connection', 'default');
        $host = config("database.redis.{$connectionName}.host");
        $port = config("database.redis.{$connectionName}.port", 6379);

        // Coba connect langsung pakai socket, bukan lewat Laravel Redis facade dulu
        $hosts = [$host];

        if ($host === 'localhost' || $host === '127.0.0.1') {
            $hosts[] = 'host.docker.internal';
        } elseif ($host === 'host.docker.internal') {
            $hosts[] = '127.0.0.1';
        }

        foreach ($hosts as $tryHost) {
            // Test dulu pakai fsockopen sebelum bikin Redis connection
            $fp = @fsockopen($tryHost, $port, $errno, $errstr, 2);
            if ($fp) {
                fclose($fp);

                if ($tryHost !== $host) {
                    $this->warn("Host '{$host}:{$port}' unreachable. Using fallback '{$tryHost}:{$port}'.");
                    config()->set("database.redis.{$connectionName}.host", $tryHost);
                    Redis::purge($connectionName);
                }

                try {
                    Redis::connection($connectionName)->command('PING');
                    return true;
                } catch (\Exception $e) {
                    $this->error("Redis PING failed on '{$tryHost}': " . $e->getMessage());
                    return false;
                }
            }
        }

        $this->error("Nggak bisa konek ke Redis. Hosts yang dicoba: " . implode(', ', $hosts));
        return false;
    }
}
