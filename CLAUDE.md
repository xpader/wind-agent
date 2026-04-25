# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## 项目概述

这是一个基于 **Wind Framework** 的 PHP 协程应用项目。Wind Framework 是一个纯 PHP 协程框架，构建在 Workerman 和 AMPHP 之上。

**PHP 版本**：8.1.33

## 开发命令

### 启动应用
```bash
# 启动 HTTP 服务器
php start.php start

# 以守护进程方式启动
php start.php start -d

# 停止服务
php start.php stop

# 重启服务
php start.php restart

# 平滑重启
php start.php reload
```

### 调试模式
```bash
# 使用 Xdebug 启动
/opt/php81/bin/php -dzend_extension=xdebug -dxdebug.mode=debug -dxdebug.start_with_request=yes start.php start
```

### Console 命令
```bash
# 运行 console 命令
./wind <command>

# 列出所有可用命令
./wind

# 创建新的 Console 命令
./wind make:command <command-name> <ClassName>
# 例如: ./wind make:command test:exec TestExecCommand
```

### 依赖管理
```bash
# 安装依赖
composer install

# 开发环境安装
composer install --verbose

# 生产环境安装
composer install --no-dev --optimize-autoloader
```

## 项目架构

### 目录结构
- `app/` - 应用代码
  - `Controller/` - HTTP 控制器
  - `Middleware/` - 中间件
  - `Listener/` - 事件监听器
  - `Collect/` - 数据收集器（用于监控和统计）
  - `Command/` - Console 命令
  - `Libs/` - 核心库
    - `LLM/` - LLM 客户端相关
      - `LLMClient.php` - LLM 客户端接口
      - `LLMRequest.php` - 请求封装类
      - `LLMResponse.php` - 响应封装类
      - `Clients/` - 客户端实现
        - `OpenAiClient.php` - OpenAI 兼容客户端
        - `OllamaClient.php` - Ollama 客户端
    - `Agent/` - Agent 功能相关
      - `ToolInterface.php` - 工具接口
      - `ToolManager.php` - 工具管理器
      - `SkillManager.php` - Skill 管理器
      - `Skill.php` - Skill 配置类
      - `Tools/` - 内置工具实现
    - `Tui/` - TUI 组件相关
      - `TextInputComponent.php` - 文本输入组件（支持光标定位、多字节字符）
    - `Traits/` - 通用 Traits
- `config/` - 配置文件
- `bootstrap/` - 引导文件
- `static/` - 静态资源文件
- `view/` - Twig 模板文件
- `runtime/` - 运行时文件（日志、PID 等）
- `vendor/` - Composer 依赖

### 核心架构

**Wind Framework 协程架构**
- 基于 Workerman 多进程架构
- 使用 AMPHP 协程组件
- 支持协程池、任务进程、Channel 通信

**路由系统**
配置在 `config/routes.php`，支持：
- 路由组（namespace、prefix、middlewares）
- HTTP 方法（GET、POST 等）
- 路由参数
- 中间件

**组件系统**
在 `config/components.php` 中启用核心组件：
- `\Wind\Event\Component` - 事件系统
- `\Wind\Process\Component` - 进程管理
- `\Wind\Collector\Component` - 数据收集
- `\Wind\Task\Component` - 任务系统

**服务器配置**
在 `config/server.php` 中配置：
- HTTP 服务器（监听地址、工作进程数）
- 静态文件服务
- Channel 服务（进程间通信）
- Task Worker（异步任务处理）

**数据库连接池**
在 `config/database.php` 中配置 MySQL 连接池：
- 支持连接池（最大连接数、最大空闲时间）
- 协程友好的数据库连接

**队列系统**
在 `config/queue.php` 中配置：
- Redis 驱动的队列
- 工作进程数和并发数

**定时任务**
在 `config/crontab.php` 中配置 Cron 任务。

### 环境变量
通过 `env()` 函数读取环境变量：
- `HTTP_SERVER_LISTEN` - HTTP 服务器监听地址（默认 0.0.0.0:2333）
- `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` - 数据库配置
- `REDIS_HOST`, `REDIS_PORT` - Redis 配置

## 配置说明

### 重要配置文件
- `config/server.php` - 服务器、Channel、Task Worker 配置
- `config/routes.php` - 路由定义
- `config/middlewares.php` - 全局中间件
- `config/database.php` - 数据库连接池
- `config/redis.php` - Redis 配置
- `config/queue.php` - 队列配置
- `config/crontab.php` - 定时任务

### 应用结构
- 控制器继承 `Wind\Web\Controller`
- 中间件实现 `Wind\Web\MiddlewareInterface`
- 监听器实现 `Wind\Listener\ListenerInterface`
- Console 命令继承 `Wind\Console\Command`

### 视图渲染
使用 Twig 模板引擎（`wind-framework/view` 组件）。

### 日志系统
日志配置在 `config/log.php`，日志文件写入 `runtime/log/`。

### LLM 客户端架构

项目采用统一的 LLM 客户端抽象架构，实现多平台适配器模式：

**核心组件**
- `LLMClient` 接口 - 定义统一的 LLM 服务交互接口
- `LLMRequest` 类 - 请求封装，支持消息组织和参数配置（链式调用）
- `LLMResponse` 类 - 响应数据封装（支持流式处理）
- `TokenUsage` 类 - Token 使用统计和成本计算

**平台适配器**
- `OpenAiClient` - OpenAI API 兼容客户端
- `OllamaClient` - Ollama 本地模型客户端
- 各客户端负责将平台特定的请求/响应格式转换为统一的 `LLMRequest`/`LLMResponse`

**设计原则**
- **消息组织抽象** - `LLMRequest` 统一管理消息和参数，简化调用
- **平台适配隔离** - 各平台的 API 差异由对应 Client 内部处理
- **链式调用** - `LLMRequest` 支持流畅的链式 API（通过 `__call` 魔术方法）
- **流式支持** - 统一的流式响应处理接口

**使用示例**
```php
// 创建请求并添加消息
$request = LLMRequest::create()
    ->addUser('你好，请介绍一下你自己')
    ->model('gpt-4')
    ->temperature(0.8)
    ->maxTokens(2000);

$response = $client->chat($request);
echo $response->content;

// 多轮对话
$request = LLMRequest::withSystem('你是一个专业的助手')
    ->addUser('我叫小明')
    ->addAssistant('你好小明！')
    ->addUser('我叫什么名字？');

$response = $client->chat($request);

// 流式处理
$client->chatStream($request, function(LLMResponse $response) {
    echo $response->content;
});
```

**Trait 复用**
- `HttpRequestTrait` - HTTP 请求通用方法
- `StreamResponseTrait` - 流式响应处理逻辑

### 工具调用架构

项目实现了完整的 LLM 工具调用（Function Calling）支持，允许 AI 模型调用外部工具：

**核心组件**
- `ToolInterface` - 工具接口，定义工具的标准契约
- `ToolManager` - 工具管理器，从配置文件加载和管理工具
- `LLMRequest` - 支持添加工具定义（`addTool()` 方法）和工具消息（`addToolMessage()` 方法）
- `LLMResponse` - 支持解析和执行工具调用（`toolCalls` 属性、`executeToolCalls()` 方法）

**工具接口定义**
```php
interface ToolInterface {
    public function getName(): string;           // 工具名称
    public function getDescription(): string;    // 工具描述
    public function getParameters(): array;      // 参数定义（OpenAI 格式）
    public function execute(array $arguments): string;  // 执行工具
    public function toArray(): array;            // 转换为 API 格式
}
```

**工具配置**
在 `config/tools.php` 中配置启用的工具：
```php
'enabled' => [
    \App\Libs\Agent\Tools\ReadFileTool::class,
    \App\Libs\Agent\Tools\WriteFileTool::class,
    \App\Libs\Agent\Tools\ExecTool::class,
    \App\Libs\Agent\Tools\ReadSkillTool::class,
],
```

**内置工具**
- `ReadFileTool` - 读取文件内容
- `WriteFileTool` - 写入文件内容
- `ExecTool` - 执行 shell 命令（包含安全限制，禁止删除文件）
- `ReadSkillTool` - 读取 Skill 文档内容

**工具调用流程**
1. 通过 `ToolManager::getAll()` 从配置文件加载所有启用的工具
2. 将工具添加到 `LLMRequest`：`$request->addTool($tool)`
3. 发送请求到 LLM，模型决定是否调用工具
4. 解析响应中的 `toolCalls`
5. 执行工具：`$response->executeToolCalls()`
6. 将工具结果作为工具消息添加到请求：`$request->addToolMessage($toolCallId, $result)`
7. 继续多轮对话

**重要提示**：
- 启用 `--with-skills` 时会自动加载工具（技能需要工具支持）
- 工具调用支持流式和非流式两种模式
- 支持多轮对话和工具链式调用

**JSON-RPC 参数格式要求（关键）**：
- MCP 协议和 Tool Call 都严格要求参数格式
- **空参数必须是对象 `{}` 而不是数组 `[]`**
- 正确：`'params' => new \stdClass()` 生成 `"params": {}`
- 错误：`'params' => []` 生成 `"params": []`
- 服务器会拒绝空数组格式的参数，导致 "Invalid input: expected object, received array" 错误
- 有参数时使用关联数组，会自动转换为对象：`'params' => ['url' => 'xxx']` 生成 `"params": {"url": "xxx"}`

**设计原则**
- **配置驱动** - 通过配置文件管理工具，方便启用/禁用
- **接口统一** - 所有工具实现相同的接口
- **安全优先** - 危险工具（如 ExecTool）默认禁用，包含安全检查
- **多轮对话** - 支持工具执行后的继续对话，使用 `addToolMessage()` 添加工具执行结果
- **平台兼容** - 工具定义格式兼容 OpenAI API 标准

### Skill 系统

项目实现了 Skill 系统，允许 LLM 动态加载和使用扩展技能：

**核心组件**
- `SkillManager` - Skill 管理器，负责扫描、加载和管理 Skills
- `Skill` - Skill 配置类，从 SKILL.md 文件加载技能定义（使用 YAML front matter）
- `LLMRequest` - 通过 `getSkillsPrompt()` 方法获取 Skill 提示词
- `read_skill` 工具 - LLM 可以通过此工具读取指定 Skill 的完整文档

**Skill 目录结构**
```
workspace/skills/
├── skill-name/
│   ├── SKILL.md          # Skill 定义文件（必需，使用 YAML front matter）
│   ├── examples/         # 示例文件（可选）
│   └── resources/        # 资源文件（可选）
```

**SKILL.md 格式（YAML front matter）**
```markdown
---
name: skill-name
description: 简短描述技能的功能（第三人称格式）
license: MIT
metadata:
  version: 1.0.0
  author: Author Name
---

# 技能名称

详细的技能说明文档...

## 使用场景

描述何时应该使用这个技能。

## 执行步骤

1. 第一步
2. 第二步
3. 第三步

## 注意事项

- 重要提示1
- 重要提示2
```

**Skill 管理架构**
- `SkillManager` 在构造时自动扫描 `workspace/skills/` 目录
- 以技能名称为索引存储 Skills（`array<string, Skill>`）
- 提供 `getAll()`, `getByName()`, `has()`, `count()` 等管理方法
- 提供 `generatePrompt()` 方法生成系统提示词

**使用流程**
1. 将 Skill 定义文件放置在 `workspace/skills/<skill-name>/SKILL.md`
2. `SkillManager` 自动扫描并加载（构造时）
3. 通过 `LLMRequest::getSkillsPrompt()` 获取提示词
4. LLM 根据需要使用 `read_skill` 工具读取完整 Skill 文档
5. LLM 按照 Skill 文档中的步骤执行任务

**设计原则**
- **职责分离** - `SkillManager` 专门管理 Skill，`LLMRequest` 专注请求封装
- **配置驱动** - 通过文件系统管理 Skills，无需配置文件
- **命名索引** - 使用技能名称作为索引，查找效率高
- **自动化** - 构造时自动扫描，无需手动加载

**工具测试命令**
```bash
# 列出所有可用工具
./wind test:tools --list-tools

# 测试工具调用
./wind test:tools --prompt "请读取 README.md 文件"

# 单步模式（每次工具调用后暂停）
./wind test:tools --single-step
```
```bash
# 列出所有已加载的 Skills
./wind test:skills --list-skills

# 读取指定 Skill 的内容
./wind test:skills --read-skill <skill-name>

# 测试 Skill 对话
./wind test:skills --prompt "请介绍一下你有哪些技能"

# 显示已加载的工具详情
./wind test:skills --show-tools
```

### 测试命令

项目提供了统一的测试命令用于验证所有功能：

**统一聊天测试 (test:chat)**
```bash
# 基本对话测试
./wind test:chat --prompt "你好" --model qwen3.5:4b

# 流式输出
./wind test:chat --prompt "写一首诗" --stream

# 启用思考模式
./wind test:chat --prompt "解释一下量子计算" --think true

# 启用工具调用
./wind test:chat --prompt "请读取 README.md 文件" --with-tools

# 启用技能支持（自动加载工具）
./wind test:chat --prompt "使用技能帮我处理任务" --with-skills

# 列出可用模型
./wind test:chat --list-models

# 单步模式（工具调用调试）
./wind test:chat --prompt "复杂任务" --with-tools --single-step

# 使用 OpenAI 兼容接口
./wind test:chat --client openai --host localhost:11434

# 显示原始响应数据
./wind test:chat --prompt "测试" --show-raw
```

**专用测试命令**
```bash
# 工具调用专用测试
./wind test:tools --list-tools
./wind test:tools --prompt "请读取 README.md 文件"
./wind test:tools --single-step

# 技能功能专用测试
./wind test:skills --list-skills
./wind test:skills --read-skill <skill-name>
./wind test:skills --prompt "请介绍一下你有哪些技能"
./wind test:skills --show-tools

# Ollama 客户端专用测试
./wind test:ollama --prompt "测试"

# TUI Demo 测试
./wind tui:demo                    # 启动 TUI 演示（支持终端尺寸自适应）
./wind tui:demo --duration=10      # 设置自动退出时间（秒）
```

**php-tui 源码位置**：`~/projects/php-tui` - 用于参考 TUI 库的实现和示例
- 官方示例：`~/projects/php-tui/example/demo/src/App.php`
- 文档：`~/projects/php-tui/docs/`
- 核心类：`~/projects/php-tui/src/`

## 开发注意事项

- Wind Framework 基于 PHP 8.1+ 协程
- 所有 I/O 操作应使用协程友好的组件（AMPHP）
- **避免使用阻塞函数**：使用 `delay()` 代替阻塞的 `sleep()`, `usleep()` 等
  - ✅ 正确：`delay(0.05)` - 50ms 延迟（AMPHP 异步延迟）
  - ❌ 错误：`usleep(50_000)` - 50ms 延迟（阻塞）
  - ❌ 错误：`sleep(1)` - 1秒延迟（阻塞）
- 进程间通信使用 Channel 服务
- 长时间运行的任务使用 Task Worker
- 定时任务使用 Crontab 组件
- LLM 相关类位于 `App\Libs\LLM` 命名空间
- Agent 相关类（工具、技能）位于 `App\Libs\Agent` 命名空间
- LLM 客户端使用 `LLMRequest` 封装请求，支持消息组织和参数配置
- LLM 客户端扩展需遵循适配器模式，实现 `LLMClient` 接口
- 新增平台适配时，只需添加新的 Client 类到 `LLM/Clients/` 目录
- 工具调用使用 `LLMRequest` 的 `addTool()` 添加工具定义，`addToolMessage()` 添加工具执行结果
- Skill 管理使用 `SkillManager` 类，支持自动扫描和按名称索引
- 已移除 `complete()` 和 `embed()` 接口，专注于聊天补全功能
- **测试命令**：使用 `test:chat` 进行统一的聊天测试（支持 LLM、工具、技能）
- **技能与工具**：启用技能时会自动加载工具，无需单独指定 `--with-tools`

### 代码严谨性要求

**逻辑要严谨，谨慎使用 `trim()` 和 `empty()`**

- 判断字符串是否相等时，可以使用 `==`
- 判断字符串是否为空时，**必须使用** `=== ''`
- 判断数组是否为空时，使用 `count($array) > 0`
- 只在明确需要清理字符串首尾空白时才使用 `trim()`
- 不要用 `empty()` 判断字符串内容，因为 `"0"` 会被视为空值

```php
// ❌ 错误
if (!empty($content)) { }
if (!empty(trim($line))) { }

// ✅ 正确
if ($content === '') { }
if ($line !== '') { }
if (count($array) > 0) { }
```

### Git Commit 消息规范

**描述变更针对的功能，而非具体代码改动**

- ✅ 正确：修复 OpenAI 兼容接口的工具调用错误
- ❌ 错误：修复 OpenAiClient 中 tool_use.input 为 [] 的问题

commit 消息应该：
- 描述修复或添加了什么功能
- 说明变更的目的和影响
- 让读者理解"为什么"而不是"改了什么"

示例：
- "修复 Anthropic 接口工具调用空参数错误"（描述功能问题）
- "优化 Agent 多轮对话的上下文管理"（描述功能改进）
- "添加流式响应的 token 统计功能"（描述功能添加）

避免：
- "将 tool_use.input 从 [] 改为 {}"（描述代码细节）
- "修改 ClientFactory 的配置读取逻辑"（描述实现方式）
- "在 parseChatResponse 中添加 usage 解析"（描述代码位置）

## 技术文档

- `docs/php-tui.md` - php-tui 终端 UI 开发完整指南（核心概念、组件系统、事件处理、布局管理、常见问题、最佳实践）

## MCP (Model Context Protocol) 实现

项目实现了完整的 MCP 客户端支持，允许 AI Agent 调用 MCP 服务器提供的工具。

**核心实现**
- `McpClientInterface` - MCP 客户端统一接口
- `McpStdioClient` - stdio 传输客户端（使用原生 PHP proc_open）
- `McpHttpClient` - HTTP 传输客户端（基于 Streamable HTTP 规范）
- `McpManager` - MCP 服务器和工具管理器
- `McpToolWrapper` - 将 MCP 工具适配到 ToolInterface

**配置文件**
- `config/mcp.php` - MCP 服务器配置

**支持两种传输方式**

1. **stdio 传输**（本地进程）
   - 使用 `command` 和 `args` 启动本地 MCP 服务器进程
   - 适用于本地工具和脚本
   - 示例：fetch, minimax, brave-search, github, memory

2. **HTTP 传输**（远程服务器）
   - 使用 `url` 连接远程 MCP 服务器
   - 可选 `headers` 用于认证
   - 适用于云服务和远程 MCP 端点
   - 示例：exa (https://mcp.exa.ai/mcp)

**配置示例**

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

**测试命令**
```bash
# 列出 MCP 服务器
./wind test:mcp --list-servers

# 列出 MCP 工具
./wind test:mcp --list-tools

# 在 Agent 中使用 MCP 工具
./wind test:agent --with-mcp --mcp-servers=fetch,exa

# 测试 HTTP MCP 客户端
php workspace/tests/test_mcp_http.php
```

**重要：JSON-RPC 参数格式要求**
MCP 协议和 Tool Call 都严格要求参数格式：
- **空参数必须是对象 `{}` 而不是数组 `[]`**
- 正确：`'params' => new \stdClass()` 生成 `"params": {}`
- 错误：`'params' => []` 生成 `"params": []`
- 服务器会拒绝空数组格式，返回 "Invalid input: expected object, received array" 错误
- 有参数时使用关联数组：`'params' => ['url' => 'xxx']` 生成 `"params": {"url": "xxx"}`
- 这个要求同时适用于：stdio 和 HTTP 两种传输方式

**已测试的 MCP 服务器**

stdio 传输：
- `@tokenizin/mcp-npx-fetch` - HTTP 请求工具（fetch_html, fetch_markdown, fetch_txt, fetch_json）
- `@modelcontextprotocol/server-brave-search` - Brave 搜索
- `@modelcontextprotocol/server-github` - GitHub 集成
- `@modelcontextprotocol/server-memory` - 内存存储
- `minimax-coding-plan-mcp` - MiniMax 编码计划

HTTP 传输：
- `exa` - AI 搜索服务 (https://mcp.exa.ai/mcp)

**关键实现细节**

stdio 传输：
- 使用 `proc_open()` 和管道进行 stdio 通信
- 使用 `fgets()` 阻塞读取 JSON-RPC 响应
- 使用 `fflush()` 确保数据立即发送
- 在发送 `initialized` 通知后需要 `sleep(1)` 延迟
- 必须正确设置 PATH 环境变量以便 `npx` 找到 MCP 服务器

HTTP 传输：
- 使用 AMPHP HttpClient 发送 HTTP POST 请求
- 支持 MCP 会话管理（MCP-Session-Id）
- 遵循 MCP Streamable HTTP 规范（2025-11-25）
- 包含必需的 HTTP 头：Accept, MCP-Protocol-Version
- 支持自定义认证头（Authorization, X-API-Key 等）

- `docs/php-tui.md` - php-tui 终端 UI 开发完整指南（核心概念、组件系统、事件处理、布局管理、常见问题、最佳实践）
