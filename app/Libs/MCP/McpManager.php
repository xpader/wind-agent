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

    /** @var string 缓存文件路径 */
    private static string $cacheFile = '';

    /** @var int 缓存有效期（秒） */
    private static int $cacheTtl = 7200; // 2 小时

    /**
     * 获取缓存文件路径
     *
     * @return string
     */
    private static function getCacheFile(): string
    {
        if (self::$cacheFile === '') {
            self::$cacheFile = \BASE_DIR . '/workspace/states/mcp.cache.json';
        }
        return self::$cacheFile;
    }

    /**
     * 从缓存加载工具定义
     *
     * @param string $serverName 服务器名称
     * @param array $serverConfig 服务器配置
     * @return array|null 工具定义列表，缓存无效时返回 null
     */
    private static function loadFromCache(string $serverName, array $serverConfig): ?array
    {
        $cacheFile = self::getCacheFile();

        if (!file_exists($cacheFile)) {
            return null;
        }

        $cacheContent = file_get_contents($cacheFile);
        if ($cacheContent === false) {
            return null;
        }

        $cache = json_decode($cacheContent, true);
        if (!is_array($cache)) {
            return null;
        }

        // 检查是否有该服务器的缓存
        if (!isset($cache[$serverName])) {
            return null;
        }

        $serverCache = $cache[$serverName];

        // 检查缓存是否过期
        $cacheTime = $serverCache['time'] ?? 0;
        if (time() - $cacheTime > self::$cacheTtl) {
            return null;
        }

        // 检查配置是否变化（通过哈希比较）
        $configHash = self::hashConfig($serverConfig);
        if ($serverCache['config_hash'] !== $configHash) {
            return null;
        }

        return $serverCache['tools'] ?? null;
    }

    /**
     * 保存工具定义到缓存
     *
     * @param string $serverName 服务器名称
     * @param array $serverConfig 服务器配置
     * @param array $tools 工具定义列表
     * @return void
     */
    private static function saveToCache(string $serverName, array $serverConfig, array $tools): void
    {
        $cacheFile = self::getCacheFile();

        // 读取现有缓存
        $cache = [];
        if (file_exists($cacheFile)) {
            $cacheContent = file_get_contents($cacheFile);
            if ($cacheContent !== false) {
                $decoded = json_decode($cacheContent, true);
                if (is_array($decoded)) {
                    $cache = $decoded;
                }
            }
        }

        // 更新该服务器的缓存
        $cache[$serverName] = [
            'time' => time(),
            'config_hash' => self::hashConfig($serverConfig),
            'tools' => $tools,
        ];

        // 保存到文件
        $json = json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json !== false) {
            file_put_contents($cacheFile, $json, LOCK_EX);
        }
    }

    /**
     * 计算配置哈希
     *
     * @param array $config 配置
     * @return string
     */
    private static function hashConfig(array $config): string
    {
        // 移除 enabled 字段（因为启用状态不影响工具定义）
        $hashConfig = $config;
        unset($hashConfig['enabled']);
        return md5(json_encode($hashConfig));
    }

    /**
     * 清除所有缓存
     *
     * @return void
     */
    public static function clearCache(): void
    {
        $cacheFile = self::getCacheFile();
        if (file_exists($cacheFile)) {
            unlink($cacheFile);
        }
    }

    /**
     * 清除指定服务器的缓存
     *
     * @param string $serverName 服务器名称
     * @return void
     */
    public static function clearServerCache(string $serverName): void
    {
        $cacheFile = self::getCacheFile();

        if (!file_exists($cacheFile)) {
            return;
        }

        $cacheContent = file_get_contents($cacheFile);
        if ($cacheContent === false) {
            return;
        }

        $cache = json_decode($cacheContent, true);
        if (!is_array($cache)) {
            return;
        }

        if (isset($cache[$serverName])) {
            unset($cache[$serverName]);
            $json = json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if ($json !== false) {
                file_put_contents($cacheFile, $json, LOCK_EX);
            }
        }
    }

    /**
     * 获取缓存状态信息
     *
     * @return array
     */
    public static function getCacheStatus(): array
    {
        $cacheFile = self::getCacheFile();

        if (!file_exists($cacheFile)) {
            return [
                'exists' => false,
                'servers' => [],
            ];
        }

        $cacheContent = file_get_contents($cacheFile);
        if ($cacheContent === false) {
            return [
                'exists' => false,
                'servers' => [],
            ];
        }

        $cache = json_decode($cacheContent, true);
        if (!is_array($cache)) {
            return [
                'exists' => false,
                'servers' => [],
            ];
        }

        $servers = [];
        foreach ($cache as $serverName => $serverCache) {
            $cacheTime = $serverCache['time'] ?? 0;
            $age = time() - $cacheTime;
            $expired = $age > self::$cacheTtl;

            $servers[$serverName] = [
                'cached_at' => date('Y-m-d H:i:s', $cacheTime),
                'age_seconds' => $age,
                'expired' => $expired,
                'tool_count' => count($serverCache['tools'] ?? []),
            ];
        }

        return [
            'exists' => true,
            'servers' => $servers,
        ];
    }

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
                $result = self::initServer($serverName, $serverConfig);
                if ($result === 'cached') {
                    echo "使用缓存 ({$serverName}).\n";
                } else {
                    echo "成功.\n";
                }
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
     * @return string 'cached' 如果使用了缓存，'loaded' 如果重新加载
     * @throws \Exception
     */
    private static function initServer(string $serverName, array $serverConfig): string
    {
        $timeout = self::$config['options']['timeout'] ?? 30;

        // 尝试从缓存加载工具定义
        $cachedTools = self::loadFromCache($serverName, $serverConfig);

        if ($cachedTools !== null) {
            // 缓存有效，使用缓存的工具定义
            // 创建客户端（但不初始化，延迟到首次工具调用）
            if (isset($serverConfig['url'])) {
                $url = $serverConfig['url'];
                $headers = $serverConfig['headers'] ?? [];
                $client = new McpHttpClient($serverName, $url, $headers, null, $timeout);
            } else {
                $command = $serverConfig['command'] ?? '';
                $args = $serverConfig['args'] ?? [];
                $env = $serverConfig['env'] ?? [];
                $client = new McpStdioClient($serverName, $command, $args, $env);
            }

            // 保存客户端（不初始化，延迟到首次工具调用）
            self::$clients[$serverName] = $client;

            // 使用缓存的工具定义创建包装器
            foreach ($cachedTools as $toolDefinition) {
                $wrapper = new McpToolWrapper($client, $toolDefinition);
                $toolName = $wrapper->getName();
                self::$tools[$toolName] = $wrapper;
            }

            return 'cached';
        }

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

        // 保存到缓存
        self::saveToCache($serverName, $serverConfig, $tools);

        // 包装工具并保存
        foreach ($tools as $toolDefinition) {
            $wrapper = new McpToolWrapper($client, $toolDefinition);
            $toolName = $wrapper->getName();
            self::$tools[$toolName] = $wrapper;
        }

        return 'loaded';
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
