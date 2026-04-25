<?php

namespace App\Libs\MCP;

/**
 * MCP 客户端接口
 *
 * 定义统一的 MCP 客户端接口，支持多种传输方式（stdio、HTTP 等）
 */
interface McpClientInterface
{
    /**
     * 获取客户端名称
     *
     * @return string
     */
    public function getName(): string;

    /**
     * 获取服务器能力
     *
     * @return array 服务器能力信息
     */
    public function getCapabilities(): array;

    /**
     * 初始化客户端
     *
     * 执行初始化握手，建立与 MCP 服务器的连接
     *
     * @return void
     * @throws \Exception 如果初始化失败
     */
    public function initialize(): void;

    /**
     * 列出可用的工具
     *
     * @return array 工具列表
     * @throws \Exception 如果客户端未初始化
     */
    public function listTools(): array;

    /**
     * 调用工具
     *
     * @param string $name 工具名称
     * @param array $arguments 工具参数
     * @return string 工具执行结果
     * @throws \Exception 如果客户端未初始化或工具调用失败
     */
    public function callTool(string $name, array $arguments): string;

    /**
     * 列出可用的资源
     *
     * @return array 资源列表
     * @throws \Exception 如果客户端未初始化
     */
    public function listResources(): array;

    /**
     * 读取资源内容
     *
     * @param string $uri 资源 URI
     * @return string 资源内容
     * @throws \Exception 如果客户端未初始化或资源读取失败
     */
    public function readResource(string $uri): string;

    /**
     * 列出可用的提示词
     *
     * @return array 提示词列表
     * @throws \Exception 如果客户端未初始化
     */
    public function listPrompts(): array;

    /**
     * 获取提示词内容
     *
     * @param string $name 提示词名称
     * @param array $arguments 提示词参数
     * @return string 提示词内容
     * @throws \Exception 如果客户端未初始化或提示词获取失败
     */
    public function getPrompt(string $name, array $arguments = []): string;

    /**
     * 关闭客户端
     *
     * 释放资源，关闭连接
     *
     * @return void
     */
    public function close(): void;
}
