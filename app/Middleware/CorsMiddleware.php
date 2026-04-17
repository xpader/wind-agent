<?php

namespace App\Middleware;

use Wind\Web\MiddlewareInterface;
use Wind\Web\RequestInterface;
use Wind\Web\Response;

/**
 * 跨源资源 (CORS) 中间件
 */
class CorsMiddleware implements MiddlewareInterface
{

    private array $options;

    public function __construct()
    {
        $this->options = config('cors');
    }

    /**
     * @inheritDoc
     */
    public function process(RequestInterface $request, callable $handler)
    {
        $origin = $request->getHeaderLine('Origin');

        if (!$origin) {
            return $handler($request);
        }

        if (!in_array($origin, $this->options['allowed_origins']) && !in_array('*', $this->options['allowed_origins'])) {
            return new Response(403, 'Forbidden: CORS policy does not allow access from this origin.');
        }

        if ($this->isPreflightRequest($request)) {
            //method
            $response = (new Response(204))->withHeader('Access-Control-Allow-Methods', '*');

            //headers
            $allowHeaders = $request->getHeaderLine('Access-Control-Request-Headers');
            if ($allowHeaders) {
                $response = $response->withHeader('Access-Control-Allow-Headers', $allowHeaders);
            }

            //max-age
            $response = $response->withHeader('Access-Control-Max-Age', (string) $this->options['max_age']);

        } else {
            $response = $handler($request);
        }

        //credentials
        if ($this->options['allow_credentials']) {
            $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        //origin
        return $response->withHeader('Access-Control-Allow-Origin', $origin);
    }

    public function isPreflightRequest(RequestInterface $request): bool
    {
        return $request->getMethod() === 'OPTIONS' && $request->hasHeader('Access-Control-Request-Method');
    }

}
