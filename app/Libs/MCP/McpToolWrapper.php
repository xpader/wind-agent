<?php

namespace App\Libs\MCP;

use App\Libs\Agent\ToolInterface;

/**
 * MCP 工具包装器
 *
 * 将 MCP 工具包装为 ToolInterface，使其可以被 Agent 系统使用
 */
class McpToolWrapper implements ToolInterface
{
    /** @var McpClient MCP 客户端 */
    private McpClient $client;

    /** @var array 工具定义 */
    private array $toolDefinition;

    /**
     * 构造函数
     *
     * @param McpClient $client MCP 客户端
     * @param array $toolDefinition 工具定义
     */
    public function __construct(McpClient $client, array $toolDefinition)
    {
        $this->client = $client;
        $this->toolDefinition = $toolDefinition;
    }

    /**
     * 获取工具名称
     *
     * 格式：{server_name}_{tool_name}
     *
     * @return string
     */
    public function getName(): string
    {
        $toolName = $this->toolDefinition['name'] ?? 'unknown';
        return $this->client->getName() . '_' . $toolName;
    }

    /**
     * 获取工具描述
     *
     * @return string
     */
    public function getDescription(): string
    {
        $description = $this->toolDefinition['description'] ?? '';

        // 添加服务器名称前缀，便于识别
        return "[{$this->client->getName()}] " . $description;
    }

    /**
     * 获取参数定义
     *
     * @return array
     */
    public function getParameters(): array
    {
        // MCP 工具使用 inputSchema，OpenAI 格式使用 parameters
        // 需要将 inputSchema 转换为 OpenAI 格式

        $inputSchema = $this->toolDefinition['inputSchema'] ?? [];

        // 如果已经是 OpenAI 格式，直接返回
        if (isset($inputSchema['type']) && $inputSchema['type'] === 'object') {
            return $inputSchema;
        }

        // 转换 JSON Schema 到 OpenAI 格式
        return [
            'type' => 'object',
            'properties' => $inputSchema['properties'] ?? [],
            'required' => $inputSchema['required'] ?? []
        ];
    }

    /**
     * 执行工具
     *
     * @param array $arguments 参数
     * @return string 执行结果
     */
    public function execute(array $arguments): string
    {
        $toolName = $this->toolDefinition['name'] ?? '';

        try {
            return $this->client->callTool($toolName, $arguments);
        } catch (\Throwable $e) {
            return "[MCP 工具调用失败] {$this->getName()}: " . $e->getMessage();
        }
    }

    /**
     * 转换为数组格式（用于 API 请求）
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->getName(),
                'description' => $this->getDescription(),
                'parameters' => $this->getParameters()
            ]
        ];
    }

    /**
     * 获取原始工具定义
     */
    public function getToolDefinition(): array
    {
        return $this->toolDefinition;
    }

    /**
     * 获取 MCP 客户端
     */
    public function getClient(): McpClient
    {
        return $this->client;
    }
}
