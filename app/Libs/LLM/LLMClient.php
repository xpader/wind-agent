<?php

namespace App\Libs\LLM;

use App\Libs\LLM\LLMRequest;
use App\Libs\LLM\LLMResponse;

/**
 * 大模型客户端接口
 *
 * 定义与大模型服务交互的基本方法，支持流式输出和高级参数
 * 所有方法都返回统一的 LLMResponse 对象
 */
interface LLMClient
{
    /**
     * 创建聊天补全请求
     *
     * @param LLMRequest $request 请求对象
     * @return LLMResponse 返回响应对象
     */
    public function chat(LLMRequest $request): LLMResponse;

    /**
     * 创建聊天补全请求（流式）
     *
     * @param LLMRequest $request 请求对象
     * @param callable $callback 接收流式数据的回调 function(LLMResponse $response)
     * @return void
     */
    public function chatStream(LLMRequest $request, callable $callback): void;

    /**
     * 获取模型列表
     *
     * @return array
     */
    public function listModels(): array;

    /**
     * 获取当前使用的 API 密钥
     *
     * @return string
     */
    public function getApiKey(): string;

    /**
     * 获取当前基础 URL
     *
     * @return string
     */
    public function getBaseUrl(): string;

    /**
     * 设置默认选项
     *
     * @param array $defaults 默认选项
     * @return void
     */
    public function setDefaultOptions(array $defaults): void;
}
