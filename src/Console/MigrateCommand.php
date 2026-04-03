<?php

namespace Masan27\LaravelRedisOM\Console;

use Illuminate\Console\Command;
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
}
