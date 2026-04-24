<?php

namespace App\Libs\Agent;

use App\Libs\LLM\LLMClient;
use App\Libs\LLM\LLMRequest;
use App\Libs\LLM\LLMResponse;
use App\Libs\Agent\ToolInterface;
use App\Libs\MCP\McpManager;

/**
 * Agent 类
 *
 * 封装 LLM 交互逻辑，提供统一的对话接口
 * 支持系统提示词、工具调用、技能系统、消息历史管理
 */
class Agent
{
    /** 系统提示词 */
    private string $systemPrompt;

    /** 添加的工具 */
    private array $tools;

    /** 是否启用技能 */
    private bool $withSkills;

    /** 使用的模型 */
    private string $model;

    /** LLM 提供商实例 */
    private LLMClient $provider;

    /** Max Tokens */
    private int $maxTokens;

    /** 温度参数 */
    private float $temperature;

    /** 思考模式 */
    private $think = null;

    /** 消息历史 */
    private array $messages = [];

    /** 最后一次请求的 total tokens */
    private int $lastTotalTokens = 0;

    /** Skill 管理器 */
    private ?SkillManager $skillManager = null;

    /** 是否启用 MCP */
    private bool $withMcp = false;

    /**
     * 构造函数
     *
     * @param string $systemPrompt 系统提示词
     * @param array $tools 工具列表
     * @param bool $withSkills 是否启用技能
     * @param string $model 模型名称
     * @param LLMClient $provider LLM 提供商实例
     * @param int $maxTokens 最大 token 数
     * @param bool $withMcp 是否启用 MCP 工具
     */
    public function __construct(
        string $systemPrompt = '',
        array $tools = [],
        bool $withSkills = false,
        string $model = 'gpt-3.5-turbo',
        ?LLMClient $provider = null,
        int $maxTokens = 32768,
        float $temperature = 0.7,
        $think = null,
        bool $withMcp = false
    ) {
        $this->systemPrompt = $systemPrompt;
        $this->tools = $tools;
        $this->withSkills = $withSkills;
        $this->model = $model;
        $this->maxTokens = $maxTokens;
        $this->temperature = $temperature;
        $this->think = $think;
        $this->withMcp = $withMcp;

        // 如果没有提供 provider，使用默认的 Ollama 客户端
        if ($provider === null) {
            $httpClient = \Amp\Http\Client\HttpClientBuilder::buildDefault();
            $this->provider = new \App\Libs\LLM\Clients\OllamaClient(
                httpClient: $httpClient,
                baseUrl: 'http://localhost:11434',
                timeout: 60
            );
        } else {
            $this->provider = $provider;
        }

        // 初始化 Skill 管理器
        if ($this->withSkills) {
            $this->skillManager = new SkillManager();
        }

        // 初始化 MCP 工具
        if ($this->withMcp) {
            try {
                McpManager::init();
                $mcpTools = McpManager::getAllTools();
                foreach ($mcpTools as $tool) {
                    // 添加到工具列表（用于发送给 LLM）
                    $this->tools[] = $tool;
                    // 注册到 ToolManager（用于执行）
                    ToolManager::register($tool);
                }
            } catch (\Throwable $e) {
                // MCP 初始化失败不应该阻止 Agent 创建
                error_log("MCP 初始化失败: " . $e->getMessage());
            }
        }

        // 如果有系统提示词或技能，添加到消息历史
        $this->initializeSystemMessage();
    }

    /**
     * 初始化系统消息
     */
    private function initializeSystemMessage(): void
    {
        $systemParts = [];

        // 添加基础系统提示词
        if ($this->systemPrompt !== '') {
            $systemParts[] = $this->systemPrompt;
        }

        // 添加技能提示词
        if ($this->withSkills && $this->skillManager !== null) {
            $skillsPrompt = $this->skillManager->generatePrompt();
            if ($skillsPrompt !== '') {
                $systemParts[] = $skillsPrompt;
            }
        }

        // 如果有系统消息，添加到历史
        if (count($systemParts) > 0) {
            $this->messages[] = [
                'role' => 'system',
                'content' => implode("\n\n", $systemParts)
            ];
        }
    }

    /**
     * 聊天对话（非流式）
     *
     * @param string $userMessage 用户消息
     * @param callable|null $onIteration 迭代回调 function(int $iteration, LLMResponse $response, array $toolResults)
     * @return LLMResponse 响应对象
     */
    public function chat(string $userMessage, ?callable $onIteration = null): LLMResponse
    {
        // 创建请求
        $request = $this->createRequest($userMessage);

        // 多轮对话处理（支持工具调用）
        $maxIterations = count($this->tools) > 0 ? 10 : 1;
        $response = null;

        for ($iteration = 0; $iteration < $maxIterations; $iteration++) {
            // 获取响应
            $response = $this->provider->chat($request);

            // 记录最后一次请求的 total tokens
            if ($response->usage !== null) {
                $this->lastTotalTokens = $response->usage->totalTokens;
            }

            // 如果没有工具调用，结束对话
            if (!$response->hasToolCalls()) {
                // 添加最终助手响应到消息历史
                $this->messages[] = $this->createAssistantMessage($response->content, [], $response->thinking);

                // 执行迭代回调（传递空的工具结果）
                if ($onIteration !== null) {
                    $onIteration($iteration + 1, $response, []);
                }

                break;
            }

            // 添加助手响应到消息历史
            $assistantMessage = $this->createAssistantMessage($response->content, $response->toolCalls, $response->thinking);
            $this->messages[] = $assistantMessage;

            // 执行工具调用
            $toolResults = $response->executeToolCalls();

            // 将 assistant 消息添加到请求中（确保 MiniMax 等平台能找到对应的 tool_call_id）
            $request->addMessage('assistant', $response->content, ['tool_calls' => $response->toolCalls]);

            // 添加工具消息到请求和消息历史
            foreach ($toolResults as $result) {
                $request->addToolMessage($result['tool_call_id'], $result['result'] ?? $result['error']);

                // 添加到消息历史
                $this->messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $result['tool_call_id'],
                    'content' => $result['result'] ?? $result['error']
                ];
            }

            // 执行迭代回调
            if ($onIteration !== null) {
                $onIteration($iteration + 1, $response, $toolResults);
            }
        }

        return $response;
    }

    /**
     * 聊天对话（流式）
     *
     * @param string $userMessage 用户消息
     * @param callable $callback 流式回调 function(LLMResponse $response, array $toolMessages = [])
     * @return LLMResponse 最终响应对象
     */
    public function chatStream(string $userMessage, callable $callback): LLMResponse
    {
        // 创建请求
        $request = $this->createRequest($userMessage);

        // 多轮对话处理（支持工具调用）
        $maxIterations = count($this->tools) > 0 ? 10 : 1;
        $finalResponse = null;

        for ($iteration = 0; $iteration < $maxIterations; $iteration++) {
            // 收集流式响应的完整内容
            $fullContent = '';
            $fullThinking = '';
            $allToolCalls = [];
            $lastUsage = null;
            $responseModel = ''; // 收集响应中的模型

            // 流式获取响应
            $this->provider->chatStream($request, function(LLMResponse $response)
                use ($callback, &$fullContent, &$fullThinking, &$allToolCalls, &$lastUsage, &$responseModel) {

                // 收集内容
                if ($response->content !== '') {
                    $fullContent .= $response->content;
                }

                // 收集思考过程
                if ($response->thinking !== '') {
                    $fullThinking .= $response->thinking;
                }

                // 收集工具调用
                if (count($response->toolCalls) > 0) {
                    $allToolCalls = array_merge($allToolCalls, $response->toolCalls);
                }

                // 收集 usage 信息
                if ($response->usage !== null) {
                    $lastUsage = $response->usage;
                }

                // 收集响应中的模型（使用最后一个非空的模型）
                if ($response->model !== '') {
                    $responseModel = $response->model;
                }

                // 调用用户回调
                $callback($response, []);
            });

            // 创建最终响应
            $finalResponse = LLMResponse::create()
                ->content($fullContent)
                ->thinking($fullThinking)
                ->model($responseModel !== '' ? $responseModel : $this->model)
                ->done(true)
                ->toolCalls($allToolCalls);

            // 设置 usage 信息
            if ($lastUsage !== null) {
                $finalResponse->usage($lastUsage);
            }

            // 记录最后一次请求的 total tokens
            if ($finalResponse->usage !== null) {
                $this->lastTotalTokens = $finalResponse->usage->totalTokens;
            }

            // 如果没有工具调用，结束对话
            if (!$finalResponse->hasToolCalls()) {
                // 添加助手响应到消息历史
                $this->messages[] = $this->createAssistantMessage($fullContent, [], $fullThinking);
                break;
            }

            // 添加助手响应到消息历史
            $this->messages[] = $this->createAssistantMessage($fullContent, $allToolCalls, $fullThinking);

            // 执行工具调用
            $toolResults = $finalResponse->executeToolCalls();

            $toolMessages = [];
            foreach ($toolResults as $result) {
                // 添加到消息历史
                $toolMessage = [
                    'role' => 'tool',
                    'tool_call_id' => $result['tool_call_id'],
                    'content' => $result['result'] ?? $result['error']
                ];
                $this->messages[] = $toolMessage;
                $toolMessages[] = $toolMessage;
            }

            // 通知调用者工具消息已添加
            $callback(LLMResponse::create()->done(true), $toolMessages);

            // 创建新的请求（已经包含了完整的消息历史，包括刚才添加的助手消息和工具消息）
            // 注意：这里不需要添加用户消息，因为消息历史已经包含了完整的上下文
            $request = LLMRequest::create();
            $request->addMessages($this->messages);
            $request->model($this->model);
            $request->temperature($this->temperature);
            $request->maxTokens($this->maxTokens);
            if ($this->think !== null) {
                $request->think($this->think);
            }
            foreach ($this->tools as $tool) {
                $request->addTool($tool);
            }
        }

        return $finalResponse;
    }

    /**
     * 创建助手消息
     *
     * @param string $content 响应内容
     * @param array $toolCalls 工具调用列表
     * @param string $thinking 推理内容（用于 DeepSeek 等推理模型）
     * @return array 助手消息数组
     */
    private function createAssistantMessage(string $content, array $toolCalls = [], string $thinking = ''): array
    {
        $message = [
            'role' => 'assistant',
            'content' => $content
        ];

        // 如果有工具调用，添加到消息中
        if (count($toolCalls) > 0) {
            $message['tool_calls'] = $toolCalls;
        }

        // 只有当思考内容不为空时才添加 reasoning_content 字段
        // 空字符串的 reasoning_content 会被 DeepSeek API 识别为启用了思考模式
        if ($thinking !== '') {
            $message['reasoning_content'] = $thinking;
        }

        return $message;
    }

    /**
     * 创建请求对象
     *
     * @param string $userMessage 用户消息
     * @return LLMRequest
     */
    private function createRequest(string $userMessage): LLMRequest
    {
        $request = LLMRequest::create();

        // 添加消息历史
        $request->addMessages($this->messages);

        // 添加当前用户消息到请求（如果有的话）
        if ($userMessage !== '') {
            $request->addUser($userMessage);

            // 同时添加到消息历史（确保完整的对话记录）
            $this->messages[] = [
                'role' => 'user',
                'content' => $userMessage
            ];
        }

        // 设置参数
        $request->model($this->model);
        $request->temperature($this->temperature);
        $request->maxTokens($this->maxTokens);

        // 设置思考模式（使用方法调用以触发类型转换）
        if ($this->think !== null) {
            $request->think($this->think);
        }

        // 添加工具
        foreach ($this->tools as $tool) {
            $request->addTool($tool);
        }

        return $request;
    }

    /**
     * 获取消息历史
     *
     * @return array
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    /**
     * 清空消息历史
     *
     * 保留系统消息,清空其他所有对话历史
     *
     * @return void
     */
    public function clearMessages(): void
    {
        // 保留系统消息(如果有)
        $systemMessages = array_filter($this->messages, function($message) {
            return ($message['role'] ?? '') === 'system';
        });

        $this->messages = array_values($systemMessages);
    }

    /**
     * 获取当前对话的总 token 数量
     *
     * 从最后一次响应中获取 total_tokens，代表当前整个请求（上下文 + 生成）的 token 总量
     *
     * @return int 当前对话的 token 总数
     */
    public function getTotalTokens(): int
    {
        return $this->lastTotalTokens;
    }

    /**
     * 析构函数
     *
     * 确保 MCP 连接被正确关闭
     */
    public function __destruct()
    {
        if ($this->withMcp) {
            try {
                McpManager::closeAll();
            } catch (\Throwable $e) {
                // 忽略析构时的错误
                error_log("MCP 关闭失败: " . $e->getMessage());
            }
        }
    }
}
