<?php

namespace App\Libs\LLM\Clients;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use App\Libs\LLM\LLMClient;
use App\Libs\LLM\LLMRequest;
use App\Libs\LLM\LLMResponse;
use App\Libs\LLM\TokenUsage;
use App\Libs\Traits\StreamResponseTrait;
use App\Libs\Traits\HttpRequestTrait;

/**
 * OpenAI 兼容客户端（基于 AMPHP HTTP Client v5）
 *
 * 支持标准的 OpenAI API 以及其他兼容接口（如 Azure OpenAI、本地 LLM 等）
 * AMPHP 3 基于 Fiber，所有异步操作都是隐式的
 */
class OpenAiClient implements LLMClient
{
    use StreamResponseTrait, HttpRequestTrait;
    private HttpClient $httpClient;
    private string $apiKey;
    private string $baseUrl;
    private string $organization;
    private array $defaultOptions;
    private int $timeout;

    public function __construct(
        HttpClient $httpClient,
        string $apiKey,
        string $baseUrl = 'https://api.openai.com/v1',
        string $organization = '',
        array $defaultOptions = [],
        int $timeout = 60
    ) {
        $this->httpClient = $httpClient;
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->organization = $organization;
        $this->defaultOptions = $defaultOptions;
        $this->timeout = $timeout;
    }

    /**
     * 创建聊天补全请求
     *
     * @param LLMRequest $request 请求对象
     * @return LLMResponse 返回响应对象
     */
    public function chat(LLMRequest $request): LLMResponse
    {
        $payload = $request->toArray();
        $response = $this->request('POST', '/chat/completions', $payload);
        $data = $this->safeJsonDecode($response);

        return $this->parseChatResponse($data);
    }

    /**
     * 创建聊天补全请求（流式）
     *
     * @param LLMRequest $request 请求对象
     * @param callable $callback 接收流式数据的回调 function(LLMResponse $response)
     * @return void
     */
    public function chatStream(LLMRequest $request, callable $callback): void
    {
        $payload = $request->toArray();
        $payload['stream'] = true;

        $httpRequest = $this->createRequest('POST', '/chat/completions', $payload);
        $response = $this->httpClient->request($httpRequest);

        // 使用 trait 的流式处理方法
        $this->processStreamByChunk(
            $response->getBody(),
            function($line) use ($callback, $request) {
                $line = trim($line);
                if ($line === '' || $line === 'data: [DONE]') {
                    return true;
                }

                if (str_starts_with($line, 'data: ')) {
                    $data = $this->safeJsonDecode(substr($line, 6));
                    if ($data !== null) {
                        $response = $this->parseStreamChunk($data);
                        $callback($response);
                        return !$response->done;
                    }
                }
                return true;
            },
            function($buffer) use ($callback) {
                $line = trim($buffer);
                if ($line !== '' && $line !== 'data: [DONE]' && str_starts_with($line, 'data: ')) {
                    $data = $this->safeJsonDecode(substr($line, 6));
                    if ($data !== null) {
                        $response = $this->parseStreamChunk($data);
                        $callback($response);
                    }
                }
            }
        );

        // 发送完成信号
        $callback(LLMResponse::createChunk('', '', true)->model($request->model));
    }

    /**
     * 获取模型列表
     *
     * @return array
     */
    public function listModels(): array
    {
        $response = $this->request('GET', '/models');
        return $this->safeJsonDecode($response);
    }

    /**
     * 发送 HTTP 请求
     *
     * @param string $method HTTP 方法
     * @param string $path API 路径
     * @param array|null $body 请求体
     * @return string
     */
    private function request(string $method, string $path, ?array $body = null): string
    {
        $request = $this->createRequest($method, $path, $body);
        $response = $this->httpClient->request($request);

        $statusCode = $response->getStatus();
        if ($statusCode < 200 || $statusCode >= 300) {
            $errorBody = $response->getBody()->buffer();
            $this->handleHttpError($statusCode, $errorBody, 'OpenAI API');
        }

        return $response->getBody()->buffer();
    }

    /**
     * 创建 HTTP 请求对象
     *
     * @param string $method
     * @param string $path
     * @param array|null $body
     * @return Request
     */
    private function createRequest(string $method, string $path, ?array $body = null): Request
    {
        $url = $this->baseUrl . $path;
        $request = new Request($url, $method);

        // 使用 trait 的统一超时设置方法
        $this->setTimeouts($request, $this->timeout);

        $request->setHeader('Authorization', 'Bearer ' . $this->apiKey);
        $request->setHeader('Content-Type', 'application/json');

        if ($this->organization) {
            $request->setHeader('OpenAI-Organization', $this->organization);
        }

        if ($body !== null) {
            $request->setBody(json_encode($body));
        }

        return $request;
    }

    /**
     * 更新默认选项
     *
     * @param array $options
     * @return void
     */
    public function setDefaultOptions(array $options): void
    {
        $this->defaultOptions = array_merge($this->defaultOptions, $options);
    }

    /**
     * 获取当前 API 密钥
     *
     * @return string
     */
    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    /**
     * 获取当前基础 URL
     *
     * @return string
     */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * 解析聊天补全响应
     */
    private function parseChatResponse(array $data): LLMResponse
    {
        $response = LLMResponse::create()
            ->content($data['choices'][0]['message']['content'] ?? '')
            ->thinking($data['choices'][0]['message']['thinking'] ?? '')
            ->model($data['model'] ?? '')
            ->done(true)
            ->finishReason($data['choices'][0]['finish_reason'] ?? '')
            ->raw($data);

        // 解析使用情况
        if (isset($data['usage'])) {
            $response->usage(new TokenUsage(
                $data['usage']['prompt_tokens'] ?? 0,
                $data['usage']['completion_tokens'] ?? 0,
                $data['usage']['total_tokens'] ?? 0
            ));
        }

        return $response;
    }

    /**
     * 解析流式响应片段
     */
    private function parseStreamChunk(array $data): LLMResponse
    {
        return LLMResponse::createChunk(
            $data['choices'][0]['delta']['content'] ?? '',
            $data['choices'][0]['delta']['thinking'] ?? '',
            ($data['choices'][0]['finish_reason'] ?? null) !== null
        )->model($data['model'] ?? '');
    }
}
