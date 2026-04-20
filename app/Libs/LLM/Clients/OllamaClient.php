<?php

namespace App\Libs\LLM\Clients;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use App\Libs\LLM\LLMClient;
use App\Libs\LLM\LLMRequest;
use App\Libs\LLM\LLMResponse;
use App\Libs\LLM\TokenUsage;
use App\Libs\Traits\StreamResponseTrait;
use App\Libs\Traits\HttpRequestTrait;

/**
 * Ollama 客户端（基于 AMPHP HTTP Client）
 *
 * 直接调用 Ollama API，支持 Ollama 特有的功能如 think 参数
 */
class OllamaClient implements LLMClient
{
    use StreamResponseTrait, HttpRequestTrait;
    private HttpClient $httpClient;
    private string $baseUrl;
    private int $timeout;

    public function __construct(
        HttpClient $httpClient,
        string $baseUrl = 'http://localhost:11434',
        int $timeout = 60
    ) {
        $this->httpClient = $httpClient;
        $this->baseUrl = rtrim($baseUrl, '/');
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
        // 注入 Skills 系统提示词
        $messages = $this->injectSkillsPrompt($request);

        $payload = $this->buildPayload($messages, $request);
        $payload['stream'] = false;

        $response = $this->request('POST', '/api/chat', $payload);
        $response = $this->fixUtf8Encoding($response);
        $data = $this->safeJsonDecode($response);

        return $this->parseChatResponse($data, $request->model);
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
        // 注入 Skills 系统提示词
        $messages = $this->injectSkillsPrompt($request);

        $payload = $this->buildPayload($messages, $request);
        $payload['stream'] = true;

        $httpRequest = $this->createRequest('POST', '/api/chat', $payload);
        $response = $this->httpClient->request($httpRequest);

        // 检查 HTTP 状态码
        $statusCode = $response->getStatus();
        if ($statusCode < 200 || $statusCode >= 300) {
            $errorBody = $response->getBody()->buffer();
            $this->handleHttpError($statusCode, $errorBody, 'Ollama API');
        }

        // 使用 trait 的流式处理方法
        $this->processStreamByChunk(
            $response->getBody(),
            function($line) use ($callback, $request) {
                $data = $this->safeJsonDecode($line);
                if ($data !== null) {
                    $response = $this->parseStreamChunk($data, $request->model);
                    $callback($response);
                    return !$response->done;
                }
                return true;
            }
        );
    }

    /**
     * 获取模型列表
     *
     * @return array
     */
    public function listModels(): array
    {
        $response = $this->request('GET', '/api/tags');
        $data = $this->safeJsonDecode($response);
        return $data['models'] ?? [];
    }

    /**
     * 获取当前 API 密钥（Ollama 不需要）
     *
     * @return string
     */
    public function getApiKey(): string
    {
        return '';
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
     * 设置默认选项
     *
     * @param array $options
     * @return void
     */
    public function setDefaultOptions(array $options): void
    {
        // Ollama 不需要存储默认选项，每次调用时通过 LLMRequest 传入
    }

    /**
     * 构建 Ollama API 请求载荷
     *
     * @param array $messages
     * @param LLMRequest $request
     * @return array
     */
    private function buildPayload(array $messages, LLMRequest $request): array
    {
        $payload = [
            'model' => $request->model,
            'messages' => $messages,
            'options' => [
                'temperature' => $request->temperature,
                'num_predict' => $request->maxTokens,
                'top_p' => $request->topP,
            ],
        ];

        // 处理 think 参数（Ollama 特有）
        if ($request->think !== null) {
            $payload['think'] = $request->think;
        }

        // 处理 tools 参数（Ollama 支持工具调用）
        if (count($request->tools) > 0) {
            // 将 ToolInterface 对象转换为 API 需要的格式
            $payload['tools'] = array_map(function($tool) {
                if (is_array($tool)) {
                    return $tool;
                }
                // 假设是 ToolInterface 对象
                return $tool->toArray();
            }, $request->tools);
        }

        // 处理 format 参数
        if (isset($request->parameters['format'])) {
            $payload['format'] = $request->parameters['format'];
        }

        // 处理 keep_alive 参数
        if (isset($request->parameters['keep_alive'])) {
            $payload['keep_alive'] = $request->parameters['keep_alive'];
        }

        // 合入其他自定义参数
        foreach ($request->parameters as $key => $value) {
            if (!in_array($key, ['format', 'keep_alive'])) {
                $payload[$key] = $value;
            }
        }

        return $payload;
    }

    /**
     * 注入 Skills 系统提示词
     *
     * @param LLMRequest $request 请求对象
     * @return array 注入系统提示词后的消息数组
     */
    private function injectSkillsPrompt(LLMRequest $request): array
    {
        $messages = $request->getMessages();

        // 如果没有 Skills，直接返回原始消息
        if (empty($request->skills)) {
            return $messages;
        }

        // 生成 Skills 提示词
        $skillsPrompt = $request->getSkillsPrompt();

        if (empty($skillsPrompt)) {
            return $messages;
        }

        // 检查是否已经有系统消息
        $hasSystemMessage = false;
        foreach ($messages as $message) {
            if (($message['role'] ?? '') === 'system') {
                $hasSystemMessage = true;
                break;
            }
        }

        if ($hasSystemMessage) {
            // 在现有系统消息后追加 Skills 提示词
            foreach ($messages as &$message) {
                if (($message['role'] ?? '') === 'system') {
                    $message['content'] .= "\n\n" . $skillsPrompt;
                    break;
                }
            }
        } else {
            // 在消息数组开头插入系统消息
            array_unshift($messages, [
                'role' => 'system',
                'content' => $skillsPrompt
            ]);
        }

        return $messages;
    }

    /**
     * 解析聊天补全响应
     */
    private function parseChatResponse(array $data, string $model): LLMResponse
    {
        $response = LLMResponse::create()
            ->content($data['message']['content'] ?? '')
            ->thinking($data['message']['thinking'] ?? '')
            ->model($data['model'] ?? $model)
            ->done($data['done'] ?? true)
            ->finishReason(($data['done'] ?? false) ? 'stop' : null)
            ->raw($data);

        // 解析使用情况
        if (isset($data['prompt_eval_count']) || isset($data['eval_count'])) {
            $response->usage(new TokenUsage(
                $data['prompt_eval_count'] ?? 0,
                $data['eval_count'] ?? 0,
                ($data['prompt_eval_count'] ?? 0) + ($data['eval_count'] ?? 0)
            ));
        }

        // 解析工具调用（Ollama API 支持工具调用）
        if (isset($data['message']['tool_calls']) && is_array($data['message']['tool_calls'])) {
            $response->toolCalls = $data['message']['tool_calls'];
        }

        return $response;
    }

    /**
     * 解析流式响应片段
     */
    private function parseStreamChunk(array $data, string $model): LLMResponse
    {
        $isDone = $data['done'] ?? false;
        $response = LLMResponse::createChunk(
            $data['message']['content'] ?? '',
            $data['message']['thinking'] ?? '',
            $isDone
        )->model($data['model'] ?? $model);

        // 处理工具调用（流式响应中也可能包含）
        if (isset($data['message']['tool_calls']) && is_array($data['message']['tool_calls'])) {
            $response->toolCalls = $data['message']['tool_calls'];
        }

        // 当流式响应完成时，设置 usage 信息
        if ($isDone) {
            if (isset($data['prompt_eval_count']) || isset($data['eval_count'])) {
                $response->usage(new TokenUsage(
                    $data['prompt_eval_count'] ?? 0,
                    $data['eval_count'] ?? 0,
                    ($data['prompt_eval_count'] ?? 0) + ($data['eval_count'] ?? 0)
                ));
            }
        }

        return $response;
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
            $this->handleHttpError($statusCode, $errorBody, 'Ollama API');
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

        $request->setHeader('Content-Type', 'application/json');

        if ($body !== null) {
            $request->setBody(json_encode($body));
        }

        return $request;
    }
}
