<?php

namespace App\Libs\LLM\Clients;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use App\Libs\LLM\LLMRequest;
use App\Libs\LLM\LLMResponse;
use App\Libs\LLM\TokenUsage;
use App\Libs\Traits\HttpRequestTrait;

/**
 * MiniMax TokenPlan 客户端
 *
 * 基于 OpenAI 兼容接口，支持 MiniMax 的特殊思考内容格式
 * 思考内容嵌入在响应的 `<think>...</think>` 标签中
 */
class MiniMaxClient extends OpenAiClient
{
    use HttpRequestTrait;

    private HttpClient $httpClient;
    private string $apiKey;
    private string $baseUrl;
    private int $timeout;

    public function __construct(
        HttpClient $httpClient,
        string $apiKey,
        string $baseUrl = 'https://api.minimax.chat/v1',
        int $timeout = 60
    ) {
        parent::__construct(
            $httpClient,
            $apiKey,
            $baseUrl,
            '',
            [],
            $timeout
        );
        $this->httpClient = $httpClient;
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeout = $timeout;
    }

    /**
     * 发送 HTTP 请求
     */
    protected function request(string $method, string $path, ?array $body = null): string
    {
        $request = $this->createMiniMaxRequest($method, '/text/chatcompletion_v2', $body);
        $response = $this->httpClient->request($request);

        $statusCode = $response->getStatus();
        if ($statusCode < 200 || $statusCode >= 300) {
            $errorBody = $response->getBody()->buffer();
            $this->handleHttpError($statusCode, $errorBody, 'MiniMax API');
        }

        return $response->getBody()->buffer();
    }

    /**
     * 创建 HTTP 请求对象
     */
    private function createMiniMaxRequest(string $method, string $path, ?array $body = null): Request
    {
        $url = $this->baseUrl . $path;
        $request = new Request($url, $method);

        $this->setTimeouts($request, $this->timeout);

        $request->setHeader('Authorization', 'Bearer ' . $this->apiKey);
        $request->setHeader('Content-Type', 'application/json');

        if ($body !== null) {
            $request->setBody(json_encode($body));
        }

        return $request;
    }

    /**
     * 创建聊天补全请求
     */
    public function chat(LLMRequest $request): LLMResponse
    {
        $payload = $request->toArray();
        $response = $this->request('POST', '/text/chatcompletion_v2', $payload);
        $data = $this->safeJsonDecode($response);

        return $this->parseChatResponse($data);
    }

    /**
     * 创建聊天补全请求（流式）
     */
    public function chatStream(LLMRequest $request, callable $callback): void
    {
        $payload = $request->toArray();
        $payload['stream'] = true;

        $httpRequest = $this->createMiniMaxRequest('POST', '/text/chatcompletion_v2', $payload);
        $httpResponse = $this->httpClient->request($httpRequest);

        // 检查 HTTP 状态码
        $statusCode = $httpResponse->getStatus();
        if ($statusCode < 200 || $statusCode >= 300) {
            $errorBody = $httpResponse->getBody()->buffer();
            $this->handleHttpError($statusCode, $errorBody, 'MiniMax API');
        }

        // 检查 Content-Type，如果不是 text/event-stream，说明是错误响应
        $contentType = $httpResponse->getHeader('content-type') ?? '';
        if (strpos($contentType, 'text/event-stream') === false) {
            // 不是流式响应，可能是错误响应
            $body = $httpResponse->getBody()->buffer();
            $data = $this->safeJsonDecode($body);
            if ($data !== null) {
                $this->checkMiniMaxError($data);
            }
            return; // 没有错误但也不是流式响应，直接返回
        }

        $accumulatedContent = '';
        $accumulatedThinking = '';
        $accumulatedToolCalls = [];
        $lastModel = '';

        $this->processStreamByChunk(
            $httpResponse->getBody(),
            function($line) use ($callback, &$accumulatedContent, &$accumulatedThinking, &$accumulatedToolCalls, &$lastModel) {
                $line = trim($line);
                if ($line === '' || $line === 'data: [DONE]') {
                    return true;
                }

                if (str_starts_with($line, 'data: ')) {
                    $data = $this->safeJsonDecode(substr($line, 6));
                    if ($data !== null) {
                        $choice = $data['choices'][0];
                        $delta = $choice['delta'] ?? [];
                        $finishReason = $choice['finish_reason'] ?? null;

                        if (isset($delta['content']) && $delta['content'] !== '') {
                            $accumulatedContent .= $delta['content'];
                        }

                        // MiniMax 使用 reasoning_content 字段
                        if (isset($delta['reasoning_content']) && $delta['reasoning_content'] !== '') {
                            $accumulatedThinking .= $delta['reasoning_content'];
                        }

                        if (isset($data['model'])) {
                            $lastModel = $data['model'];
                        }

                        if (isset($delta['tool_calls']) && is_array($delta['tool_calls'])) {
                            foreach ($delta['tool_calls'] as $toolCall) {
                                $index = $toolCall['index'] ?? 0;
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

                                if (isset($toolCall['id'])) {
                                    $accumulatedToolCalls[$index]['id'] = $toolCall['id'];
                                }
                                if (isset($toolCall['function']['name'])) {
                                    $accumulatedToolCalls[$index]['function']['name'] = $toolCall['function']['name'];
                                }
                                if (isset($toolCall['function']['arguments'])) {
                                    $accumulatedToolCalls[$index]['function']['arguments'] .= $toolCall['function']['arguments'];
                                }
                            }
                        }

                        $isDone = $finishReason !== null;
                        if ($isDone) {
                            $completeToolCalls = [];
                            foreach ($accumulatedToolCalls as $toolCall) {
                                if ($toolCall['function']['name'] !== '' && $toolCall['id'] !== '') {
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

                            $callback($response);

                            $accumulatedContent = '';
                            $accumulatedThinking = '';
                            $accumulatedToolCalls = [];

                            return false;
                        } elseif ($accumulatedContent !== '' || $accumulatedThinking !== '') {
                            $response = LLMResponse::createChunk(
                                $accumulatedContent,
                                $accumulatedThinking,
                                false
                            )->model($lastModel);
                            $callback($response);

                            $accumulatedContent = '';
                            $accumulatedThinking = '';
                        }
                    }
                }
                return true;
            }
        );

        $callback(LLMResponse::createChunk('', '', true));
    }

    /**
     * 解析流式响应片段
     */
    protected function parseStreamChunk(array $data): LLMResponse
    {
        $choice = $data['choices'][0];
        $delta = $choice['delta'] ?? [];

        // MiniMax 使用 reasoning_content 字段
        $thinking = $delta['reasoning_content'] ?? '';

        $chunk = LLMResponse::createChunk(
            $delta['content'] ?? '',
            $thinking,
            ($choice['finish_reason'] ?? null) !== null
        )->model($data['model'] ?? '');

        if (isset($delta['tool_calls']) && is_array($delta['tool_calls'])) {
            $chunk->toolCalls($delta['tool_calls']);
        }

        return $chunk;
    }

    /**
     * 解析聊天补全响应，提取 think 标签中的思考内容
     */
    /**
     * 检查 MiniMax API 响应错误
     *
     * @param array $data 响应数据
     * @throws \RuntimeException 当响应包含错误时
     */
    protected function checkMiniMaxError(array $data): void
    {
        if (!isset($data['choices'])) {
            // 检查 MiniMax API 错误响应
            if (isset($data['base_resp'])) {
                $baseResp = $data['base_resp'];
                $statusCode = $baseResp['status_code'] ?? null;
                if ($statusCode !== null && $statusCode !== 0) {
                    $statusMsg = $baseResp['status_msg'] ?? 'Unknown error';
                    throw new \RuntimeException("MiniMax API error ({$statusCode}): {$statusMsg}");
                }
            }
            throw new \RuntimeException('Invalid MiniMax response: missing choices');
        }

        if (!isset($data['choices'][0])) {
            throw new \RuntimeException('Invalid MiniMax response: missing choices[0]');
        }
    }

    protected function parseChatResponse(array $data): LLMResponse
    {
        $this->checkMiniMaxError($data);

        $choice = $data['choices'][0];
        $message = $choice['message'] ?? [];

        $content = $message['content'] ?? '';
        // MiniMax-M2.7 模型思考内容在 reasoning_content 字段
        $thinking = $message['reasoning_content'] ?? '';

        $response = LLMResponse::create()
            ->content($content)
            ->thinking($thinking)
            ->model($data['model'] ?? '')
            ->done(true)
            ->finishReason($choice['finish_reason'] ?? '')
            ->raw($data);

        if (isset($message['tool_calls']) && is_array($message['tool_calls'])) {
            $response->toolCalls($message['tool_calls']);
        }

        if (isset($data['usage'])) {
            $response->usage(new TokenUsage(
                $data['usage']['prompt_tokens'] ?? 0,
                $data['usage']['completion_tokens'] ?? 0,
                $data['usage']['total_tokens'] ?? 0
            ));
        }

        return $response;
    }
}
