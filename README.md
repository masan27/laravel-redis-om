# Laravel Redis OM

A high-performance Redis Object Mapper (OM) for Laravel, powered by RedisJSON and RediSearch. This library provides an Eloquent-like experience for interacting with Redis, while offloading complex search operations to a high-speed Python backend.

## Features

- **Direct Redis Access**: Performance-critical operations (`find`, `save`, `update`, `delete`) bypass HTTP and interact directly with Redis using `JSON.GET/SET`.
- **Hybrid Architecture**: Uses a Python microservice for advanced `query()` and `paginate()` operations using RediSearch.
- **Transaction Support**: Group multiple operations into atomic `MULTI`/`EXEC` blocks with automatic rollback on exceptions.
- **Scalar Support**: Supports direct storage of strings, numbers, and booleans in Generic Style.
- **Atomic Updates**: Partial updates are performed atomically using RedisJSON paths.
- **Eager Loading**: Supports record relationships (`hasOne`, `hasMany`) and eager loading with `with()`.

## Audit Trail

This library automatically tracks the last update time for every record stored as an array/object (RedisJSON).

- **`update_time`**: Automatically set to the current ISO8601 timestamp whenever a record is created or updated via `save()`, `create()`, or `update()`.

> [!IMPORTANT]
> **Reserved Attribute**: Avoid using `update_time` as a custom attribute name in your models or data payloads. This field is reserved for the system's automatic audit trail and will be overwritten on every write operation.

## Installation

Install the package via Composer:

```bash
composer require masan27/laravel-redis-om
```

## Configuration

Complete the installation and publish the config file:

```bash
php artisan redis-om:install
```

This command will:
1. Publish the `config/redis_om.php` file.
2. Automatically add `REDIS_OM_URL`, `REDIS_OM_CONNECTION`, and `REDIS_OM_TIMEOUT` to your `.env` file if they are missing.

### Manual Configuration (Optional)

If you prefer to do it manually, you can publish the config file using:

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
        // Global relations defined here
    ],
];
```

### Defining Relations in Models

You can also define relations directly in your Model class for a more Eloquent-like experience:

```php
namespace App\Models\Redis;

use Masan27\LaravelRedisOM\RedisOM;

class Transaction extends RedisOM
{
    protected array $relations = [
        'user' => [
            'type'        => 'hasOne',
            'related'     => 'User',
            'foreign_key' => 'id',
            'local_key'   => 'user_id'
        ],
    ];
}
```

> [!TIP]
> **Merge Strategy**: If a relation is defined in both the config and the model, the model's definition will take precedence.

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

use Masan27\LaravelRedisOM\RedisOM;

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
use Masan27\LaravelRedisOM\RedisOM;

// Generic query
$results = RedisOM::query('users')->where('role', 'admin')->get();

// Direct set scalar
RedisOM::set('app:status', 'online', 3600);
```

Detailed usage examples for both interaction styles:

### Model Style (Eloquent-like)
- [**CRUD Operations**](examples/model/crud.md) — Finding, saving, updating, and deleting via Model classes.
- [**Querying & Pagination**](examples/model/query.md) — Advanced search and pagination using Models.

### Generic Style (Pure RedisOM)
- [**CRUD Operations**](examples/generic/crud.md) — Direct key/value and mass operations.
- [**Querying**](examples/generic/query.md) — Flexible search without predefined models.

### Advanced Features (Applicable to both)
- [**Relations**](examples/relations.md) — Data relations and eager loading.
- [**Transactions**](examples/transaction.md) — Atomic operations with MULTI/EXEC.

## Redis Prefixing

To avoid key collisions, it is highly recommended to use a global prefix. Ensure both Laravel and the Python backend are synchronized:

- **Laravel**: Add a prefix in your `config/database.php` Redis options.
- **Python**: Define the `global_key_prefix` in your Python model's `BaseMeta`.

> [!TIP]
> This library's `directGet` and `directSet` methods respect the prefix configured in your Laravel Redis database options (if using PHPRedis).

## License

The MIT License (MIT).
