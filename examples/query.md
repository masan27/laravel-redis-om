# Querying & Pagination

Advanced queries use the `query()` entry point, which communicates with the Python backend for RediSearch capabilities.

## 1. Basic Filtering

```php
use App\Models\Redis\User;

// Simple where
$users = User::query()->where('role', 'admin')->get();

// Multiple where
$users = User::query()
    ->where('is_active', true)
    ->where('age', '>=', 18)
    ->get();

// Where In
$users = User::query()->whereIn('id', [1, 2, 3])->get();

// Where Between
$users = User::query()->whereBetween('price', [100, 500])->get();
```

---

## 2. Advanced Search

### Contains (Full-text)
```php
$products = Product::query()->whereContains('description', 'modern laptop')->get();
```

### Starts With (Prefix)
```php
$users = User::query()->whereStartsWith('username', 'sian')->get();
```

---

## 3. Sorting & Slicing

```php
$users = User::query()
    ->orderBy('created_at', 'desc')
    ->limit(10)
    ->offset(20)
    ->get();
```

---

## 4. Pagination

### Length Aware (With total count)
Best for tables with page numbers.
```php
$paginated = User::query()->where('role', 'user')->paginate(15);

// In Blade/Svelte:
// $paginated->items()
// $paginated->total()
// $paginated->currentPage()
```

### Simple Paginate (No total count)
Best for "Load More" or "Infinite Scroll" (Faster).
```php
$paginated = User::query()->simplePaginate(15);
```

### Cursor Paginate
Best for large datasets with high frequency updates.
```php
$paginated = User::query()->cursorPaginate(15);
```

---

## 5. Generic Query (No Model required)

```php
use Masan27\LaravelRedisOM\RedisOM;

$results = RedisOM::query('transactions')
    ->where('status', 'success')
    ->get();
```
