<?php

namespace App\Libs\LLM;

use App\Libs\Agent\ToolManager;

/**
 * LLM 响应封装类
 *
 * 封装了大模型响应的所有数据，包括内容、思考过程、使用情况等
 *
 * @method self content(string $content) 设置响应内容
 * @method self thinking(string $thinking) 设置思考内容
 * @method self done(bool $done) 设置完成状态
 * @method self model(string $model) 设置模型名称
 * @method self usage(TokenUsage $usage) 设置token使用情况
 * @method self finishReason(string $reason) 设置完成原因
 * @method self raw(array $raw) 设置原始响应数据
 */
class LLMResponse
{
    /** 响应内容 */
    public string $content = '';

    /** 思考内容（用于支持思考的模型） */
    public string $thinking = '';

    /** 是否完成 */
    public bool $done = false;

    /** 模型名称 */
    public string $model = '';

    /** 使用的 token 数量 */
    public ?TokenUsage $usage = null;

    /** 完成原因（stop/length/content_filter等） */
    public ?string $finishReason = null;

    /** 原始响应数据 */
    public ?array $raw = null;

    /** @var array<array{string, string, array}> 工具调用列表 [id, type, function] */
    public array $toolCalls = [];

    /**
     * 创建空响应
     */
    public static function create(): self
    {
        return new self();
    }

    /**
     * 创建流式响应块
     */
    public static function createChunk(string $content, string $thinking = '', bool $done = false): self
    {
        $response = new self();
        $response->content = $content;
        $response->thinking = $thinking;
        $response->done = $done;
        return $response;
    }

    /**
     * 魔术方法：支持链式调用
     */
    public function __call(string $name, array $arguments)
    {
        if (property_exists($this, $name)) {
            $this->$name = $arguments[0] ?? null;
            return $this;
        }

        throw new \BadMethodCallException("Method {$name} does not exist");
    }

    /**
     * 获取嵌入向量
     */
    public function getEmbedding(): array
    {
        return $this->raw['embedding'] ?? ($this->raw['data'][0]['embedding'] ?? []);
    }

    /**
     * 是否成功
     */
    public function isSuccess(): bool
    {
        return $this->content !== '' || $this->done;
    }

    /**
     * 是否有工具调用
     */
    public function hasToolCalls(): bool
    {
        return count($this->toolCalls) > 0;
    }

    /**
     * 执行所有工具调用
     */
    public function executeToolCalls(): array
    {
        $results = [];
        foreach ($this->toolCalls as $call) {
            $functionName = $call['function']['name'] ?? '';
            $arguments = $call['function']['arguments'] ?? [];

            if (is_string($arguments)) {
                $arguments = json_decode($arguments, true) ?? [];
            }

            try {
                $result = ToolManager::execute($functionName, $arguments);
                $results[] = [
                    'tool_call_id' => $call['id'],
                    'result' => $result
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    'tool_call_id' => $call['id'],
                    'error' => $e->getMessage()
                ];
            }
        }
        return $results;
    }

    /**
     * 获取总内容长度
     */
    public function getContentLength(): int
    {
        return mb_strlen($this->content);
    }

    /**
     * 获取总 token 使用量
     */
    public function getTotalTokens(): int
    {
        return $this->usage?->totalTokens ?? 0;
    }

    /**
     * 追加内容（流式响应用）
     */
    public function appendContent(string $content): self
    {
        $this->content .= $content;
        return $this;
    }

    /**
     * 追加思考内容（流式响应用）
     */
    public function appendThinking(string $thinking): self
    {
        $this->thinking .= $thinking;
        return $this;
    }
}

/**
 * Token 使用情况
 */
class TokenUsage
{
    /** 提示词 token 数量 */
    public function __construct(
        public int $promptTokens = 0,
        public int $completionTokens = 0,
        public int $totalTokens = 0
    ) {}

    /**
     * 获取提示词成本（按模型价格估算，单位：美元）
     */
    public function getPromptCost(string $model = 'gpt-3.5-turbo'): float
    {
        $pricePer1kTokens = $this->getPromptPrice($model);
        return ($this->promptTokens / 1000) * $pricePer1kTokens;
    }

    /**
     * 获取补全成本
     */
    public function getCompletionCost(string $model = 'gpt-3.5-turbo'): float
    {
        $pricePer1kTokens = $this->getCompletionPrice($model);
        return ($this->completionTokens / 1000) * $pricePer1kTokens;
    }

    /**
     * 获取总成本
     */
    public function getTotalCost(string $model = 'gpt-3.5-turbo'): float
    {
        return $this->getPromptCost($model) + $this->getCompletionCost($model);
    }

    private function getPromptPrice(string $model): float
    {
        $prices = [
            'gpt-3.5-turbo' => 0.0005,
            'gpt-4' => 0.03,
            'gpt-4-turbo' => 0.01,
        ];
        return $prices[$model] ?? 0.0005;
    }

    private function getCompletionPrice(string $model): float
    {
        $prices = [
            'gpt-3.5-turbo' => 0.0015,
            'gpt-4' => 0.06,
            'gpt-4-turbo' => 0.03,
        ];
        return $prices[$model] ?? 0.0015;
    }
}
