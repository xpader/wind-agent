<?php

return [
    'default' => [
        'driver' => Wind\Queue\Driver\RedisDriver::class,
        'key' => 'wind:queue',
        'processes' => 2,
        'concurrent' => 16
    ]
];
