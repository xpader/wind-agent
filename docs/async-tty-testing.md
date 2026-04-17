# AsyncTtyEventProvider 测试指南

## 问题排查

如果命令一打开就退出，可能的原因：

### 1. 终端不是 TTY

```bash
# 检查是否在交互式终端中
tty

# 如果输出 "not a tty"，则不能运行 TUI 命令
# 解决方案：在真实的终端中运行，不要通过管道或重定向
```

### 2. STDIN 被重定向

```bash
# 错误示例
echo "test" | ./wind tui:async-test
./wind tui:async-test < input.txt

# 正确示例
./wind tui:async-test
```

### 3. 事件循环问题

如果 EventLoop 没有正确运行，协程可能无法执行。

## 测试命令

### 1. 调试命令（推荐首先测试）

```bash
./wind tui:async-debug
```

**功能：**
- 详细的调试输出
- 3 秒超时
- 显示每一步的状态
- 捕获并显示所有错误

**预期输出：**
```
=== 异步 TTY 事件提供者调试 ===

步骤 1: 创建 AsyncTtyEventProvider
✓ AsyncTtyEventProvider 创建成功

步骤 2: 创建 Terminal
✓ Terminal 创建成功

步骤 3: 启用 Raw Mode
✓ Raw Mode 已启用

步骤 4: 创建测试协程
协程将在 3 秒后超时...

请按下一个键（或等待超时）...
```

**如果你按键：**
```
✓ 收到事件
  类型: CharKeyEvent
  内容: a
```

**如果超时：**
```
✓ 超时：3 秒内没有收到事件
```

### 2. 简单测试

```bash
./wind tui:async-simple
```

**功能：**
- 5 秒超时
- 简单的输出
- 适合快速测试

### 3. 事件计数测试

```bash
./wind tui:async-test
```

**功能：**
- 读取 5 个事件后退出
- 显示每个事件的详细信息
- 自动完成（适合自动化测试）

### 4. 完整演示

```bash
./wind tui:async-demo
```

**功能：**
- 交互式 TUI 界面
- 实时显示事件信息
- 按 Ctrl+C 退出

## 手动测试步骤

### 1. 基础功能测试

```bash
# 运行调试命令
./wind tui:async-debug

# 在 3 秒内按下一个键（如 'a'）
# 应该看到：✓ 收到事件
```

### 2. 超时测试

```bash
# 运行调试命令
./wind tui:async-debug

# 不按键，等待 3 秒
# 应该看到：✓ 超时：3 秒内没有收到事件
```

### 3. 多次事件测试

```bash
# 运行计数测试
./wind tui:async-test

# 连续按下 5 个键
# 应该看到每个事件的详细信息
```

### 4. 特殊按键测试

```bash
# 运行调试命令
./wind tui:async-debug

# 测试特殊键：
# - 方向键
# - Enter 键
# - Tab 键
# - Backspace 键
# - Ctrl+C（应该退出）
```

## 常见问题

### Q: 命令一打开就退出

**A:** 检查以下几点：
1. 是否在交互式终端中运行？（运行 `tty` 命令检查）
2. STDIN 是否被重定向？（不要使用 `<` 或 `|`）
3. 是否有足够的权限？（检查 TTY 设备权限）

### Q: 没有输出任何信息

**A:** 尝试使用 `-vvv` 选项获取详细输出：
```bash
./wind tui:async-debug -vvv
```

### Q: 显示 "not a tty"

**A:** 你不在交互式终端中。请确保：
- 在真实的终端（如 GNOME Terminal、iTerm2）中运行
- 不要通过 SSH 运行（除非使用 `-t` 选项）
- 不要在脚本或 CI/CD 环境中运行

### Q: 事件无法读取

**A:** 检查 Raw Mode 是否启用：
```bash
# 运行 stty 命令查看当前终端设置
stty -a

# 如果显示 "-icanon"，说明 Raw Mode 已启用
```

## 调试技巧

### 1. 查看详细输出

```bash
./wind tui:async-debug -vvv
```

### 2. 检查 EventLoop

在 `AsyncTtyEventProvider` 的 `handleReadable()` 方法中添加调试输出：

```php
private function handleReadable(): void
{
    error_log("handleReadable() 被调用");
    $bytes = stream_get_contents($this->stream);
    error_log("读取到字节: " . bin2hex($bytes));

    // ... 其他代码
}
```

### 3. 检查协程状态

```php
$future = \Amp\async(function () use ($terminal) {
    error_log("协程：开始执行");
    $event = $terminal->events()->next();
    error_log("协程：收到事件: " . var_export($event, true));
    return $event;
});
```

### 4. 测试 EventLoop

```php
// 测试 EventLoop 是否正常工作
\Amp\async(function () {
    for ($i = 0; $i < 5; $i++) {
        \Amp\delay(1.0);
        echo "定时器: {$i}\n";
    }
});

\Amp\Future\awaitFirst([]);
```

## 预期行为

### 正常情况下

1. **启动阶段**
   - 创建 AsyncTtyEventProvider
   - 启用 Raw Mode
   - 显示提示信息

2. **等待阶段**
   - 程序等待用户输入
   - CPU 占用应该很低（事件驱动）
   - 可以按 Ctrl+C 退出

3. **事件处理**
   - 按键后立即显示事件
   - 事件类型正确
   - 事件内容正确

4. **清理阶段**
   - 禁用 Raw Mode
   - 恢复终端状态
   - 退出程序

### 异常情况下

1. **终端不支持 TTY**
   - 显示错误信息
   - 程序退出

2. **权限不足**
   - 显示错误信息
   - 程序退出

3. **其他错误**
   - 显示详细错误信息
   - 显示堆栈跟踪
   - 程序退出

## 下一步

如果测试成功，可以：
1. 将 `AsyncTtyEventProvider` 集成到其他 TUI 应用
2. 添加更多功能（定时器、动画等）
3. 优化性能和用户体验

如果测试失败，请：
1. 记录错误信息
2. 检查系统环境
3. 查看日志文件
4. 尝试其他测试命令
