<?php

namespace App\Libs\MCP;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Response;
use App\Libs\Traits\HttpRequestTrait;

/**
 * MCP HTTP 客户端类
 *
 * 实现与 MCP 服务器的 JSON-RPC 通信（Streamable HTTP 传输）
 * 基于 MCP 2025-11-25 规范
 */
class McpHttpClient implements McpClientInterface
{
    use HttpRequestTrait;

    private string $name;
    private string $url;
    private array $headers;
    private ?string $sessionId = null;
    private int $requestId = 1;
    private bool $initialized = false;
    private array $capabilities = [];
    private HttpClient $httpClient;
    private int $timeout;

    /**
     * 构造函数
     *
     * @param string $name 客户端名称
     * @param string $url MCP 服务器 URL
     * @param array $headers 自定义 HTTP 头
     * @param HttpClient|null $httpClient HTTP 客户端（可选）
     * @param int $timeout 超时时间（秒）
     */
    public function __construct(
        string $name,
        string $url,
        array $headers = [],
        ?HttpClient $httpClient = null,
        int $timeout = 60
    ) {
        $this->name = $name;
        $this->url = rtrim($url, '/');
        $this->headers = $headers;
        $this->httpClient = $httpClient ?? \Amp\Http\Client\HttpClientBuilder::buildDefault();
        $this->timeout = $timeout;
    }

    public function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        try {
            $this->performHandshake();
            $this->initialized = true;
        } catch (\Throwable $e) {
            throw new \Exception("MCP HTTP 客户端初始化失败 ({$this->name}): " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 执行初始化握手
     */
    private function performHandshake(): void
    {
        // 发送 initialize 请求
        $request = $this->createJsonRpcRequest('initialize', [
            'protocolVersion' => '2024-11-05',
            'capabilities' => new \stdClass(),
            'clientInfo' => [
                'name' => 'wind-chat',
                'version' => '1.0.0'
            ]
        ]);

        $response = $this->sendRequest($request, true);

        if (!isset($response['result']['capabilities'])) {
            throw new \Exception("初始化响应格式错误: " . json_encode($response));
        }

        $this->capabilities = $response['result']['capabilities'];

        // 发送 initialized 通知
        $notification = $this->createJsonRpcNotification('notifications/initialized');
        $this->sendNotification($notification);
    }

    public function listTools(): array
    {
        $this->ensureInitialized();

        $request = $this->createJsonRpcRequest('tools/list', new \stdClass());
        $response = $this->sendRequest($request);

        return $response['result']['tools'] ?? [];
    }

    public function callTool(string $name, array $arguments): string
    {
        $this->ensureInitialized();

        $request = $this->createJsonRpcRequest('tools/call', [
            'name' => $name,
            'arguments' => $arguments
        ]);

        $response = $this->sendRequest($request);

        if (isset($response['result']['content']) && is_array($response['result']['content'])) {
            $result = '';
            foreach ($response['result']['content'] as $item) {
                if (isset($item['text'])) {
                    $result .= $item['text'];
                }
            }
            return $result;
        }

        return json_encode($response['result'] ?? [], JSON_UNESCAPED_UNICODE);
    }

    public function listResources(): array
    {
        $this->ensureInitialized();

        $request = $this->createJsonRpcRequest('resources/list', new \stdClass());
        $response = $this->sendRequest($request);

        return $response['result']['resources'] ?? [];
    }

    public function readResource(string $uri): string
    {
        $this->ensureInitialized();

        $request = $this->createJsonRpcRequest('resources/read', [
            'uri' => $uri
        ]);

        $response = $this->sendRequest($request);

        if (isset($response['result']['contents'][0]['text'])) {
            return $response['result']['contents'][0]['text'];
        }

        return json_encode($response['result'] ?? [], JSON_UNESCAPED_UNICODE);
    }

    public function listPrompts(): array
    {
        $this->ensureInitialized();

        $request = $this->createJsonRpcRequest('prompts/list', new \stdClass());
        $response = $this->sendRequest($request);

        return $response['result']['prompts'] ?? [];
    }

    public function getPrompt(string $name, array $arguments = []): string
    {
        $this->ensureInitialized();

        $request = $this->createJsonRpcRequest('prompts/get', [
            'name' => $name,
            'arguments' => $arguments
        ]);

        $response = $this->sendRequest($request);

        if (isset($response['result']['messages'])) {
            $result = '';
            foreach ($response['result']['messages'] as $message) {
                if (isset($message['content']['text'])) {
                    $result .= $message['content']['text'] . "\n";
                }
            }
            return rtrim($result);
        }

        return json_encode($response['result'] ?? [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 创建 JSON-RPC 请求
     *
     * @param string $method 方法名
     * @param mixed $params 参数
     * @return array JSON-RPC 请求
     */
    private function createJsonRpcRequest(string $method, $params): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $this->requestId++,
            'method' => $method,
            'params' => $params
        ];
    }

    /**
     * 创建 JSON-RPC 通知
     *
     * @param string $method 方法名
     * @param mixed $params 参数
     * @return array JSON-RPC 通知
     */
    private function createJsonRpcNotification(string $method, $params = null): array
    {
        $notification = [
            'jsonrpc' => '2.0',
            'method' => $method
        ];

        if ($params !== null) {
            $notification['params'] = $params;
        } else {
            $notification['params'] = new \stdClass();
        }

        return $notification;
    }

    /**
     * 发送 JSON-RPC 请求
     *
     * @param array $jsonRpcRequest JSON-RPC 请求
     * @param bool $saveSessionId 是否保存会话 ID
     * @return array JSON-RPC 响应
     * @throws \Exception
     */
    private function sendRequest(array $jsonRpcRequest, bool $saveSessionId = false): array
    {
        // 构建请求头
        $headers = array_merge($this->headers, [
            'Accept' => 'application/json, text/event-stream',
            'Content-Type' => 'application/json',
            'MCP-Protocol-Version' => '2025-11-25'
        ]);

        // 如果有会话 ID，添加到请求头
        if ($this->sessionId !== null) {
            $headers['MCP-Session-Id'] = $this->sessionId;
        }

        // 创建 HTTP 请求
        $httpRequest = $this->createHttpRequest(
            $this->url,
            'POST',
            $headers,
            json_encode($jsonRpcRequest, JSON_UNESCAPED_UNICODE),
            $this->timeout
        );

        // 发送请求并获取响应
        /** @var Response $response */
        $response = $this->httpClient->request($httpRequest);
        $statusCode = $response->getStatus();
        $body = $this->readResponseBody($response);

        // 检查 HTTP 状态码
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \Exception("HTTP 请求失败: {$statusCode} - {$body}");
        }

        // 保存会话 ID（如果响应中有）
        if ($saveSessionId && $response->hasHeader('MCP-Session-Id')) {
            $this->sessionId = $response->getHeader('MCP-Session-Id');
        }

        // 解析响应（可能是 SSE 或纯 JSON）
        $data = $this->parseResponse($body);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("JSON 解析失败: " . json_last_error_msg() . "\n响应内容: " . substr($body, 0, 500));
        }

        // 检查 JSON-RPC 错误
        if (isset($data['error'])) {
            $errorMessage = $data['error']['message'] ?? 'Unknown error';
            $errorCode = $data['error']['code'] ?? 0;
            throw new \Exception("JSON-RPC 错误 ({$errorCode}): {$errorMessage}");
        }

        return $data;
    }

    /**
     * 解析响应（支持 SSE 和纯 JSON）
     *
     * @param string $body 响应体
     * @return array 解析后的 JSON-RPC 数据
     * @throws \Exception
     */
    private function parseResponse(string $body): array
    {
        // 检查是否是 SSE 格式
        if (strpos($body, 'event:') === 0 || strpos($body, 'data:') !== false) {
            return $this->parseSseResponse($body);
        }

        // 纯 JSON 格式
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("JSON 解析失败: " . json_last_error_msg() . "\n响应内容: " . substr($body, 0, 500));
        }

        return $data;
    }

    /**
     * 解析 SSE 响应
     *
     * @param string $body SSE 响应体
     * @return array JSON-RPC 数据
     * @throws \Exception
     */
    private function parseSseResponse(string $body): array
    {
        $lines = explode("\n", $body);
        $data = null;

        foreach ($lines as $line) {
            $line = trim($line);

            if (strpos($line, 'data:') === 0) {
                $jsonStr = substr($line, 5); // 移除 "data:" 前缀
                $jsonStr = trim($jsonStr);

                if ($jsonStr !== '' && $jsonStr !== '[' && $jsonStr !== ']') {
                    $parsed = json_decode($jsonStr, true);

                    if (json_last_error() === JSON_ERROR_NONE) {
                        $data = $parsed;
                        // 找到第一个有效的 JSON-RPC 响应就返回
                        if (isset($data['jsonrpc']) && isset($data['id'])) {
                            return $data;
                        }
                    }
                }
            }
        }

        if ($data !== null) {
            return $data;
        }

        throw new \Exception("SSE 响应解析失败：未找到有效的 JSON-RPC 数据\n响应内容: " . substr($body, 0, 500));
    }

    /**
     * 发送 JSON-RPC 通知
     *
     * @param array $jsonRpcNotification JSON-RPC 通知
     * @return void
     * @throws \Exception
     */
    private function sendNotification(array $jsonRpcNotification): void
    {
        // 构建请求头
        $headers = array_merge($this->headers, [
            'Accept' => 'application/json, text/event-stream',
            'Content-Type' => 'application/json',
            'MCP-Protocol-Version' => '2025-11-25'
        ]);

        // 如果有会话 ID，添加到请求头
        if ($this->sessionId !== null) {
            $headers['MCP-Session-Id'] = $this->sessionId;
        }

        // 创建 HTTP 请求
        $httpRequest = $this->createHttpRequest(
            $this->url,
            'POST',
            $headers,
            json_encode($jsonRpcNotification, JSON_UNESCAPED_UNICODE),
            $this->timeout
        );

        // 发送请求（不等待响应）
        /** @var Response $response */
        $response = $this->httpClient->request($httpRequest);
        $statusCode = $response->getStatus();

        // 通知应该返回 202 Accepted，非 2xx 状态码表示有问题
        if ($statusCode < 200 || $statusCode >= 300) {
            $body = $this->readResponseBody($response);
            error_log("MCP HTTP 通知返回状态码: {$statusCode}, 响应: {$body}");
        }
    }

    private function ensureInitialized(): void
    {
        if (!$this->initialized) {
            throw new \Exception("MCP 客户端未初始化");
        }
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getCapabilities(): array
    {
        return $this->capabilities;
    }

    public function close(): void
    {
        // 如果有会话 ID，发送 DELETE 请求终止会话
        if ($this->sessionId !== null) {
            try {
                $headers = $this->headers;
                $headers['MCP-Session-Id'] = $this->sessionId;

                $httpRequest = $this->createHttpRequest(
                    $this->url,
                    'DELETE',
                    $headers,
                    null,
                    $this->timeout
                );

                /** @var Response $response */
                $response = $this->httpClient->request($httpRequest);

                // 405 Method Not Allowed 表示服务器不允许客户端终止会话，这是正常的
                if ($response->getStatus() !== 405 && $response->getStatus() !== 200 && $response->getStatus() !== 202) {
                    error_log("MCP HTTP 会话终止失败: " . $response->getStatus());
                }
            } catch (\Throwable $e) {
                // 忽略关闭时的错误
                error_log("MCP HTTP 会话终止异常: " . $e->getMessage());
            }
        }

        $this->initialized = false;
    }

    public function __destruct()
    {
        $this->close();
    }
}
