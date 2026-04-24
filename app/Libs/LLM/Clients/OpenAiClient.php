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
    protected HttpClient $httpClient;
    protected string $apiKey;
    protected string $baseUrl = 'https://api.openai.com/v1';
    protected string $organization;
    protected array $defaultOptions;
    protected int $timeout;

    public function __construct(
        HttpClient $httpClient,
        string $apiKey,
        string $baseUrl = '',
        string $organization = '',
        array $defaultOptions = [],
        int $timeout = 60
    ) {
        $this->httpClient = $httpClient;
        $this->apiKey = $apiKey;
        if ($baseUrl !== '') {
            $this->baseUrl = rtrim($baseUrl, '/');
        }
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
        $payload = $this->buildOpenAiPayload($request);

        // 调试：打印请求 payload（仅在调试模式）
        if (getenv('DEBUG_OPENAI_PAYLOAD') === 'true') {
            error_log('[OpenAiClient] Request payload: ' . json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

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
        // 默认的完成判断：使用 finish_reason
        $isDoneCallback = function(array $data): bool {
            $choice = $data['choices'][0] ?? [];
            return ($choice['finish_reason'] ?? null) !== null;
        };

        $this->sendChatStream($request, $callback, $isDoneCallback);
    }

    /**
     * 发送流式聊天请求（内部方法）
     *
     * @param LLMRequest $request 请求对象
     * @param callable $callback 接收流式数据的回调 function(LLMResponse $response)
     * @param callable $isDoneCallback 判断流是否完成的回调 function(array $data): bool
     * @return void
     */
    protected function sendChatStream(LLMRequest $request, callable $callback, callable $isDoneCallback): void
    {
        $payload = $this->buildOpenAiPayload($request);
        $payload['stream'] = true;

        $httpRequest = $this->createRequest('POST', '/chat/completions', $payload);
        $response = $this->httpClient->request($httpRequest);

        // 检查 HTTP 状态码
        $statusCode = $response->getStatus();
        if ($statusCode < 200 || $statusCode >= 300) {
            $errorBody = $response->getBody()->buffer();
            $this->handleHttpError($statusCode, $errorBody, 'OpenAI API');
        }

        // 累积完整的 tool_calls（处理 MiniMax 等平台的分片情况）
        $accumulatedToolCalls = [];
        $accumulatedContent = '';
        $accumulatedThinking = '';
        $lastModel = '';
        $hasIncompleteToolCalls = false;

        // 使用 trait 的流式处理方法
        $this->processStreamByChunk(
            $response->getBody(),
            function($line) use ($callback, $request, &$accumulatedToolCalls, &$accumulatedContent, &$accumulatedThinking, &$lastModel, &$hasIncompleteToolCalls, $isDoneCallback) {
                $line = trim($line);
                if ($line === '' || $line === 'data: [DONE]') {
                    return true;
                }

                if (str_starts_with($line, 'data: ')) {
                    $data = $this->safeJsonDecode(substr($line, 6));
                    if ($data !== null) {
                        $choice = $data['choices'][0] ?? [];
                        $delta = $choice['delta'] ?? [];
                        $finishReason = $choice['finish_reason'] ?? null;

                        // 累积内容
                        if (isset($delta['content']) && $delta['content'] !== '') {
                            $accumulatedContent .= $delta['content'];
                        }

                        // 累积思考过程
                        if (isset($delta['reasoning_content']) && $delta['reasoning_content'] !== '') {
                            $accumulatedThinking .= $delta['reasoning_content'];
                        }

                        // 记录模型名称
                        if (isset($data['model'])) {
                            $lastModel = $data['model'];
                        }

                        // 处理 tool_calls
                        if (isset($delta['tool_calls']) && is_array($delta['tool_calls'])) {
                            $hasIncompleteToolCalls = true;
                            foreach ($delta['tool_calls'] as $toolCall) {
                                $index = $toolCall['index'] ?? 0;

                                // 初始化这个位置的工具调用
                                if (!isset($accumulatedToolCalls[$index])) {
                                    $accumulatedToolCalls[$index] = [
                                        'id' => '',
                                        'type' => 'function',
                                        'function' => [
                                            'name' => '',
                                            'arguments' => ''
                                        ]
                                    ];
                                }

                                // 合并字段
                                if (isset($toolCall['id'])) {
                                    $accumulatedToolCalls[$index]['id'] = $toolCall['id'];
                                }
                                if (isset($toolCall['type'])) {
                                    $accumulatedToolCalls[$index]['type'] = $toolCall['type'];
                                }
                                if (isset($toolCall['function'])) {
                                    if (isset($toolCall['function']['name'])) {
                                        $accumulatedToolCalls[$index]['function']['name'] = $toolCall['function']['name'];
                                    }
                                    if (isset($toolCall['function']['arguments'])) {
                                        $accumulatedToolCalls[$index]['function']['arguments'] .= $toolCall['function']['arguments'];
                                    }
                                }
                            }
                        }

                        // 判断是否完成
                        $isDone = $isDoneCallback($data);
                        if ($isDone) {
                            // 验证并组装完整的 tool_calls
                            $completeToolCalls = [];
                            foreach ($accumulatedToolCalls as $toolCall) {
                                if ($toolCall['function']['name'] !== '' && $toolCall['id'] !== '') {
                                    // 解析 arguments 字符串为对象
                                    $arguments = $toolCall['function']['arguments'] ?? '{}';
                                    $parsedArgs = json_decode($arguments, true);

                                    // 确保 parsedArgs 是对象
                                    if (!is_array($parsedArgs)) {
                                        $parsedArgs = [];
                                    } elseif (array_is_list($parsedArgs)) {
                                        $parsedArgs = [];
                                    }

                                    $toolCall['function']['arguments'] = $parsedArgs;
                                    $completeToolCalls[] = $toolCall;
                                }
                            }

                            $response = LLMResponse::createChunk(
                                $accumulatedContent,
                                $accumulatedThinking,
                                true
                            )->model($lastModel);

                            if (count($completeToolCalls) > 0) {
                                $response->toolCalls($completeToolCalls);
                            }

                            // 设置 usage 信息（流式响应在完成时会包含 usage）
                            if (isset($data['usage'])) {
                                $response->usage(new \App\Libs\LLM\TokenUsage(
                                    $data['usage']['prompt_tokens'] ?? 0,
                                    $data['usage']['completion_tokens'] ?? 0,
                                    $data['usage']['total_tokens'] ?? 0
                                ));
                            }

                            $callback($response);

                            // 清空累加的内容
                            $accumulatedContent = '';
                            $accumulatedThinking = '';
                            $accumulatedToolCalls = [];
                            $hasIncompleteToolCalls = false;

                            return false;
                        } elseif (!$hasIncompleteToolCalls && ($accumulatedContent !== '' || $accumulatedThinking !== '')) {
                            // 如果没有工具调用，可以流式输出内容
                            $response = LLMResponse::createChunk(
                                $accumulatedContent,
                                $accumulatedThinking,
                                false
                            )->model($lastModel);
                            $callback($response);

                            // 清空已发送的内容
                            $accumulatedContent = '';
                            $accumulatedThinking = '';
                        }
                    }
                }
                return true;
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
    protected function parseChatResponse(array $data): LLMResponse
    {
        $choice = $data['choices'][0];
        $message = $choice['message'];

        $response = LLMResponse::create()
            ->content($message['content'] ?? '')
            ->thinking($message['reasoning_content'] ?? '')
            ->model($data['model'] ?? '')
            ->done(true)
            ->finishReason($choice['finish_reason'] ?? '')
            ->raw($data);

        // 解析工具调用
        if (isset($message['tool_calls']) && is_array($message['tool_calls'])) {
            $response->toolCalls($message['tool_calls']);
        }

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
    protected function parseStreamChunk(array $data): LLMResponse
    {
        $choice = $data['choices'][0];
        $delta = $choice['delta'];

        $chunk = LLMResponse::createChunk(
            $delta['content'] ?? '',
            $delta['reasoning_content'] ?? '',
            ($choice['finish_reason'] ?? null) !== null
        )->model($data['model'] ?? '');

        // 解析流式响应中的工具调用
        if (isset($delta['tool_calls']) && is_array($delta['tool_calls'])) {
            $chunk->toolCalls($delta['tool_calls']);
        }

        return $chunk;
    }

    /**
     * 构建 OpenAI 格式的请求载荷
     * 将 tool_calls 中的 arguments 从对象格式转换为 JSON 字符串
     * 处理 DeepSeek/OpenAI 的思考模式参数
     */
    private function buildOpenAiPayload(LLMRequest $request): array
    {
        $payload = $request->toArray();

        // 转换消息中的 tool_calls 格式
        if (isset($payload['messages']) && is_array($payload['messages'])) {
            foreach ($payload['messages'] as &$message) {
                if (isset($message['tool_calls']) && is_array($message['tool_calls'])) {
                    foreach ($message['tool_calls'] as &$toolCall) {
                        if (isset($toolCall['function']['arguments'])) {
                            $arguments = $toolCall['function']['arguments'];

                            // 如果是对象，转换为 JSON 字符串
                            if (is_array($arguments)) {
                                // 空数组时转换为空对象
                                if (!$arguments) {
                                    $arguments = (object)[];
                                }
                                $toolCall['function']['arguments'] = json_encode($arguments, JSON_UNESCAPED_UNICODE);
                            }
                            // 如果已经是字符串，保持不变
                        }
                    }
                }
            }
        }

        // 处理思考模式参数（DeepSeek/OpenAI 格式）
        if ($request->think !== null) {
            // 移除统一层的 'think' 参数
            unset($payload['think']);

            if ($request->think === false) {
                // 禁用思考模式
                $payload['thinking'] = ['type' => 'disabled'];
            } elseif ($request->think === true) {
                // 启用思考模式（默认）
                $payload['thinking'] = ['type' => 'enabled'];
            } elseif (is_string($request->think)) {
                // 支持推理强度控制：low, medium, high, max
                if (in_array($request->think, ['low', 'medium', 'high', 'max'])) {
                    $payload['reasoning_effort'] = $request->think;
                    $payload['thinking'] = ['type' => 'enabled'];
                }
            }
        }

        return $payload;
    }
}
