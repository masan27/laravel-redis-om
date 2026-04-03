<?php

namespace Masan27\LaravelRedisOM;

use Illuminate\Support\Str;
use Carbon\Carbon;
use JsonSerializable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;

abstract class RedisOM implements Arrayable, Jsonable, JsonSerializable
{
    /**
     * The model's attributes.
     * 
     * @var array
     */
    protected array $attributes = [];

    /**
     * The model's original attributes.
     * 
     * @var array
     */
    protected array $original = [];

    /**
     * The attributes that are mass assignable.
     * 
     * @var array
     */
    protected array $fillable = [];

    protected array $guarded = [];

    /**
     * The attributes that should be cast.
     * 
     * @var array
     */
    protected array $casts = [];

    /**
     * The model's relations.
     * 
     * @var array
     */
    protected array $relations = [];

    /**
     * Fields to index in RediSearch.
     * Only fields defined here can be used in where() queries.
     * 
     * Supported types: TEXT, TEXT SORTABLE, TAG, TAG SORTABLE, TAG_CASE, DATE, DATETIME, NUMERIC, GEO
     *
     * @var array
     */
    protected array $index = [];

    /**
     * Override Redis key prefix (e.g., 'my_users:').
     * Default: auto-generated from class name (e.g., 'users:').
     * Index name will also be derived from this (e.g., 'my_users:index').
     *
     * @var string|null
     */
    protected ?string $keyPrefix = null;

    /**
     * Get the model name (e.g., 'User').
     */
    public static function getModelName(): string
    {
        return class_basename(static::class);
    }

    /**
     * Get the custom key prefix if defined, or null to use auto-generated.
     */
    public static function getKeyPrefix(): ?string
    {
        $instance = new static();
        return $instance->keyPrefix;
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
     * Find a record by ID directly from Redis (JSON.GET).
     */
    public static function find($id, ?string $modelName = null)
    {
        $modelNameExplicit = $modelName ?: static::getModelName();
        $indexManager = app(IndexManager::class);
        $key = $indexManager->resolveKeyPrefix($modelNameExplicit) . $id;

        /** @var RedisModel $service */
        $service = app(RedisModel::class);

        // 1. Direct Redis — fastest (JSON.GET → fallback plain GET)
        $data = $service->directGet($key);
        if ($data !== null) {
            if (!is_array($data)) {
                // Scalar value (int, bool, string, float)
                return $data;
            }

            // Audit Trail and internal fields are not needed in PHP
            foreach ($data as $key => $val) {
                if ($key === 'updated_time' || $key === 'update_time' || str_starts_with($key, '_')) {
                    unset($data[$key]);
                }
            }

            $currentClass = static::class;
            $isBase = $currentClass === self::class || $currentClass === 'Masan27\LaravelRedisOM\RedisOM';

            if (!$isBase) {
                // Model Style: Create instance and bypass fillable protection for database load
                $instance = new static();
                $instance->attributes = $data;
                return $instance->syncOriginal();
            }

            // Generic Style: Return stdClass object
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
        $key   = app(IndexManager::class)->resolveKeyPrefix($model) . $id;
        return app(RedisModel::class)->directExists($key);
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
        $this->syncOriginal();
    }

    /**
     * Fill the model with attributes.
     */
    public function fill(array $attributes): self
    {
        $completelyGuarded = $this->totallyGuarded();

        foreach ($this->fillableFromArray($attributes) as $key => $value) {
            if ($this->isFillable($key)) {
                $this->setAttribute($key, $value);
            } elseif ($completelyGuarded) {
                // If it's totally guarded, we might want to throw an exception or just skip.
                // Standard Laravel behavior is to skip unless forced.
            }
        }

        return $this;
    }

    /**
     * Determine if the model is totally guarded.
     */
    protected function totallyGuarded(): bool
    {
        return count($this->fillable) === 0 && $this->guarded === ['*'];
    }

    /**
     * Get the fillable attributes of a given array.
     */
    protected function fillableFromArray(array $attributes): array
    {
        if (count($this->fillable) > 0) {
            return array_intersect_key($attributes, array_flip($this->fillable));
        }

        return $attributes;
    }

    /**
     * Determine if the given attribute may be mass assigned.
     */
    public function isFillable(string $key): bool
    {
        if (in_array($key, $this->fillable)) {
            return true;
        }

        if ($this->isGuarded($key)) {
            return false;
        }

        return empty($this->fillable);
    }

    /**
     * Determine if the given key is guarded.
     */
    public function isGuarded(string $key): bool
    {
        if (empty($this->guarded) || $this->guarded === ['*']) {
            return $this->guarded === ['*'];
        }

        return in_array($key, $this->guarded);
    }

    /**
     * Set a given attribute on the model.
     */
    public function setAttribute(string $key, $value): self
    {
        $this->attributes[$key] = $value;
        return $this;
    }

    /**
     * Get a given attribute from the model.
     */
    public function getAttribute(string $key)
    {
        if (!$key) {
            return null;
        }

        $value = $this->attributes[$key] ?? null;

        // Apply casting
        return $this->castAttribute($key, $value);
    }

    /**
     * Cast an attribute to a native PHP type.
     */
    protected function castAttribute(string $key, $value)
    {
        if ($value === null) {
            return null;
        }

        $type = $this->casts[$key] ?? null;

        if ($type === 'date' || $type === 'datetime') {
            return Carbon::parse($value);
        }

        return $value;
    }

    /**
     * Sync the original attributes with the current.
     */
    public function syncOriginal(): self
    {
        $this->original = $this->attributes;
        return $this;
    }

    /**
     * Dynamically retrieve attributes on the model.
     */
    public function __get($key)
    {
        return $this->getAttribute($key);
    }

    /**
     * Dynamically set attributes on the model.
     */
    public function __set($key, $value)
    {
        $this->setAttribute($key, $value);
    }

    /**
     * Determine if an attribute exists on the model.
     */
    public function __isset($key)
    {
        return isset($this->attributes[$key]);
    }

    /**
     * Unset an attribute on the model.
     */
    public function __unset($key)
    {
        unset($this->attributes[$key]);
    }

    /**
     * Get the full Redis key (Model:id).
     */
    public function getFullKey(): string
    {
        $idField = app(IndexManager::class)->getPrimaryKeyField(static::class);
        $id      = $idField ? $this->getAttribute($idField) : ($this->getAttribute('pk') ?? $this->getAttribute('id'));

        if (!$id) {
            throw new \Exception("Cannot generate Redis key: Model has no ID (" . ($idField ?: "pk/id") . ")");
        }

        return app(IndexManager::class)->resolveKeyPrefix(static::class) . $id;
    }

    /**
     * Save to Redis using JSON.SET.
     */
    public function save(): bool
    {
        /** @var RedisModel $service */
        $service = app(RedisModel::class);
        $idManager = app(IndexManager::class);

        // Auto-generate ID if missing
        $idField = $idManager->getPrimaryKeyField(static::class);
        $id      = $idField ? $this->getAttribute($idField) : ($this->getAttribute('pk') ?? $this->getAttribute('id'));

        if (!$id) {
            $targetField = $idField ?: 'id';
            $id = (string) Str::uuid();
            $this->setAttribute($targetField, $id);
        }

        // Audit Trail (Redis-side only): _updated_time
        $attributes = $this->attributes;
        $attributes['_updated_time'] = Carbon::now()->toIso8601String();

        // Automatic Normalization for TAG_CASE and DATE/DATETIME
        foreach ($this->index as $field => $type) {
            $type   = strtoupper(trim($type));
            $isDate = ($type === 'DATE' || $type === 'DATETIME');

            if ($type === 'TAG_CASE' && isset($attributes[$field])) {
                $attributes["_ci_{$field}"] = strtolower((string) $attributes[$field]);
            }

            if ($isDate && isset($attributes[$field])) {
                $val = $attributes[$field];
                if ($val instanceof \DateTimeInterface) {
                    $attributes["_ts_{$field}"] = $val->getTimestamp();
                } else {
                    try {
                        $attributes["_ts_{$field}"] = Carbon::parse($val)->timestamp;
                    } catch (\Exception $e) {
                        // skip if invalid date
                    }
                }
            }
        }

        $success = $service->directSet($this->getFullKey(), $attributes);

        if ($success) {
            $this->syncOriginal();
        }

        return $success;
    }

    /**
     * Delete from Redis using DEL.
     */
    public function delete(): bool
    {
        return app(RedisModel::class)->directDelete($this->getFullKey());
    }

    /**
     * Update the model with attributes and save.
     */
    public function updateModel(array $attributes = []): bool
    {
        $idField = app(IndexManager::class)->getPrimaryKeyField(static::class);
        $id      = $idField ? $this->getAttribute($idField) : ($this->getAttribute('id') ?? $this->getAttribute('pk'));

        if (!$id || !$this->exists($id)) {
            return false;
        }

        return $this->fill($attributes)->save();
    }

    /**
     * Convert the model instance to an array.
     */
    public function toArray(): array
    {
        return $this->attributes;
    }

    /**
     * Convert the model instance to JSON.
     */
    public function toJson($options = 0): string
    {
        return json_encode($this->jsonSerialize(), $options);
    }

    /**
     * Convert the object into something JSON serializable.
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
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
     * Generic set key via JSON.SET (array) or Redis SET (scalar).
     */
    public static function set(string $key, mixed $data, ?int $ttl = null): bool
    {
        return app(RedisModel::class)->directSet($key, $data, $ttl);
    }

    /**
     * Generic partial update via JSON.SET per path.
     * Atomic per field. RediSearch auto re-index.
     */
    public static function update(string $key, array $fields, ?int $ttl = null): bool
    {
        return app(RedisModel::class)->directUpdate($key, $fields, $ttl);
    }

    /**
     * Generic delete key via DEL.
     */
    public static function drop(string $key): bool
    {
        return app(RedisModel::class)->directDelete($key);
    }

    /**
     * Generic check key existence via EXISTS.
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
