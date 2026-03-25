# Redis Transactions (MULTI/EXEC)

`laravel-redis-om` supports Redis transactions via the `MULTI`/`EXEC` commands. This allows you to group multiple operations into a single atomic block.

## Basic Usage

You can use the `transaction` method on the `RedisOM` base class or any of your models.

```php
use Masan27\LaravelRedisOM\RedisOM;
use App\Models\Redis\User;
use App\Models\Redis\Post;

RedisOM::transaction(function() {
    // These operations are queued and executed atomically
    $user = User::create(['id' => 1, 'name' => 'Sian']);
    
    Post::create([
        'id' => 101,
        'user_id' => 1,
        'title' => 'Hello Redis OM'
    ]);
});
```

## Rollback (Discard)

If an exception is thrown inside the transaction closure, Laravel will automatically call `DISCARD`, and none of the queued commands will be executed.

```php
try {
    User::transaction(function() {
        User::create(['id' => 2, 'name' => 'John']);
        
        // Something goes wrong...
        throw new \Exception("Manual Rollback");
        
        // This will never be executed
        User::create(['id' => 3, 'name' => 'Jane']);
    });
} catch (\Exception $e) {
    // Transaction was discarded, no users were created.
}
```

## Manual Transaction Control

If you prefer to control the transaction manually (similar to `DB::beginTransaction()`), you can use the following methods:

```php
use Masan27\LaravelRedisOM\RedisOM;
use App\Models\Redis\User;

try {
    RedisOM::beginTransaction();

    User::create(['id' => 4, 'name' => 'Alice']);
    User::create(['id' => 5, 'name' => 'Bob']);

    // Commit all queued commands
    RedisOM::commit();
} catch (\Exception $e) {
    // Discard all queued commands
    RedisOM::rollBack();
}
```

## Mass Operations in Transactions

You can also include mass updates, deletes, and inserts within a transaction.

```php
RedisOM::transaction(function() {
    // Mass Insert
    User::query()->insert([
        ['id' => 10, 'name' => 'A'],
        ['id' => 11, 'name' => 'B']
    ]);

    // Mass Update
    User::where('role', 'guest')->update(['role' => 'member']);

    // Mass Delete
    User::where('status', 'inactive')->delete();
});
```

> [!IMPORTANT]
> **Redis Transaction Limits**: Unlike relational databases, Redis transactions do not support "rollback" after they have been executed (`EXEC`). If a command fails *during* execution (e.g., a data type mismatch), earlier successful commands in the same `MULTI` block are **not** undone. The `transaction()` method ensures atomicity of queuing and prevents execution if an error occurs *before* the transaction is committed.
