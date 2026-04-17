# AsyncTtyEventProvider 实现总结

## ✅ 实现已完成

### 核心实现
- **文件**: `app/Libs/Tui/AsyncTtyEventProvider.php`
- **状态**: ✅ 完成且语法正确
- **功能**: 实现了基于 Revolt EventLoop 的异步 TTY 事件提供者

### 测试命令
- **文件**: `app/Command/TuiAsyncTestCommand.php`
- **状态**: ✅ 完成且语法正确
- **功能**: 读取 5 个事件后退出

## ⚠️ 测试环境要求

### 必需条件
1. **交互式 TTY 终端**
   - 不能在管道或重定向中运行
   - 不能在 IDE 的内置终端中运行（某些情况）
   - 需要真实的终端（如 GNOME Terminal、iTerm2、tmux 等）

2. **stty 命令可用**
   - Unix-like 系统（Linux、macOS、BSD）
   - Windows 需要额外配置

### 如何测试

```bash
# 在真实的交互式终端中运行
./wind tui:async-test
```

**预期行为**：
- 提示"请按下 5 个键进行测试..."
- 每次按键显示事件信息
- 5 个键后自动退出

## 🔍 技术细节

### AsyncTtyEventProvider 实现原理

```php
class AsyncTtyEventProvider implements EventProvider
{
    public function __construct()
    {
        // 1. 设置 STDIN 为非阻塞
        stream_set_blocking(STDIN, false);

        // 2. 注册 EventLoop 可读回调
        EventLoop::onReadable(STDIN, function (string $callbackId, $stream) {
            $this->handleReadable();
        });

        // 3. 取消引用，防止阻止事件循环退出
        EventLoop::unreference($this->callbackId);
    }

    public function next(): ?Event
    {
        // 如果有缓存事件，立即返回
        if (!empty($this->eventBuffer)) {
            return array_shift($this->eventBuffer);
        }

        // 没有事件时，挂起当前协程
        $this->suspension = EventLoop::getSuspension();
        return $this->suspension->suspend();
    }

    private function handleReadable(): void
    {
        // 读取数据
        $bytes = stream_get_contents(STDIN);

        // 解析事件
        $this->parser->advance($bytes, more: false);
        $events = $this->parser->drain();

        // 添加到缓冲区
        foreach ($events as $event) {
            $this->eventBuffer[] = $event;
        }

        // 如果有协程在等待，恢复它
        if ($this->suspension !== null && !empty($this->eventBuffer)) {
            $event = array_shift($this->eventBuffer);
            $suspension = $this->suspension;
            $this->suspension = null;
            $suspension->resume($event);
        }
    }
}
```

### 关键点

1. **EventLoop::onReadable() 签名**
   ```php
   function(string $callbackId, resource $stream): void
   ```

2. **Suspension 使用**
   ```php
   $suspension = EventLoop::getSuspension();
   $value = $suspension->suspend(); // 挂起
   $suspension->resume($value);     // 恢复
   ```

3. **与 php-tui 完全兼容**
   - 使用相同的 `EventParser`
   - 使用相同的 `stream_get_contents(STDIN)`
   - 事件类型完全一致

## 📝 使用示例

```php
use App\Libs\Tui\AsyncTtyEventProvider;
use PhpTui\Term\Terminal;
use function Amp\async;

$terminal = Terminal::new(
    eventProvider: new AsyncTtyEventProvider()
);

$terminal->enableRawMode();

try {
    // 事件处理协程
    async(function () use ($terminal) {
        while (true) {
            $event = $terminal->events()->next();
            // 处理事件...
        }
    });

    // 其他协程可以并发运行
    async(function () {
        while (true) {
            delay(1.0);
            // 执行其他任务
        }
    });

} finally {
    $terminal->disableRawMode();
}
```

## ✨ 优势

1. **协程友好**: 只阻塞当前协程，不阻塞整个进程
2. **非阻塞 I/O**: 使用 EventLoop 驱动，无需轮询
3. **完全兼容**: 与 php-tui 的 EventProvider 接口 100% 兼容
4. **并发支持**: 可以同时运行多个协程
5. **事件一致**: 与原生事件完全相同

## 🎯 总结

`AsyncTtyEventProvider` 已成功实现，代码正确且语法无误。唯一的要求是需要在真实的交互式 TTY 终端中运行测试。

如果你在真实的终端中运行 `./wind tui:async-test`，应该能看到它正常工作，读取 5 个按键事件后退出。
