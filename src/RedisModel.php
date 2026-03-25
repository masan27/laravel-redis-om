<?php

namespace Sian\LaravelRedisOM;

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
     * Get relation definitions for a model
     */
    public function getRelations(string $model): array
    {
        return $this->relations[$model] ?? [];
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
     * Get value by full Redis key
     * 
     * @param string $key
     * @return array|null
     * @deprecated Use directGet() for better performance (Direct Redis access)
     */
    public function getByKey(string $key): ?array
    {
        $response = Http::timeout($this->timeout)->get("{$this->baseUrl}/key/{$key}");

        if ($response->failed()) {
            return null;
        }

        return $response->json();
    }

    /**
     * Set / Upsert full value by key
     * 
     * @param string $key
     * @param mixed $value
     * @param int|null $ttl
     * @return bool
     * @deprecated Use directSet() for better performance (Direct Redis access)
     */
    public function setKey(string $key, $value, ?int $ttl = null): bool
    {
        try {
            $response = Http::timeout($this->timeout)->put("{$this->baseUrl}/key/{$key}", [
                'value' => $value,
                'ttl' => $ttl,
            ]);

            if ($response->failed()) {
                Log::error("RedisOM setKey Failed: " . $response->body());
                return false;
            }

            return true;
        } catch (\Exception $e) {
            Log::error("RedisOM setKey Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Partial update fields by key
     * 
     * @param string $key
     * @param array $fields ['field_name' => 'new_value']
     * @param int|null $ttl
     * @return bool
     * @deprecated Use directUpdate() for better performance (Direct Redis access)
     */
    public function updateFields(string $key, array $fields, ?int $ttl = null): bool
    {
        try {
            $response = Http::timeout($this->timeout)->put("{$this->baseUrl}/key/{$key}", [
                'fields' => $fields,
                'ttl' => $ttl,
            ]);

            if ($response->failed()) {
                Log::error("RedisOM updateFields Failed: " . $response->body());
                return false;
            }

            return true;
        } catch (\Exception $e) {
            Log::error("RedisOM updateFields Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete key from Redis
     * 
     * @param string $key
     * @return bool
     * @deprecated Use directDelete() for better performance (Direct Redis access)
     */
    public function deleteKey(string $key): bool
    {
        try {
            $response = Http::timeout($this->timeout)->delete("{$this->baseUrl}/key/{$key}");

            if ($response->failed()) {
                Log::error("RedisOM deleteKey Failed: " . $response->body());
                return false;
            }

            return true;
        } catch (\Exception $e) {
            Log::error("RedisOM deleteKey Error: " . $e->getMessage());
            return false;
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
