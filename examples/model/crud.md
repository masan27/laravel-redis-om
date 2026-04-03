# CRUD & Direct Redis Operations (Model Style)

This guide covers basic operations using **Model Style** (Eloquent-like) interaction. All examples assume you have a `User` model extending `RedisOM`.

## 1. Finding Records

```php
use App\Models\Redis\User;

$user = User::find(1); // Returns User instance or null
if ($user) {
    echo $user->name;
}
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

### Create Many (Mass Insert)
Insert multiple records in a single high-performance operation using Redis pipelines.

```php
User::insert([
    ['id' => 10, 'name' => 'Alice', 'role' => 'user'],
    ['id' => 11, 'name' => 'Bob', 'role' => 'user'],
    ['id' => 12, 'name' => 'Charlie', 'role' => 'admin'],
]);

// Supports optional TTL (seconds)
User::insert($manyUsers, 3600);
```

---

## 3. Updating

### Full Update (Save)
```php
$user = User::find(1);
$user->name = 'Sian Updated';
$user->save();
```

### Mass Update (Multi-records)
Update multiple records matching a query criteria.
```php
User::where('role', 'admin')->update(['role' => 'superadmin']);
```

---

## 4. Deleting

### Instance Delete
```php
$user = User::find(1);
$user->delete();
```

### Mass Delete (Multi-records)
Delete multiple records matching a query criteria.
```php
User::where('status', 'banned')->delete();
```

---

## 5. Check Existence
```php
if (User::exists(1)) {
    // ...
}
```
