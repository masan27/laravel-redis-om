<?php

namespace Masan27\LaravelRedisOM;

use Illuminate\Support\Str;
use Carbon\Carbon;

abstract class RedisOM
{
    /**
     * The model's relations.
     * 
     * @var array
     */
    protected array $relations = [];

    /**
     * Get the model name (e.g., 'User').
     */
    public static function getModelName(): string
    {
        return class_basename(static::class);
    }

    /**
     * Start a new query.
     * 
     * Supports:
     * 1. User::query() -> Returns objects cast to User
     * 2. RedisOM::query('User') -> Returns generic arrays (Quick style)
     */
    public static function query(?string $modelName = null): RedisOMQueryBuilder
    {
        $currentClass = static::class;
        $isBase = $currentClass === self::class || $currentClass === 'Masan27\LaravelRedisOM\RedisOM';

        if ($modelName || $isBase) {
            // Sebutkan nama model secara eksplisit (Generic Style)
            return app(RedisModel::class)->builder($modelName ?: 'Generic');
        }

        // Dipanggil dari subclass (Model Style)
        return app(RedisModel::class)->builder(static::getModelName(), $currentClass);
    }

    /**
     * Find a record by ID.
     * Langsung dari Redis (JSON.GET) — no HTTP gap.
     */
    public static function find($id, ?string $modelName = null)
    {
        $model = $modelName ?: static::getModelName();
        $key = "{$model}:{$id}";

        /** @var RedisModel $service */
        $service = app(RedisModel::class);

        // 1. Direct Redis — fastest (JSON.GET → fallback plain GET)
        $data = $service->directGet($key);
        if ($data !== null) {
            if (!is_array($data)) {
                // Scalar value (int, bool, string, float)
                return $data;
            }
            $currentClass = static::class;
            if ($currentClass !== self::class && $currentClass !== 'Masan27\LaravelRedisOM\RedisOM') {
                return new static($data);
            }
            unset($data['update_time']);
            return (object) $data;
        }

        return null;
    }

    /**
     * Check if a record exists in Redis.
     */
    public static function exists($id, ?string $modelName = null): bool
    {
        $model = $modelName ?: static::getModelName();
        return app(RedisModel::class)->directExists("{$model}:{$id}");
    }

    /**
     * Get all records.
     */
    public static function all(?string $modelName = null)
    {
        return static::query($modelName)->get();
    }

    /**
     * Create a new model instance.
     */
    public function __construct(array $attributes = [])
    {
        $this->fill($attributes);
    }

    /**
     * Fill the model with attributes.
     */
    public function fill(array $attributes): self
    {
        unset($attributes['update_time']);
        foreach ($attributes as $key => $value) {
            $this->{$key} = $value;
        }
        return $this;
    }

    /**
     * Get the full Redis key (Model:id).
     */
    public function getFullKey(): string
    {
        $model = static::getModelName();
        $id = $this->pk ?? $this->id ?? null;

        if (!$id) {
            throw new \Exception("Cannot generate Redis key: Model has no ID (pk/id)");
        }

        return "{$model}:{$id}";
    }

    /**
     * Save to Redis.
     * Langsung via JSON.SET — no HTTP gap.
     */
    public function save(): bool
    {
        $attributes = get_object_vars($this);
        return app(RedisModel::class)->directSet($this->getFullKey(), $attributes);
    }

    /**
     * Delete from Redis.
     * Langsung via DEL — no HTTP gap.
     */
    public function delete(): bool
    {
        return app(RedisModel::class)->directDelete($this->getFullKey());
    }

    /**
     * Execute a transaction (MULTI/EXEC).
     * 
     * @param callable $callback
     * @return mixed
     */
    public static function transaction(callable $callback): mixed
    {
        return app(RedisModel::class)->transaction($callback);
    }

    /**
     * Start a manual Redis transaction (MULTI).
     */
    public static function beginTransaction(): void
    {
        app(RedisModel::class)->beginTransaction();
    }

    /**
     * Commit a manual Redis transaction (EXEC).
     */
    public static function commit(): array|bool
    {
        return app(RedisModel::class)->commit();
    }

    /**
     * Rollback a manual Redis transaction (DISCARD).
     */
    public static function rollBack(): void
    {
        app(RedisModel::class)->rollBack();
    }

    /**
     * Static create.
     */
    public static function create(array $attributes): self
    {
        $model = new static($attributes);
        $model->save();
        return $model;
    }

    /**
     * Generic set key — langsung via JSON.SET (array) atau Redis SET (scalar), no HTTP gap.
     */
    public static function set(string $key, mixed $data, ?int $ttl = null): bool
    {
        return app(RedisModel::class)->directSet($key, $data, $ttl);
    }

    /**
     * Generic partial update — langsung via JSON.SET per path, no HTTP gap.
     * Atomic per field. RediSearch auto re-index.
     */
    public static function update(string $key, array $fields, ?int $ttl = null): bool
    {
        return app(RedisModel::class)->directUpdate($key, $fields, $ttl);
    }

    /**
     * Generic delete key — langsung via DEL, no HTTP gap.
     */
    public static function drop(string $key): bool
    {
        return app(RedisModel::class)->directDelete($key);
    }

    /**
     * Generic check key existence — langsung via EXISTS, no HTTP gap.
     */
    public static function has(string $key): bool
    {
        return app(RedisModel::class)->directExists($key);
    }

    /**
     * Forward static calls to query builder.
     */
    public static function __callStatic($method, $parameters)
    {
        $currentClass = static::class;
        if ($currentClass === self::class || $currentClass === 'Masan27\LaravelRedisOM\RedisOM') {
            throw new \Exception("Static call '{$method}' must be called from a Model (e.g. User::{$method}) or via RedisOM::query('ModelName')->{$method}(...)");
        }

        return static::query()->$method(...$parameters);
    }
}
