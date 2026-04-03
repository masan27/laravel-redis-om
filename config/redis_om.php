<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Redis Connection
    |--------------------------------------------------------------------------
    |
    | The Redis connection name to use (from config/database.php).
    |
    */
    'connection' => env('REDIS_OM_CONNECTION', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Index Suffix
    |--------------------------------------------------------------------------
    |
    | Suffix appended to the index name.
    | e.g. User → "users:index"
    |
    */
    'index_suffix' => 'index',

    /*
    |--------------------------------------------------------------------------
    | Model Relations
    |--------------------------------------------------------------------------
    |
    | Define cross-model relations here, or define them directly in your model.
    |
    | Example:
    | 'Transaction' => [
    |     'user' => [
    |         'type'        => 'hasOne',
    |         'related'     => 'User',
    |         'foreign_key' => 'id',
    |         'local_key'   => 'user_id',
    |     ],
    | ],
    |
    */
    'relations' => [
        //
    ],
];
