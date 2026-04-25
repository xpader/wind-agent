<?php
/**
 * MCP 服务器配置文件
 *
 * 配置可用的 MCP (Model Context Protocol) 服务器
 * MCP 服务器可以为 AI Agent 提供额外的工具和资源
 *
 * 环境变量说明：
 * - MCP_<SERVER_NAME>: 启用服务器（设置为 true）
 * - MCP_<SERVER_NAME>_<VAR_NAME>: 服务器专用环境变量
 * - MCP_TIMEOUT: 全局连接超时时间
 * - MCP_REQUEST_TIMEOUT: 全局请求超时时间
 *
 * 传输方式说明：
 * - stdio 传输：使用 'command' 和 'args' 启动本地进程
 * - HTTP 传输：使用 'url' 连接远程 MCP 服务器
 */
return [
    'servers' => [
        // Brave 搜索服务器
        'brave-search' => [
            'enabled' => env('MCP_BRAVE_SEARCH') === true,
            'command' => 'npx',
            'args' => ['-y', '@modelcontextprotocol/server-brave-search'],
            'env' => [
                'BRAVE_API_KEY' => env('MCP_BRAVE_SEARCH_API_KEY', ''),
            ],
        ],

        // GitHub 服务器
        'github' => [
            'enabled' => env('MCP_GITHUB') === true,
            'command' => 'npx',
            'args' => ['-y', '@modelcontextprotocol/server-github'],
            'env' => [
                'GITHUB_TOKEN' => env('MCP_GITHUB_TOKEN', ''),
            ],
        ],

        // Fetch HTTP 请求服务器
        'fetch' => [
            'enabled' => env('MCP_FETCH') === true,
            'command' => 'npx',
            'args' => ['-y', '@tokenizin/mcp-npx-fetch'],
            'env' => [],
        ],

        // Memory 内存存储服务器
        'memory' => [
            'enabled' => env('MCP_MEMORY') === true,
            'command' => 'npx',
            'args' => ['-y', '@modelcontextprotocol/server-memory'],
            'env' => [],
        ],

        // MiniMax 编码计划服务器
        'minimax' => [
            'enabled' => env('MCP_MINIMAX') === true,
            'command' => 'uvx',
            'args' => ['minimax-coding-plan-mcp', '-y'],
            'env' => [
                'MINIMAX_API_HOST' => env('MINIMAX_API_HOST', 'https://api.minimaxi.com'),
                'MINIMAX_API_KEY' => env('MINIMAX_API_KEY', ''),
            ],
        ],

        // Exa HTTP MCP 服务器（AI 搜索服务）
        'exa' => [
            'enabled' => env('MCP_EXA') === true,
            'url' => 'https://mcp.exa.ai/mcp',
            'headers' => [
                'Authorization' => 'Bearer ' . env('MCP_EXA_API_KEY', ''),
            ],
        ],

        // 智谱 AI Web Reader 服务器
        'zhipu-web-reader' => [
            'enabled' => env('MCP_ZHIPU_WEB_READER') === true,
            'url' => 'https://open.bigmodel.cn/api/mcp/web_reader/mcp',
            'headers' => [
                'Authorization' => 'Bearer ' . env('MCP_ZHIPU_WEB_READER_API_KEY', ''),
            ],
        ],

        // Context7 上下文管理服务器
        'context7' => [
            'enabled' => env('MCP_CONTEXT7') === true,
            'url' => 'https://mcp.context7.com/mcp',
        ],
    ],

    // 全局配置
    'options' => [
        'timeout' => (int)env('MCP_TIMEOUT', 30),
        'request_timeout' => (int)env('MCP_REQUEST_TIMEOUT', 60),
        'max_retries' => (int)env('MCP_MAX_RETRIES', 3),
        'continue_on_error' => env('MCP_CONTINUE_ON_ERROR', 'true') === 'true',
    ],
];
