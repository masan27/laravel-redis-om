<?php

namespace Masan27\LaravelRedisOM;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Carbon\Carbon;

class RedisModel
{
    protected array $relations = [];
    protected string $connection;

    public function __construct()
    {
        $this->relations  = config('redis_om.relations', []);
        $this->connection = config('redis_om.connection', 'default');
    }

    /**
     * Resolve the service for a model (Static entry point)
     */
    public static function query(string $model, ?string $modelClass = null): RedisOMQueryBuilder
    {
        return app(static::class)->builder($model, $modelClass);
    }

    /**
     * Start a fluent query builder (Instance method)
     */
    public function builder(string $model, ?string $modelClass = null): RedisOMQueryBuilder
    {
        return new RedisOMQueryBuilder($this, $model, $modelClass);
    }

    /**
     * Define a relationship between models (Runtime definition)
     */
    public function defineRelation(
        string $model,
        string $name,
        string $type,
        string $relatedModel,
        string $foreignKey,
        string $localKey = 'id'
    ): void {
        $this->relations[$model][$name] = [
            'type'        => $type,
            'related'     => $relatedModel,
            'foreign_key' => $foreignKey,
            'local_key'   => $localKey,
        ];
    }

    /**
     * Get relation definitions for a model.
     * Merges relations from config and the Model class itself.
     */
    public function getRelations(string $model, ?string $modelClass = null): array
    {
        $configRelations = $this->relations[$model] ?? [];
        $classRelations  = [];

        if ($modelClass && class_exists($modelClass)) {
            try {
                $instance  = new $modelClass();
                $reflector = new \ReflectionClass($instance);
                $property  = $reflector->getProperty('relations');
                $property->setAccessible(true);
                $classRelations = $property->getValue($instance);
            } catch (\Exception $e) {
                // ignore
            }
        }

        return array_merge($configRelations, $classRelations);
    }

    /**
     * Get the indexed fields for a model class.
     * Used to validate that queried fields are actually indexed.
     */
    public function getIndexedFields(?string $modelClass): array
    {
        if (!$modelClass || !class_exists($modelClass)) {
            return [];
        }

        try {
            $instance  = new $modelClass();
            $reflector = new \ReflectionClass($instance);

            if (!$reflector->hasProperty('index')) {
                return [];
            }

            $property = $reflector->getProperty('index');
            $property->setAccessible(true);
            return $property->getValue($instance);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Resolve index name for a model.
     * e.g. "User" → "users:index"
     */
    public function resolveIndexName(string $model): string
    {
        return app(IndexManager::class)->resolveIndexName($model);
    }

    /**
     * Get the index type for a specific field.
     * Returns e.g. "NUMERIC SORTABLE", "TEXT", "TAG", or null if not indexed.
     */
    protected function getFieldIndexType(string $field, ?string $modelClass): ?string
    {
        $indexedFields = $this->getIndexedFields($modelClass);
        return isset($indexedFields[$field]) ? strtoupper(trim($indexedFields[$field])) : null;
    }

    /**
     * Build FT.SEARCH query string from filters array.
     */
    protected function buildFtQuery(array $filters, ?string $modelClass = null): string
    {
        if (empty($filters)) {
            return '*';
        }

        $parts = [];

        foreach ($filters as $filter) {
            $field = $filter['field'];
            $op    = $filter['op'];
            $value = $filter['value'];

            // Validate field is indexed (only when modelClass is known)
            if ($modelClass) {
                $indexType = $this->getFieldIndexType($field, $modelClass);
                if ($indexType === null) {
                    throw new \Exception(
                        "Field '{$field}' is not indexed. " .
                        "Add it to \$index in your model to enable querying."
                    );
                }
            }

            $parts[] = $this->buildCondition($field, $op, $value, $modelClass);
        }

        return implode(' ', $parts);
    }

    /**
     * Build a single FT.SEARCH condition.
     */
    protected function buildCondition(string $field, string $op, mixed $value, ?string $modelClass = null): string
    {
        $indexType = $this->getFieldIndexType($field, $modelClass) ?? 'TEXT';
        $isNumeric = str_starts_with($indexType, 'NUMERIC');
        $isTag     = str_starts_with($indexType, 'TAG');
        $isTagCase = $indexType === 'TAG_CASE';
        $isDate    = ($indexType === 'DATE' || $indexType === 'DATETIME');

        if (($isTagCase || $isDate) && !is_null($value)) {
            if (is_array($value)) {
                $value = array_map(function($v) use ($isTagCase) {
                    if ($isTagCase) return strtolower((string) $v);
                    return $v instanceof \DateTimeInterface ? $v->getTimestamp() : Carbon::parse($v)->timestamp;
                }, $value);
            } else {
                if ($isTagCase) {
                    $value = strtolower((string) $value);
                } else {
                    $value = $value instanceof \DateTimeInterface ? $value->getTimestamp() : Carbon::parse($value)->timestamp;
                }
            }
        }

        switch ($op) {
            case '=':
            case '==':
                if ($isNumeric) {
                    return "@{$field}:[{$value} {$value}]";
                }
                if ($isTag || $isTagCase) {
                    $escaped = $this->escapeTagValue((string) $value);
                    return "@{$field}:{{$escaped}}";
                }
                // TEXT field: exact match usually requires quoting if multiple words, 
                // but for single word it's just the word.
                return "@{$field}:\"{$value}\"";

            case '!=':
                if ($isNumeric) {
                    return "-@{$field}:[{$value} {$value}]";
                }
                if ($isTag || $isTagCase) {
                    $escaped = $this->escapeTagValue((string) $value);
                    return "-@{$field}:{{$escaped}}";
                }
                return "-@{$field}:\"{$value}\"";

            case '>':
                return "@{$field}:[({$value} +inf]";

            case '>=':
                return "@{$field}:[{$value} +inf]";

            case '<':
                return "@{$field}:[-inf ({$value}]";

            case '<=':
                return "@{$field}:[-inf {$value}]";

            case 'between':
                if (!is_array($value) || count($value) !== 2) {
                    throw new \Exception("Operator 'between' requires array with 2 values.");
                }
                return "@{$field}:[{$value[0]} {$value[1]}]";

            case 'in':
                if (!is_array($value) || empty($value)) {
                    throw new \Exception("Operator 'in' requires a non-empty array.");
                }
                if ($isNumeric) {
                    $conditions = array_map(fn($v) => "@{$field}:[{$v} {$v}]", $value);
                    return '(' . implode('|', $conditions) . ')';
                }
                $escaped = array_map(fn($v) => $this->escapeTagValue($v), (array) $value);
                return "@{$field}:{" . implode('|', $escaped) . "}";

            case '!in':
                if (!is_array($value) || empty($value)) {
                    throw new \Exception("Operator '!in' requires a non-empty array.");
                }
                if ($isNumeric) {
                    $conditions = array_map(fn($v) => "-@{$field}:[{$v} {$v}]", $value);
                    return implode(' ', $conditions);
                }
                $escaped = array_map(fn($v) => $this->escapeTagValue($v), (array) $value);
                return "-@{$field}:{" . implode('|', $escaped) . "}";

            case 'startswith':
                return "@{$field}:{$value}*";

            case 'null':
                return "ismissing(@{$field})";

            case 'not_null':
                return "exists(@{$field})";

            default:
                throw new \Exception("Operator '{$op}' is not supported.");
        }
    }

    /**
     * Escape special characters for TAG field values.
     */
    protected function escapeTagValue(string $value): string
    {
        return str_replace(
            [',', '.', '<', '>', '{', '}', '[', ']', '"', "'", ':', ';', '!', '@', '#', '$', '%', '^', '&', '*', '(', ')', '-', '+', '=', '~', '|', ' ', '/'],
            array_map(fn($c) => "\\{$c}", [',', '.', '<', '>', '{', '}', '[', ']', '"', "'", ':', ';', '!', '@', '#', '$', '%', '^', '&', '*', '(', ')', '-', '+', '=', '~', '|', ' ', '/']),
            $value
        );
    }

    /**
     * Parse raw FT.SEARCH response into ['data' => [], 'total' => 0].
     *
     * FT.SEARCH raw response format:
     * [total, key1, [field, value, ...], key2, [field, value, ...], ...]
     */
    protected function parseFtSearchResult(mixed $raw): array
    {
        if (empty($raw) || !is_array($raw)) {
            return ['data' => [], 'total' => 0];
        }

        $total = (int) ($raw[0] ?? 0);
        $docs  = [];

        // Results start at index 1, each doc = [key, fields_array]
        for ($i = 1; $i < count($raw); $i += 2) {
            $fieldsRaw = $raw[$i + 1] ?? [];

            // When using RETURN or no RETURN, fields come back differently
            // With JSON index: fields is array like ['$', '{...json...}']
            if (is_array($fieldsRaw) && count($fieldsRaw) >= 2) {
                // Pair-based: [field1, val1, field2, val2, ...]
                if ($fieldsRaw[0] === '$') {
                    // Full JSON doc stored under '$'
                    $decoded = json_decode($fieldsRaw[1], true);
                    if ($decoded !== null) {
                        $docs[] = $decoded;
                        continue;
                    }
                }

                // Pair-based fields (RETURN specific fields)
                $doc = [];
                for ($j = 0; $j < count($fieldsRaw); $j += 2) {
                    $key         = $fieldsRaw[$j];
                    $val         = $fieldsRaw[$j + 1] ?? null;
                    $doc[$key]   = $val;
                }
                $docs[] = $doc;
            }
        }

        return ['data' => $docs, 'total' => $total];
    }

    /**
     * Execute FT.SEARCH directly against Redis.
     */
    public function rawQuery(
        string $model,
        array $filters = [],
        ?int $limit = null,
        ?int $offset = null,
        ?string $sortBy = null,
        bool $sortAsc = true,
        ?array $fields = null,
        ?string $modelClass = null
    ): array {
        try {
            $indexName   = $this->resolveIndexName($model);
            $queryString = $this->buildFtQuery($filters, $modelClass);

            $args = [$indexName, $queryString];

            // RETURN specific fields
            if ($fields) {
                $args[] = 'RETURN';
                $args[] = count($fields);
                foreach ($fields as $f) {
                    $args[] = $f;
                }
            }

            // SORTBY
            if ($sortBy) {
                $args[] = 'SORTBY';
                $args[] = $sortBy;
                $args[] = $sortAsc ? 'ASC' : 'DESC';
            }

            // LIMIT
            $args[] = 'LIMIT';
            $args[] = $offset ?? 0;
            $args[] = $limit ?? 10;

            $raw    = $this->executeRaw(array_merge(['FT.SEARCH'], $args));
            $result = $this->parseFtSearchResult($raw);

            // Strip internal audit and hidden fields
            $result['data'] = array_map(function ($doc) {
                foreach ($doc as $key => $val) {
                    if ($key === 'updated_time' || $key === 'update_time' || str_starts_with($key, '_')) {
                        unset($doc[$key]);
                    }
                }
                return $doc;
            }, $result['data']);

            return $result;
        } catch (\Exception $e) {
            Log::error("RedisOM FT.SEARCH Error: " . $e->getMessage());
            return ['data' => [], 'total' => 0, 'error' => $e->getMessage()];
        }
    }

    // ─────────────────────────────────────────────
    // Direct Redis Methods
    // ─────────────────────────────────────────────

    /**
     * Get the Redis connection to use.
     */
    protected function redis()
    {
        return Redis::connection($this->connection);
    }

    /**
     * Execute a raw Redis command, compatible with both Predis and PhpRedis.
     * Needed because command names like 'FT.SEARCH' and 'JSON.GET' contain
     * dots which are not valid PHP method names and break ->command().
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
     * GET value directly from Redis.
     */
    public function directGet(string $key): mixed
    {
        // 1. Try JSON.GET
        try {
            $raw = $this->executeRaw(['JSON.GET', $key]);
            if ($raw !== null && $raw !== false) {
                return json_decode($raw, true);
            }
        } catch (\Exception $e) {
            // Not a RedisJSON key, fallback
        }

        // 2. Fallback: plain GET
        try {
            $plain = $this->redis()->get($key);
            if ($plain === null) {
                return null;
            }
            $decoded = json_decode($plain, true);
            return $decoded ?? $plain;
        } catch (\Exception $e) {
            Log::warning("RedisOM directGet error for key '{$key}': " . $e->getMessage());
            return null;
        }
    }

    /**
     * SET value directly to Redis.
     */
    public function directSet(string $key, mixed $value, ?int $ttl = null): bool
    {
        try {
            if (is_array($value)) {
                unset($value['_updated_time'], $value['updated_time'], $value['update_time']);
                $payload = [
                    '_updated_time' => Carbon::now()->toIso8601String(),
                    ...$value,
                ];
                $this->executeRaw(['JSON.SET', $key, '$', json_encode($payload)]);
            } else {
                $stored = is_bool($value) ? (int) $value : $value;
                $this->redis()->set($key, $stored);
            }

            if ($ttl) {
                $this->redis()->expire($key, $ttl);
            }

            return true;
        } catch (\Exception $e) {
            Log::error("RedisOM directSet Error for key '{$key}': " . $e->getMessage());
            return false;
        }
    }

    /**
     * UPDATE partial fields directly via JSON.SET per path.
     */
    public function directUpdate(string $key, array $fields, ?int $ttl = null): bool
    {
        try {
            foreach ($fields as $field => $val) {
                $this->executeRaw(['JSON.SET', $key, "\$.{$field}", json_encode($val)]);
            }

            $this->executeRaw(['JSON.SET', $key, '$._updated_time', json_encode(
                Carbon::now()->toIso8601String()
            )]);

            if ($ttl) {
                $this->redis()->expire($key, $ttl);
            }

            return true;
        } catch (\Exception $e) {
            Log::error("RedisOM directUpdate Error for key '{$key}': " . $e->getMessage());
            return false;
        }
    }

    /**
     * DELETE key directly from Redis.
     */
    public function directDelete(string $key): bool
    {
        try {
            $this->redis()->del($key);
            return true;
        } catch (\Exception $e) {
            Log::error("RedisOM directDelete Error for key '{$key}': " . $e->getMessage());
            return false;
        }
    }

    /**
     * CHECK if key exists directly in Redis.
     */
    public function directExists(string $key): bool
    {
        try {
            return (bool) $this->redis()->exists($key);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Mass insert via pipeline with automatic normalization.
     */
    public function massInsert(string $model, array $records, ?int $ttl = null, ?string $modelClass = null): bool
    {
        try {
            $indexedFields = $this->getIndexedFields($modelClass);

            $this->redis()->pipeline(function ($pipe) use ($model, $records, $ttl, $indexedFields) {
                $now = Carbon::now()->toIso8601String();

                foreach ($records as $record) {
                    $id = $record['id'] ?? $record['pk'] ?? null;
                    if (!$id) continue;

                    $key = app(IndexManager::class)->resolveKeyPrefix($model) . $id;
                    unset($record['_updated_time'], $record['updated_time'], $record['update_time']);
                    
                    // Normalization
                    foreach ($indexedFields as $f => $type) {
                        $type   = strtoupper(trim($type));
                        $isDate = ($type === 'DATE' || $type === 'DATETIME');

                        if ($type === 'TAG_CASE' && isset($record[$f])) {
                            $record["_ci_{$f}"] = strtolower((string) $record[$f]);
                        }

                        if ($isDate && isset($record[$f])) {
                            $val = $record[$f];
                            $record["_ts_{$f}"] = $val instanceof \DateTimeInterface ? $val->getTimestamp() : Carbon::parse($val)->timestamp;
                        }
                    }

                    $payload = ['_updated_time' => $now, ...$record];

                    $pipe->rawCommand('JSON.SET', $key, '$', json_encode($payload));

                    if ($ttl) {
                        $pipe->expire($key, $ttl);
                    }
                }
            });

            return true;
        } catch (\Exception $e) {
            Log::error("RedisOM massInsert Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Mass update via pipeline.
     */
    public function massUpdate(string $model, array $ids, array $fields, ?string $modelClass = null): bool
    {
        try {
            $indexedFields = $this->getIndexedFields($modelClass);

            $this->redis()->pipeline(function ($pipe) use ($model, $ids, $fields, $indexedFields) {
                $now = Carbon::now()->toIso8601String();

                foreach ($ids as $id) {
                    $key = app(IndexManager::class)->resolveKeyPrefix($model) . $id;

                    foreach ($fields as $field => $val) {
                        $pipe->rawCommand('JSON.SET', $key, "$.{$field}", json_encode($val));

                        // Normalization update if needed
                        $type   = isset($indexedFields[$field]) ? strtoupper(trim($indexedFields[$field])) : null;
                        $isDate = ($type === 'DATE' || $type === 'DATETIME');

                        if ($type === 'TAG_CASE') {
                            $pipe->rawCommand('JSON.SET', $key, "$._ci_{$field}", json_encode(strtolower((string) $val)));
                        }

                        if ($isDate) {
                            $ts = $val instanceof \DateTimeInterface ? $val->getTimestamp() : Carbon::parse($val)->timestamp;
                            $pipe->rawCommand('JSON.SET', $key, "$._ts_{$field}", json_encode($ts));
                        }
                    }

                    $pipe->rawCommand('JSON.SET', $key, '$._updated_time', json_encode($now));
                }
            });

            return true;
        } catch (\Exception $e) {
            Log::error("RedisOM massUpdate Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Mass delete via pipeline.
     */
    public function massDelete(string $model, array $ids): bool
    {
        try {
            $this->redis()->pipeline(function ($pipe) use ($model, $ids) {
                foreach ($ids as $id) {
                    $key = app(IndexManager::class)->resolveKeyPrefix($model) . $id;
                    $pipe->del($key);
                }
            });

            return true;
        } catch (\Exception $e) {
            Log::error("RedisOM massDelete Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Transaction via closure.
     */
    public function transaction(callable $callback): mixed
    {
        return $this->redis()->transaction($callback);
    }

    /**
     * Begin Redis transaction (MULTI).
     */
    public function beginTransaction(): void
    {
        $this->redis()->multi();
    }

    /**
     * Commit Redis transaction (EXEC).
     */
    public function commit(): array|bool
    {
        return $this->redis()->exec();
    }

    /**
     * Rollback Redis transaction (DISCARD).
     */
    public function rollBack(): void
    {
        $this->redis()->discard();
    }
}
