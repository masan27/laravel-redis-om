<?php

namespace Masan27\LaravelRedisOM;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Carbon\Carbon;

class RedisModel
{
    protected string $baseUrl;
    protected array $relations = [];
    protected string $connection;
    protected int $timeout;

    public function __construct()
    {
        $this->baseUrl = config('redis_om.url', 'http://redis-om:8000');
        $this->relations = config('redis_om.relations', []);
        $this->connection = config('redis_om.connection', 'default');
        $this->timeout = config('redis_om.timeout', 30);
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
            'type' => $type,
            'related' => $relatedModel,
            'foreign_key' => $foreignKey,
            'local_key' => $localKey,
        ];
    }

    /**
     * Get relation definitions for a model.
     * Merges relations from config and the Model class itself.
     */
    public function getRelations(string $model, ?string $modelClass = null): array
    {
        $configRelations = $this->relations[$model] ?? [];
        $classRelations = [];

        if ($modelClass && class_exists($modelClass)) {
            try {
                // We use reflection or just instantiate to get the protected property
                // Since it's protected, we can access it if we're in the same hierarchy, 
                // but RedisModel is not. We'll use a temporary instance or reflection.
                $instance = new $modelClass();
                $reflector = new \ReflectionClass($instance);
                $property = $reflector->getProperty('relations');
                $property->setAccessible(true);
                $classRelations = $property->getValue($instance);
            } catch (\Exception $e) {
                // If it fails (e.g. no property), just ignore
            }
        }

        return array_merge($configRelations, $classRelations);
    }

    /**
     * Raw query model with dynamic filters (Backend API)
     */
    public function rawQuery(
        string $model, 
        array $filters = [], 
        ?int $limit = null, 
        ?int $offset = null,
        ?string $sortBy = null,
        bool $sortAsc = true,
        ?array $fields = null
    ): array {
        try {
            $response = Http::timeout($this->timeout)->post("{$this->baseUrl}/query", [
                'model' => $model,
                'filters' => $filters,
                'limit' => $limit,
                'offset' => $offset,
                'sort_by' => $sortBy,
                'sort_asc' => $sortAsc,
                'fields' => $fields,
            ]);

            if ($response->failed()) {
                Log::error("RedisOM Query Failed: " . $response->body());
                return ['data' => [], 'total' => 0, 'error' => $response->json('detail')];
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::error("RedisOM Connection Error: " . $e->getMessage());
            return ['data' => [], 'total' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * Health check of the Redis Service
     * 
     * @return bool
     */
    public function health(): bool
    {
        try {
            $response = Http::timeout(2)->get("{$this->baseUrl}/health");
            return $response->successful() && $response->json('status') === 'ok';
        } catch (\Exception $e) {
            return false;
        }
    }

    // ─────────────────────────────────────────────
    // Direct Redis Methods (Bypass HTTP, no gap)
    // ─────────────────────────────────────────────

    /**
     * Get the Redis connection to use.
     */
    protected function redis()
    {
        return Redis::connection($this->connection);
    }

    /**
     * GET value directly from Redis.
     * Mencoba JSON.GET terlebih dahulu (untuk RedisJSON),
     * fallback ke plain GET untuk scalar values.
     * 
     * @param string $key  Full key tanpa prefix (e.g. 'Model:id')
     * @return mixed       array|scalar|null
     */
    public function directGet(string $key): mixed
    {
        // 1. Coba JSON.GET (untuk data array/object)
        try {
            $raw = $this->redis()->command('JSON.GET', [$key]);
            if ($raw !== null && $raw !== false) {
                return json_decode($raw, true);
            }
        } catch (\Exception $e) {
            // Bukan RedisJSON key, lanjut ke plain GET
        }

        // 2. Fallback: plain GET (untuk scalar: int, bool, string)
        try {
            $plain = $this->redis()->get($key);
            if ($plain === null) {
                return null;
            }
            // Coba decode JSON (misal: "42", "true"), jika gagal kembalikan as-is
            $decoded = json_decode($plain, true);
            return $decoded ?? $plain;
        } catch (\Exception $e) {
            Log::warning("RedisOM directGet error for key '{$key}': " . $e->getMessage());
            return null;
        }
    }

    /**
     * SET value directly to Redis.
     * - Array/object: JSON.SET + auto-inject update_time
     * - Scalar (int, float, bool, string): plain Redis SET
     * 
     * @param string $key   Full key tanpa prefix (e.g. 'Model:id')
     * @param mixed  $value Data (array atau scalar)
     * @param int|null $ttl TTL dalam detik
     * @return bool
     */
    public function directSet(string $key, mixed $value, ?int $ttl = null): bool
    {
        try {
            if (is_array($value)) {
                // Array/object → RedisJSON
                // Pastikan update_time di atas (audit trail), tapi tetap yang terbaru
                unset($value['update_time']);
                $payload = [
                    'update_time' => Carbon::now()->toIso8601String(),
                    ...$value,
                ];
                $this->redis()->command('JSON.SET', [$key, '$', json_encode($payload)]);
            } else {
                // Scalar (int, float, bool, string) → plain Redis SET
                // bool: simpan sebagai 1/0 supaya portable
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
     * RedisJSON atomic per field, RediSearch auto re-index.
     * 
     * @param string $key    Full key tanpa prefix (e.g. 'Model:id')
     * @param array  $fields ['field' => value]
     * @param int|null $ttl  TTL dalam detik
     * @return bool
     */
    public function directUpdate(string $key, array $fields, ?int $ttl = null): bool
    {
        try {
            foreach ($fields as $field => $val) {
                $this->redis()->command('JSON.SET', [$key, "\$.{$field}", json_encode($val)]);
            }

            // Selalu update update_time
            $this->redis()->command('JSON.SET', [$key, '$.update_time', json_encode(
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
     * 
     * @param string $key Full key tanpa prefix (e.g. 'Model:id')
     * @return bool
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
     * Perform a mass update on multiple keys using a Redis pipeline.
     * 
     * @param string $model
     * @param array $ids
     * @param array $fields
     * @return bool
     */
    public function massUpdate(string $model, array $ids, array $fields): bool
    {
        try {
            $this->redis()->pipeline(function ($pipe) use ($model, $ids, $fields) {
                $now = Carbon::now()->toIso8601String();
                
                foreach ($ids as $id) {
                    $key = "{$model}:{$id}";
                    
                    // Update each field
                    foreach ($fields as $field => $val) {
                        $pipe->command('JSON.SET', [$key, "$.{$field}", json_encode($val)]);
                    }
                    
                    // Always update update_time
                    $pipe->command('JSON.SET', [$key, '$.update_time', json_encode($now)]);
                }
            });

            return true;
        } catch (\Exception $e) {
            Log::error("RedisOM massUpdate Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Perform a mass delete on multiple keys using a Redis pipeline.
     * 
     * @param string $model
     * @param array $ids
     * @return bool
     */
    public function massDelete(string $model, array $ids): bool
    {
        try {
            $this->redis()->pipeline(function ($pipe) use ($model, $ids) {
                foreach ($ids as $id) {
                    $pipe->del("{$model}:{$id}");
                }
            });

            return true;
        } catch (\Exception $e) {
            Log::error("RedisOM massDelete Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * CHECK if key exists directly in Redis.
     * 
     * @param string $key Full key tanpa prefix (e.g. 'Model:id')
     * @return bool
     */
    public function directExists(string $key): bool
    {
        try {
            return (bool) $this->redis()->exists($key);
        } catch (\Exception $e) {
            return false;
        }
    }
}
