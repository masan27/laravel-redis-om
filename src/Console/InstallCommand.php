<?php

namespace Masan27\LaravelRedisOM\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class InstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'redis-om:install';

    /**
     * The console command description.
     */
    protected $description = 'Complete the installation of Laravel Redis OM package';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->info('Installing Laravel Redis OM...');
        $this->newLine();

        $this->publishConfig();
        $this->updateEnvFile();

        $this->newLine();
        $this->info('Installation complete!');
        $this->newLine();
        $this->line('Next steps:');
        $this->line('  1. Define your models in <fg=cyan>app/Models/Redis/</> extending <fg=cyan>RedisOM</>');
        $this->line('  2. Add <fg=cyan>protected array $index</> to each model with fields to index');
        $this->line('  3. Run <fg=cyan>php artisan redis-om:migrate</> to create RediSearch indexes');
    }

    /**
     * Publish the configuration file.
     */
    protected function publishConfig(): void
    {
        $this->info('Publishing configuration...');

        $this->call('vendor:publish', [
            '--provider' => "Masan27\\LaravelRedisOM\\RedisOMServiceProvider",
            '--tag'      => 'redis-om-config',
        ]);
    }

    /**
     * Update the .env file with necessary variables.
     */
    protected function updateEnvFile(): void
    {
        $this->info('Updating .env file...');

        $envFile = base_path('.env');

        if (!File::exists($envFile)) {
            $this->warn('.env file not found. Please manually add:');
            $this->line('REDIS_OM_CONNECTION=default');
            return;
        }

        $content = File::get($envFile);

        $variables = [
            'REDIS_OM_CONNECTION' => 'default',
        ];

        $appended = false;
        foreach ($variables as $key => $value) {
            if (!str_contains($content, "{$key}=")) {
                $content  .= "\n{$key}={$value}";
                $appended  = true;
            }
        }

        if ($appended) {
            File::put($envFile, $content);
            $this->info('Added Redis OM variables to .env');
        } else {
            $this->info('Redis OM variables already exist in .env');
        }
    }
}
