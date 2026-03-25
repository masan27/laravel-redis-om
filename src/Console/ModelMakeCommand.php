<?php

namespace Masan27\LaravelRedisOM\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ModelMakeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'redis_om:model {name : The name of the Redis model}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new Redis OM model class';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $name = $this->argument('name');
        
        // 1. Prepare Path and Class Details
        $name = str_replace('/', '\\', $name);
        $parts = explode('\\', $name);
        $className = array_pop($parts);
        $subNamespace = !empty($parts) ? '\\' . implode('\\', $parts) : '';
        
        $path = base_path('app/Models/Redis/' . str_replace('\\', '/', $name) . '.php');
        $directory = dirname($path);

        // 2. Create Directory if not exists
        if (!File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        if (File::exists($path)) {
            $this->error("Model {$name} already exists!");
            return;
        }

        // 3. Prepare Content from Stub
        $stub = File::get(__DIR__ . '/stubs/model.stub');
        
        $namespace = 'App\\Models\\Redis' . $subNamespace;
        
        $content = str_replace(
            ['{{ namespace }}', '{{ class }}'],
            [$namespace, $className],
            $stub
        );

        // 4. Save File
        File::put($path, $content);

        $this->info("Redis Model [app/Models/Redis/" . str_replace('\\', '/', $name) . ".php] created successfully.");
    }
}
