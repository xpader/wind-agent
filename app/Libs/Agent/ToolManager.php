<?php

namespace App\Libs\Agent;

/**
 * 工具管理器
 *
 * 从配置文件加载和管理工具
 */
class ToolManager
{
    /** @var array<string, ToolInterface> 工具映射表 */
    private static array $tools = [];

    /**
     * 初始化工具管理器
     *
     * @return void
     * @throws \RuntimeException 如果工具加载失败
     */
    public static function init(): void
    {
        $config = config('tools');

        // 加载启用的工具
        foreach ($config['enabled'] as $toolClass) {
            if (!class_exists($toolClass)) {
                throw new \RuntimeException("工具类不存在：{$toolClass}");
            }

            $tool = new $toolClass();
            if (!($tool instanceof ToolInterface)) {
                throw new \RuntimeException("工具类必须实现 ToolInterface 接口：{$toolClass}");
            }

            self::$tools[$tool->getName()] = $tool;
        }
    }

    /**
     * 获取工具
     *
     * @param string $name 工具名称
     * @return ToolInterface|null
     */
    public static function get(string $name): ?ToolInterface
    {
        if (empty(self::$tools)) {
            self::init();
        }

        return self::$tools[$name] ?? null;
    }

    /**
     * 注册工具（用于 MCP 工具等动态工具）
     *
     * @param ToolInterface $tool 工具实例
     * @return void
     */
    public static function register(ToolInterface $tool): void
    {
        self::$tools[$tool->getName()] = $tool;
    }

    /**
     * 获取所有工具
     *
     * @return array<ToolInterface>
     */
    public static function getAll(): array
    {
        if (empty(self::$tools)) {
            self::init();
        }

        return array_values(self::$tools);
    }

    /**
     * 执行工具调用
     *
     * @param string $toolName 工具名称
     * @param array $arguments 参数
     * @return string 执行结果
     * @throws \RuntimeException 仅当工具不存在时抛出异常
     */
    public static function execute(string $toolName, array $arguments): string
    {
        $tool = self::get($toolName);

        if ($tool === null) {
            throw new \RuntimeException("工具不存在：{$toolName}");
        }

        try {
            return $tool->execute($arguments);
        } catch (\Throwable $e) {
            // 捕获工具执行异常，返回带有明确失败标识的错误消息
            return "[{$toolName}] 调用失败：{$e->getMessage()}";
        }
    }

    /**
     * 检查工具是否存在
     *
     * @param string $name 工具名称
     * @return bool
     */
    public static function has(string $name): bool
    {
        if (empty(self::$tools)) {
            self::init();
        }

        return isset(self::$tools[$name]);
    }
}
