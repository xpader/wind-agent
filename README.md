# Wind Chat

基于 **Wind Framework** 的 AI Agent 协程应用项目。

## 简介

Wind Chat 是一个功能完整的 AI Agent 应用，集成了多个 LLM 平台、工具调用、技能系统和 MCP (Model Context Protocol) 支持。

## 特性

- **多平台 LLM 支持** - OpenAI、Ollama、Anthropic、MiniMax 等
- **工具调用** - 完整的 Tool Call 支持，内置文件读写、命令执行等工具
- **技能系统** - 动态加载和使用扩展技能
- **MCP 协议** - 支持 stdio 和 HTTP 两种传输方式
- **Agent 会话管理** - 多轮对话、自动标题生成
- **协程架构** - 基于 Workerman 和 AMPHP 的高性能协程框架
- **TUI 支持** - 终端用户界面（基于 php-tui）

## 环境要求

- PHP 8.1+
- Composer
- Redis（可选，用于队列和缓存）
- MySQL（可选，用于数据持久化）

## 安装

```bash
# 克隆项目
git clone <repository-url>
cd wind-chat

# 安装依赖
composer install

# 复制环境配置
cp .env.example .env

# 编辑配置文件
vim .env
vim config/mcp.php
```

## Console 命令

### 测试命令

```bash
# 统一聊天测试
./wind test:chat --prompt "你好" --model qwen3.5:4b
./wind test:chat --prompt "写一首诗" --stream
./wind test:chat --prompt "请读取 README.md" --with-tools
./wind test:chat --prompt "使用技能帮我处理任务" --with-skills
./wind test:chat --list-models

# Agent 会话测试
./wind test:agent --with-mcp --mcp-servers=fetch,exa

# MCP 测试
./wind test:mcp --list-servers
./wind test:mcp --list-tools

# TUI Demo
./wind tui:demo
```

### 创建命令

```bash
# 创建新的 Console 命令
./wind make:command <command-name> <ClassName>
```

## 核心架构

### 目录结构

```
wind-chat/
├── app/                    # 应用代码
│   ├── Controller/         # HTTP 控制器
│   ├── Middleware/         # 中间件
│   ├── Listener/           # 事件监听器
│   ├── Command/            # Console 命令
│   └── Libs/               # 核心库
│       ├── LLM/            # LLM 客户端
│       └── Agent/          # Agent 功能
├── config/                 # 配置文件
├── workspace/              # 工作空间
│   └── skills/             # 技能定义
├── runtime/                # 运行时文件
└── static/                 # 静态资源
```

### LLM 客户端

统一的 LLM 客户端抽象架构，支持多平台适配：

- `LLMClient` 接口 - 统一的 LLM 服务交互接口
- `LLMRequest` 类 - 请求封装，支持链式调用
- `LLMResponse` 类 - 响应数据封装，支持流式处理
- 平台适配器：`OpenAiClient`、`OllamaClient`、`AnthropicClient`、`MiniMaxClient`

### 工具调用

完整的 Tool Call 支持：

- `ToolInterface` - 工具接口
- `ToolManager` - 工具管理器
- 内置工具：ReadFileTool、WriteFileTool、ExecTool、ReadSkillTool
- MCP 工具集成（通过 `McpToolWrapper`）

### 技能系统

动态加载扩展技能：

- `SkillManager` - 技能管理器
- `Skill` - 技能配置类（从 SKILL.md 加载）
- 支持技能文档、示例和资源文件

### MCP 支持

完整的 MCP 客户端实现：

- `McpStdioClient` - stdio 传输（本地进程）
- `McpHttpClient` - HTTP 传输（远程服务器）
- `McpManager` - MCP 服务器和工具管理

## 配置

### 重要配置文件

- `config/server.php` - 服务器、Channel、Task Worker
- `config/routes.php` - 路由定义
- `config/middlewares.php` - 全局中间件
- `config/database.php` - 数据库连接池
- `config/redis.php` - Redis 配置
- `config/queue.php` - 队列配置
- `config/mcp.php` - MCP 服务器配置
- `config/tools.php` - 工具启用配置

### 环境变量

通过 `.env` 文件或系统环境变量配置：

- `HTTP_SERVER_LISTEN` - HTTP 服务器监听地址
- `DB_*` - 数据库配置
- `REDIS_*` - Redis 配置
- `MCP_*` - MCP 服务器配置

## 技术栈

- **Wind Framework** - PHP 协程框架
- **Workerman** - 多进程架构
- **AMPHP** - 协程组件
- **php-tui** - 终端 UI 库

## 开发指南

详见 [CLAUDE.md](./CLAUDE.md) 获取完整的开发指南。

### 代码规范

- 避免使用阻塞函数，使用协程友好的组件
- 判断字符串是否为空时使用 `=== ''`
- Git Commit 消息应描述变更针对的功能，而非具体代码改动

## License

MIT License
