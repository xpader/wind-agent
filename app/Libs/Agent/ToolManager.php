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
     */
    public static function init(): void
    {
        $config = config('tools');

        // 加载启用的工具
        foreach ($config['enabled'] as $toolClass) {
            if (class_exists($toolClass)) {
                $tool = new $toolClass();
                if ($tool instanceof ToolInterface) {
                    self::$tools[$tool->getName()] = $tool;
                }
            }
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
     * @throws \RuntimeException
     */
    public static function execute(string $toolName, array $arguments): string
    {
        $tool = self::get($toolName);

        if ($tool === null) {
            throw new \RuntimeException("工具不存在：{$toolName}");
        }

        return $tool->execute($arguments);
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
