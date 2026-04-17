<?php

declare(strict_types=1);

namespace App\Command;

use PhpTui\Term\Actions;
use PhpTui\Term\ClearType;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\KeyModifiers;
use PhpTui\Term\Terminal;
use PhpTui\Term\InformationProvider\SizeFromSttyProvider;
use PhpTui\Tui\Bridge\PhpTerm\PhpTermBackend;
use PhpTui\Tui\DisplayBuilder;
use function Amp\delay;
use PhpTui\Tui\Extension\Core\Widget\Block\Padding;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Extension\Core\Widget\GaugeWidget;
use PhpTui\Tui\Extension\Core\Widget\TabsWidget;
use PhpTui\Tui\Extension\Core\Widget\ListWidget;
use PhpTui\Tui\Extension\Core\Widget\List\ListItem;
use PhpTui\Tui\Extension\Core\Widget\List\ListState;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Text\Text;
use PhpTui\Tui\Text\Title;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Text\Span;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\BorderType;
use PhpTui\Tui\Widget\Direction;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Color\AnsiColor;
use App\Libs\Tui\TextInputComponent;
use App\Libs\Tui\AsyncAggregateEventProvider;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * TUI 演示命令
 *
 * 展示 php-tui 库的基本功能
 * 注意：此命令需要在交互式终端中运行
 */
class TuiDemoCommand extends Command
{
    /**
     * TUI 状态数据
     */
    private array $state = [
        'page' => 0,
        'textInput' => null,
        'lastEventInfo' => '',
        'eventHistory' => [],
        'records' => [],  // 记录列表
        'recordOffset' => 0,  // 记录列表滚动偏移量
        'startTime' => 0,
        'charCount' => 0,
        'byteCount' => 0,
    ];

    protected function configure(): void
    {
        $this->setName('tui:demo')
            ->setDescription('演示 php-tui 终端 UI 功能（需要在交互式终端中运行）')
            ->addOption('duration', 'd', InputOption::VALUE_OPTIONAL, '演示持续时间（秒）', 0);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $duration = (int) $input->getOption('duration');

        $output->writeln("<info>Wind Chat TUI 演示</info>");
        $output->writeln("<comment>基于 php-tui 库构建的终端用户界面</comment>");
        $output->writeln("");
        $output->writeln("<fg=yellow;options=bold>操作说明：</>");
        $output->writeln("  • 按 <fg=cyan>Tab</> 键切换页面");
        $output->writeln("  • 按 <fg=cyan>Ctrl+C</> 清空输入（有内容时）或退出程序（无内容时）");
        $output->writeln("  • 输入 <fg=cyan>exit</> 并按 <fg=cyan>Enter</> 退出程序");
        $output->writeln("  • 输入 <fg=cyan>clear</> 并按 <fg=cyan>Enter</> 清空调键历史");
        $output->writeln("  • 按 <fg=cyan>Ctrl+Z</> 挂起程序（系统功能）");
        $output->writeln("  • <fg=cyan>直接输入文字</> 支持中文、英文、符号");
        $output->writeln("  • 按 <fg=cyan>Backspace</> 删除字符");
        $output->writeln("  • 支持 <fg=cyan>Shift</> 输入大写和符号");
        if ($duration > 0) {
            $output->writeln("  • {$duration} 秒后自动退出");
        }
        $output->writeln("");
        $output->writeln("<comment>即将启动 TUI 界面...</comment>");

        // 等待用户准备
        delay(1);

        try {
            $this->runTuiDemo($duration);
            return self::SUCCESS;
        } catch (Throwable $e) {
            $output->writeln("");
            $output->writeln("<fg=red;options=bold>✗ TUI 启动失败</>");
            $output->writeln("<error>错误信息: " . $e->getMessage() . "</error>");
            $output->writeln("<error>错误位置: " . $e->getFile() . ":" . $e->getLine() . "</error>");
            $output->writeln("");
            $output->writeln("<comment>提示：</comment>");
            $output->writeln("  • 此命令需要在交互式终端中运行");
            $output->writeln("  • 请确保终端支持 TUI 功能");
            $output->writeln("  • 如果在 IDE 中运行，请尝试在系统终端中运行");
            return self::FAILURE;
        }
    }

    private function runTuiDemo(int $duration): void
    {
        // 初始化状态数据
        $this->state['page'] = 0;
        $totalPages = 3;
        $this->state['textInput'] = TextInputComponent::new()
            ->label('Text Input')
            ->maxLength(200);
        $this->state['lastEventInfo'] = '';
        $this->state['eventHistory'] = [];
        $this->state['charCount'] = 0;
        $this->state['byteCount'] = 0;

        // 创建 Terminal - 使用 AsyncAggregateEventProvider 实现协程友好的事件读取
        // AsyncAggregateEventProvider 监听键盘输入和终端 Resize 信号
        $terminal = Terminal::new(
            infoProvider: SizeFromSttyProvider::new(),        // 只使用 stty 命令获取实时尺寸
            eventProvider: new AsyncAggregateEventProvider(),  // 使用异步聚合事件提供者
        );
        $backend = PhpTermBackend::new($terminal);
        $display = DisplayBuilder::default($backend)->build();

        try {
            // 启用终端原始模式
            $terminal->execute(Actions::cursorHide());
            $terminal->execute(Actions::alternateScreenEnable());
            $terminal->enableRawMode();

            // 初始绘制界面
            $display->draw($this->buildLayout());

            // 主事件处理循环
            while (true) {
                // 获取一个事件
                $event = $terminal->events()->next();

                // 如果无事件，继续等待
                if ($event === null) {
                    continue;
                }

                // 记录事件信息用于调试
                $eventInfo = $this->formatEventInfo($event);
                $this->state['lastEventInfo'] = $eventInfo;

                // 只保留最近5个事件
                array_unshift($this->state['eventHistory'], $eventInfo);
                if (count($this->state['eventHistory']) > 5) {
                    array_pop($this->state['eventHistory']);
                }

                // 处理 Ctrl+C
                if ($event instanceof CharKeyEvent) {
                    // 检查是否是 Ctrl+C（c 字符 + CTRL 修饰键）
                    if ($event->char === 'c' && ($event->modifiers & KeyModifiers::CONTROL)) {
                        // 检查输入框是否有内容
                        if (!empty($this->state['textInput']->getText())) {
                            // 有内容：清空输入框
                            $this->state['textInput']->clear();
                        } else {
                            // 无内容：直接退出程序
                            break; // 退出循环
                        }
                    }
                }

                // 处理 Tab 键页面切换
                if ($event instanceof CodedKeyEvent) {
                    if ($event->code === KeyCode::Tab) {
                        $this->state['page'] = ($this->state['page'] + 1) % $totalPages;
                    }
                    // 处理 Enter 键：检查是否输入了 "exit" 或 "clear"
                    elseif ($event->code === KeyCode::Enter) {
                        $inputText = $this->state['textInput']->getText();
                        $trimmedText = trim($inputText);

                        if ($trimmedText === 'exit') {
                            break; // 退出循环
                        } elseif ($trimmedText === 'clear') {
                            // 清空记录列表
                            $this->state['records'] = [];
                            $this->state['lastEventInfo'] = '已清空记录';
                            $this->state['textInput']->clear();
                        } elseif ($trimmedText !== '') {
                            // 将输入内容追加到记录列表（保留所有记录）
                            $this->state['records'][] = $trimmedText;
                            $this->state['textInput']->clear();
                        }
                        // 如果是空字符串，不做处理
                    }
                }

                // 让文本输入组件处理所有其他事件
                $this->state['textInput']->handle($event);

                // 更新字数统计
                $this->state['charCount'] = mb_strlen($this->state['textInput']->getText(), 'UTF-8');
                $this->state['byteCount'] = strlen($this->state['textInput']->getText());

                // 绘制界面
                $display->draw($this->buildLayout());
                // 使用 AsyncTtyEventProvider 后不需要 delay，因为 next() 会自动挂起协程等待事件
            }
        } finally {
            // 恢复终端
            $terminal->disableRawMode();
            $terminal->execute(Actions::cursorShow());
            $terminal->execute(Actions::alternateScreenDisable());
            $terminal->execute(Actions::clear(ClearType::All));
        }
    }

    private function buildLayout()
    {
        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(
                Constraint::length(3),    // 标题区
                Constraint::min(1),       // 主内容区（包含侧栏）
                Constraint::length(5),    // 输入组件区（固定 5 行高度）
                Constraint::length(6),    // 调试信息区（优化高度）
            )
            ->widgets(
                $this->buildHeader(),
                $this->buildMainContent(),
                $this->state['textInput']->build(),
                $this->buildDebugInfo(),
            );
    }

    /**
     * 构建调试信息区域
     */
    private function buildDebugInfo()
    {
        // 事件历史（最近5个）
        $historyText = '';
        if (!empty($this->state['eventHistory'])) {
            $historyLines = [];
            foreach ($this->state['eventHistory'] as $i => $event) {
                $historyLines[] = sprintf("  %d. %s", $i + 1, $event);
            }
            $historyText = implode("\n", $historyLines);
        }

        // 构建调试信息文本（没有初始信息）
        $debugText = $historyText ?: '  (No keyboard events yet)';

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->titles(Title::fromString(' Debug Info '))
            ->widget(
                ParagraphWidget::fromText(
                    Text::parse($debugText)
                )
            );
    }

    /**
     * 构建主内容区域（包含侧栏）
     */
    private function buildMainContent()
    {
        return GridWidget::default()
            ->direction(Direction::Horizontal)
            ->constraints(
                Constraint::percentage(70),  // 左侧内容区 70%
                Constraint::percentage(30),  // 右侧操作说明 30%
            )
            ->widgets(
                $this->buildPage(),
                $this->buildSidebar(),
            );
    }

    /**
     * 构建操作说明侧栏
     */
    private function buildSidebar()
    {
        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->titles(Title::fromString(' Sidebar '))
            ->padding(Padding::all(2))
            ->widget(
                ParagraphWidget::fromText(
                    Text::parse(<<<'EOT'
                        <fg=yellow;options=bold>Keyboard Shortcuts</>

                        <fg=green>Tab</>     Switch pages
                        <fg=green>Enter</>   Add to records
                        <fg=green>Ctrl+C</>  Clear/Exit

                        <fg=yellow;options=bold>Text Commands</>

                        Type <fg=green>exit</> + Enter to quit
                        Type <fg=green>clear</> + Enter to clear history
                        Other text + Enter to add to records page
                        EOT)
                )
            );
    }

    private function buildHeader()
    {
        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->style(Style::default()->fg(AnsiColor::Cyan))
            ->widget(
                TabsWidget::fromTitles(
                    Line::fromString('介绍'),
                    Line::fromString('特性'),
                    Line::fromString('记录'),
                    Line::parse('<fg=green>[输入]</>'),
                )
                ->select($this->state['page'])
                ->highlightStyle(Style::default()->fg(AnsiColor::Black)->bg(AnsiColor::Blue))
                ->divider(Span::fromString(' | '))
            );
    }

    private function buildPage()
    {
        return match ($this->state['page']) {
            0 => $this->buildIntroPage(),
            1 => $this->buildFeaturesPage(),
            2 => $this->buildRecordsPage(),
            default => $this->buildIntroPage(),
        };
    }

    private function buildIntroPage()
    {
        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(
                Constraint::percentage(60),
                Constraint::percentage(40),
            )
            ->widgets(
                BlockWidget::default()
                    ->titles(Title::fromString(' Welcome to Wind Chat '))
                    ->borders(Borders::ALL)
                    ->borderType(BorderType::Rounded)
                    ->padding(Padding::all(2))
                    ->widget(
                        ParagraphWidget::fromText(
                            Text::parse(<<<'EOT'
                                <fg=green;options=bold>Wind Chat</> is a PHP coroutine
                                application built on <fg=blue;options=bold>Wind Framework</>.

                                It demonstrates modern PHP capabilities in
                                Terminal User Interface (TUI) development.

                                This demo uses the <fg=cyan>php-tui</> library,
                                inspired by Rust's TUI/Ratatui.

                                Key Features:
                                • PHP 8.1+ coroutine support
                                • Modern terminal UI
                                • Rich component library
                                • Flexible layout system
                                EOT)
                        )
                    ),
                BlockWidget::default()
                    ->titles(Title::fromString(' Statistics '))
                    ->borders(Borders::ALL)
                    ->borderType(BorderType::Rounded)
                    ->padding(Padding::all(2))
                    ->widget(
                        ParagraphWidget::fromText(
                            Text::parse(sprintf(
                                <<<'EOT'
                                <fg=yellow;options=bold>Input Statistics</>

                                <fg=cyan>Characters:</> %d
                                <fg=cyan>Bytes:</> %d

                                <fg=cyan>Current Page: Introduction</>

                                This page introduces Wind Chat
                                project and php-tui library.

                                Press Tab to view more pages.
                                EOT,
                                $this->state['charCount'],
                                $this->state['byteCount']
                            ))
                        )
                    ),
            );
    }

    private function buildFeaturesPage()
    {
        return BlockWidget::default()
            ->titles(Title::fromString(' Key Features '))
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->padding(Padding::all(2))
            ->widget(
                ParagraphWidget::fromText(
                    Text::parse(<<<'EOT'
                        <fg=green>✓</> PHP 8.1+ coroutine support
                        <fg=green>✓</> Wind Framework framework
                        <fg=green>✓</> LLM client integration
                        <fg=green>✓</> Tool calling and skill system
                        <fg=green>✓</> Modern terminal UI
                        <fg=green>✓</> Connection pool and task system
                        <fg=green>✓</> Event-driven architecture
                        <fg=green>✓</> Database connection pool
                        <fg=green>✓</> Queue system
                        <fg=green>✓</> Scheduled tasks

                        <fg=blue;options=bold>Framework Layer</>
                        • Wind Framework
                        • Workerman
                        • AMPHP

                        <fg=blue;options=bold>UI Layer</>
                        • php-tui/php-tui
                        • Twig template

                        <fg=blue;options=bold>LLM Layer</>
                        • OpenAI compatible API
                        • Ollama native API

                        <fg=blue;options=bold>Data Layer</>
                        • MySQL (connection pool)
                        • Redis (queue/cache)
                        EOT)
                )
            );
    }

    private function buildRecordsPage()
    {
        // 构建记录列表项
        $items = [];
        if (!empty($this->state['records'])) {
            foreach ($this->state['records'] as $index => $record) {
                $items[] = ListItem::new(Text::fromString(sprintf("%d. %s", $index + 1, $record)));
            }
        } else {
            $items[] = ListItem::new(Text::fromString('(暂无记录)'));
        }

        // 不设置 offset，从第一条记录开始显示
        // ListWidget 会自动处理滚动和可视区域
        return BlockWidget::default()
            ->titles(Title::fromString(' 输入记录 '))
            ->borders(Borders::ALL)
            ->borderType(BorderType::Rounded)
            ->widget(
                ListWidget::default()
                    ->state(new ListState(0, null))
                    ->items(...$items)
            );
    }

    /**
     * 格式化事件信息用于调试显示
     */
    private function formatEventInfo($event): string
    {
        if ($event instanceof CharKeyEvent) {
            $char = $event->char;
            // 显示不可打印字符的十六进制表示
            if (!ctype_print($char)) {
                $charDisplay = sprintf("\\x%02x", ord($char));
            } else {
                $charDisplay = $char;
            }

            $modifierInfo = $this->formatModifiers($event->modifiers);

            return sprintf("CharKey: '%s' [%s] %s",
                $charDisplay,
                $modifierInfo,
                strlen($char) > 1 ? sprintf("(UTF-8: %d 字节)", strlen($char)) : ''
            );
        }

        if ($event instanceof CodedKeyEvent) {
            $keyCode = $event->code->name;
            $modifierInfo = $this->formatModifiers($event->modifiers ?? KeyModifiers::NONE);

            return sprintf("CodeKey: %s [%s]", $keyCode, $modifierInfo);
        }

        if ($event instanceof \PhpTui\Term\Event\TerminalResizedEvent) {
            return "TerminalResized";
        }

        return get_class($event);
    }

    /**
     * 格式化修饰键信息
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
}
