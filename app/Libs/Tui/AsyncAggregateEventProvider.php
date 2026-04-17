<?php

declare(strict_types=1);

namespace App\Libs\Tui;

use PhpTui\Term\Event;
use PhpTui\Term\EventProvider;
use PhpTui\Term\Event\TerminalResizedEvent;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;

/**
 * 异步聚合事件提供者
 *
 * 组合多个事件源，提供真正的异步事件聚合。
 * 使用 EventLoop 的原生机制监听多个事件源：
 * - onReadable: 监听 STDIN 键盘输入
 * - onSignal: 监听 SIGWINCH 信号（终端大小变化）
 *
 * 当任何一个事件源有事件时，立即恢复协程并返回事件。
 */
final class AsyncAggregateEventProvider implements EventProvider
{
    /**
     * 当前协程的 Suspension 对象
     */
    private ?Suspension $suspension = null;

    /**
     * 事件缓冲区
     *
     * @var Event[]
     */
    private array $eventBuffer = [];

    /**
     * TTY 流资源（STDIN）
     */
    private mixed $stream;

    /**
     * STDIN 可读回调 ID
     */
    private string $readCallbackId = '';

    /**
     * SIGWINCH 信号回调 ID
     */
    private string $signalCallbackId = '';

    /**
     * 构造函数
     *
     * 注册 EventLoop 监听：
     * 1. STDIN 可读事件（键盘输入）
     * 2. SIGWINCH 信号事件（终端 Resize）
     */
    public function __construct()
    {
        // 使用 STDIN 作为 TTY 流
        $this->stream = STDIN;

        // 设置为非阻塞模式
        stream_set_blocking($this->stream, false);

        // 注册 STDIN 可读事件监听
        $this->readCallbackId = EventLoop::onReadable($this->stream, function (string $callbackId, $stream) {
            $this->handleReadable();
        });

        // 注册 SIGWINCH 信号监听（终端大小变化）
        try {
            $this->signalCallbackId = EventLoop::onSignal(SIGWINCH, function (string $callbackId, int $signal) {
                $this->handleSignal($signal);
            });
        } catch (\Throwable $e) {
            // 如果系统不支持信号处理（如 Windows），忽略错误
            // signalCallbackId 保持为空
        }
    }

    /**
     * 获取下一个事件（协程友好的阻塞读取）
     *
     * 如果有缓存的事件，立即返回
     * 如果没有事件，挂起当前协程，等待任意事件源触发
     *
     * @return Event|null
     */
    public function next(): ?Event
    {
        // 先返回缓冲区中的事件
        if (!empty($this->eventBuffer)) {
            return array_shift($this->eventBuffer);
        }

        // 没有事件时，挂起当前协程，等待任意事件源触发
        $this->suspension = EventLoop::getSuspension();
        return $this->suspension->suspend();
    }

    /**
     * 处理 STDIN 可读事件
     *
     * 当 STDIN 有数据可读时，EventLoop 会调用这个方法。
     */
    private function handleReadable(): void
    {
        // 读取可用的数据
        $bytes = stream_get_contents($this->stream);

        if ($bytes === false || $bytes === '') {
            return;
        }

        // 解析键盘事件
        $parser = \PhpTui\Term\EventParser::new();
        $parser->advance($bytes, more: false);
        $events = $parser->drain();

        // 将事件添加到缓冲区
        foreach ($events as $event) {
            $this->eventBuffer[] = $event;
        }

        // 如果有协程在等待，恢复它
        $this->resumeIfWaiting();
    }

    /**
     * 处理信号事件
     *
     * 当收到 SIGWINCH 信号时，EventLoop 会调用这个方法。
     */
    private function handleSignal(int $signal): void
    {
        if ($signal === SIGWINCH) {
            // 创建终端 Resize 事件
            $this->eventBuffer[] = new \PhpTui\Term\Event\TerminalResizedEvent();

            // 如果有协程在等待，恢复它
            $this->resumeIfWaiting();
        }
    }

    /**
     * 如果有协程在等待且有事件，恢复协程
     */
    private function resumeIfWaiting(): void
    {
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
     */
    public function __destruct()
    {
        if ($this->readCallbackId !== '') {
            EventLoop::cancel($this->readCallbackId);
            $this->readCallbackId = '';
        }

        if ($this->signalCallbackId !== '') {
            EventLoop::cancel($this->signalCallbackId);
            $this->signalCallbackId = '';
        }
    }
}
