# CRUD Operations (Generic Style)

The **Generic Style** is useful for one-off operations or when you don't want to create a full model class. All interactions are handled via the `RedisOM` entry-point.

## 1. Finding Records

```php
use Masan27\LaravelRedisOM\RedisOM;

// Returns (object) array or null
$user = RedisOM::find('1', 'User'); 

if ($user) {
    echo $user->name;
}

// Find a simple scalar value (string, int, bool)
$status = RedisOM::find('app:status');
```

---

## 2. Setting & Creating

### Create Single (Object)
```php
RedisOM::set('User:1', [
    'name' => 'Sian',
    'role' => 'admin'
]);
```

### Create Many (Mass Insert)
```php
RedisOM::query('User')->insert([
    ['id' => 20, 'name' => 'John'],
    ['id' => 21, 'name' => 'Jane'],
]);

// With optional TTL (1 hour)
RedisOM::query('User')->insert($records, 3600);
```

### Set Scalar (Plain Redis)
```php
RedisOM::set('app:status', 'online', 3600); // with TTL
RedisOM::set('counter:login', 42);
RedisOM::set('is_active', true); // Stored as 1
```

---

## 3. Updating

### Partial Update
```php
RedisOM::update('User:1', [
    'role' => 'superadmin',
    'last_login' => now()->toIso8601String()
]);
```

### Mass Update
```php
RedisOM::query('User')
    ->where('role', 'user')
    ->update(['status' => 'active']);
```

---

## 4. Deleting

### Delete Key
```php
RedisOM::drop('User:1');
```

### Mass Delete
```php
RedisOM::query('User')
    ->where('expired', true)
    ->delete();
```

---

## 5. Check Existence
```php
if (RedisOM::has('User:1')) {
    // ...
}
```
