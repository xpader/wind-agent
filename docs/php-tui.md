# PHP-TUI 实践指南

本文档记录了在 Wind Chat 项目中使用 php-tui 库实现终端用户界面的关键点和实践经验。

## 目录

- [环境要求](#环境要求)
- [安装配置](#安装配置)
- [核心概念](#核心概念)
- [组件系统](#组件系统)
- [事件处理](#事件处理)
- [布局管理](#布局管理)
- [样式系统](#样式系统)
- [实践案例](#实践案例)
- [常见问题](#常见问题)
- [最佳实践](#最佳实践)

---

## 环境要求

### 必需条件

- **PHP 版本**: 8.1+
- **交互式终端**: 必须在真实的终端中运行，不支持 IDE 内置终端
- **系统工具**: `stty` 命令可用
- **操作系统**: Linux/macOS/Windows (WSL/Git Bash)

### 终端要求

```bash
# 检查是否为交互式终端
php -r '
if (function_exists("posix_isatty") && posix_isatty(STDOUT)) {
    echo "交互式终端\n";
} else {
    echo "非交互式终端\n";
}
'
```

---

## 安装配置

### 基础安装

```bash
composer require php-tui/php-tui
```

### 依赖包

安装 `php-tui` 会自动安装以下依赖：
- `php-tui/term` - 终端控制库
- `php-tui/cassowary` - 布局算法库

---

## 核心概念

### Display（显示器）

Display 是 php-tui 的核心入口点，负责渲染和管理终端显示。

#### 创建 Display

```php
use PhpTui\Tui\DisplayBuilder;
use PhpTui\Tui\Bridge\PhpTerm\PhpTermBackend;
use PhpTui\Term\Terminal;

// 创建 Display
$terminal = Terminal::new();
$backend = PhpTermBackend::new($terminal);
$display = DisplayBuilder::default($backend)->build();
```

#### 关键方法

```php
// 清屏
$display->clear();

// 绘制组件
$display->draw($widget);

// 获取终端大小
$size = $backend->size();
```

### Widget（组件）

Widget 是 php-tui 的基本构建块，所有可见元素都是 Widget。

#### 内置 Widget

- **ParagraphWidget** - 文本段落
- **BlockWidget** - 带边框的容器
- **GridWidget** - 网格布局
- **TabsWidget** - 标签页
- **GaugeWidget** - 进度条
- **TableWidget** - 表格
- **ListWidget** - 列表
- **CanvasWidget** - 画布

---

## 组件系统

### 自定义组件示例

#### 文本输入组件

```php
<?php

namespace App\Libs\Tui;

use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\Event\Event;
use PhpTui\Term\KeyCode;
use PhpTui\Tui\Term\KeyModifiers;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Text\Text;
use PhpTui\Tui\Text\Title;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\Widget;

class TextInputComponent
{
    private string $text = '';
    private int $maxLength = 200;
    private string $placeholder = '(等待输入...)';
    private string $label = '输入';

    public function handle(Event $event): void
    {
        // 只处理字符键和编码键事件
        if (!$event instanceof CharKeyEvent && !$event instanceof CodedKeyEvent) {
            return;
        }

        if ($event instanceof CharKeyEvent) {
            $this->handleCharKey($event);
        } elseif ($event instanceof CodedKeyEvent) {
            $this->handleCodedKey($event);
        }
    }

    public function build(): Widget
    {
        // 构建输入显示
        if (!empty($this->text)) {
            $charCount = mb_strlen($this->text, 'UTF-8');
            $byteCount = strlen($this->text);
            $displayText = sprintf('<fg=yellow>%s:</> %s_ <fg=darkgray>(%d 字符, %d 字节)</>',
                $this->label,
                $this->text,
                $charCount,
                $byteCount
            );
        } else {
            $displayText = sprintf('<fg=yellow>%s:</> _<fg=darkgray>%s</>',
                $this->label,
                $this->placeholder
            );
        }

        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->titles(Title::fromString(' 文本输入 '))
            ->widget(
                ParagraphWidget::fromText(
                    Text::parse(sprintf("%s\n<fg=green>Wind Chat © 2025</>", $displayText))
                )
            );
    }

    private function handleCharKey(CharKeyEvent $event): void
    {
        $char = $event->char;

        // 不处理 CTRL 修饰键的事件
        if ($event->modifiers & KeyModifiers::CONTROL) {
            return;
        }

        // 处理退格键
        if ($char === "\x7f" || $char === "\x08") {
            $this->backspace();
            return;
        }

        // 跳过控制字符和换行
        if ($char === "\t" || $char === "\n") {
            return;
        }

        // 处理可打印字符
        if (ctype_print($char)) {
            if (mb_strlen($this->text, 'UTF-8') < $this->maxLength) {
                $this->text .= $char;
            }
        } else {
            // 处理不可打印字符（中文输入法中间状态）
            $this->text .= $char;
        }
    }

    private function handleCodedKey(CodedKeyEvent $event): void
    {
        if ($event->code === KeyCode::Backspace) {
            $this->backspace();
        }
    }

    private function backspace(): void
    {
        if (mb_strlen($this->text, 'UTF-8') > 0) {
            $this->text = mb_substr($this->text, 0, -1, 'UTF-8');
        }
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function clear(): void
    {
        $this->text = '';
    }
}
```

---

## 事件处理

### 事件类型

php-tui 使用面向对象的事件系统，主要事件类型：

#### CharKeyEvent（字符键事件）

```php
use PhpTui\Term\Event\CharKeyEvent;

if ($event instanceof CharKeyEvent) {
    $char = $event->char;           // 按键的字符
    $modifiers = $event->modifiers;  // 修饰键状态
}
```

#### CodedKeyEvent（编码键事件）

```php
use PhpTui\Term\Event\CodedKeyEvent;

if ($event instanceof CodedKeyEvent) {
    $keyCode = $event->code;  // 键码（如 KeyCode::Enter）
    $modifiers = $event->modifiers; // 修饰键状态
}
```

### 修饰键检测

```php
use PhpTui\Term\KeyModifiers;

// 检查单个修饰键
if ($event->modifiers & KeyModifiers::SHIFT) {
    // 包含 SHIFT 键
}

if ($event->modifiers & KeyModifiers::CONTROL) {
    // 包含 CTRL 键
}

if ($event->modifiers & KeyModifiers::ALT) {
    // 包含 ALT 键
}

// 检查无修饰键
if ($event->modifiers === KeyModifiers::NONE) {
    // 无修饰键
}
```

### 事件循环模式

```php
// 主循环
while (true) {
    // 处理事件
    while (null !== $event = $terminal->events()->next()) {
        // 处理 Ctrl+C
        if ($event instanceof CharKeyEvent) {
            if ($event->char === 'c' && ($event->modifiers & KeyModifiers::CONTROL)) {
                if (!empty($textInput->getText())) {
                    $textInput->clear();
                    continue;
                } else {
                    break 2; // 退出程序
                }
            }
        }

        // 让组件处理其他事件
        $textInput->handle($event);
    }

    // 绘制界面
    $display->draw($layout);

    // 异步延迟（使用 AMPHP）
    delay(0.05); // 50ms
}
```

### 重要事件处理原则

1. **系统快捷键优先**：不要拦截 Ctrl+Z、Ctrl+\ 等系统快捷键
2. **组件职责分离**：让组件只处理相关事件
3. **事件传递链**：主程序先处理，然后传递给组件
4. **修饰键检查**：正确处理 SHIFT、CTRL、ALT 组合键

---

## 布局管理

### GridWidget（网格布局）

GridWidget 是最灵活的布局组件，支持复杂的嵌套布局。

#### 基础网格布局

```php
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Widget\Direction;

// 水平布局
$grid = GridWidget::default()
    ->direction(Direction::Horizontal)
    ->constraints(
        Constraint::percentage(50),  // 50% 宽度
        Constraint::percentage(50),  // 50% 宽度
    )
    ->widgets(
        $widget1,
        $widget2,
    );
```

#### 垂直布局

```php
// 垂直布局
$grid = GridWidget::default()
    ->direction(Direction::Vertical)
    ->constraints(
        Constraint::length(3),    // 固定高度 3 行
        Constraint::min(1),       // 最小高度 1 行
        Constraint::percentage(50), // 50% 高度
    )
    ->widgets(
        $headerWidget,
        $contentWidget,
        $footerWidget,
    );
```

#### 约束类型

```php
use PhpTui\Tui\Layout\Constraint;

// 固定长度
Constraint::length(10)     // 固定 10 行高

// 百分比
Constraint::percentage(50)   // 50% 高度/宽度

// 最小值
Constraint::min(10)          // 最小 10 行高

// 最大值
Constraint::max(20)          // 最大 20 行高
```

#### 嵌套布局

```php
GridWidget::default()
    ->direction(Direction::Vertical)
    ->constraints(
        Constraint::length(3),
        Constraint::min(1),
        Constraint::length(3),
    )
    ->widgets(
        $headerWidget,
        // 嵌套的水平布局
        GridWidget::default()
            ->direction(Direction::Horizontal)
            ->constraints(
                Constraint::percentage(50),
                Constraint::percentage(50),
            )
            ->widgets(
                $leftWidget,
                $rightWidget,
            ),
        $footerWidget,
    );
```

---

## 样式系统

### 颜色系统

#### ANSI 颜色

```php
use PhpTui\Tui\Color\AnsiColor;
use PhpTui\Tui\Style\Style;

// 前景色
Style::default()->fg(AnsiColor::Red)
Style::default()->fg(AnsiColor::Green)
Style::default()->fg(AnsiColor::Yellow)
Style::default()->fg(AnsiColor::Blue)
Style::default()->fg(AnsiColor::Magenta)
Style::default()->fg(AnsiColor::Cyan)
Style::default()->fg(AnsiColor::White)

// 背景色
Style::default()->bg(AnsiColor::Red)
Style::default()->bg(AnsiColor::Blue)
```

#### 文本样式

```php
use PhpTui\Tui\Text\Text;

// 简单文本
Text::fromString('Hello World')

// 带样式的文本
Text::parse('<fg=red>红色</>文本')
Text::parse('<fg=green;options=bold>绿色加粗</>文本')
Text::parse('<bg=blue>蓝色背景</>文本')
```

### BlockWidget 样式

```php
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\BorderType;

BlockWidget::default()
    ->borders(Borders::ALL)              // 边框
    ->borderType(BorderType::Rounded)     // 圆角边框
    ->style(Style::default()
        ->fg(AnsiColor::Cyan)              // 前景色
        ->bg(AnsiColor::Black))             // 背景色
    ->titles(Title::fromString('标题'));   // 标题
```

---

## 实践案例

### 完整的 TUI 应用结构

#### 主循环实现

```php
<?php

declare(strict_types=1);

use PhpTui\Term\Actions;
use PhpTui\Term\ClearType;
use PhpTui\Term\Terminal;
use PhpTui\Tui\Bridge\PhpTerm\PhpTermBackend;
use PhpTui\Tui\DisplayBuilder;
use function Amp\delay;

class TuiApplication
{
    public function run(): int
    {
        $terminal = Terminal::new();
        $backend = PhpTermBackend::new($terminal);
        $display = DisplayBuilder::default($backend)->build();

        try {
            // 启用终端原始模式
            $terminal->execute(Actions::cursorHide());
            $terminal->execute(Actions::alternateScreenEnable());
            $terminal->enableRawMode();

            return $this->mainLoop($terminal, $display);

        } finally {
            // 恢复终端状态
            $terminal->disableRawMode();
            $terminal->execute(Actions::cursorShow());
            $terminal->execute(Actions::alternateScreenDisable());
            $terminal->execute(Actions::clear(ClearType::All));
        }
    }

    private function mainLoop(Terminal $terminal, Display $display): int
    {
        $page = 0;
        $textInput = TextInputComponent::new();

        while (true) {
            // 处理事件
            while (null !== $event = $terminal->events()->next()) {
                // 事件处理逻辑
                $textInput->handle($event);
            }

            // 绘制界面
            $display->draw($this->buildLayout($page, $textInput));

            // 异步延迟
            delay(0.05);
        }

        return 0;
    }
}
```

### 关键实现要点

#### 1. 终端模式管理

```php
try {
    // 启用特殊终端模式
    $terminal->execute(Actions::cursorHide());
    $terminal->execute(Actions::alternateScreenEnable());
    $terminal->enableRawMode();

    // 主逻辑
    $this->mainLoop($terminal, $display);

} finally {
    // 确保恢复终端状态
    $terminal->disableRawMode();
    $terminal->execute(Actions::cursorShow());
    $terminal->execute(Actions::alternateScreenDisable());
    $terminal->execute(Actions::clear(ClearType::All));
}
```

#### 2. 事件处理流程

```php
while (null !== $event = $terminal->events()->next()) {
    // 1. 主程序优先处理系统事件
    if ($event instanceof CharKeyEvent) {
        if ($event->char === 'c' && ($event->modifiers & KeyModifiers::CONTROL)) {
            break 2; // 退出程序
        }
    }

    // 2. Tab 键切换等导航事件
    if ($event instanceof CodedKeyEvent) {
        if ($event->code === KeyCode::Tab) {
            $page = ($page + 1) % $totalPages;
        }
    }

    // 3. 组件处理剩余事件
    $textInput->handle($event);
}
```

#### 3. 延迟函数选择

```php
// ❌ 错误：阻塞式延迟（不适合协程）
usleep(50_000);

// ✅ 正确：异步延迟（协程友好）
use function Amp\delay;
delay(0.05); // 50ms
```

---

## 常见问题

### Q1: 终端 Resize 后界面无法自适应

**症状**：调整终端窗口大小后，TUI 界面没有相应调整

**根本原因**：
php-tui 使用 `AggregateInformationProvider` 按顺序尝试两种方式获取终端尺寸：

1. `SizeFromEnvVarProvider` - 从环境变量 `LINES` 和 `COLUMNS` 读取
2. `SizeFromSttyProvider` - 执行 `stty -a` 命令获取

当第一个提供者成功返回值后，就不会尝试第二个。

在 Wind Framework/Symfony Console 环境中：
- 框架启动时会设置并缓存 `LINES` 和 `COLUMNS` 环境变量
- 这些环境变量记录的是**启动时**的终端尺寸
- 用户调整终端窗口大小后，环境变量**不会自动更新**
- 因此 php-tui 永远获取到的是启动时的旧尺寸

**解决方案**：

创建 Terminal 时，**只使用 SizeFromSttyProvider**，跳过环境变量提供者：

```php
use PhpTui\Term\Terminal;
use PhpTui\Term\InformationProvider\SizeFromSttyProvider;

// ❌ 错误：会使用缓存的环境变量
$terminal = Terminal::new();

// ✅ 正确：只使用 stty 命令获取实时尺寸
$terminal = Terminal::new(
    null,
    SizeFromSttyProvider::new()
);
```

**验证方法**：

```bash
# 检查环境变量是否被设置
echo "LINES: $LINES"
echo "COLUMNS: $COLUMNS"

# 检查 stty 命令获取的实时尺寸
stty -a | grep -E "rows|columns"
```

**注意事项**：

- **Windows 兼容性**：Windows 系统没有 `stty` 命令，只能依赖环境变量。如果环境变量未设置，会抛出 "Could not determine terminal size!" 异常
- **性能影响**：每次调用 `backend->size()` 都会执行 `stty -a` 命令（同步阻塞操作），php-tui 的 `Display::draw()` 内部会自动调用，无需手动处理

### Q2: TUI 无法在 IDE 终端中运行

**症状**：
```
Could not get stty settings
Call Stack Trace:
PhpTui\Tui\Bridge\PhpTerm\PhpTermBackend.php:54
```

**原因**：IDE 内置终端不是交互式终端

**解决方案**：
1. 在系统终端中运行
2. 使用 SSH 连接到本地
3. 使用 screen/tmux 会话

### Q3: 中文输入法不工作

**症状**：输入中文时显示乱码或不响应

**原因**：事件处理逻辑过滤了多字节字符

**解决方案**：
```php
// 接受所有字符，包括不可打印字符
if (ctype_print($char)) {
    $this->text .= $char;
} else {
    // 中文输入法的中间状态
    $this->text .= $char;
}
```

### Q4: Backspace 无法删除

**症状**：按 Backspace 无反应

**原因**：Backspace 在不同终端中的发送方式不同

**解决方案**：
```php
// 处理字符键的退格
if ($char === "\x7f" || $char === "\x08") {
    $this->backspace();
}

// 处理编码键的退格
if ($event->code === KeyCode::Backspace) {
    $this->backspace();
}
```

### Q5: 系统快捷键被拦截

**症状**：Ctrl+Z、Ctrl+\ 等系统快捷键不工作

**原因**：程序拦截了所有 CTRL 组合键

**解决方案**：
```php
// 只处理特定的 CTRL 组合键
if ($event->modifiers & KeyModifiers::CONTROL) {
    // 只处理 Ctrl+C，其他让系统处理
    if (!($event->char === 'c' && ($event->modifiers & KeyModifiers::CONTROL))) {
        continue; // 让系统处理
    }
}
```

---

## 最佳实践

### 1. 组件设计

#### 单一职责
- 每个组件只负责特定功能
- 主程序负责协调和事件分发

#### 可配置性
- 使用链式调用提供配置选项
- 提供合理的默认值

#### 错误处理
- 在 finally 块中恢复终端状态
- 避免终端处于异常状态

### 2. 事件处理

#### 事件过滤优先级
1. 主程序处理系统级事件（退出、导航）
2. 过滤系统快捷键（Ctrl+Z、Ctrl+\）
3. 组件处理应用级事件（输入、选择）

#### 修饰键处理
```php
// 检查修饰键的正确方式
if ($event->modifiers & KeyModifiers::CONTROL) {
    // 处理 CTRL 组合键
}
```

### 3. 布局设计

#### 合理的约束
- 使用 `Constraint::min()` 给内容区足够空间
- 固定关键区域（标题、状态栏）
- 使用百分比实现响应式布局

#### 组件复用
- 将常用布局封装为方法
- 组件可以独立测试和使用

### 4. 性能优化

#### 异步延迟
```php
// ✅ 使用 AMPHP 异步延迟
delay(0.05);

// ❌ 避免阻塞延迟
usleep(50000);
```

#### 渲染频率
- 50-100ms 的渲染间隔通常足够
- 根据应用复杂度调整
- 避免过度渲染

### 5. 调试技巧

#### 事件调试
```php
// 记录事件信息
$lastEventInfo = $this->formatEventInfo($event);

// 显示事件历史
$eventHistory[] = $eventInfo;
```

#### 分步测试
- 先测试简单组件
- 逐步添加功能
- 验证事件处理逻辑

---

## 技术要点总结

### 成功要素

1. **正确的环境**：交互式终端是必需的
2. **事件处理**：正确区分和处理各种事件类型
3. **组件设计**：模块化、可复用的组件架构
4. **异步支持**：使用 AMPHP 异步函数，适合协程环境
5. **错误处理**：确保终端状态正确恢复

### 架构优势

1. **协程友好**：与 Wind Framework 的异步架构完美配合
2. **模块化设计**：组件独立，易于维护和扩展
3. **事件驱动**：响应式用户界面
4. **类型安全**：使用 PHP 8.1+ 的类型特性

### 扩展方向

1. **更多组件**：列表、表格、表单等
2. **交互增强**：鼠标支持、拖拽等
3. **动画效果**：平滑过渡、渐变等
4. **主题系统**：可切换的配色方案

---

## 参考资源

- [php-tui GitHub](https://github.com/php-tui/php-tui)
- [php-tui 文档](https://php-tui.github.io/php-tui/)
- [Wind Framework](https://github.com/wind-framework/wind-framework)
- [AMPHP 文档](https://amphp.org/)

---

*最后更新: 2025-04-10*
*Wind Chat 项目实践*
