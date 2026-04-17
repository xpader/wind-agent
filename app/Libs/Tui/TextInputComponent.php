<?php

declare(strict_types=1);

namespace App\Libs\Tui;

use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\Event;
use PhpTui\Term\KeyCode;
use PhpTui\Term\KeyModifiers;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Text\Text;
use PhpTui\Tui\Text\Title;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Extension\Core\Widget\Block\Padding;
use PhpTui\Tui\Extension\Core\Widget\Paragraph\Wrap;
use PhpTui\Tui\Widget\Widget;

/**
 * 文本输入组件
 *
 * 基于 php-tui 的可复用文本输入组件
 * 支持中文、英文、符号和多字节字符
 */
class TextInputComponent
{
    private string $text = '';
    private int $maxLength = 50;
    private string $placeholder = 'Type to input';
    private string $label = '输入';
    private bool $enabled = true;
    private int $cursorPosition = 0; // 光标位置（字符索引）

    /**
     * 创建新的文本输入组件
     */
    public static function new(): self
    {
        return new self();
    }

    /**
     * 设置初始文本
     */
    public function text(string $text): self
    {
        $this->text = $text;
        return $this;
    }

    /**
     * 获取当前文本
     */
    public function getText(): string
    {
        return $this->text;
    }

    /**
     * 设置最大长度（按字符数计算）
     */
    public function maxLength(int $length): self
    {
        $this->maxLength = $length;
        return $this;
    }

    /**
     * 设置占位符文本
     */
    public function placeholder(string $placeholder): self
    {
        $this->placeholder = $placeholder;
        return $this;
    }

    /**
     * 设置标签文本
     */
    public function label(string $label): self
    {
        $this->label = $label;
        return $this;
    }

    /**
     * 设置是否启用
     */
    public function enabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        return $this;
    }

    /**
     * 清空文本
     */
    public function clear(): void
    {
        $this->text = '';
        $this->cursorPosition = 0;
    }

    /**
     * 处理键盘事件
     */
    public function handle(Event $event): void
    {
        if (!$this->enabled) {
            return;
        }

        // 只处理字符键和编码键事件，忽略其他事件
        if (!$event instanceof CharKeyEvent && !$event instanceof CodedKeyEvent) {
            return;
        }

        if ($event instanceof CharKeyEvent) {
            $this->handleCharKey($event);
        } elseif ($event instanceof CodedKeyEvent) {
            $this->handleCodedKey($event);
        }
    }

    /**
     * 构建显示组件
     */
    public function build(): Widget
    {
        // 构建输入显示文本（支持光标位置）
        if (!empty($this->text)) {
            // 将文本按光标位置分成两部分，在光标位置插入下划线
            $before = mb_substr($this->text, 0, $this->cursorPosition, 'UTF-8');
            $after = mb_substr($this->text, $this->cursorPosition, null, 'UTF-8');
            $displayText = sprintf('%s<fg=darkgray>_</>%s <fg=darkgray>(Typing..)</>',
                $before,
                $after
            );
        } else {
            $displayText = sprintf('<fg=darkgray>_</><fg=darkgray>%s</>',
                $this->placeholder
            );
        }

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->titles(Title::fromString(' Text Input '))
            ->padding(Padding::horizontal(1))
            ->widget(
                ParagraphWidget::fromText(
                    Text::parse($displayText)
                )->wrap(Wrap::Word)
            );
    }

    /**
     * 处理字符键事件
     */
    private function handleCharKey(CharKeyEvent $event): void
    {
        $char = $event->char;

        // 处理 Ctrl+A - 移动到开头
        if ($event->modifiers & KeyModifiers::CONTROL && $char === 'a') {
            $this->cursorPosition = 0;
            return;
        }

        // 处理 Ctrl+E - 移动到结尾
        if ($event->modifiers & KeyModifiers::CONTROL && $char === 'e') {
            $this->cursorPosition = mb_strlen($this->text, 'UTF-8');
            return;
        }

        // 不处理其他有 CTRL 修饰键的事件（让主程序处理）
        if ($event->modifiers & KeyModifiers::CONTROL) {
            return;
        }

        // 处理退格键（某些终端可能作为字符发送）
        if ($char === "\x7f" || $char === "\x08") {
            $this->backspace();
            return;
        }

        // 跳过控制字符和换行
        if ($char === "\t" || $char === "\n") {
            return;
        }

        // 处理可打印字符 - 在光标位置插入
        if (ctype_print($char)) {
            // 在光标位置插入字符并限制长度
            if (mb_strlen($this->text, 'UTF-8') < $this->maxLength) {
                $before = mb_substr($this->text, 0, $this->cursorPosition, 'UTF-8');
                $after = mb_substr($this->text, $this->cursorPosition, null, 'UTF-8');
                $this->text = $before . $char . $after;
                $this->cursorPosition++; // 移动光标到新字符后面
            }
        } else {
            // 处理不可打印字符（如中文输入法的中间状态）
            if (mb_strlen($this->text, 'UTF-8') < $this->maxLength) {
                $before = mb_substr($this->text, 0, $this->cursorPosition, 'UTF-8');
                $after = mb_substr($this->text, $this->cursorPosition, null, 'UTF-8');
                $this->text = $before . $char . $after;
                $this->cursorPosition++;
            }
        }
    }

    /**
     * 处理编码键事件
     */
    private function handleCodedKey(CodedKeyEvent $event): void
    {
        // 处理 Backspace 键
        if ($event->code === KeyCode::Backspace) {
            $this->backspace();
        }
        // 处理左箭头键
        elseif ($event->code === KeyCode::Left) {
            if ($this->cursorPosition > 0) {
                $this->cursorPosition--;
            }
        }
        // 处理右箭头键
        elseif ($event->code === KeyCode::Right) {
            $maxPosition = mb_strlen($this->text, 'UTF-8');
            if ($this->cursorPosition < $maxPosition) {
                $this->cursorPosition++;
            }
        }
        // 处理 Home 键（移动到开头）
        elseif ($event->code === KeyCode::Home) {
            $this->cursorPosition = 0;
        }
        // 处理 End 键（移动到末尾）
        elseif ($event->code === KeyCode::End) {
            $this->cursorPosition = mb_strlen($this->text, 'UTF-8');
        }
    }

    /**
     * 执行退格删除操作（删除光标前的字符）
     */
    private function backspace(): void
    {
        if ($this->cursorPosition > 0) {
            $before = mb_substr($this->text, 0, $this->cursorPosition - 1, 'UTF-8');
            $after = mb_substr($this->text, $this->cursorPosition, null, 'UTF-8');
            $this->text = $before . $after;
            $this->cursorPosition--; // 光标向后移动
        }
    }
}
