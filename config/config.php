<?php
//Global Config
return [
    'default_timezone' => 'Asia/Shanghai',
    'debug' => env('APP_DEBUG', false),
    'max_stack_trace' => 50,
    'annotation' => [
        'scan_ns_paths' => [
            'App\\Controller@server' => BASE_DIR.'/app/Controller'
        ]
    ],

	'env' => env('ENVIRONMENT'),

    'domain' => env('DOMAIN'),
	'base_url' => env('BASE_URL'),
	'weibo_oauth_url' => env('WEIBO_OAUTH_URL'),

	'redis_prefix' => env('REDIS_PREFIX', 'ohmystar:'),
	'cache_prefix' => env('CACHE_PREFIX', env('REDIS_PREFIX', 'ohmystar:').'cache:')
];
