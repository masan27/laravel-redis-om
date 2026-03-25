<?php

namespace Masan27\LaravelRedisOM\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class InstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'redis-om:install';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Complete the installation of Laravel Redis OM package';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->info('Installing Laravel Redis OM...');

        // 1. Publish Configuration
        $this->publishConfig();

        // 2. Update .env file
        $this->updateEnvFile();

        $this->info('Laravel Redis OM installation completed successfully!');
    }

    /**
     * Publish the configuration file.
     */
    protected function publishConfig(): void
    {
        $this->info('Publishing configuration...');

        $this->call('vendor:publish', [
            '--provider' => "Masan27\LaravelRedisOM\RedisOMServiceProvider",
            '--tag'      => "redis-om-config"
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
            $this->warn('.env file not found. Please manually add the following variables:');
            $this->line('REDIS_OM_URL=http://redis-om:8000');
            $this->line('REDIS_OM_CONNECTION=default');
            $this->line('REDIS_OM_TIMEOUT=30');
            return;
        }

        $content = File::get($envFile);

        $variables = [
            'REDIS_OM_URL'        => 'http://redis-om:8000',
            'REDIS_OM_CONNECTION' => 'default',
            'REDIS_OM_TIMEOUT'    => '30',
        ];

        $appended = false;
        foreach ($variables as $key => $value) {
            if (!str_contains($content, "{$key}=")) {
                $content .= "\n{$key}={$value}";
                $appended = true;
            }
        }

        if ($appended) {
            File::put($envFile, $content);
            $this->info('Added missing Redis OM variables to .env');
        } else {
            $this->info('Redis OM variables already exist in .env');
        }
    }
}
