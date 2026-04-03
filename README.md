# Laravel Redis OM

A high-performance **Pure PHP** Redis Object Mapper (OM) for Laravel, powered by RedisJSON and RediSearch. This library provides an Eloquent-like experience with zero external dependencies other than Redis itself.

## Features

- **Direct Redis Access**: Performance-critical operations (`find`, `save`, `update`, `delete`) interact directly with Redis using `JSON.GET/SET`.
- **Search & Pagination**: Full support for RediSearch filtering, sorting, and various pagination styles.
- **Transaction Support**: Group multiple operations into atomic `MULTI`/`EXEC` blocks.
- **Atomic Updates**: Partial updates are performed atomically using RedisJSON paths.
- **Eager Loading**: Supports record relationships (`hasOne`, `hasMany`) and eager loading with `with()`.

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

This command will publish the `config/redis_om.php` file and add necessary environment variables to your `.env`.

```php
return [
    'connection'   => env('REDIS_OM_CONNECTION', 'default'),
    'model_path'   => app_path('Models/Redis'),
    'index_suffix' => 'index',
];
```

## Creating Models

You can quickly generate a new Redis OM model class using the following artisan command:

```bash
php artisan redis-om:model {name}
```

**Example:**
```bash
php artisan redis-om:model User
```

This will create `app/Models/Redis/User.php`. You can also use subdirectories: `php artisan redis-om:model Products/Electronic`.

## Migrating Indexes

Since this is a Schema-based OM, you must create RediSearch indexes before querying. Run the following command whenever you add or update the `$index` property in your models:

```bash
php artisan redis-om:migrate
```

Use `--force` to drop and recreate existing indexes.

## Basic Usage

### Defining a Model

```php
namespace App\Models\Redis;

use Masan27\LaravelRedisOM\RedisOM;

class User extends RedisOM 
{
    protected array $index = [
        'name'   => 'TEXT',
        'email'  => 'TAG',
        'age'    => 'NUMERIC',
        'status' => 'TAG',
    ];
}
```

### CRUD Operations

```php
// Create
$user = User::create(['id' => 1, 'name' => 'Sian', 'status' => 'active']);

// Find
$user = User::find(1);

// Update
$user->status = 'inactive';
$user->save();

// Delete
$user->delete();
```

### Querying

```php
// Fluent Query Building
$users = User::query()
    ->where('status', 'active')
    ->where('age', '>=', 18)
    ->whereStartsWith('name', 'Sia')
    ->orderBy('age', 'desc')
    ->get();

// Pagination
$paginated = User::query()->paginate(15);
```

## Documentation & Examples

Detailed usage examples:

- [**CRUD Operations**](examples/model/crud.md) — Finding, saving, updating, and deleting.
- [**Querying & Pagination**](examples/model/query.md) — Filtering, sorting, and pagination styles.
- [**Relations**](examples/relations.md) — Defining and using relationships.
- [**Transactions**](examples/transaction.md) — Atomic operations with MULTI/EXEC.

## License

Custom Fork-Only License. Please see the [LICENSE](LICENSE) file for more information.
