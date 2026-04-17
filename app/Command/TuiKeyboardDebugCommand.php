<?php

declare(strict_types=1);

namespace App\Command;

use PhpTui\Term\Actions;
use PhpTui\Term\ClearType;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\Event;
use PhpTui\Term\KeyCode;
use PhpTui\Term\KeyModifiers;
use PhpTui\Term\Terminal;
use PhpTui\Tui\Bridge\PhpTerm\PhpTermBackend;
use PhpTui\Tui\DisplayBuilder;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Text\Text;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Color\AnsiColor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use function Amp\delay;

/**
 * TUI 键盘调试命令
 *
 * 用于诊断键盘事件和终端兼容性问题
 */
class TuiKeyboardDebugCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('tui:keyboard-debug')
            ->setDescription('调试键盘事件和终端兼容性（需要在交互式终端中运行）');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln("<info>键盘事件调试工具</info>");
        $output->writeln("<comment>按 Ctrl+C 退出</comment>");
        $output->writeln("");
        $output->writeln("<comment>即将启动键盘调试界面...</comment>");

        delay(1);

        try {
            $this->runKeyboardDebug();
            return self::SUCCESS;
        } catch (Throwable $e) {
            $output->writeln("");
            $output->writeln("<fg=red;options=bold>✗ 启动失败</>");
            $output->writeln("<error>错误信息: " . $e->getMessage() . "</error>");
            return self::FAILURE;
        }
    }

    private function runKeyboardDebug(): void
    {
        $terminal = Terminal::new();
        $backend = PhpTermBackend::new($terminal);
        $display = DisplayBuilder::default($backend)->build();

        try {
            $terminal->execute(Actions::cursorHide());
            $terminal->execute(Actions::alternateScreenEnable());
            $terminal->enableRawMode();

            // 启用扩展键盘模式以支持修饰键检测
            // 这使得 Shift+Enter、Ctrl+Enter 等组合键发送不同的转义序列
            $terminal->execute(Actions::printString("\033[>4;1m"));

            $eventHistory = [];
            $maxHistory = 15;
            $lastEventInfo = '等待按键...';
            $keyStatistics = [
                'total' => 0,
                'charKeys' => 0,
                'codedKeys' => 0,
                'withModifiers' => 0,
            ];

            while (true) {
                // 处理事件
                while (null !== $event = $terminal->events()->next()) {
                    $keyStatistics['total']++;

                    // 详细的事件分析
                    $eventAnalysis = $this->analyzeEvent($event);

                    // 更新统计
                    if ($event instanceof CharKeyEvent) {
                        $keyStatistics['charKeys']++;
                        if ($event->modifiers !== KeyModifiers::NONE) {
                            $keyStatistics['withModifiers']++;
                        }
                    } elseif ($event instanceof CodedKeyEvent) {
                        $keyStatistics['codedKeys']++;
                        if (($event->modifiers ?? KeyModifiers::NONE) !== KeyModifiers::NONE) {
                            $keyStatistics['withModifiers']++;
                        }
                    }

                    // 处理 Ctrl+C 退出
                    if ($event instanceof CharKeyEvent) {
                        if ($event->char === 'c' && ($event->modifiers & KeyModifiers::CONTROL)) {
                            break 2;
                        }
                    }

                    // 在调试模式下，记录所有事件（包括 CTRL 组合键）
                    // 不要跳过任何事件，都要记录到历史中

                    // 更新历史记录
                    $lastEventInfo = $eventAnalysis['summary'];
                    array_unshift($eventHistory, $eventAnalysis);
                    if (count($eventHistory) > $maxHistory) {
                        array_pop($eventHistory);
                    }
                }

                // 绘制界面
                $display->draw($this->buildDebugInterface($lastEventInfo, $eventHistory, $keyStatistics));

                // 异步延迟
                delay(0.05);
            }
        } finally {
            $terminal->disableRawMode();
            $terminal->execute(Actions::cursorShow());
            $terminal->execute(Actions::alternateScreenDisable());
            $terminal->execute(Actions::clear(ClearType::All));
        }
    }

    /**
     * 深度分析事件
     */
    private function analyzeEvent($event): array
    {
        $analysis = [
            'summary' => '',
            'details' => [],
            'raw' => '',
        ];

        if ($event instanceof CharKeyEvent) {
            $char = $event->char;
            $charCode = ord($char);
            $hexCode = sprintf("\\x%02x", $charCode);
            $binaryCode = sprintf("%08b", $charCode);

            $analysis['summary'] = sprintf("CharKey: '%s' [%s]",
                $this->displayChar($char),
                $this->formatModifiers($event->modifiers)
            );

            $analysis['details'] = [
                sprintf("字符: %s", $this->displayChar($char)),
                sprintf("ASCII码: %d (0x%s)", $charCode, dechex($charCode)),
                sprintf("二进制: %s", $binaryCode),
                sprintf("UTF-8字节: %d", strlen($char)),
                sprintf("可打印: %s", ctype_print($char) ? '是' : '否'),
                sprintf("修饰键: %s", $this->formatModifiers($event->modifiers)),
            ];

            $analysis['raw'] = sprintf(
                "CharKeyEvent(char='%s',modifiers=%d,ascii=%d,hex=%s,bytes=%d)",
                $char,
                $event->modifiers,
                $charCode,
                dechex($charCode),
                strlen($char)
            );
        } elseif ($event instanceof CodedKeyEvent) {
            $keyCode = $event->code->name;
            $modifiers = $event->modifiers ?? KeyModifiers::NONE;

            $analysis['summary'] = sprintf("CodeKey: %s [%s]", $keyCode, $this->formatModifiers($modifiers));

            $analysis['details'] = [
                sprintf("按键: %s", $keyCode),
                sprintf("修饰键: %s", $this->formatModifiers($modifiers)),
            ];

            $analysis['raw'] = sprintf(
                "CodedKeyEvent(code=%s,modifiers=%d)",
                $keyCode,
                $modifiers
            );
        } else {
            $analysis['summary'] = get_class($event);
            $analysis['details'] = [get_class($event)];
            $analysis['raw'] = get_class($event);
        }

        return $analysis;
    }

    /**
     * 显示字符（安全显示控制字符）
     */
    private function displayChar(string $char): string
    {
        if (!ctype_print($char)) {
            return sprintf("\\x%02x", ord($char));
        }
        return $char;
    }

    /**
     * 格式化修饰键
     */
    private function formatModifiers(int $modifiers): string
    {
        $parts = [];
        if ($modifiers & KeyModifiers::SHIFT) {
            $parts[] = 'SHIFT';
        }
        if ($modifiers & KeyModifiers::ALT) {
            $parts[] = 'ALT';
        }
        if ($modifiers & KeyModifiers::CONTROL) {
            $parts[] = 'CTRL';
        }

        return empty($parts) ? 'NONE' : implode('|', $parts);
    }

    /**
     * 构建调试界面
     */
    private function buildDebugInterface(string $lastEventInfo, array $eventHistory, array $keyStatistics)
    {
        // 构建统计信息
        $statsText = Text::parse(sprintf(
            "<fg=yellow;options=bold>事件统计：</>总计: %d | 字符键: %d | 编码键: %d | 带修饰键: %d\n\n",
            $keyStatistics['total'],
            $keyStatistics['charKeys'],
            $keyStatistics['codedKeys'],
            $keyStatistics['withModifiers']
        ));

        // 构建当前事件信息
        $currentEventText = Text::parse(sprintf(
            "<fg=green;options=bold>当前事件：</>%s\n\n",
            $lastEventInfo
        ));

        // 添加键盘提示
        if (strpos($lastEventInfo, 'j') !== false && strpos($lastEventInfo, 'CTRL') !== false) {
            $currentEventText = $currentEventText->append(Text::parse(
                "<fg=yellow;options=bold>⚠ 检测到 Shift+Enter 错误映射！</>\n" .
                "<fg=red>Shift+Enter 被错误识别为 CharKey 'j' + CTRL</>\n" .
                "<fg=cyan>这是 php-tui 库或终端配置的已知问题</>\n\n"
            ));
        }

        // 构建历史记录
        $historyLines = [];
        foreach ($eventHistory as $i => $analysis) {
            $line = sprintf("<fg=darkgray>%2d.</> <fg=white>%s</>",
                $i + 1,
                $analysis['summary']
            );

            // 添加关键细节的标记
            if (isset($analysis['details']) && count($analysis['details']) > 0) {
                $line .= sprintf(" <fg=darkgray>[%s]</>", $analysis['details'][0] ?? '');
            }

            $historyLines[] = $line;
        }

        $historyText = empty($eventHistory)
            ? Text::fromString("(等待按键事件...)")
            : Text::parse(implode("\n", $historyLines));

        // 构建详细信息（显示最后事件的详细信息）
        $detailLines = [];
        if (!empty($eventHistory)) {
            $lastAnalysis = $eventHistory[0];
            $detailLines[] = "<fg=cyan;options=bold>详细信息：</>";
            foreach ($lastAnalysis['details'] as $detail) {
                $detailLines[] = sprintf("  • %s", $detail);
            }
            $detailLines[] = "";
            $detailLines[] = "<fg=darkgray>原始数据:</>";
            $detailLines[] = sprintf("  %s", $lastAnalysis['raw']);
        }

        $detailText = empty($detailLines)
            ? Text::fromString("")
            : Text::parse(implode("\n", $detailLines));

        // 组合所有文本
        $allLines = [];
        $allLines[] = "<fg=yellow;options=bold>事件统计：</>总计: {$keyStatistics['total']} | 字符键: {$keyStatistics['charKeys']} | 编码键: {$keyStatistics['codedKeys']} | 带修饰键: {$keyStatistics['withModifiers']}";
        $allLines[] = "";
        $allLines[] = "<fg=green;options=bold>当前事件：</>{$lastEventInfo}";
        $allLines[] = "";

        if (!empty($eventHistory)) {
            $lastAnalysis = $eventHistory[0];
            $allLines[] = "<fg=cyan;options=bold>详细信息：</>";
            foreach ($lastAnalysis['details'] as $detail) {
                $allLines[] = sprintf("  • %s", $detail);
            }
            $allLines[] = "";
            $allLines[] = "<fg=darkgray>原始数据:</>";
            $allLines[] = sprintf("  %s", $lastAnalysis['raw']);
        }

        $allLines[] = "";
        $allLines[] = "<fg=yellow;options=bold>事件历史（最近15个）：</>";
        $allLines[] = "";

        if (empty($eventHistory)) {
            $allLines[] = "(等待按键事件...)";
        } else {
            foreach ($eventHistory as $i => $analysis) {
                $line = sprintf("<fg=darkgray>%2d.</> <fg=white>%s</>",
                    $i + 1,
                    $analysis['summary']
                );

                // 添加关键细节的标记
                if (isset($analysis['details']) && count($analysis['details']) > 0) {
                    $line .= sprintf(" <fg=darkgray>[%s]</>", $analysis['details'][0] ?? '');
                }

                $allLines[] = $line;
            }
        }

        $allLines[] = "";
        $allLines[] = "<fg=green>提示：测试 Shift+Enter, Ctrl+Enter 等组合键 | Ctrl+C 退出</>";

        $fullText = Text::parse(implode("\n", $allLines));

        return ParagraphWidget::fromText($fullText);
    }
}
