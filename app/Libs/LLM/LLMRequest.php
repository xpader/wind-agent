<?php

namespace App\Libs\LLM;

use App\Libs\Agent\ToolInterface;
use App\Libs\Agent\SkillManager;

/**
 * LLM 请求封装类
 *
 * 统一管理请求参数和消息组织，支持链式调用
 *
 * @method self model(string $model) 设置模型名称
 * @method self temperature(float $temperature) 设置温度参数
 * @method self maxTokens(int $maxTokens) 设置最大token数
 * @method self topP(float $topP) 设置top_p参数
 * @method self think(mixed $think) 设置think参数
 */
class LLMRequest
{
    /** @var array<array{role: string, content: string, array}> 消息列表 */
    public array $messages = [];

    public string $model = 'gpt-3.5-turbo';
    public float $temperature = 0.7;
    public int $maxTokens = 2000;
    public float $topP = 1.0;
    public $think = null;
    public array $parameters = [];

    /** @var array<ToolInterface> 可用工具列表 */
    public array $tools = [];

    /** @var SkillManager Skill 管理器 */
    private static ?SkillManager $skillManager = null;

    /**
     * 创建默认实例
     */
    public static function create(): self
    {
        return new self();
    }

    /**
     * 从系统提示词创建
     */
    public static function withSystem(string $systemPrompt): self
    {
        return self::create()->addSystem($systemPrompt);
    }

    /**
     * 添加用户消息
     *
     * @param string $content 消息内容
     * @param array $extra 额外参数
     * @return $this
     */
    public function addUser(string $content, array $extra = []): self
    {
        return $this->addMessage('user', $content, $extra);
    }

    /**
     * 添加助手消息
     *
     * @param string $content 消息内容
     * @param array $extra 额外参数
     * @return $this
     */
    public function addAssistant(string $content, array $extra = []): self
    {
        return $this->addMessage('assistant', $content, $extra);
    }

    /**
     * 添加系统消息
     *
     * @param string $content 消息内容
     * @param array $extra 额外参数
     * @return $this
     */
    public function addSystem(string $content, array $extra = []): self
    {
        return $this->addMessage('system', $content, $extra);
    }

    /**
     * 添加工具消息（用于多轮对话）
     *
     * @param string $toolCallId 工具调用ID
     * @param string $content 工具执行结果
     * @return $this
     */
    public function addToolMessage(string $toolCallId, string $content): self
    {
        $this->messages[] = [
            'role' => 'tool',
            'tool_call_id' => $toolCallId,
            'content' => $content
        ];
        return $this;
    }

    /**
     * 添加消息
     *
     * @param string $role 角色 (user/assistant/system/tool)
     * @param string $content 消息内容
     * @param array $extra 额外参数
     * @return $this
     */
    public function addMessage(string $role, string $content, array $extra = []): self
    {
        $message = array_merge(['role' => $role, 'content' => $content], $extra);
        $this->messages[] = $message;
        return $this;
    }

    /**
     * 批量添加消息
     *
     * @param array $messages 消息数组
     * @return $this
     */
    public function addMessages(array $messages): self
    {
        foreach ($messages as $message) {
            if (isset($message['role']) && isset($message['content'])) {
                $this->messages[] = $message;
            }
        }
        return $this;
    }

    /**
     * 设置消息列表（覆盖现有消息）
     *
     * @param array $messages 消息数组
     * @return $this
     */
    public function setMessages(array $messages): self
    {
        $this->messages = $messages;
        return $this;
    }

    /**
     * 获取消息列表
     *
     * @return array
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    /**
     * 添加工具
     *
     * @param ToolInterface $tool 工具实例
     * @return $this
     */
    public function addTool(ToolInterface $tool): self
    {
        $this->tools[] = $tool;
        return $this;
    }

    /**
     * 设置工具列表
     *
     * @param array<ToolInterface> $tools 工具列表
     * @return $this
     */
    public function setTools(array $tools): self
    {
        $this->tools = $tools;
        return $this;
    }

    /**
     * 魔术方法：支持链式调用设置属性
     *
     * @param string $name 属性名
     * @param array $arguments 参数
     * @return $this
     */
    public function __call(string $name, array $arguments)
    {
        if (property_exists($this, $name)) {
            $value = $arguments[0] ?? null;

            // 对 think 属性进行特殊处理：将 'true'/'false' 字符串转换为布尔值
            if ($name === 'think' && is_string($value)) {
                if ($value === 'true') {
                    $value = true;
                } elseif ($value === 'false') {
                    $value = false;
                }
                // 其它情况保持为字符串（如 'high', 'medium', 'low' 等）
            }

            $this->$name = $value;
            return $this;
        }

        throw new \BadMethodCallException("Method {$name} does not exist");
    }

    /**
     * 获取 SkillManager 实例
     *
     * @return SkillManager
     */
    private static function getSkillManager(): SkillManager
    {
        if (self::$skillManager === null) {
            self::$skillManager = new SkillManager();
        }
        return self::$skillManager;
    }

    /**
     * 生成 Skill 列表的提示词
     *
     * @return string
     */
    public function getSkillsPrompt(): string
    {
        return self::getSkillManager()->generatePrompt();
    }

    /**
     * 获取指定名称的 Skill
     *
     * @param string $name Skill 名称
     * @return \App\Libs\Agent\Skill|null
     */
    public function getSkill(string $name): ?\App\Libs\Agent\Skill
    {
        return self::getSkillManager()->getByName($name);
    }

    /**
     * 转换为数组格式（用于 API 请求）
     *
     * @return array
     */
    public function toArray(): array
    {
        $array = [
            'model' => $this->model,
            'messages' => $this->messages,
            'temperature' => $this->temperature,
            'max_tokens' => $this->maxTokens,
            'top_p' => $this->topP,
        ];

        if ($this->think !== null) {
            $array['think'] = $this->think;
        }

        // 添加工具定义
        if (count($this->tools) > 0) {
            $array['tools'] = array_map(fn($tool) => $tool->toArray(), $this->tools);
        }

        return array_merge($array, $this->parameters);
    }
}
