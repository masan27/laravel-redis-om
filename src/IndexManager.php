<?php

namespace Masan27\LaravelRedisOM;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class IndexManager
{
    protected string $connection;
    protected string $indexSuffix;

    public function __construct()
    {
        $this->connection  = config('redis_om.connection', 'default');
        $this->indexSuffix = config('redis_om.index_suffix', 'index');
    }

    /**
     * Get Redis connection.
     */
    protected function redis()
    {
        return Redis::connection($this->connection);
    }

    /**
     * Get the global Redis prefix from connection config.
     */
    public function getGlobalPrefix(): string
    {
        $opts = config('database.redis.options.prefix', '');
        return config("database.redis.{$this->connection}.prefix", $opts);
    }

    /**
     * Execute a raw Redis command, compatible with both Predis and PhpRedis.
     * Needed because command names like 'FT.INFO' contain dots which are not
     * valid PHP method names and break ->command() on some drivers.
     */
    protected function executeRaw(array $args): mixed
    {
        $client = $this->redis()->client();

        // Duck typing: Predis has executeRaw(), PhpRedis has rawCommand()
        // Avoids instanceof \Predis\Client which requires Predis to be installed.
        if (method_exists($client, 'executeRaw')) {
            return $client->executeRaw($args);
        }

        // PhpRedis
        $cmd = array_shift($args);
        return $client->rawCommand($cmd, ...$args);
    }

    /**
     * Resolve index name for a model.
     * If the model class has a $keyPrefix property, derive from that.
     * Otherwise auto-generate: e.g. User → "users:index"
     */
    public function resolveIndexName(string $model): string
    {
        $globalPrefix = $this->getGlobalPrefix();

        if (class_exists($model) && is_subclass_of($model, RedisOM::class)) {
            $customPrefix = $model::getKeyPrefix();
            if ($customPrefix !== null) {
                return $globalPrefix . rtrim($customPrefix, ':') . ':' . $this->indexSuffix;
            }
        }

        $prefix = Str::plural(strtolower(class_basename($model)));
        return $globalPrefix . "{$prefix}:{$this->indexSuffix}";
    }
    
    /**
     * Resolve key prefix for a model.
     * If the model class has a $keyPrefix property, use that.
     * Otherwise auto-generate: e.g. User → "users:"
     */
    public function resolveKeyPrefix(string $model): string
    {
        $globalPrefix = $this->getGlobalPrefix();

        // Check if $model is a FQCN with custom $keyPrefix
        if (class_exists($model) && is_subclass_of($model, RedisOM::class)) {
            $custom = $model::getKeyPrefix();
            if ($custom !== null) {
                return $globalPrefix . rtrim($custom, ':') . ':';
            }
        }

        return $globalPrefix . Str::plural(strtolower(class_basename($model))) . ':';
    }

    /**
     * Check if an index exists.
     */
    public function indexExists(string $indexName): bool
    {
        try {
            $this->executeRaw(['FT.INFO', $indexName]);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Drop an existing index.
     */
    public function dropIndex(string $indexName): bool
    {
        try {
            $this->executeRaw(['FT.DROPINDEX', $indexName]);
            return true;
        } catch (\Exception $e) {
            Log::warning("RedisOM dropIndex: could not drop '{$indexName}': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Resolve schema from a model class $index property.
     * Returns array of ['field' => 'REDIS_TYPE']
     */
    public function resolveSchema(string $modelClass): array
    {
        if (!class_exists($modelClass)) {
            throw new \Exception("Model class '{$modelClass}' not found.");
        }

        $instance  = new $modelClass();
        $reflector = new \ReflectionClass($instance);

        // Read $index property
        if (!$reflector->hasProperty('index')) {
            throw new \Exception(
                "Model '{$modelClass}' has no \$index property defined. " .
                "Add a protected array \$index = ['field' => 'TYPE'] to enable indexing."
            );
        }

        $property = $reflector->getProperty('index');
        $property->setAccessible(true);
        $schema = $property->getValue($instance);

        if (empty($schema)) {
            throw new \Exception(
                "Model '{$modelClass}' has an empty \$index. " .
                "Define at least one field to index."
            );
        }

        $this->validateSchema($schema, $modelClass);

        return $schema;
    }

    /**
     * Get the field marked as 'ID' in the model's schema.
     * 
     * @param string $modelClass
     * @return string|null
     * @throws \Exception
     */
    public function getPrimaryKeyField(string $modelClass): ?string
    {
        $schema = $this->resolveSchema($modelClass);
        $idFields = [];

        foreach ($schema as $field => $type) {
            $parts = explode(' ', strtoupper(trim($type)));
            if (in_array('ID', $parts)) {
                $idFields[] = $field;
            }
        }

        if (count($idFields) > 1) {
            throw new \Exception("Model '{$modelClass}' has multiple 'ID' fields defined. Only one 'ID' field is supported.");
        }

        return $idFields[0] ?? null;
    }

    /**
     * Validate schema types.
     */
    protected function validateSchema(array $schema, string $modelClass): void
    {
        $validSearchTypes = ['TEXT', 'TAG', 'DATE', 'DATETIME', 'NUMERIC', 'GEO', 'TAG_CASE'];

        foreach ($schema as $field => $type) {
            $parts = explode(' ', strtoupper(trim($type)));
            $isValid = false;

            $isValid = !empty($parts);
            foreach ($parts as $part) {
                if (!in_array($part, $validSearchTypes) && $part !== 'ID' && $part !== 'SORTABLE') {
                    $isValid = false;
                    break;
                }
            }

            if (!$isValid) {
                throw new \Exception(
                    "Invalid index type '{$type}' for field '{$field}' in model '{$modelClass}'. " .
                    "Valid types: TEXT, TAG, DATE, DATETIME, NUMERIC, GEO, or ID marker."
                );
            }
        }
    }

    /**
     * Create an FT index for a model class.
     */
    public function createIndex(string $modelClass, bool $force = false): bool
    {
        $indexName = $this->resolveIndexName($modelClass);
        $keyPrefix = $this->resolveKeyPrefix($modelClass);

        // Drop first if force
        if ($force && $this->indexExists($indexName)) {
            $this->dropIndex($indexName);
        }

        // Skip if already exists
        if (!$force && $this->indexExists($indexName)) {
            return false; // false = skipped (already exists)
        }

        $schema = $this->resolveSchema($modelClass);

        // Build FT.CREATE args
        // FT.CREATE <index> ON JSON PREFIX 1 <prefix> SCHEMA <fields...>
        $args = [
            $indexName,
            'ON', 'JSON',
            'PREFIX', '1', $keyPrefix,
            'SCHEMA',
        ];

        foreach ($schema as $field => $type) {
            $typeParts = explode(' ', strtoupper(trim($type)));
            
            // Detect special handling
            $isTagCase = in_array('TAG_CASE', $typeParts);
            $isDate    = in_array('DATE', $typeParts) || in_array('DATETIME', $typeParts);
            
            // Remove 'ID' marker from parts sent to Redis
            $typeParts = array_filter($typeParts, fn($p) => $p !== 'ID');
            
            // If it was just 'ID', default to 'TAG'
            if (empty($typeParts)) {
                $typeParts = ['TAG'];
            }

            // Map internal types to RediSearch types
            $typeParts = array_map(function($p) {
                if ($p === 'DATE' || $p === 'DATETIME') return 'NUMERIC';
                if ($p === 'TAG_CASE') return 'TAG';
                return $p;
            }, $typeParts);

            $jsonPath = $isTagCase ? "$._ci_{$field}" : ($isDate ? "$._ts_{$field}" : "$.{$field}");
            $alias    = $field;

            $args[] = $jsonPath;
            $args[] = 'AS';
            $args[] = $alias;

            foreach ($typeParts as $part) {
                $args[] = $part;
            }
        }

        try {
            file_put_contents('/tmp/redis_om_args.txt', print_r($args, true));
            $this->executeRaw(array_merge(['FT.CREATE'], $args));
            return true;
        } catch (\Exception $e) {
            Log::error("RedisOM createIndex failed for '{$indexName}': " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get index info.
     */
    public function getIndexInfo(string $indexName): array
    {
        try {
            return $this->executeRaw(['FT.INFO', $indexName]) ?? [];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Discover all RedisOM model classes under a given path.
     * Returns array of fully-qualified class names.
     */
    public function discoverModels(string $path): array
    {
        if (!is_dir($path)) {
            return [];
        }

        $models = [];
        $files  = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path)
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $content = file_get_contents($file->getRealPath());
            if (!preg_match('/namespace\s+(.+?);/', $content, $matches)) {
                continue;
            }

            $namespace = $matches[1];
            $className = $namespace . '\\' . str_replace('.php', '', $file->getBasename());

            if (!class_exists($className)) {
                include_once $file->getRealPath();
            }

            if (!class_exists($className)) {
                continue;
            }

            try {
                $reflector = new \ReflectionClass($className);

                if (
                    $reflector->isAbstract() ||
                    !$reflector->isSubclassOf(RedisOM::class)
                ) {
                    continue;
                }

                $models[] = $className;
            } catch (\Exception $e) {
                continue;
            }
        }

        return $models;
    }
}
