<?php

declare(strict_types=1);

namespace App\Libs\Tui;

use PhpTui\Term\Event;
use PhpTui\Term\EventProvider;
use PhpTui\Term\EventParser;
use Revolt\EventLoop;

/**
 * 异步 TTY 事件提供者
 *
 * 使用 Revolt EventLoop 实现协程友好的事件读取。
 * 外部接口保持阻塞语义（有值时才返回），内部使用非阻塞 I/O。
 *
 * 使用示例：
 * ```php
 * $terminal = Terminal::new(
 *     eventProvider: new AsyncTtyEventProvider()
 * );
 *
 * // 在协程中使用
 * async(function () use ($terminal) {
 *     while (true) {
 *         // 协程友好的阻塞读取（有值时才返回）
 *         $event = $terminal->events()->next();
 *         // 处理事件...
 *     }
 * });
 * ```
 */
final class AsyncTtyEventProvider implements EventProvider
{
    /**
     * 当前协程的 Suspension 对象
     */
    private ?\Revolt\EventLoop\Suspension $suspension = null;

    /**
     * 事件缓冲区
     *
     * @var Event[]
     */
    private array $eventBuffer = [];

    /**
     * 事件解析器
     */
    private EventParser $parser;

    /**
     * TTY 流资源（STDIN）
     */
    private mixed $stream;

    /**
     * EventLoop 回调 ID
     */
    private string $callbackId = '';

    /**
     * 构造函数
     *
     * 初始化异步 TTY 事件提供者：
     * 1. 使用 STDIN 作为 TTY 流（与 StreamReader::tty() 一致）
     * 2. 设置为非阻塞模式
     * 3. 创建事件解析器
     * 4. 注册 EventLoop 可读事件监听
     */
    public function __construct()
    {
        // 使用 STDIN 作为 TTY 流（与 StreamReader::tty() 一致）
        $this->stream = STDIN;

        // 设置为非阻塞模式（与 StreamReader 一致）
        stream_set_blocking($this->stream, false);

        // 创建事件解析器
        $this->parser = EventParser::new();

        // 注册可读事件监听
        // 回调签名: function(string $callbackId, resource $stream): void
        $this->callbackId = EventLoop::onReadable($this->stream, function (string $callbackId, $stream) {
            $this->handleReadable();
        });

        // 注意：不要调用 unreference()，因为我们需要保持 EventLoop 运行
        // 当有协程在 next() 中等待时，EventLoop 必须保持运行状态
    }

    /**
     * 获取下一个事件（协程友好的阻塞读取）
     *
     * 如果有缓存的事件，立即返回
     * 如果没有事件，挂起当前协程，等待事件到达
     *
     * @return Event|null 返回事件，如果没有事件则挂起直到有事件
     */
    public function next(): ?Event
    {
        // 先返回缓冲区中的事件
        if (!empty($this->eventBuffer)) {
            return array_shift($this->eventBuffer);
        }

        // 没有事件时，挂起当前协程，等待 onReadable 回调恢复
        $this->suspension = EventLoop::getSuspension();
        return $this->suspension->suspend();
    }

    /**
     * 处理 TTY 可读事件
     *
     * 当 TTY 有数据可读时，EventLoop 会调用这个方法：
     * 1. 读取可用的数据
     * 2. 使用 EventParser 解析事件
     * 3. 将事件添加到缓冲区
     * 4. 如果有协程在等待，恢复协程并返回事件
     *
     * @return void
     */
    private function handleReadable(): void
    {
        // 读取可用的数据（使用 stream_get_contents 与 StreamReader 一致）
        $bytes = stream_get_contents($this->stream);

        if ($bytes === false || $bytes === '') {
            return;
        }

        // 使用 EventParser 解析事件
        // more: false 表示这是本次读取的全部数据
        $this->parser->advance($bytes, more: false);

        // 获取解析到的事件
        $events = $this->parser->drain();

        // 将事件添加到缓冲区
        foreach ($events as $event) {
            $this->eventBuffer[] = $event;
        }

        // 如果有协程在等待，并且有新事件，恢复协程
        if ($this->suspension !== null && !empty($this->eventBuffer)) {
            $event = array_shift($this->eventBuffer);
            $suspension = $this->suspension;
            $this->suspension = null;

            // 恢复挂起的协程，并返回事件
            $suspension->resume($event);
        }
    }

    /**
     * 清理资源
     *
     * 取消 EventLoop 回调，防止资源泄漏
     */
    public function __destruct()
    {
        if ($this->callbackId !== '') {
            EventLoop::cancel($this->callbackId);
            $this->callbackId = '';
        }
    }
}
