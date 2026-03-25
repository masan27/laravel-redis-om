# Querying (Generic Style)

The **Generic Style** provides the same powerful query capabilities as Model Style but returns results as generic objects or arrays.

## 1. Basic Filtering

```php
use Masan27\LaravelRedisOM\RedisOM;

$results = RedisOM::query('Product')
    ->where('category', 'electronics')
    ->where('price', '<', 1000)
    ->get();
```

---

## 2. Advanced Search

```php
// Full-text search
$results = RedisOM::query('Article')
    ->whereContains('content', 'laravel redis')
    ->get();

// Prefix search
$results = RedisOM::query('User')
    ->whereStartsWith('username', 'sian')
    ->get();
```

---

## 3. Sorting & Pagination

```php
$results = RedisOM::query('Log')
    ->orderBy('timestamp', 'desc')
    ->paginate(15);
```

---

## 4. Mass Operations

```php
// Mass Update
RedisOM::query('Notification')
    ->where('read', false)
    ->update(['read' => true]);

// Mass Delete
RedisOM::query('Log')
    ->where('level', 'debug')
    ->delete();
```
