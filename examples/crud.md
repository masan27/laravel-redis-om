# CRUD & Direct Redis Operations

This library provides two styles of interaction: **Model Style** (Eloquent-like) and **Generic Style** (Entry-point `RedisOM`).

## 1. Finding Records

### Model Style
```php
use App\Models\Redis\User;

$user = User::find(1); // Returns User instance or null
if ($user) {
    echo $user->name;
}
```

### Generic Style
```php
use Masan27\LaravelRedisOM\RedisOM;

$data = RedisOM::find('1', 'User'); // Returns (object) array or null
```

---

## 2. Creating & Saving

### Create (Static)
```php
$user = User::create([
    'id' => 1,
    'name' => 'Sian',
    'role' => 'admin'
]);
```

### Manual Save (Instance)
```php
$user = new User();
$user->id = 2;
$user->name = 'Budi';
$user->save(); // Direct JSON.SET to Redis
```

---

## 3. Updating

### Full Update (Save)
```php
$user = User::find(1);
$user->name = 'Sian Updated';
$user->save();
```

### Partial Atomic Update
Updates only specific fields without loading the whole object. Best for performance and avoiding race conditions.
```php
User::update('User:1', [
    'role' => 'superadmin',
    'last_login' => now()->toIso8601String()
]);

// Note: update_time is automatically updated on every write.
```

---

## 4. Deleting

### Instance Delete
```php
$user = User::find(1);
$user->delete();
```

### Static Delete (Generic)
```php
User::drop('User:1');
// OR
RedisOM::drop('User:1');
```

---

## 5. Direct Key Operations (Generic)

### Set Scalar (String, Int, Bool)
Stored as plain Redis values (not JSON).
```php
RedisOM::set('app:status', 'online', 3600); // 1 hour TTL
RedisOM::set('counter:login', 42);
RedisOM::set('is_active', true); // Stored as 1
```

### Get Generic
```php
$status = RedisOM::find('app:status');
```

---

## 6. Check Existence
```php
if (User::exists(1)) {
    // ...
}

if (RedisOM::has('app:status')) {
    // ...
}
```
