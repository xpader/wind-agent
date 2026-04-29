<?php

/**
 * LLM 客户端配置
 *
 * 用于配置不同 LLM 提供商的 API Key 和相关设置
 */

return [
    /**
     * LLM 提供商配置
     *
     * 每个提供商可以指定：
     * - client: 使用的 Client 类（openai=OpenAiClient, anthropic=AnthropicClient, 或完整类名）
     * - api_key: API 密钥
     * - base_url: API 基础 URL
     * - version: API 版本（仅 Anthropic 兼容接口需要）
     * - default_options: 默认请求参数（temperature, top_p 等），如果不设置则不会传递这些参数
     */
    'providers' => [
        /**
         * Ollama 配置
         */
        'ollama' => [
            'client' => \App\Libs\LLM\Clients\OllamaClient::class,
            'base_url' => 'http://localhost:11434',
        ],

        /**
         * OpenAI 配置
         */
        'openai' => [
            'client' => 'openai', // 等同于 \App\Libs\LLM\Clients\OpenAiClient::class
            'api_key' => env('OPENAI_API_KEY', ''),
            'base_url' => 'https://api.openai.com/v1',
        ],

        /**
         * MiniMax TokenPlan 配置
         */
        'minimax' => [
            'client' => \App\Libs\LLM\Clients\MiniMaxClient::class,
            'api_key' => env('MINIMAX_API_KEY', ''),
            'base_url' => 'https://api.minimaxi.com/v1',
        ],

        /**
         * MiniMax Anthropic 兼容接口配置
         */
        'minimax-anthropic' => [
            'client' => 'anthropic', // 等同于 \App\Libs\LLM\Clients\AnthropicClient::class
            'api_key' => env('MINIMAX_API_KEY', ''),
            'base_url' => 'https://api.minimaxi.com/anthropic',
        ],

        /**
         * DeepSeek 配置
         */
        'deepseek' => [
            'client' => 'openai', // 使用 OpenAI 兼容接口
            'api_key' => env('DEEPSEEK_API_KEY', ''),
            'base_url' => 'https://api.deepseek.com',
        ],

        /**
         * DeepSeek Anthropic 兼容接口配置
         */
        'deepseek-anthropic' => [
            'client' => 'anthropic', // 使用 Anthropic 兼容接口
            'api_key' => env('DEEPSEEK_API_KEY', ''),
            'base_url' => 'https://api.deepseek.com/anthropic',
        ],

        /**
         * Anthropic 官方接口配置
         */
        'anthropic' => [
            'client' => 'anthropic',
            'api_key' => env('ANTHROPIC_API_KEY', ''),
            'base_url' => 'https://api.anthropic.com',
        ],

        /**
         * Kimi (月之暗面) 配置
         */
        'kimi' => [
            'client' => 'openai', // 使用 OpenAI 兼容接口
            'api_key' => env('KIMI_API_KEY', ''),
            'base_url' => 'https://api.moonshot.cn/v1',
            // 如果需要设置 temperature 和 top_p，取消下面的注释
            // 'default_options' => [
            //     'temperature' => 0.7,
            //     'top_p' => 1.0,
            // ],
        ],

        /**
         * 智谱 Coding Plan (GLM) 配置
         */
        'zai-coding-plan' => [
            'client' => 'openai', // 使用 OpenAI 兼容接口
            'api_key' => env('ZHIPU_API_KEY', ''),
            'base_url' => 'https://open.bigmodel.cn/api/coding/paas/v4/',
        ],
    ],

    /**
     * 默认配置
     */
    'defaults' => [
        'timeout' => 60,
        'max_tokens' => 32768,
    ],
];
