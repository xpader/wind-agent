<?php

namespace App\Libs\Agent;

/**
 * 工具接口
 *
 * 所有 LLM 可调用的工具都需要实现此接口
 */
interface ToolInterface
{
    /**
     * 获取工具名称
     *
     * @return string
     */
    public function getName(): string;

    /**
     * 获取工具描述
     *
     * @return string
     */
    public function getDescription(): string;

    /**
     * 获取参数定义（JSON Schema 格式）
     *
     * @return array
     */
    public function getParameters(): array;

    /**
     * 执行工具
     *
     * @param array $arguments 参数数组
     * @return string 执行结果
     */
    public function execute(array $arguments): string;

    /**
     * 转换为数组格式（用于 API 请求）
     *
     * @return array
     */
    public function toArray(): array;
}
