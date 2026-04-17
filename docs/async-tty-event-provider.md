# AsyncTtyEventProvider 使用说明

## 概述

`AsyncTtyEventProvider` 是一个基于 Revolt EventLoop 的异步 TTY 事件提供者，实现了协程友好的事件读取机制。

**核心特性：**
- ✅ 协程友好的阻塞接口（`next()` 有值时才返回）
- ✅ 内部使用非阻塞 I/O（`EventLoop::onReadable()`）
- ✅ 完全兼容 php-tui 的 `EventProvider` 接口
- ✅ 事件类型与 php-tui 原生完全相同
- ✅ 支持协程并发处理多个任务

## 实现原理

### 核心机制

1. **协程挂起/恢复**
   - 使用 `Revolt\EventLoop\Suspension` 实现协程挂起
   - 调用 `suspend()` 挂起当前协程
   - 调用 `resume($value)` 恢复协程并返回值

2. **非阻塞 I/O**
   - 使用 `EventLoop::onReadable()` 监听 STDIN
   - 当有数据可读时，自动触发回调
   - 在回调中读取数据并解析事件

3. **事件缓冲**
   - 解析的事件先放入缓冲区
   - 如果有协程在等待，立即恢复协程并返回事件
   - 如果没有协程等待，事件保留在缓冲区

### 技术要点

**1. EventLoop::onReadable() 签名**
```php
// 正确的回调签名（必须接受两个参数）
EventLoop::onReadable($stream, function (string $callbackId, resource $stream) {
    // 处理可读事件
});

// 返回值为回调 ID，可用于取消监听
```

**2. Suspension 使用**
```php
// 获取当前协程的 Suspension
$suspension = EventLoop::getSuspension();

// 挂起协程（等待 resume）
$value = $suspension->suspend();

// 在其他地方恢复协程
$suspension->resume($value);
```

**3. 事件解析**
```php
// 使用 php-tui 的 EventParser
$parser = EventParser::new();
$parser->advance($bytes, more: false);
$events = $parser->drain(); // 返回 Event[]
```

## 使用方法

### 基本使用

```php
use App\Libs\Tui\AsyncTtyEventProvider;
use PhpTui\Term\Terminal;
use function Amp\async;

// 创建异步终端
$terminal = Terminal::new(
    eventProvider: new AsyncTtyEventProvider()
);

$terminal->enableRawMode();

try {
    // 在协程中处理事件
    async(function () use ($terminal) {
        while (true) {
            // 协程友好的阻塞读取（有值时才返回）
            $event = $terminal->events()->next();

            if ($event !== null) {
                // 处理事件...
            }
        }
    });

} finally {
    $terminal->disableRawMode();
}
```

### 并发处理

```php
use function Amp\async;
use function Amp\delay;

// 事件处理协程
async(function () use ($terminal) {
    while (true) {
        $event = $terminal->events()->next();
        // 处理事件...
    }
});

// 定时器协程（不阻塞事件处理）
async(function () {
    while (true) {
        delay(1.0);
        // 定期更新状态栏等...
    }
});

// LLM 流式输出协程
async(function () {
    $response = $llmClient->chatStream($request);
    // 处理流式响应...
});
```

## 测试命令

### 1. 事件测试命令

读取 5 个事件后退出：

```bash
./wind tui:async-test
```

**功能：**
- 测试 AsyncTtyEventProvider 的基本功能
- 读取 5 个键盘事件
- 显示事件类型和内容
- 自动退出（适合自动化测试）

### 2. 完整演示命令

交互式演示（按 Ctrl+C 退出）：

```bash
./wind tui:async-demo
```

**功能：**
- 完整的 TUI 界面
- 实时显示事件信息
- 显示事件间隔时间
- 演示协程并发（定时器协程）

## 对比：同步 vs 异步

### 同步实现（SyncTtyEventProvider）

```php
while (true) {
    // 阻塞式读取（占用整个进程）
    $event = $terminal->events()->next();

    if ($event !== null) {
        // 处理事件
    } else {
        // 降低 CPU 占用
        delay(0.05);
    }
}
```

**特点：**
- ❌ 阻塞整个进程
- ❌ 无法同时处理其他任务
- ❌ 需要轮询降低 CPU 占用

### 异步实现（AsyncTtyEventProvider）

```php
// 事件处理协程
async(function () use ($terminal) {
    while (true) {
        // 只阻塞当前协程，不阻塞其他协程
        $event = $terminal->events()->next();
        // 处理事件
    }
});

// 其他协程可以并发运行
async(function () {
    while (true) {
        delay(1.0);
        // 执行其他任务
    }
});
```

**特点：**
- ✅ 只阻塞当前协程
- ✅ 多个协程可以并发运行
- ✅ 无需轮询，事件驱动

## 性能优势

1. **CPU 占用低**
   - 事件驱动，无需轮询
   - 只在有事件时才唤醒协程

2. **响应速度快**
   - 非阻塞 I/O
   - 事件立即触发回调

3. **内存效率高**
   - 协程栈比线程栈小
   - 可以同时运行数千个协程

## 应用场景

### 1. 实时 LLM 对话界面

```php
async(function () use ($terminal) {
    while (true) {
        $event = $terminal->events()->next();
        // 处理用户输入
    }
});

async(function () use ($llmClient) {
    // 流式输出 LLM 响应
    $response = $llmClient->chatStream($request);
    foreach ($response->stream() as $chunk) {
        // 实时显示生成的内容
    }
});
```

### 2. 多窗口 TUI

```php
// 窗口 1：事件处理
async(function () use ($terminal) {
    while (true) {
        $event = $terminal->events()->next();
        // 处理窗口 1 的事件
    }
});

// 窗口 2：定时更新
async(function () {
    while (true) {
        delay(1.0);
        // 更新窗口 2 的内容（如状态栏）
    }
});
```

### 3. 交互式调试工具

```php
// 事件处理协程
async(function () use ($terminal) {
    while (true) {
        $event = $terminal->events()->next();
        // 处理用户命令
    }
});

// 日志监听协程
async(function () {
    while (true) {
        $logEntry = $logChannel->receive();
        // 实时显示日志
    }
});
```

## 注意事项

1. **必须在协程中使用**
   - `AsyncTtyEventProvider::next()` 必须在协程中调用
   - 否则会导致错误

2. **资源清理**
   - 使用 `try...finally` 确保调用 `disableRawMode()`
   - `__destruct()` 会自动清理 EventLoop 回调

3. **终端兼容性**
   - 需要交互式终端（TTY）
   - 不支持重定向输入/输出

4. **协程安全**
   - 每个 `next()` 调用应该在独立的协程中
   - 避免多个协程同时调用 `next()`

## 扩展方向

1. **添加定时器支持**
   - 定时刷新界面
   - 光标闪烁效果
   - 动画效果

2. **多路复用**
   - 同时监听多个输入源
   - 网络事件
   - 文件系统事件

3. **事件过滤**
   - 事件预处理
   - 事件转发
   - 事件聚合

## 总结

`AsyncTtyEventProvider` 通过 Revolt EventLoop 和 Suspension 机制，实现了一个简洁而强大的异步事件提供者：

- **外部接口简洁**：`next()` 方法保持阻塞语义
- **内部实现高效**：使用非阻塞 I/O 和事件驱动
- **完全兼容**：与 php-tui 的 `EventProvider` 接口 100% 兼容
- **协程友好**：支持协程并发处理多个任务

这使得在 Wind Framework 中构建高性能的异步 TUI 应用成为可能！
