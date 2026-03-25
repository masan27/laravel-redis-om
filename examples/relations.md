# Relations & Eager Loading

This library supports cross-Redis relationships using `belongsTo` and `hasMany` patterns.

## 1. Defining Relations

Relations are defined in `config/redis_om.php`:

```php
'relations' => [
    'Transaction' => [
        'user' => [
            'type'        => 'hasOne', // Currently mapped logic
            'related'     => 'User', 
            'foreign_key' => 'id', 
            'local_key'   => 'user_id'
        ],
    ],
    'User' => [
        'posts' => [
            'type'        => 'hasMany', 
            'related'     => 'Post', 
            'foreign_key' => 'user_id', 
            'local_key'   => 'id'
        ],
    ]
],
```

---

## 2. Eager Loading (`with`)

Prevents N+1 query problems by loading all related records in one batch after the main query.

```php
use App\Models\Redis\Transaction;

// Single relation
$transactions = Transaction::query()->with('user')->get();

// Multiple relations
$transactions = Transaction::query()->with('user', 'partner')->get();

// Nested relations (dot notation)
$transactions = Transaction::query()->with('user.profile')->get();
```

---

## 3. Relation Filtering

### Where Has
Filter the main model based on properties of the related model.
```php
// Only transactions where user has 'gold' rank
$transactions = Transaction::query()->whereHas('user', function($q) {
    $q->where('rank', 'gold');
})->get();
```

### Where Doesn't Have
```php
// Only transactions without a user (orphan)
$transactions = Transaction::query()->whereDoesntHave('user')->get();
```

---

## 4. Lazy Loading
Currently, recursive lazy loading like `$transaction->user` is not fully implemented via magic methods to keep the package lean. **Eager loading using `with()` is the recommended approach.**
