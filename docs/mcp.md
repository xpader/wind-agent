# MCP 配置指南

## 概述

MCP (Model Context Protocol) 是一个开放协议，允许 AI 应用连接到外部数据源和工具。

本项目已实现完整的 MCP 客户端支持，Agent 可以调用 MCP 服务器提供的工具来扩展其能力。

## 快速开始

### 1. 启用 MCP 服务器

编辑 `.env` 文件，设置要启用的 MCP 服务器：

```bash
# 启用 Brave 搜索服务器
MCP_BRAVE_SEARCH=true
MCP_BRAVE_SEARCH_API_KEY=your_api_key_here

# 启用 GitHub 服务器
MCP_GITHUB=true
MCP_GITHUB_TOKEN=your_token_here
```

### 2. 测试 MCP 功能

```bash
# 列出所有可用的 MCP 服务器和工具
./wind test:mcp --list-servers
./wind test:mcp --list-tools

# 使用 MCP 工具测试 Agent
./wind test:agent --with-mcp --message "搜索 PHP 8.2 的新特性"

# 指定特定 MCP 服务器
./wind test:agent --with-mcp --mcp-servers=brave-search --message "搜索最新新闻"

# 交互式测试
./wind test:agent --with-mcp --interactive
```

## 支持的 MCP 服务器

### 1. Brave 搜索服务器 (brave-search)

**功能**: 允许 Agent 进行网络搜索

**获取 API 密钥**: https://search.brave.com/search/api

**环境变量**:
```bash
# 启用服务器
MCP_BRAVE_SEARCH=true

# API 密钥（必需）
MCP_BRAVE_SEARCH_API_KEY=BSGyour_api_key_here
```

**使用示例**:
```bash
# .env 配置
MCP_BRAVE_SEARCH=true
MCP_BRAVE_SEARCH_API_KEY=BSGyour_api_key_here

# 测试
./wind test:agent --with-mcp --mcp-servers=brave-search \
  --message "搜索 PHP 8.2 的新特性"
```

### 2. GitHub 服务器 (github)

**功能**: 允许 Agent 访问 GitHub 仓库信息

**获取 Token**: https://github.com/settings/tokens

**环境变量**:
```bash
# 启用服务器
MCP_GITHUB=true

# GitHub Personal Access Token（必需）
MCP_GITHUB_TOKEN=ghp_your_token_here
```

**使用示例**:
```bash
# .env 配置
MCP_GITHUB=true
MCP_GITHUB_TOKEN=ghp_your_token_here

# 测试
./wind test:agent --with-mcp --mcp-servers=github \
  --message "查看 symfony/console 仓库的最新 release"
```

### 3. Fetch HTTP 请求服务器 (fetch)

**功能**: 允许 Agent 发送 HTTP 请求

**环境变量**:
```bash
# 启用服务器
MCP_FETCH=true
```

**使用示例**:
```bash
# .env 配置
MCP_FETCH=true

# 测试
./wind test:agent --with-mcp --mcp-servers=fetch \
  --message "访问 https://example.com 并返回内容"
```

### 4. Memory 内存存储服务器 (memory)

**功能**: 为 Agent 提供内存存储功能

**环境变量**:
```bash
# 启用服务器
MCP_MEMORY=true
```

**使用示例**:
```bash
# .env 配置
MCP_MEMORY=true

# 测试
./wind test:agent --with-mcp --mcp-servers=memory \
  --message "记住我的名字是张三"
```

## 全局配置

```bash
# 连接超时时间（秒）
MCP_TIMEOUT=30

# 请求超时时间（秒）
MCP_REQUEST_TIMEOUT=60

# 最大重试次数
MCP_MAX_RETRIES=3

# 初始化失败时是否继续
MCP_CONTINUE_ON_ERROR=true
```

## 自定义 MCP 服务器

在 `config/mcp.php` 中添加自定义服务器配置：

```php
'servers' => [
    'my-server' => [
        'enabled' => env('MCP_MY_SERVER') === true,
        'command' => 'npx',
        'args' => ['-y', '@my-org/my-mcp-server'],
        'env' => [
            'MY_API_KEY' => env('MCP_MY_SERVER_API_KEY', ''),
        ],
    ],
],
```

在 `.env` 中配置：

```bash
MCP_MY_SERVER=true
MCP_MY_SERVER_API_KEY=your_api_key_here
```

## 工具调用

MCP 工具会自动加载到 Agent 中，工具名称格式为：`{server_name}_{tool_name}`

**示例**:
- Brave 搜索: `brave-search_brave_web_search`
- GitHub: `github_create_issue`, `github_list_issues`
- Fetch: `fetch_fetch`
- Memory: `memory_write`, `memory_read`

## 测试和调试

### 测试命令

```bash
# 列出所有服务器
./wind test:mcp --list-servers

# 列出所有工具
./wind test:mcp --list-tools

# 测试特定工具调用
./wind test:mcp --test-call brave-search_brave_web_search \
  --test-args '{"query": "PHP", "count": 5}'
```

### 常见问题

**问题**: MCP 服务器启动失败
- **解决**: 检查 `npx` 是否正确安装
- **解决**: 检查环境变量配置是否正确
- **解决**: 查看日志文件获取详细错误信息

**问题**: 工具调用超时
- **解决**: 增加 `MCP_REQUEST_TIMEOUT` 值
- **解决**: 检查网络连接

**问题**: 环境变量未生效
- **解决**: 确保环境变量设置为 `true`（不是字符串 `'true'`）
- **解决**: 检查 `.env` 文件是否在项目根目录
- **解决**: 重新启动应用以加载新的环境变量

## 技术实现

### 核心组件

项目实现了完整的 MCP 客户端架构：

- **McpClientInterface** - MCP 客户端统一接口
- **McpStdioClient** - stdio 传输客户端（使用原生 PHP proc_open）
- **McpHttpClient** - HTTP 传输客户端（基于 Streamable HTTP 规范）
- **McpManager** - MCP 服务器和工具管理器
- **McpToolWrapper** - 将 MCP 工具适配到 ToolInterface

### 传输方式

#### 1. stdio 传输（本地进程）

使用 `command` 和 `args` 启动本地 MCP 服务器进程，适用于本地工具和脚本。

**实现细节**：
- 使用 `proc_open()` 和管道进行 stdio 通信
- 使用 `fgets()` 阻塞读取 JSON-RPC 响应
- 使用 `fflush()` 确保数据立即发送
- 在发送 `initialized` 通知后需要 `sleep(1)` 延迟
- 必须正确设置 PATH 环境变量以便 `npx` 找到 MCP 服务器

**已测试的 stdio 服务器**：
- `@tokenizin/mcp-npx-fetch` - HTTP 请求工具（fetch_html, fetch_markdown, fetch_txt, fetch_json）
- `@modelcontextprotocol/server-brave-search` - Brave 搜索
- `@modelcontextprotocol/server-github` - GitHub 集成
- `@modelcontextprotocol/server-memory` - 内存存储
- `minimax-coding-plan-mcp` - MiniMax 编码计划

#### 2. HTTP 传输（远程服务器）

使用 `url` 连接远程 MCP 服务器，支持云服务和远程 MCP 端点。

**实现细节**：
- 使用 AMPHP HttpClient 发送 HTTP POST 请求
- 支持 MCP 会话管理（MCP-Session-Id）
- 遵循 MCP Streamable HTTP 规范（2025-11-25）
- 包含必需的 HTTP 头：Accept, MCP-Protocol-Version
- 支持自定义认证头（Authorization, X-API-Key 等）

**已测试的 HTTP 服务器**：
- `exa` - AI 搜索服务 (https://mcp.exa.ai/mcp)

### JSON-RPC 参数格式要求

MCP 协议严格要求参数格式：
- **空参数必须是对象 `{}` 而不是数组 `[]`**
- 正确：`'params' => new \stdClass()` 生成 `"params": {}`
- 错误：`'params' => []` 生成 `"params": []`
- 服务器会拒绝空数组格式，返回 "Invalid input: expected object, received array" 错误
- 有参数时使用关联数组：`'params' => ['url' => 'xxx']` 生成 `"params": {"url": "xxx"}`
- 这个要求同时适用于：stdio 和 HTTP 两种传输方式

### 配置文件

MCP 服务器配置位于 `config/mcp.php`，支持两种传输方式的配置：

```php
// stdio 传输（本地进程）
'fetch' => [
    'enabled' => env('MCP_FETCH') === true,
    'command' => 'npx',
    'args' => ['-y', '@tokenizin/mcp-npx-fetch'],
    'env' => [],
],

// HTTP 传输（远程服务器）
'exa' => [
    'enabled' => env('MCP_EXA') === true,
    'url' => 'https://mcp.exa.ai/mcp',
    'headers' => [
        'Authorization' => 'Bearer ' . env('MCP_EXA_API_KEY', ''),
    ],
],
```

## 相关资源

- [MCP 官方文档](https://modelcontextprotocol.io/)
- [MCP 规范](https://spec.modelcontextprotocol.io/)
- [MCP 服务器列表](https://github.com/modelcontextprotocol/servers)
- 详见 `docs/mcp-cache.md` - MCP 工具缓存机制详解

## 文档更新记录

**2026-04-26**
- 新增技术实现部分，详细说明核心组件和传输方式
- 补充 stdio 和 HTTP 两种传输方式的实现细节
- 添加已测试的 MCP 服务器列表
- 强调 JSON-RPC 参数格式要求
