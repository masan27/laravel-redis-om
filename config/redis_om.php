<?php

return [
    /*
    |--------------------------------------------------------------------------
    | RedisOM Backend URL
    |--------------------------------------------------------------------------
    |
    | The URL of your Python RedisOM microservice.
    |
    */
    'url'        => env('REDIS_OM_URL', 'http://redis-om:8000'),
    'connection' => env('REDIS_OM_CONNECTION', 'default'),
    'timeout'    => env('REDIS_OM_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Model Relations
    |--------------------------------------------------------------------------
    |
    | Define your cross-model relations here.
    | Example:
    | 'Transaction' => [
    |     'user' => ['type' => 'hasOne', 'related' => 'User', 'foreign_key' => 'id', 'local_key' => 'user_id'],
    | ],
    |
    */
    'relations' => [
        //
    ],
];
