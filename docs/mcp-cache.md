# MCP 工具缓存功能

## 概述

MCP 工具缓存功能可以显著加快 Agent 启动速度，避免每次都重新加载 MCP 服务器的工具定义。

## 功能特性

- **自动缓存**：首次初始化时自动缓存工具定义
- **延迟初始化**：使用缓存时，客户端连接延迟到首次工具调用时建立
- **智能过期**：每个服务器缓存 2 小时后自动过期
- **配置检测**：服务器配置变化时自动重新加载
- **灵活管理**：支持全局或单服务器缓存清除

## 缓存位置

缓存文件保存在：`workspace/states/mcp.cache.json`

## 使用方法

### 基本使用

缓存功能是自动启用的，无需额外配置。

```bash
# 启动 Agent（首次运行会创建缓存）
./wind test:agent --with-mcp

# 第二次启动会使用缓存（速度更快）
./wind test:agent --with-mcp
```

### 管理命令

使用 `test:mcp` 命令管理缓存：

```bash
# 查看缓存状态
./wind test:mcp --cache-status

# 清除所有缓存
./wind test:mcp --clear-cache

# 清除指定服务器的缓存
./wind test:mcp --clear-server-cache fetch

# 强制重新加载（禁用缓存）
./wind test:mcp --server fetch --no-cache
```

## 性能对比

基于 `fetch` MCP 服务器的测试结果：

| 场景 | 耗时 | 说明 |
|------|------|------|
| 首次加载（无缓存） | ~2.0 秒 | 需要启动 MCP 服务器进程并获取工具列表 |
| 使用缓存（启动） | ~0.05 秒 | 直接从缓存读取工具定义（**快 43 倍！**） |
| 首次工具调用 | ~4.0 秒 | 触发延迟初始化，建立连接并执行工具 |
| 后续工具调用 | ~3.5 秒 | 连接已建立，直接执行工具 |

**延迟初始化优势**：
- Agent 启动速度快（使用缓存，不初始化连接）
- 只在首次使用工具时才建立连接
- 如果不使用工具，就不需要建立连接（节省资源）

## 缓存策略

### 缓存键

每个 MCP 服务器的缓存基于以下信息：

- **服务器名称**：配置文件中的服务器键名
- **配置哈希**：基于服务器配置（command, args, url, headers 等）
- **缓存时间**：工具定义的缓存时间戳

### 缓存失效条件

缓存会在以下情况下失效：

1. **时间过期**：缓存超过 2 小时
2. **配置变化**：服务器配置发生变化（通过哈希检测）
3. **手动清除**：用户主动清除缓存

## 缓存文件格式

```json
{
    "服务器名称": {
        "time": 1777184068,
        "config_hash": "b4a0c77d6bea020f4c0636241d2d2f25",
        "tools": [
            {
                "name": "fetch_html",
                "description": "Fetch a website and return the content as HTML",
                "inputSchema": { ... }
            }
        ]
    }
}
```

## 高级用法

### 在代码中使用

```php
use App\Libs\MCP\McpManager;

// 清除所有缓存
McpManager::clearCache();

// 清除指定服务器缓存
McpManager::clearServerCache('fetch');

// 获取缓存状态
$status = McpManager::getCacheStatus();
foreach ($status['servers'] as $server => $info) {
    echo "{$server}: {$info['tool_count']} 工具\n";
}
```

### 调整缓存时间

修改 `app/Libs/MCP/McpManager.php` 中的 `$cacheTtl` 属性：

```php
/** @var int 缓存有效期（秒） */
private static int $cacheTtl = 7200; // 默认 2 小时
```

## 注意事项

1. **延迟初始化**：使用缓存时，客户端连接延迟到首次工具调用时建立
2. **缓存不影响工具调用**：缓存只存储工具定义，不影响工具执行
3. **多进程安全**：使用 `LOCK_EX` 文件锁防止并发写入冲突
4. **配置变化检测**：修改配置后自动重新加载，无需手动清除缓存
5. **存储位置**：`workspace/states/` 目录已加入版本控制（.gitkeep）

## 故障排查

### 缓存未生效

如果缓存未生效，检查：

1. 缓存文件是否存在：`workspace/states/mcp.cache.json`
2. 文件权限是否正确
3. 服务器配置是否频繁变化

### 强制重新加载

使用 `--no-cache` 选项：

```bash
./wind test:mcp --server fetch --no-cache
```

### 查看详细状态

使用 `--cache-status` 查看每个服务器的缓存状态：

```bash
./wind test:mcp --cache-status
```

## 相关文档

- [MCP 协议实现](./mcp.md)
- [Agent 系统文档](../workspace/AGENTS.md)

## 文档更新记录

**2026-04-26**
- 初始版本，详细说明 MCP 工具缓存机制的设计和实现
