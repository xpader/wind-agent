<?php

use function DI\{autowire, create, factory};

return [
	\Wind\View\ViewInterface::class => create(\Wind\View\Twig::class),
    \Psr\SimpleCache\CacheInterface::class => autowire(\Wind\Cache\RedisCache::class),

    // 全局 HttpClient
	\Amp\Http\Client\HttpClient::class => factory(fn () => (new \Amp\Http\Client\HttpClientBuilder())->retry(0)->build()),
];
