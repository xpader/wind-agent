<?php

namespace App\Libs\Traits;

use Amp\Http\Client\Request;

/**
 * HTTP 请求处理 Trait
 *
 * 提供 HTTP 请求的通用处理逻辑
 */
trait HttpRequestTrait
{
    /**
     * 创建 HTTP 请求对象
     *
     * @param string $url 请求 URL
     * @param string $method HTTP 方法
     * @param array $headers 请求头
     * @param string|null $body 请求体
     * @param int $timeout 超时时间（秒）
     * @return Request
     */
    protected function createHttpRequest(
        string $url,
        string $method = 'GET',
        array $headers = [],
        ?string $body = null,
        int $timeout = 60
    ): Request {
        $request = new Request($url, $method);

        // 设置超时时间
        $request->setTcpConnectTimeout($timeout);
        $request->setTlsHandshakeTimeout($timeout);
        $request->setTransferTimeout($timeout);
        $request->setInactivityTimeout($timeout);

        // 设置请求头
        foreach ($headers as $key => $value) {
            $request->setHeader($key, $value);
        }

        // 设置请求体
        if ($body !== null) {
            $request->setBody($body);
        }

        return $request;
    }

    /**
     * 创建 JSON 请求
     *
     * @param string $url 请求 URL
     * @param array $data 请求数据
     * @param string $method HTTP 方法
     * @param array $additionalHeaders 额外的请求头
     * @param int $timeout 超时时间（秒）
     * @return Request
     */
    protected function createJsonRequest(
        string $url,
        array $data,
        string $method = 'POST',
        array $additionalHeaders = [],
        int $timeout = 60
    ): Request {
        $headers = array_merge([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], $additionalHeaders);

        return $this->createHttpRequest($url, $method, $headers, json_encode($data), $timeout);
    }

    /**
     * 创建带认证的 JSON 请求
     *
     * @param string $url 请求 URL
     * @param string $apiKey API 密钥
     * @param array $data 请求数据
     * @param string $method HTTP 方法
     * @param array $additionalHeaders 额外的请求头
     * @param int $timeout 超时时间（秒）
     * @return Request
     */
    protected function createAuthenticatedJsonRequest(
        string $url,
        string $apiKey,
        array $data,
        string $method = 'POST',
        array $additionalHeaders = [],
        int $timeout = 60
    ): Request {
        $headers = array_merge([
            'Authorization' => 'Bearer ' . $apiKey,
        ], $additionalHeaders);

        return $this->createJsonRequest($url, $data, $method, $headers, $timeout);
    }

    /**
     * 处理 HTTP 响应错误
     *
     * @param int $statusCode HTTP 状态码
     * @param string $errorBody 错误响应体
     * @param string $apiName API 名称
     * @throws \RuntimeException
     * @return void
     */
    protected function handleHttpError(int $statusCode, string $errorBody, string $apiName = 'API'): void
    {
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException(
                "{$apiName} error: {$statusCode} - {$errorBody}"
            );
        }
    }

    /**
     * 读取完整的响应体
     *
     * @param mixed $response 响应对象
     * @return string 响应体内容
     */
    protected function readResponseBody($response): string
    {
        return $response->getBody()->buffer();
    }

    /**
     * 构建基础 URL
     *
     * @param string $host 主机地址
     * @param string $path 路径
     * @param bool $https 是否使用 HTTPS
     * @return string 完整的 URL
     */
    protected function buildUrl(string $host, string $path = '', bool $https = true): string
    {
        $scheme = $https ? 'https' : 'http';
        $host = rtrim($host, '/');
        $path = ltrim($path, '/');
        return "{$scheme}://{$host}/{$path}";
    }

    /**
     * 为请求对象设置统一的超时配置
     *
     * @param Request $request 请求对象
     * @param int $timeout 超时时间（秒）
     * @param int $transferTimeout 传输超时时间（秒），默认120
     * @param int $inactivityTimeout 不活动超时时间（秒），默认60
     * @return void
     */
    protected function setTimeouts(Request $request, int $timeout, int $transferTimeout = 120, int $inactivityTimeout = 60): void
    {
        $request->setTcpConnectTimeout($timeout);
        $request->setTlsHandshakeTimeout($timeout);
        $request->setTransferTimeout($transferTimeout);
        $request->setInactivityTimeout($inactivityTimeout);
    }
}
