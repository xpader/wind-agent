<?php

/**
 * 工具配置文件
 *
 * 配置哪些工具可以被 LLM 调用
 */
return [
    // 启用的工具列表
    'enabled' => [
        \App\Libs\Agent\Tools\ReadFileTool::class,
        \App\Libs\Agent\Tools\WriteFileTool::class,
        \App\Libs\Agent\Tools\EditFileTool::class,
        \App\Libs\Agent\Tools\AppendFileTool::class,
        \App\Libs\Agent\Tools\ExecTool::class,
        \App\Libs\Agent\Tools\ReadSkillTool::class,
        \App\Libs\Agent\Tools\WebSearchTool::class,
        \App\Libs\Agent\Tools\TimeTool::class,
    ],

    // 全局配置
    'options' => [
        // 工具执行超时时间（秒）
        'timeout' => 30,

        // 是否允许危险操作
        'allow_dangerous' => false,

        // 工具调用日志路径
        'log_path' => RUNTIME_DIR . '/log/tools.log',
    ],

    // WebSearch 工具配置
    'web_search' => [
        // Tavily API 密钥（从环境变量读取）
        'api_key' => env('TAVILY_API_KEY', ''),

        // 请求超时时间（秒）
        'timeout' => (int)env('TAVILY_TIMEOUT', 30),

        // 最大返回结果数
        'max_results' => (int)env('TAVILY_MAX_RESULTS', 10),
    ],
];