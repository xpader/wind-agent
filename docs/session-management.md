# Agent 会话管理详解

## 概述

项目实现了完整的会话管理系统，支持多轮对话的持久化存储和恢复。会话系统允许用户在不同时间点继续之前的对话，自动保存对话历史，并提供灵活的会话管理功能。

## 核心架构

### 组件构成

**SessionManager** - 会话管理器
- 负责会话的创建、加载、保存、删除和列表
- 提供静态方法接口，无需实例化
- 使用 JSONL 格式持久化到 `workspace/sessions/` 目录

**Session** - 会话类
- 封装会话数据和元数据
- 提供访问会话信息的接口
- 包含消息历史和元数据

**Agent::clearMessages()** - 消息清理
- 清空内存中的对话历史
- 保留系统消息
- 不涉及会话文件的删除

### 存储格式

**文件位置**：`workspace/sessions/<session-id>.jsonl`

**文件格式**：JSONL（每行一个 JSON 对象）
```json
{"type":"meta","session_id":"uuid","created_at":"2025-01-01T00:00:00+00:00",...}
{"type":"message","role":"user","content":"你好"}
{"type":"message","role":"assistant","content":"你好！有什么可以帮助你的？"}
```

**第一行**：元数据（type = meta）
**后续行**：消息记录（type = message）

## 会话生命周期

### 1. 延迟创建机制

**设计理念**：不指定 `--session` 参数时，不会立即创建会话文件，而是在发送第一条消息时才创建。

**实现逻辑**：
```php
// TestAgentCommand.php
if (!$sessionId) {
    // 标记需要自动创建会话
    $autoCreateSession = true;
    $agent->setAutoSave(true);
    $output->writeln("<info>会话模式: 新会话</>");
}

// 发送第一条消息时
if ($autoCreateSession && !$sessionCreated) {
    $metadata = [...];
    $sessionId = $agent->createSession($metadata);
    $output->writeln("<info>✓ 创建会话: {$sessionId}</>");
    $sessionCreated = true;
}
```

**优势**：
- 避免创建空的会话文件
- 只在真正需要时才分配资源
- 用户可以随时放弃对话而不留下痕迹

### 2. 恢复已有会话

**操作方式**：
```bash
# 使用数字编号恢复（推荐，更简洁）
./wind test:agent --session 1

# 使用完整会话 ID 恢复
./wind test:agent --session <session-id>
```

**恢复过程**：
1. 检查会话是否存在
2. 加载会话元数据和消息历史
3. 重新加载系统提示词（使用最新版本）
4. 检查是否已生成标题
5. 启用自动保存

**代码实现**：
```php
// Agent.php
public function loadSession(string $sessionId): bool
{
    $session = SessionManager::load($sessionId);
    if ($session === null) {
        return false;
    }

    $this->sessionId = $sessionId;
    $loadedMessages = $session->getMessages();
    $metadata = $session->getMetadata();

    // 检查会话是否已有标题
    if (!empty($metadata['title'])) {
        $this->titleGenerated = true;
    }

    // 重新加载系统提示词文件（使用最新内容）
    foreach ($this->systemPromptFiles as $file) {
        $this->loadSystemPromptFileComponent($file);
    }

    // 添加技能组件（如果启用了技能）
    if ($this->withSkills && $this->skillManager !== null) {
        $this->updateSkillsComponent();
    }

    // 组装最新的系统消息
    $this->assembleSystemMessage();

    // 加载会话中的非系统消息
    $this->messages = [];
    // 先添加当前最新的系统消息
    // 然后添加会话中的所有非系统消息

    return true;
}
```

**特性**：
- 系统提示词自动更新到最新版本
- 保留原有的对话历史
- 标题只生成一次，恢复后不再重新生成

### 3. clear 命令的行为

**用户操作**：
```bash
# 在交互模式中
> clear
```

**执行逻辑**：
```php
// TestAgentCommand.php
if ($userMessage === '__CLEAR__') {
    $oldSessionId = $agent->getSessionId();
    $agent->clearMessages();  // 只清空内存

    // 标记需要创建新会话（延迟创建）
    $autoCreateSession = true;
    $sessionCreated = false;

    if ($oldSessionId) {
        $output->writeln("🗑️  对话上下文已清空");
        $output->writeln("📁 旧会话保留: {$oldSessionId}");
        $output->writeln("✨ 将创建新会话");
    } else {
        $output->writeln("🗑️  对话上下文已清空");
        $output->writeln("✨ 将创建新会话");
    }
}
```

**行为说明**：
1. **清空内存**：调用 `clearMessages()` 清空内存中的对话历史（保留系统消息）
2. **脱离旧会话**：不再关联之前的会话 ID
3. **旧会话保留**：旧会话文件保留在磁盘上，不会被删除
4. **延迟创建新会话**：标记 `autoCreateSession = true`，在下次发送消息时创建新会话

**使用场景**：
- 想要开始新的对话话题
- 当前对话历史过长，影响性能
- 需要独立的对话上下文

**注意**：clear 命令不会删除旧会话，用户可以随时使用 `--session <session-id>` 恢复之前的会话。

## 会话元数据

### 元数据字段

```php
$metadata = [
    'session_id' => string,      // 会话 ID（UUID）
    'created_at' => string,      // 创建时间（ISO 8601 格式）
    'updated_at' => string,      // 更新时间（ISO 8601 格式）
    'model' => string,           // 使用的模型名称
    'temperature' => float,      // 温度参数
    'max_tokens' => int,         // 最大 token 数
    'think' => mixed,            // 思考模式设置
    'with_tools' => bool,        // 是否启用工具
    'with_skills' => bool,       // 是否启用技能
    'with_mcp' => bool,          // 是否启用 MCP
    'client_type' => string,     // 客户端类型
    'title' => string,           // 会话标题（首轮对话后自动生成）
    'message_count' => int,      // 消息数量
];
```

### 自动标题生成

**触发时机**：首轮对话结束后

**生成逻辑**：
```php
// Agent.php
private function generateSessionTitle(): void
{
    if ($this->sessionId === null || $this->titleGenerated) {
        return;
    }

    // 提取首个 user 消息
    $firstUserMessage = '';
    $lastAssistantMessage = '';

    foreach ($this->messages as $message) {
        $role = $message['role'] ?? '';
        if ($role === 'user' && $firstUserMessage === '') {
            $firstUserMessage = $message['content'] ?? '';
        }
        if ($role === 'assistant') {
            $lastAssistantMessage = $message['content'] ?? '';
        }
    }

    if ($firstUserMessage === '') {
        return;
    }

    try {
        // 组合用户消息和助手回复
        $contentForTitle = "用户: " . $firstUserMessage;
        if ($lastAssistantMessage !== '') {
            $contentForTitle .= "\n助手: " . $lastAssistantMessage;
        }

        // 使用 LLM 生成标题
        $request = \App\Libs\LLM\LLMRequest::create();
        $request->addSystem('你是一个专业的对话标题生成助手...');
        $request->addUser("请为以下对话生成一个标题：\n\n" . $contentForTitle);
        $request->model($this->model);
        $request->temperature(0.3);
        $request->maxTokens(1000);
        $request->think(false);

        $response = $this->provider->chat($request);
        $title = trim($response->content);

        // 清理标题
        $title = str_replace(['"', '"', '\'', '\'', '\'', '。', '！', '？', '~', '…'], '', $title);
        $title = mb_substr($title, 0, 50, 'UTF-8');

        if ($title !== '') {
            SessionManager::updateTitle($this->sessionId, $title);
            $this->titleGenerated = true;
        }
    } catch (\Throwable $e) {
        // 生成标题失败不影响对话流程
        error_log("生成会话标题失败: " . $e->getMessage());
        $this->titleGenerated = true;
    }
}
```

**标题特点**：
- 简洁明了（不超过 50 个字符）
- 准确概括对话主题
- 使用中文
- 不包含标点符号
- 只生成一次，恢复会话后不再重新生成

## 会话操作命令

### 命令行操作

```bash
# 创建新会话（延迟创建）
./wind test:agent

# 恢复已有会话（支持数字编号或完整会话 ID）
./wind test:agent --session 1           # 使用编号恢复
./wind test:agent --session <session-id>  # 使用完整 ID 恢复

# 列出所有会话
./wind test:agent --list-sessions

# 发送单条消息
./wind test:agent --message "你好"

# 启用交互模式
./wind test:agent --interactive
```

### 交互模式命令

在 `test:agent` 交互模式中，可以使用以下命令：

- **`clear`** - 清空对话上下文，脱离旧会话，准备创建新会话（新会话会在首轮对话后自动生成标题）
- **`save`** - 手动保存当前会话
- **`info`** - 显示当前会话信息
- **`quit`** 或 **`exit`** - 退出交互模式
- **`Ctrl+C`** - 强制退出

### 会话信息显示

```bash
> info

========== 会话信息 ==========
会话 ID: 550e8400-e29b-41d4-a716-446655440000
创建时间: 2025-01-01T10:00:00+00:00
更新时间: 2025-01-01T10:30:00+00:00
模型: qwen3.5:4b
温度: 0.7
最大 Token: 32768
思考模式: false
工具调用: 启用
技能支持: 启用
MCP 支持: 启用
消息数量: 15
```

## 会话列表

### 列出所有会话

```bash
./wind test:agent --list-sessions
```

### 输出格式

```
========== 会话列表 ==========

找到 2 个会话:

[1] 使用 Agent 编写代码
    会话 ID: 550e8400-e29b-41d4-a716-446655440000
    模型: qwen3.5:4b
    创建时间: 2025-01-01 10:00:00
    更新时间: 2025-01-01 10:30:00
    消息数量: 15

[2] <未命名>
    会话 ID: 550e8400-e29b-41d4-a716-446655440001
    模型: qwen3.5:4b
    创建时间: 2025-01-01 09:00:00
    更新时间: 2025-01-01 09:15:00
    消息数量: 8

提示: 使用 --session <会话ID> 来恢复会话
```

### 排序规则

会话列表按**更新时间倒序**排列，最近更新的会话显示在前面。

### 会话编号功能

为了方便恢复会话，每个会话在列表中都有一个编号（从 1 开始）。可以使用编号来快速恢复会话，无需输入完整的 UUID。

**使用示例**：
```bash
# 列出会话（显示编号）
./wind test:agent --list-sessions

# 输出示例：
# [1] 使用 Agent 编写代码
#     会话 ID: 550e8400-e29b-41d4-a716-446655440000
# [2] <未命名>
#     会话 ID: 650f9501-f39c-52d5-b827-557766551111

# 使用编号恢复（推荐）
./wind test:agent --session 1

# 使用完整 ID 也可以
./wind test:agent --session 550e8400-e29b-41d4-a716-446655440000
```

**实现原理**：
- 编号基于会话列表的当前排序（按更新时间倒序）
- 编号是临时的，会随着会话列表的变化而变化
- 系统会自动识别数字输入，将其转换为对应的会话 ID
- 如果编号无效，会提示使用 `--list-sessions` 查看正确的编号

**注意事项**：
- 编号会随着会话的创建和删除而变化
- 建议在使用编号前先运行 `--list-sessions` 确认最新的编号
- 完整的会话 ID 是永久的，不会变化

## 会话持久化机制

### 自动保存

**触发时机**：
- 每轮对话结束后自动保存
- 仅保存新增的消息（增量保存）

**实现逻辑**：
```php
// Agent.php
public function saveSession(): void
{
    if ($this->sessionId === null) {
        return;
    }

    $session = SessionManager::load($this->sessionId);
    if ($session !== null) {
        $savedMessages = $session->getMessages();
        $messageCount = count($savedMessages);

        // 只保存新增的消息
        for ($i = $messageCount; $i < count($this->messages); $i++) {
            SessionManager::saveMessage($this->sessionId, $this->messages[$i]);
        }

        // 更新元数据
        SessionManager::updateMetadata($this->sessionId, [
            'updated_at' => date('c'),
        ]);
    }
}
```

### 手动保存

在交互模式中，可以使用 `save` 命令手动保存会话：

```bash
> save
💾 会话已保存
```

## SessionManager API

### 静态方法

**创建会话**
```php
$sessionId = SessionManager::create([
    'model' => 'qwen3.5:4b',
    'temperature' => 0.7,
    'max_tokens' => 32768,
    // ... 其他元数据
]);
```

**加载会话**
```php
$session = SessionManager::load($sessionId);
if ($session !== null) {
    $metadata = $session->getMetadata();
    $messages = $session->getMessages();
}
```

**保存消息**
```php
SessionManager::saveMessage($sessionId, [
    'role' => 'user',
    'content' => '你好'
]);
```

**更新元数据**
```php
SessionManager::updateMetadata($sessionId, [
    'updated_at' => date('c'),
]);
```

**更新标题**
```php
SessionManager::updateTitle($sessionId, '新标题');
```

**检查会话是否存在**
```php
$exists = SessionManager::exists($sessionId);
```

**删除会话**
```php
SessionManager::delete($sessionId);
```

**列出所有会话**
```php
$sessions = SessionManager::listAll();
// 返回格式：[
//     ['session_id' => '...', 'created_at' => '...', ...],
//     ...
// ]
```

## 最佳实践

### 1. 会话命名

- 依赖自动标题生成，避免手动命名
- 标题应该简洁明了，概括对话主题
- 避免在标题中使用特殊字符

### 2. 会话清理

- 定期清理不再需要的会话
- 使用 `--list-sessions` 查看所有会话
- 手动删除 `workspace/sessions/` 中的文件

### 3. 性能优化

- 长对话后使用 `clear` 命令开始新会话
- 避免单个会话包含过多消息（建议不超过 100 条）
- 定期备份重要会话

### 4. 数据迁移

- 会话文件采用 JSONL 格式，易于解析和迁移
- 可以直接复制 `workspace/sessions/` 目录备份会话
- 支持跨环境迁移会话文件

## 常见问题

### Q1: clear 命令会删除旧会话吗？

**A**: 不会。clear 命令只是清空内存中的对话历史，并标记需要创建新会话。旧会话文件保留在磁盘上，可以随时恢复。

### Q2: 如何彻底删除会话？

**A**: 使用 `SessionManager::delete($sessionId)` 方法，或者直接删除 `workspace/sessions/<session-id>.jsonl` 文件。

### Q3: 会话恢复后系统提示词会更新吗？

**A**: 会。恢复会话时，会重新加载系统提示词文件（使用最新版本），但保留原有的对话历史。

### Q4: 标题是何时生成的？

**A**: 标题在首轮对话结束后自动生成，且只生成一次。恢复会话后不会重新生成标题。

### Q5: 如何在代码中访问会话？

**A**:
```php
// 获取当前会话 ID
$sessionId = $agent->getSessionId();

// 加载会话
$session = SessionManager::load($sessionId);
$metadata = $session->getMetadata();
$messages = $session->getMessages();

// 创建新会话
$sessionId = $agent->createSession($metadata);
```

## 相关文档

- `CLAUDE.md` - 项目总览和开发指南
- `docs/mcp.md` - MCP 协议实现
- `docs/php-tui.md` - TUI 开发指南

## 文档更新记录

**2026-04-26**
- 新增会话编号功能说明，支持使用数字编号快速恢复会话
- 新增 clear 命令行为说明，强调脱离旧会话创建新会话的机制
- 更新会话恢复示例，同时支持编号和完整 ID 两种方式
- 修复 createSession 方法，确保新会话能正确生成标题

**2026-04-26**
- 初始版本创建，完整记录会话管理系统的设计和实现
- 包含会话生命周期、存储格式、API 参考等核心内容
