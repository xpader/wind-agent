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
 * Anthropic Claude 客户端（基于 AMPHP HTTP Client v5）
 *
 * 实现对 Anthropic Claude API 的支持，包括 Messages API
 * 支持 Claude 3 系列模型（Claude 3.5 Sonnet, Claude 3 Opus, Claude 3 Haiku 等）
 *
 * API 文档：https://docs.anthropic.com/claude/reference/messages-post
 */
class AnthropicClient implements LLMClient
{
    use StreamResponseTrait, HttpRequestTrait;

    protected HttpClient $httpClient;
    protected string $apiKey;
    protected string $baseUrl = 'https://api.anthropic.com';
    protected string $version = '2023-06-01';
    protected array $defaultOptions;
    protected int $timeout;

    public function __construct(
        HttpClient $httpClient,
        string $apiKey,
        string $baseUrl = '',
        string $version = '',
        array $defaultOptions = [],
        int $timeout = 60
    ) {
        $this->httpClient = $httpClient;
        $this->apiKey = $apiKey;
        if ($baseUrl !== '') {
            $this->baseUrl = rtrim($baseUrl, '/');
        }
        if ($version !== '') {
            $this->version = $version;
        }
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
        $payload = $this->buildAnthropicPayload($request);
        $response = $this->request('POST', '/v1/messages', $payload);
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
        $payload = $this->buildAnthropicPayload($request);
        $payload['stream'] = true;

        $httpRequest = $this->createRequest('POST', '/v1/messages', $payload);
        $response = $this->httpClient->request($httpRequest);

        // 检查 HTTP 状态码
        $statusCode = $response->getStatus();
        if ($statusCode < 200 || $statusCode >= 300) {
            $errorBody = $response->getBody()->buffer();
            $this->handleHttpError($statusCode, $errorBody, 'Anthropic API');
        }

        // 累积完整的 tool_calls（Anthropic 的流式响应中 tool_calls 也是分片的）
        $accumulatedToolCalls = [];
        $lastModel = '';
        $hasIncompleteToolCalls = false;
        $currentBlockType = null;  // 跟踪当前块类型（thinking 或 text）

        // 使用 trait 的流式处理方法
        $this->processStreamByChunk(
            $response->getBody(),
            function($line) use ($callback, $request, &$accumulatedToolCalls, &$lastModel, &$hasIncompleteToolCalls, &$currentBlockType) {
                $line = trim($line);
                if ($line === '' || $line === 'event: message_stop') {
                    return true;
                }

                // Anthropic 使用事件流格式
                if (str_starts_with($line, 'data: ')) {
                    $data = $this->safeJsonDecode(substr($line, 6));
                    if ($data !== null) {
                        $eventType = $data['type'] ?? '';

                        // 记录模型名称
                        if ($eventType === 'message_start' && isset($data['message']['model'])) {
                            $lastModel = $data['message']['model'];
                        }

                        // 处理内容块
                        if ($eventType === 'content_block_start') {
                            $blockType = $data['content_block']['type'] ?? '';
                            $currentBlockType = $blockType;  // 跟踪当前块类型

                            if ($blockType === 'tool_use') {
                                // 初始化工具调用
                                $index = $data['index'] ?? 0;
                                $accumulatedToolCalls[$index] = [
                                    'id' => $data['content_block']['id'] ?? '',
                                    'type' => 'function',
                                    'function' => [
                                        'name' => $data['content_block']['name'] ?? '',
                                        'arguments' => ''
                                    ]
                                ];
                                $hasIncompleteToolCalls = true;
                            } elseif ($blockType === 'thinking') {
                                // 思考块开始，不需要特殊处理
                            }
                        } elseif ($eventType === 'content_block_delta') {
                            $blockType = $data['delta']['type'] ?? '';
                            $index = $data['index'] ?? 0;

                            if ($blockType === 'text_delta') {
                                // 获取文本内容
                                $text = $data['delta']['text'] ?? '';
                                if ($text !== '') {
                                    // 立即发送文本内容（流式）
                                    $response = LLMResponse::createChunk(
                                        $text,
                                        '',
                                        false
                                    )->model($lastModel ?: $request->model);
                                    $callback($response);
                                }
                            } elseif ($blockType === 'thinking_delta') {
                                // 获取思考内容
                                $thinking = $data['delta']['thinking'] ?? '';
                                if ($thinking !== '') {
                                    // 立即发送思考内容（流式）
                                    $response = LLMResponse::createChunk(
                                        '',
                                        $thinking,
                                        false
                                    )->model($lastModel ?: $request->model);
                                    $callback($response);
                                }
                            } elseif ($blockType === 'input_json_delta') {
                                // 累积工具参数
                                if (isset($accumulatedToolCalls[$index])) {
                                    $accumulatedToolCalls[$index]['function']['arguments'] .= $data['delta']['partial_json'] ?? '';
                                }
                            }
                        } elseif ($eventType === 'message_delta') {
                            // 消息结束，处理 usage
                            $isDone = true;

                            // 验证并组装完整的 tool_calls
                            $completeToolCalls = [];
                            foreach ($accumulatedToolCalls as $toolCall) {
                                if ($toolCall['function']['name'] !== '' && $toolCall['id'] !== '') {
                                    $completeToolCalls[] = $toolCall;
                                }
                            }

                            // 创建空的完成响应（只包含 usage 和 tool_calls）
                            $response = LLMResponse::createChunk(
                                '',
                                '',
                                true
                            )->model($lastModel ?: $request->model);

                            if (count($completeToolCalls) > 0) {
                                $response->toolCalls($completeToolCalls);
                            }

                            // 设置 usage 信息
                            if (isset($data['usage'])) {
                                $response->usage(new TokenUsage(
                                    $data['usage']['input_tokens'] ?? 0,
                                    $data['usage']['output_tokens'] ?? 0,
                                    ($data['usage']['input_tokens'] ?? 0) + ($data['usage']['output_tokens'] ?? 0)
                                ));
                            }

                            $callback($response);

                            // 清空累加的工具调用
                            $accumulatedToolCalls = [];
                            $hasIncompleteToolCalls = false;
                            $currentBlockType = null;

                            return false;
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
     * Anthropic 不提供模型列表 API，返回支持的模型列表
     *
     * @return array
     */
    public function listModels(): array
    {
        // Anthropic 不提供模型列表端点，返回已知支持的模型
        return [
            'claude-3-5-sonnet-20241022',
            'claude-3-5-sonnet-20240620',
            'claude-3-5-haiku-20241022',
            'claude-3-opus-20240229',
            'claude-3-sonnet-20240229',
            'claude-3-haiku-20240307',
        ];
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
            $this->handleHttpError($statusCode, $errorBody, 'Anthropic API');
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

        // Anthropic 特有的请求头
        $request->setHeader('x-api-key', $this->apiKey);
        $request->setHeader('anthropic-version', $this->version);
        $request->setHeader('Content-Type', 'application/json');

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
     * 构建 Anthropic API 请求载荷
     *
     * Anthropic Messages API 与 OpenAI 格式的主要差异：
     * - 使用 system 字段而非 system 消息
     * - 工具调用格式不同
     * - 参数命名不同（max_tokens vs max_completion_tokens）
     *
     * @param LLMRequest $request 请求对象
     * @return array
     */
    private function buildAnthropicPayload(LLMRequest $request): array
    {
        $messages = $request->getMessages();
        $systemMessage = '';
        $filteredMessages = [];

        // 提取系统消息，保留其他所有消息（包括 tool 消息）
        foreach ($messages as $message) {
            if (($message['role'] ?? '') === 'system') {
                $systemMessage .= ($systemMessage === '' ? '' : "\n\n") . $message['content'];
            } else {
                // 保留所有非系统消息，包括 tool 消息
                $filteredMessages[] = $message;
            }
        }

        // 构建 Anthropic 格式的载荷（会处理 tool 消息转换为 tool_result）
        $anthropicMessages = $this->convertMessagesToAnthropicFormat($filteredMessages);

        $payload = [
            'model' => $request->model,
            'messages' => $anthropicMessages,
            'max_tokens' => $request->maxTokens,
            'temperature' => $request->temperature,
            'top_p' => $request->topP,
        ];

        // 添加系统消息
        if ($systemMessage !== '') {
            $payload['system'] = $systemMessage;
        }

        // 添加思考模式配置
        if ($request->think !== null) {
            if ($request->think === false) {
                // 明确禁用思考模式
                $payload['thinking'] = [
                    'type' => 'disabled'
                ];
            } elseif ($request->think === true) {
                // 启用扩展思考模式（默认预算）
                $payload['thinking'] = [
                    'type' => 'enabled',
                    'budget_tokens' => 10000
                ];
            } elseif (is_string($request->think)) {
                // 支持设置预算或特定级别
                if (in_array($request->think, ['high', 'medium', 'low'])) {
                    $budgetTokens = match($request->think) {
                        'high' => 20000,
                        'medium' => 10000,
                        'low' => 5000,
                    };
                    $payload['thinking'] = [
                        'type' => 'enabled',
                        'budget_tokens' => $budgetTokens
                    ];
                } else {
                    // 假设是数字字符串
                    $payload['thinking'] = [
                        'type' => 'enabled',
                        'budget_tokens' => (int)$request->think
                    ];
                }
            }
        }

        // 添加工具定义
        if (count($request->tools) > 0) {
            $payload['tools'] = array_map(function($tool) {
                $toolArray = is_array($tool) ? $tool : $tool->toArray();
                // 转换为 Anthropic 格式
                return [
                    'name' => $toolArray['function']['name'] ?? '',
                    'description' => $toolArray['function']['description'] ?? '',
                    'input_schema' => $toolArray['function']['parameters'] ?? []
                ];
            }, $request->tools);
        }

        // 合入默认选项和自定义参数
        return array_merge($payload, $this->defaultOptions, $request->parameters);
    }

    /**
     * 将消息转换为 Anthropic 格式
     *
     * Anthropic 的内容格式支持结构化内容：
     * - 简单文本：["type" => "text", "text" => "内容"]
     * - 工具调用结果：需要使用 result 块
     *
     * @param array $messages
     * @return array
     */
    private function convertMessagesToAnthropicFormat(array $messages): array
    {
        $anthropicMessages = [];
        $pendingToolResults = []; // 收集待处理的 tool_result

        foreach ($messages as $message) {
            $role = $message['role'] ?? 'user';
            $content = $message['content'] ?? '';

            // 处理工具调用结果（如果有 tool_call_id）
            if (isset($message['tool_call_id'])) {
                // 收集 tool_result，稍后一起处理
                $pendingToolResults[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => $message['tool_call_id'],
                    'content' => $content
                ];

                // 继续处理下一条消息，不立即添加到 anthropicMessages
                continue;
            }

            // 如果有待处理的 tool_result，先创建用户消息
            if (count($pendingToolResults) > 0) {
                $anthropicMessages[] = [
                    'role' => 'user',
                    'content' => $pendingToolResults
                ];
                $pendingToolResults = [];
            }

            if (isset($message['tool_calls']) && is_array($message['tool_calls'])) {
                // 转换助手消息中的工具调用
                $contentBlocks = [];
                if ($content !== '') {
                    $contentBlocks[] = ['type' => 'text', 'text' => $content];
                }

                foreach ($message['tool_calls'] as $toolCall) {
                    $contentBlocks[] = [
                        'type' => 'tool_use',
                        'id' => $toolCall['id'] ?? '',
                        'name' => $toolCall['function']['name'] ?? '',
                        'input' => json_decode($toolCall['function']['arguments'] ?? '{}', true) ?? []
                    ];
                }

                $anthropicMessages[] = [
                    'role' => 'assistant',
                    'content' => $contentBlocks
                ];
            } else {
                // 普通消息
                $anthropicMessages[] = [
                    'role' => $role,
                    'content' => $content
                ];
            }
        }

        // 处理最后剩余的 tool_result
        if (count($pendingToolResults) > 0) {
            $anthropicMessages[] = [
                'role' => 'user',
                'content' => $pendingToolResults
            ];
        }

        return $anthropicMessages;
    }

    /**
     * 解析聊天补全响应
     */
    protected function parseChatResponse(array $data): LLMResponse
    {
        $response = LLMResponse::create()
            ->model($data['model'] ?? '')
            ->done(true)
            ->finishReason($data['stop_reason'] ?? '')
            ->raw($data);

        // 解析内容
        $content = '';
        $toolCalls = [];

        if (isset($data['content']) && is_array($data['content'])) {
            foreach ($data['content'] as $block) {
                $blockType = $block['type'] ?? '';

                if ($blockType === 'text') {
                    $content .= $block['text'] ?? '';
                } elseif ($blockType === 'tool_use') {
                    // 转换为 OpenAI 格式的 tool_calls
                    $toolCalls[] = [
                        'id' => $block['id'] ?? '',
                        'type' => 'function',
                        'function' => [
                            'name' => $block['name'] ?? '',
                            'arguments' => json_encode($block['input'] ?? [])
                        ]
                    ];
                }
            }
        }

        $response->content($content);

        if (count($toolCalls) > 0) {
            $response->toolCalls($toolCalls);
        }

        // 解析使用情况
        if (isset($data['usage'])) {
            $response->usage(new TokenUsage(
                $data['usage']['input_tokens'] ?? 0,
                $data['usage']['output_tokens'] ?? 0,
                ($data['usage']['input_tokens'] ?? 0) + ($data['usage']['output_tokens'] ?? 0)
            ));
        }

        return $response;
    }

    /**
     * 解析流式响应片段
     */
    protected function parseStreamChunk(array $data): LLMResponse
    {
        // 流式响应的解析在 chatStream 方法中完成
        return LLMResponse::createChunk('', '', false);
    }
}
