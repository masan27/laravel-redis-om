<?php

namespace Masan27\LaravelRedisOM;

use Illuminate\Support\Collection;
use Illuminate\Pagination\Paginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\Cursor;

class RedisOMQueryBuilder
{
    protected RedisModel $service;
    protected string $model;
    protected ?string $modelClass = null;
    protected array $filters = [];
    protected ?int $limit = null;
    protected ?int $offset = null;
    protected ?string $sortBy = null;
    protected bool $sortAsc = true;
    protected ?array $fields = null;
    protected array $with = [];

    public function __construct(RedisModel $service, string $model, ?string $modelClass = null)
    {
        $this->service = $service;
        $this->model = $model;
        $this->modelClass = $modelClass;
    }

    /**
     * Add a where clause to the query.
     */
    public function where(string $field, $operator = null, $value = null): self
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        $this->filters[] = [
            'field' => $field,
            'op' => $operator,
            'value' => $value,
        ];

        return $this;
    }

    /**
     * Add a whereIn clause to the query.
     */
    public function whereIn(string $field, array $values): self
    {
        $this->filters[] = [
            'field' => $field,
            'op' => 'in',
            'value' => $values,
        ];

        return $this;
    }

    /**
     * Add a whereBetween clause to the query.
     */
    public function whereBetween(string $field, array $values): self
    {
        $this->filters[] = [
            'field' => $field,
            'op' => 'between',
            'value' => $values,
        ];

        return $this;
    }

    /**
     * Add a whereContains clause to the query (Full-text search).
     */
    public function whereContains(string $field, string $value): self
    {
        return $this->where($field, 'contains', $value);
    }

    /**
     * Add a whereStartsWith clause to the query (Prefix search).
     */
    public function whereStartsWith(string $field, string $value): self
    {
        return $this->where($field, 'startswith', $value);
    }

    /**
     * Add a whereEndsWith clause to the query (Suffix search).
     * Note: Requires SUFFIX_MATCHING on the Redis index.
     */
    public function whereEndsWith(string $field, string $value): self
    {
        return $this->where($field, 'endswith', $value);
    }

    /**
     * Select specific fields.
     */
    public function select(string ...$fields): self
    {
        $this->fields = $fields;
        return $this;
    }

    /**
     * Offset the results.
     */
    public function offset(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    /**
     * Limit the results.
     */
    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    /**
     * Sort the results.
     */
    public function orderBy(string $field, string $direction = 'asc'): self
    {
        $this->sortBy = $field;
        $this->sortAsc = strtolower($direction) === 'asc';
        return $this;
    }

    /**
     * Eager load relations.
     */
    public function with(string ...$relations): self
    {
        $this->with = array_merge($this->with, $relations);
        return $this;
    }

    /**
     * Filter results based on the existence of a relation.
     */
    public function whereHas(string $relation, \Closure $callback = null): self
    {
        if (str_contains($relation, '.')) {
            [$first, $rest] = explode('.', $relation, 2);
            return $this->whereHas($first, function ($q) use ($rest, $callback) {
                $q->whereHas($rest, $callback);
            });
        }

        $relationDef = $this->service->getRelations($this->model, $this->modelClass)[$relation] ?? null;
        if (!$relationDef) {
            throw new \Exception("Relation '{$relation}' not defined for model '{$this->model}'");
        }

        $relatedBuilder = $this->service->query($relationDef['related']);
        if ($callback) {
            $callback($relatedBuilder);
        }

        $relatedIdField = $relationDef['type'] === 'hasMany' ? $relationDef['foreign_key'] : 'id';
        $relatedIds = $relatedBuilder->select($relatedIdField)
            ->get()
            ->pluck($relatedIdField)
            ->unique()
            ->toArray();

        return $this->whereIn($relationDef['local_key'], $relatedIds);
    }

    /**
     * Filter results based on the non-existence of a relation.
     */
    public function whereDoesntHave(string $relation, \Closure $callback = null): self
    {
        if (str_contains($relation, '.')) {
            [$first, $rest] = explode('.', $relation, 2);
            return $this->whereHas($first, function ($q) use ($rest, $callback) {
                $q->whereDoesntHave($rest, $callback);
            });
        }

        $relationDef = $this->service->getRelations($this->model, $this->modelClass)[$relation] ?? null;
        if (!$relationDef) {
            throw new \Exception("Relation '{$relation}' not defined for model '{$this->model}'");
        }

        $relatedBuilder = $this->service->query($relationDef['related']);
        if ($callback) {
            $callback($relatedBuilder);
        }

        $relatedIdField = $relationDef['type'] === 'hasMany' ? $relationDef['foreign_key'] : 'id';
        $relatedIds = $relatedBuilder->select($relatedIdField)
            ->get()
            ->pluck($relatedIdField)
            ->unique()
            ->toArray();

        $this->filters[] = [
            'field' => $relationDef['local_key'],
            'op' => '!in',
            'value' => $relatedIds,
        ];

        return $this;
    }

    /**
     * Execute the query and get results with extra metadata (total).
     */
    public function runQuery(): array
    {
        $response = $this->service->rawQuery(
            $this->model,
            $this->filters,
            $this->limit,
            $this->offset,
            $this->sortBy,
            $this->sortAsc,
            $this->fields
        );

        $items = collect($response['data'] ?? []);

        if ($this->modelClass) {
            $items = $items->map(function ($attributes) {
                return $this->newModelInstance($attributes);
            });
        }

        if ($items->isNotEmpty() && !empty($this->with)) {
            $this->loadRelations($items);
        }

        return [
            'data' => $items,
            'total' => $response['total'] ?? $items->count(),
        ];
    }

    /**
     * Execute the query and get results.
     */
    public function get(): Collection
    {
        return $this->runQuery()['data'];
    }

    /**
     * Create a new model instance.
     */
    protected function newModelInstance(array $attributes)
    {
        $model = new $this->modelClass();
        
        foreach ($attributes as $key => $value) {
            $model->{$key} = $value;
        }

        return $model;
    }

    /**
     * Load eager relations for the given items.
     */
    protected function loadRelations(Collection $items): void
    {
        $relationDefinitions = $this->service->getRelations($this->model, $this->modelClass);
        $groupedRelations = [];

        // Parse nested relations: ['partner.contacts'] -> ['partner' => ['contacts']]
        foreach ($this->with as $relation) {
            if (str_contains($relation, '.')) {
                [$first, $rest] = explode('.', $relation, 2);
                $groupedRelations[$first][] = $rest;
            } else {
                $groupedRelations[$relation] = $groupedRelations[$relation] ?? [];
            }
        }

        foreach ($groupedRelations as $relationName => $subRelations) {
            $def = $relationDefinitions[$relationName] ?? null;
            if (!$def) continue;

            $localKey = $def['local_key'];
            $foreignKey = $def['foreign_key'];
            $ids = $items->pluck($localKey)->unique()->filter()->toArray();

            $query = $this->service->query($def['related'])
                ->whereIn($def['type'] === 'hasMany' ? $foreignKey : 'id', $ids);

            if (!empty($subRelations)) {
                $query->with(...$subRelations);
            }

            $relatedItems = $query->get();

            foreach ($items as $item) {
                $keyValue = $item instanceof \ArrayAccess || is_array($item) ? ($item[$localKey] ?? null) : ($item->{$localKey} ?? null);
                
                if ($def['type'] === 'hasMany') {
                    $itemsInRelation = $relatedItems->where($foreignKey, $keyValue)->values();
                    if ($item instanceof \ArrayAccess || is_array($item)) {
                        $item[$relationName] = $itemsInRelation;
                    } else {
                        $item->{$relationName} = $itemsInRelation;
                    }
                } else {
                    $itemInRelation = $relatedItems->firstWhere('id', $keyValue);
                    if ($item instanceof \ArrayAccess || is_array($item)) {
                        $item[$relationName] = $itemInRelation;
                    } else {
                        $item->{$relationName} = $itemInRelation;
                    }
                }
            }
        }
    }

    /**
     * Paginate the results with total count (SQL-like).
     */
    public function paginate(int $perPage = 15, int $page = null): LengthAwarePaginator
    {
        $page = $page ?: Paginator::resolveCurrentPage();
        $this->limit($perPage);
        $this->offset(($page - 1) * $perPage);

        $results = $this->runQuery();

        return new LengthAwarePaginator(
            $results['data'],
            $results['total'],
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath()]
        );
    }

    /**
     * Paginate the results without total count (Faster).
     */
    public function simplePaginate(int $perPage = 15, int $page = null): Paginator
    {
        $page = $page ?: Paginator::resolveCurrentPage();
        
        // Fetch one extra to determine if there's a next page
        $this->limit($perPage + 1);
        $this->offset(($page - 1) * $perPage);

        $items = $this->get();
        $hasMore = $items->count() > $perPage;

        if ($hasMore) {
            $items->pop();
        }

        return new Paginator($items, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
        ]);
    }

    /**
     * Cursor paginate the results.
     */
    public function cursorPaginate(int $perPage = 15): CursorPaginator
    {
        $cursor = CursorPaginator::resolveCurrentCursor();
        
        // Handling cursor logic depends on sorting field.
        // For now, this is a simplified version using offset if possible.
        // In real cases, we'd use the last ID from the cursor.
        
        $this->limit($perPage + 1); // Get one extra to see if there's more
        
        $results = $this->get();
        $hasMore = $results->count() > $perPage;
        
        if ($hasMore) {
            $results->pop();
        }

        return new CursorPaginator($results, $perPage, $cursor, [
            'path' => Paginator::resolveCurrentPath(),
        ]);
    }

    /**
     * Get the total count of documents matching the query.
     */
    public function count(): int
    {
        return $this->runQuery()['total'];
    }

    /**
     * Get the first result.
     */
    public function first()
    {
        return $this->limit(1)->get()->first();
    }
}
