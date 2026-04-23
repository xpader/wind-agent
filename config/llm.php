<?php

/**
 * LLM 客户端配置
 *
 * 用于配置不同 LLM 提供商的 API Key 和相关设置
 */

return [
    /**
     * LLM 提供商配置
     */
    'providers' => [
        /**
         * MiniMax TokenPlan 配置
         */
        'minimax' => [
            'api_key' => env('MINIMAX_API_KEY', ''),
            'base_url' => 'https://api.minimaxi.com/v1',
        ],

        /**
         * MiniMax Anthropic 兼容接口配置
         */
        'minimax-anthropic' => [
            'api_key' => env('MINIMAX_API_KEY', ''),
            'base_url' => 'https://api.minimaxi.com/anthropic',
        ],

        /**
         * DeepSeek 配置
         */
        'deepseek' => [
            'api_key' => env('DEEPSEEK_API_KEY', ''),
            'base_url' => 'https://api.deepseek.com',
        ],

        /**
         * DeepSeek Anthropic 兼容接口配置
         */
        'deepseek-anthropic' => [
            'api_key' => env('DEEPSEEK_API_KEY', ''),
            'base_url' => 'https://api.deepseek.com/anthropic',
        ],
    ],

    /**
     * 默认配置
     */
    'defaults' => [
        'timeout' => 60,
        'max_tokens' => 32768,
        'temperature' => 0.7,
    ],
];
