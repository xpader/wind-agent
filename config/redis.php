<?php
return [
    'default' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'port' => env('REDIS_PORT', 6379),
        'auth' => false, //密码
        'db' => 0,
        'connect_timeout' => 5
    ]
];