# TextInputComponent 使用文档

## 概述

`TextInputComponent` 是一个基于 php-tui 的可复用文本输入组件，支持中文、英文、符号和多字节字符。

## 特性

- ✅ 支持中文、英文、数字、符号输入
- ✅ 正确处理多字节字符（UTF-8）
- ✅ 支持 Backspace 和退格键删除
- ✅ 可自定义最大长度、标签、占位符
- ✅ 可启用/禁用输入
- ✅ 链式调用 API
- ✅ 自动字符数和字节数统计

## 基本使用

### 创建组件

```php
use App\Libs\Tui\TextInputComponent;

// 创建默认配置的文本输入组件
$textInput = TextInputComponent::new();

// 创建自定义配置的文本输入组件
$textInput = TextInputComponent::new()
    ->label('用户名')
    ->placeholder('请输入用户名')
    ->maxLength(20);
```

### 在 TUI 循环中使用

```php
// 在主循环中处理事件
while (null !== $event = $terminal->events()->next()) {
    // 让文本输入组件处理事件
    $textInput->handle($event);

    // 其他事件处理...
}

// 在绘制时使用
$display->draw($textInput->build());
```

### 获取输入的文本

```php
// 获取当前输入的文本
$text = $textInput->getText();

// 清空文本
$textInput->clear();
```

## API 参考

### 配置方法

#### `label(string $label): self`
设置输入框的标签文本。

```php
$textInput->label('用户名');
```

#### `placeholder(string $placeholder): self`
设置占位符文本（当输入为空时显示）。

```php
$textInput->placeholder('请输入用户名');
```

#### `maxLength(int $length): self`
设置最大输入长度（按字符数计算）。

```php
$textInput->maxLength(20);
```

#### `text(string $text): self`
设置初始文本。

```php
$textInput->text('默认值');
```

#### `enabled(bool $enabled): self`
设置是否启用输入。

```php
$textInput->enabled(false); // 禁用输入
```

### 操作方法

#### `handle(Event $event): void`
处理键盘事件。

```php
$textInput->handle($event);
```

#### `getText(): string`
获取当前输入的文本。

```php
$text = $textInput->getText();
```

#### `clear(): void`
清空输入的文本。

```php
$textInput->clear();
```

### 显示方法

#### `build(): Widget`
构建用于显示的 Widget。

```php
$widget = $textInput->build();
$display->draw($widget);
```

## 完整示例

### 基础示例

```php
use App\Libs\Tui\TextInputComponent;
use PhpTui\Tui\DisplayBuilder;

// 创建组件
$textInput = TextInputComponent::new()
    ->label('测试输入')
    ->placeholder('请输入文本...')
    ->maxLength(50);

// 在 TUI 主循环中
while (true) {
    // 处理事件
    while (null !== $event = $terminal->events()->next()) {
        // 处理退出
        if ($event instanceof CharKeyEvent && $event->char === 'q') {
            break 2;
        }

        // 让文本组件处理其他事件
        $textInput->handle($event);
    }

    // 绘制界面
    $display->draw($textInput->build());
    usleep(50_000);
}

// 获取最终输入
echo "输入的文本: " . $textInput->getText() . "\n";
```

### 多个输入框示例

```php
// 创建多个输入组件
$usernameInput = TextInputComponent::new()
    ->label('用户名')
    ->placeholder('请输入用户名')
    ->maxLength(20);

$passwordInput = TextInputComponent::new()
    ->label('密码')
    ->placeholder('请输入密码')
    ->maxLength(30);

// 在事件处理中
while (null !== $event = $terminal->events()->next()) {
    if ($event instanceof CharKeyEvent) {
        if ($event->char === "\t") {
            // 切换焦点
            $activeInput = ($activeInput === 'username') ? 'password' : 'username';
            continue;
        }
        if ($event->char === 'q') {
            break 2;
        }
    }

    // 让当前激活的输入组件处理事件
    if ($activeInput === 'username') {
        $usernameInput->handle($event);
    } else {
        $passwordInput->handle($event);
    }
}
```

## 技术实现

### 字符编码处理

组件使用 `mb_*` 函数正确处理多字节字符：

```php
// 计算字符数（而非字节数）
mb_strlen($text, 'UTF-8')

// 删除最后一个字符（正确处理多字节）
mb_substr($text, 0, -1, 'UTF-8')
```

### 事件处理

组件处理两种类型的键盘事件：

1. **CharKeyEvent**：字符键事件
   - 可打印字符：直接添加到文本
   - 不可打印字符：作为多字节字符的组成部分
   - 退格键：删除最后一个字符

2. **CodedKeyEvent**：编码键事件
   - Backspace：删除最后一个字符
   - 其他功能键可扩展

### 中文输入法支持

组件通过接受所有非控制字符来支持中文输入法：

```php
// 处理可打印字符
if (ctype_print($char)) {
    $this->text .= $char;
} else {
    // 处理不可打印字符（如中文输入法的中间状态）
    $this->text .= $char;
}
```

## 限制和注意事项

1. **最大长度限制**：按字符数计算，不是字节数
2. **不支持多行输入**：目前只支持单行文本输入
3. **光标位置**：不支持在文本中间移动光标
4. **文本选择**：不支持文本选择和复制粘贴

## 扩展建议

可以考虑添加的功能：

1. 密码模式（显示 `*` 而不是实际字符）
2. 输入验证（如只允许数字、邮箱格式等）
3. 自动补全
4. 多行文本输入
5. 光标位置控制
6. 文本历史记录

## 相关类

- `PhpTui\Term\Event\CharKeyEvent` - 字符键事件
- `PhpTui\Term\Event\CodedKeyEvent` - 编码键事件
- `PhpTui\Tui\Extension\Core\Widget\BlockWidget` - 边框组件
- `PhpTui\Tui\Extension\Core\Widget\ParagraphWidget` - 段落组件
