<?php

namespace App\Libs\MCP;

use App\Libs\Agent\ToolInterface;

/**
 * MCP 管理器
 *
 * 管理 MCP 服务器连接和工具生命周期
 */
class McpManager
{
    /** @var array<string, McpClientInterface> MCP 客户端映射表 */
    private static array $clients = [];

    /** @var array<string, ToolInterface> MCP 工具映射表 */
    private static array $tools = [];

    /** @var bool 是否已初始化 */
    private static bool $initialized = false;

    /** @var array<string, mixed> 配置 */
    private static array $config = [];

    /**
     * 初始化 MCP 管理器
     *
     * @param array<string>|null $enabledServers 启用的服务器列表（null 表示启用所有配置的服务器）
     * @return void
     * @throws \RuntimeException 如果初始化失败
     */
    public static function init(?array $enabledServers = null): void
    {
        if (self::$initialized) {
            return;
        }

        // 加载配置
        self::$config = config('mcp');

        // 初始化启用的服务器
        $servers = self::$config['servers'] ?? [];

        foreach ($servers as $serverName => $serverConfig) {
            // 检查是否启用
            if (!($serverConfig['enabled'] ?? false)) {
                continue;
            }

            // 如果指定了启用的服务器列表，检查是否在列表中
            if ($enabledServers !== null && !in_array($serverName, $enabledServers)) {
                continue;
            }

            try {
                echo "MCP: 正在初始化服务器 {$serverName}... ";
                self::initServer($serverName, $serverConfig);
                echo "成功.\n";
            } catch (\Throwable $e) {
                $continueOnError = self::$config['options']['continue_on_error'] ?? true;

                echo "失败: {$e->getMessage()}\n";

                if ($continueOnError) {
                    // 继续初始化其他服务器
                    continue;
                } else {
                    throw new \RuntimeException("MCP 服务器初始化失败 ({$serverName}): " . $e->getMessage(), 0, $e);
                }
            }
        }

        self::$initialized = true;
    }

    /**
     * 初始化单个服务器
     *
     * @param string $serverName 服务器名称
     * @param array $serverConfig 服务器配置
     * @return void
     * @throws \Exception
     */
    private static function initServer(string $serverName, array $serverConfig): void
    {
        $timeout = self::$config['options']['timeout'] ?? 30;

        // 根据配置类型创建客户端
        if (isset($serverConfig['url'])) {
            // HTTP 传输
            $url = $serverConfig['url'];
            $headers = $serverConfig['headers'] ?? [];

            $client = new McpHttpClient($serverName, $url, $headers, null, $timeout);
        } else {
            // stdio 传输（默认）
            $command = $serverConfig['command'] ?? '';
            $args = $serverConfig['args'] ?? [];
            $env = $serverConfig['env'] ?? [];

            $client = new McpStdioClient($serverName, $command, $args, $env);
        }

        // 初始化客户端
        $client->initialize();

        // 保存客户端
        self::$clients[$serverName] = $client;

        // 获取工具列表
        $tools = $client->listTools();

        // 包装工具并保存
        foreach ($tools as $toolDefinition) {
            $wrapper = new McpToolWrapper($client, $toolDefinition);
            $toolName = $wrapper->getName();
            self::$tools[$toolName] = $wrapper;
        }
    }

    /**
     * 获取所有 MCP 工具
     *
     * @return array<ToolInterface>
     */
    public static function getAllTools(): array
    {
        if (!self::$initialized) {
            self::init();
        }

        return array_values(self::$tools);
    }

    /**
     * 获取指定名称的工具
     *
     * @param string $name 工具名称
     * @return ToolInterface|null
     */
    public static function getTool(string $name): ?ToolInterface
    {
        if (!self::$initialized) {
            self::init();
        }

        return self::$tools[$name] ?? null;
    }

    /**
     * 获取所有 MCP 客户端
     *
     * @return array<string, McpClientInterface>
     */
    public static function getClients(): array
    {
        if (!self::$initialized) {
            self::init();
        }

        return self::$clients;
    }

    /**
     * 获取指定名称的客户端
     *
     * @param string $name 服务器名称
     * @return McpClientInterface|null
     */
    public static function getClient(string $name): ?McpClientInterface
    {
        if (!self::$initialized) {
            self::init();
        }

        return self::$clients[$name] ?? null;
    }

    /**
     * 检查工具是否存在
     *
     * @param string $name 工具名称
     * @return bool
     */
    public static function hasTool(string $name): bool
    {
        if (!self::$initialized) {
            self::init();
        }

        return isset(self::$tools[$name]);
    }

    /**
     * 获取工具数量
     *
     * @return int
     */
    public static function getToolCount(): int
    {
        if (!self::$initialized) {
            self::init();
        }

        return count(self::$tools);
    }

    /**
     * 获取客户端数量
     *
     * @return int
     */
    public static function getClientCount(): int
    {
        if (!self::$initialized) {
            self::init();
        }

        return count(self::$clients);
    }

    /**
     * 关闭所有连接
     *
     * @return void
     */
    public static function closeAll(): void
    {
        foreach (self::$clients as $client) {
            try {
                $client->close();
            } catch (\Throwable $e) {
                // 忽略关闭时的错误
                error_log("MCP 客户端关闭失败 ({$client->getName()}): " . $e->getMessage());
            }
        }

        self::$clients = [];
        self::$tools = [];
        self::$initialized = false;
    }

    /**
     * 重置管理器（主要用于测试）
     *
     * @return void
     */
    public static function reset(): void
    {
        self::closeAll();
        self::$config = [];
    }
}
