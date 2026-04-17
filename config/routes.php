<?php
/**
 * Router Config
 *
 * groups[]
 * - namespace
 * - prefix
 * - middlewares
 * - routes
 *    - name
 *    - middlewares
 *    - handler
 */

use App\Middleware\CorsMiddleware;

return [
    //app group
    [
        'namespace' => 'App\Controller',
        'routes' => [
            'get /' => 'IndexController::index',
            'get /gc-status' => 'WindController::gcStatus',
            'get /gc-recycle' => 'WindController::gcRecycle',
            'get /queue' => 'QueueController::index',
            'get /queue/peek/{status}' => 'QueueController::peek',
            'get /queue/wakeup' => 'QueueController::wakeup',
            'get /queue/drop' => 'QueueController::drop'
        ]
    ],

    //static group
    [
        'namespace' => 'Wind\Web',
        'routes' => [
            'get /static/{filename:.+}' => 'FileServer::sendStatic',
            'get /{filename:favicon\.ico}' => 'FileServer::sendStatic',
            'get /{filename:\.well-known\/.+}' => 'FileServer::sendStatic',
        ]
    ],

    //Cors Preflight
    [
        'namespace' => 'App\Controller',
        'middlewares' => [CorsMiddleware::class],
        'routes' => [
            'options /{any:.*}' => 'WindController::preflight'
        ]
    ],
];
