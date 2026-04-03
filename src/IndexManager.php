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
        if (class_exists($model) && is_subclass_of($model, RedisOM::class)) {
            $customPrefix = $model::getKeyPrefix();
            if ($customPrefix !== null) {
                return rtrim($customPrefix, ':') . ':' . $this->indexSuffix;
            }
        }

        $prefix = Str::plural(strtolower(class_basename($model)));
        return "{$prefix}:{$this->indexSuffix}";
    }
    
    /**
     * Resolve key prefix for a model.
     * If the model class has a $keyPrefix property, use that.
     * Otherwise auto-generate: e.g. User → "users:"
     */
    public function resolveKeyPrefix(string $model): string
    {
        // Check if $model is a FQCN with custom $keyPrefix
        if (class_exists($model) && is_subclass_of($model, RedisOM::class)) {
            $custom = $model::getKeyPrefix();
            if ($custom !== null) {
                return rtrim($custom, ':') . ':';
            }
        }

        return Str::plural(strtolower(class_basename($model))) . ':';
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
            if (strtoupper(trim($type)) === 'ID') {
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
        $validTypes = ['TEXT', 'TEXT SORTABLE', 'TAG', 'TAG SORTABLE', 'TAG_CASE', 'DATE', 'DATETIME', 'NUMERIC', 'NUMERIC SORTABLE', 'GEO', 'ID'];

        foreach ($schema as $field => $type) {
            $baseType = strtoupper(trim($type));
            $valid = false;

            foreach ($validTypes as $validType) {
                if (str_starts_with($baseType, $validType)) {
                    $valid = true;
                    break;
                }
            }

            if (!$valid) {
                throw new \Exception(
                    "Invalid index type '{$type}' for field '{$field}' in model '{$modelClass}'. " .
                    "Valid types: TEXT, TEXT SORTABLE, TAG, TAG SORTABLE, TAG_CASE, DATE, DATETIME, NUMERIC, GEO."
                );
            }
        }
    }

    /**
     * Create an FT index for a model class.
     */
    public function createIndex(string $modelClass, bool $force = false): bool
    {
        $modelName = class_basename($modelClass);
        $indexName = $this->resolveIndexName($modelName);
        $keyPrefix = $this->resolveKeyPrefix($modelName);

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
            $type     = strtoupper(trim($type));
            $isDate   = ($type === 'DATE' || $type === 'DATETIME');
            $jsonPath = ($type === 'TAG_CASE') ? "$._ci_{$field}" : ($isDate ? "$._ts_{$field}" : "$.{$field}");
            $alias    = $field;
            
            if ($isDate) {
                $typeParts = ['NUMERIC'];
            } else {
                $typeParts = explode(' ', ($type === 'TAG_CASE') ? 'TAG' : $type);
            }

            $args[] = $jsonPath;
            $args[] = 'AS';
            $args[] = $alias;

            foreach ($typeParts as $part) {
                $args[] = $part;
            }
        }

        try {
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
