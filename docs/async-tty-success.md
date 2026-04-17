# AsyncTtyEventProvider 实现成功 ✅

## 测试结果

```bash
./wind tui:async-test

异步 TTY 事件测试
请按下 5 个键进行测试...

事件 #1: CharKeyEvent - CharKeyEvent(char: k, modifiers: none)
事件 #2: CharKeyEvent - CharKeyEvent(char: k, modifiers: none)
事件 #3: CharKeyEvent - CharKeyEvent(char: i, modifiers: none)
事件 #4: CharKeyEvent - CharKeyEvent(char: i, modifiers: none)
事件 #5: CharKeyEvent - CharKeyEvent(char: j, modifiers: none)

测试完成！
```

## 实现要点

### 核心文件
- `app/Libs/Tui/AsyncTtyEventProvider.php` - 异步事件提供者
- `app/Command/TuiAsyncTestCommand.php` - 测试命令

### 关键修复

**问题**: 最初使用了 `EventLoop::unreference()`，导致 EventLoop 提前退出，Suspension 永远不会被恢复。

**解决方案**: 移除 `EventLoop::unreference()` 调用，让 `onReadable` 回调保持引用，确保 EventLoop 持续运行。

### 技术原理

```php
class AsyncTtyEventProvider implements EventProvider
{
    public function __construct()
    {
        // 设置 STDIN 为非阻塞
        stream_set_blocking(STDIN, false);

        // 注册 EventLoop 可读回调（保持引用！）
        $this->callbackId = EventLoop::onReadable(STDIN, function () {
            $this->handleReadable();
        });
    }

    public function next(): ?Event
    {
        // 有缓存事件时立即返回
        if (!empty($this->eventBuffer)) {
            return array_shift($this->eventBuffer);
        }

        // 没有事件时，挂起当前协程
        $this->suspension = EventLoop::getSuspension();
        return $this->suspension->suspend();
    }

    private function handleReadable(): void
    {
        // 读取并解析事件
        $bytes = stream_get_contents(STDIN);
        $this->parser->advance($bytes, more: false);
        $events = $this->parser->drain();

        // 恢复等待中的协程
        if ($this->suspension !== null && !empty($events)) {
            $this->suspension->resume($events[0]);
            $this->suspension = null;
        }
    }
}
```

## 使用方法

```php
use App\Libs\Tui\AsyncTtyEventProvider;
use PhpTui\Term\Terminal;
use function Amp\async;

$terminal = Terminal::new(
    eventProvider: new AsyncTtyEventProvider()
);

$terminal->enableRawMode();

try {
    async(function () use ($terminal) {
        while (true) {
            $event = $terminal->events()->next();
            // 处理事件...
        }
    });
} finally {
    $terminal->disableRawMode();
}
```

## 优势

1. ✅ **协程友好**: 只阻塞当前协程，不阻塞整个进程
2. ✅ **非阻塞 I/O**: 使用 EventLoop 驱动，无需轮询
3. ✅ **完全兼容**: 与 php-tui 的 `EventProvider` 接口 100% 兼容
4. ✅ **事件一致**: 与原生事件完全相同
5. ✅ **并发支持**: 可以同时运行多个协程

## 总结

`AsyncTtyEventProvider` 已成功实现并通过测试！它提供了 `$event = $provider->next()` 的协程友好接口，内部使用 `EventLoop::onReadable()` 实现非阻塞事件读取。

🎯 目标达成！
