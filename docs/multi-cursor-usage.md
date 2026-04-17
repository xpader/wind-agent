# 多光标协议使用指南

## 概述

本项目实现了基于 kitty 终端的多光标协议支持，允许 TUI 程序在屏幕的任意位置显示光标。这使得输入法状态栏能够跟随光标位置，提供更好的用户体验。

## 功能特性

- ✅ 自动检测终端类型和支持情况
- ✅ 在指定位置显示光标（跟随主光标形状）
- ✅ 支持多个光标同时显示
- ✅ 自定义光标和文本颜色
- ✅ 兼容不支持多光标协议的终端（自动降级）
- ✅ 与 TextInput 组件无缝集成

## 支持的终端

| 终端 | 支持状态 | 备注 |
|------|----------|------|
| **kitty** | ✅ 完全支持 | 原生实现多光标协议 |
| **wezterm** | ✅ 部分支持 | 基本功能可用 |
| **alacritty** | ❌ 不支持 | 自动降级 |
| **iTerm2** | ❌ 不支持 | 自动降级 |
| **Windows Terminal** | ❌ 不支持 | 自动降级 |

## 使用方法

### 1. 在 TextInput 组件中使用

```php
use App\Libs\Tui\TextInputComponent;

// 创建输入组件并启用多光标支持
$input = TextInputComponent::new()
    ->placeholder('在此输入文本...')
    ->label('输入')
    ->useMultiCursor(true)  // 启用多光标协议
    ->renderPosition(5, 10); // 设置渲染位置

// 正常使用
$input->handle($event);
$widget = $input->build();
```

### 2. 独立使用 MultiCursorProtocol

```php
use App\Libs\Tui\MultiCursorProtocol;

$multiCursor = MultiCursorProtocol::new();

// 查询终端支持
if ($multiCursor->querySupport()) {
    // 在指定位置显示光标
    $multiCursor->setCursor(10, 20);

    // 设置多个光标
    $multiCursor->setMultipleCursors([
        ['row' => 5, 'col' => 10],
        ['row' => 7, 'col' => 15],
    ]);

    // 自定义光标颜色
    $multiCursor->setCursorColor(
        0xFF0000, // 光标颜色（红色）
        0x00FF00  // 文本颜色（绿色）
    );

    // 清除所有光标
    $multiCursor->clearAllCursors();
}
```

### 3. 测试命令

运行测试命令查看效果：

```bash
./wind test:multicursor
```

## 工作原理

### ANSI 转义序列

多光标协议使用扩展的 ANSI 转义序列与终端通信：

```
# 查询支持
ESC [ > SPACE q

# 设置光标（跟随主光标形状）
ESC [ > 29;2:row:col SPACE q

# 清除所有光标
ESC [ > 0;4 SPACE q
```

### 光标位置计算

TextInput 组件会自动计算光标在屏幕上的实际位置：

```php
public function getCursorPosition(): array
{
    // 渲染位置 + 标签长度 + 边框 + 光标在文本中的位置
    $col = $this->renderCol + 2 + mb_strlen($textBeforeCursor, 'UTF-8');
    $row = $this->renderRow + 1; // +1 因为有上边框

    return ['row' => $row, 'col' => $col];
}
```

## 终端架构

在 SSH 远程场景下：

```
┌─────────────────────────────────────────┐
│ TUI 程序（SSH 服务器）                  │
│ • 使用多光标协议发送光标位置            │
│ • printf "\e[>29;2:10:20 q"             │
└─────────────────────────────────────────┘
                  ↓ SSH
┌─────────────────────────────────────────┐
│ 终端模拟器（本地机器）                  │
│ • 接收多光标协议转义序列                │
│ • 在指定位置显示硬件光标                │
│ • 输入法框架查询光标位置                │
└─────────────────────────────────────────┘
                  ↓
┌─────────────────────────────────────────┐
│ 输入法框架（本地机器）                  │
│ • 状态栏窗口跟随光标显示                │
└─────────────────────────────────────────┘
```

## 注意事项

1. **终端兼容性** - 不是所有终端都支持多光标协议，程序会自动降级
2. **性能考虑** - 每次光标移动都会发送转义序列，避免频繁更新
3. **坐标系统** - 坐标从 1 开始，原点在左上角
4. **调试模式** - 使用 `getSupportInfo()` 查看终端支持情况

## 故障排除

### 输入法状态栏不跟随光标

1. 检查终端类型：
   ```php
   $info = $multiCursor->getSupportInfo();
   var_dump($info);
   ```

2. 确认使用支持的终端（kitty 或 wezterm）

3. 检查是否启用了多光标支持：
   ```php
   $input->useMultiCursor(true);
   ```

### 光标位置不准确

调整 `renderPosition()` 的参数：
```php
$input->renderPosition($row, $col);
```

## 参考资料

- [kitty 多光标协议文档](https://sw.kovidgoyal.net/kitty/multiple-cursors-protocol/)
- [ANSI 转义序列参考](https://gist.github.com/ConnerWill/d4b6c776b509add763e17f9f113fd25b)
- [Charmbracelet Bubble Tea](https://github.com/charmbracelet/bubbletea)

## 技术实现

### 核心文件

- `app/Libs/Tui/MultiCursorProtocol.php` - 多光标协议实现
- `app/Libs/Tui/TextInputComponent.php` - 支持多光标的输入组件
- `app/Command/TestMultiCursorCommand.php` - 测试命令

### 扩展性

可以轻松为其他组件添加多光标支持：

```php
use App\Libs\Tui\MultiCursorProtocol;

class YourComponent
{
    private MultiCursorProtocol $multiCursor;

    public function __construct()
    {
        $this->multiCursor = MultiCursorProtocol::new();
    }

    public function updateCursor(int $row, int $col): void
    {
        if ($this->multiCursor->querySupport()) {
            $this->multiCursor->setCursor($row, $col);
        }
    }
}
```

## 许可证

MIT License
