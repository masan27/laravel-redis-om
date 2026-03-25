# Laravel Redis OM

A high-performance Redis Object Mapper (OM) for Laravel, powered by RedisJSON and RediSearch. This library provides an Eloquent-like experience for interacting with Redis, while offloading complex search operations to a high-speed Python backend.

## Features

- **Direct Redis Access**: Performance-critical operations (`find`, `save`, `update`, `delete`) bypass HTTP and interact directly with Redis using `JSON.GET/SET`.
- **Hybrid Architecture**: Uses a Python microservice for advanced `query()` and `paginate()` operations using RediSearch.
- **Scalar Support**: Supports direct storage of strings, numbers, and booleans in Generic Style.
- **Atomic Updates**: Partial updates are performed atomically using RedisJSON paths.
- **Eager Loading**: Supports Eloquent-style relationships (`hasOne`, `hasMany`) and eager loading with `with()`.

## Audit Trail

This library automatically tracks the last update time for every record stored as an array/object (RedisJSON).

- **`update_time`**: Automatically set to the current ISO8601 timestamp whenever a record is created or updated via `save()`, `create()`, or `update()`.

> [!IMPORTANT]
> **Reserved Attribute**: Avoid using `update_time` as a custom attribute name in your models or data payloads. This field is reserved for the system's automatic audit trail and will be overwritten on every write operation.

## Installation

Add the repository to your `composer.json`:

```json
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/masan27/laravel-redis-om"
    }
],
"require": {
    "masan27/laravel-redis-om": "0.1.0"
}
```

Then run:
```bash
composer update masan27/laravel-redis-om
```

## Configuration

Publish the config file:

```bash
php artisan vendor:publish --tag=redis-om-config
```

This will create `config/redis_om.php`:

```php
return [
    'url'        => env('REDIS_OM_URL', 'http://redis-om:8000'),
    'connection' => env('REDIS_OM_CONNECTION', 'default'),
    'timeout'    => env('REDIS_OM_TIMEOUT', 30),
    'relations'  => [
        // Define your cross-model relations here
    ],
];
```

### Redis Configuration

By default, the package uses the `default` Redis connection defined in your `config/database.php`.

If you wish to use a dedicated connection for Redis OM (e.g., to use a different database or prefix), add a new connection to the `redis` array in `config/database.php`:

```php
'redis' => [
    'redis_om' => [
        'url'      => env('REDIS_URL'),
        'host'     => env('REDIS_HOST', '127.0.0.1'),
        'password' => env('REDIS_PASSWORD', null),
        'port'     => env('REDIS_PORT', '6379'),
        'database' => env('REDIS_OM_DB', '1'), // Use a different DB if needed
        'prefix'   => 'om:',                  // Optional custom prefix
    ],
],
```

Then, update your `.env` file:
```env
REDIS_OM_CONNECTION=redis_om
```

## Basic Usage

### Using Models

```php
namespace App\Models\Redis;

use Sian\LaravelRedisOM\RedisOM;

class User extends RedisOM {}

// Find by ID (Direct Redis access)
$user = User::find(1);

// Create / Save
$user = User::create(['id' => 1, 'name' => 'Sian']);
$user->email = 'sian@example.com';
$user->save();
```

### Generic Style (No model class required)

```php
use Sian\LaravelRedisOM\RedisOM;

// Generic query
$results = RedisOM::query('users')->where('role', 'admin')->get();

// Direct set scalar
RedisOM::set('app:status', 'online', 3600);
```

## Detailed Examples

For more in-depth usage, check the following guides:

- [**CRUD Operations**](examples/crud.md) — Finding, saving, updating (atomic), and deleting.
- [**Querying & Pagination**](examples/query.md) — Advanced filtering, search, and pagination styles.
- [**Relations & Eager Loading**](examples/relations.md) — Cross-model relations and `with()` support.

## Redis Prefixing

To avoid key collisions, it is highly recommended to use a global prefix. Ensure both Laravel and the Python backend are synchronized:

- **Laravel**: Add a prefix in your `config/database.php` Redis options.
- **Python**: Define the `global_key_prefix` in your Python model's `BaseMeta`.

> [!TIP]
> This library's `directGet` and `directSet` methods respect the prefix configured in your Laravel Redis database options (if using PHPRedis).

## License

The MIT License (MIT).
