# php-tui 事件类型分析

## 原始 php-tui 支持的事件

### 1. 键盘事件（通过 STDIN 解析）
- **CharKeyEvent**: 字符键事件（字母、数字、符号）
- **CodedKeyEvent**: 编码键事件（方向键、功能键、Enter、Tab 等）
- **FunctionKeyEvent**: 功能键事件（F1-F12）

### 2. 鼠标事件（通过 STDIN 解析）
- **MouseEvent**: 鼠标事件
  - 点击（Down/Up）
  - 拖拽（Drag）
  - 移动（Moved）
  - 滚轮（ScrollUp/ScrollDown/ScrollLeft/ScrollRight）

### 3. 终端事件（通过 STDIN 解析）
- **CursorPositionEvent**: 光标位置报告事件
- **FocusEvent**: 焦点事件（获得/失去焦点）

### 4. 信号事件（通过 pcntl_signal）
- **TerminalResizedEvent**: 终端大小变化事件（SIGWINCH 信号）

## AsyncAggregateEventProvider 的支持情况

### ✅ 完全支持的事件

1. **键盘事件** (通过 `onReadable(STDIN)` + EventParser)
   - CharKeyEvent ✅
   - CodedKeyEvent ✅
   - FunctionKeyEvent ✅

2. **鼠标事件** (通过 `onReadable(STDIN)` + EventParser)
   - MouseEvent ✅（所有类型）
   - 点击、拖拽、移动、滚轮

3. **终端事件** (通过 `onReadable(STDIN)` + EventParser)
   - CursorPositionEvent ✅
   - FocusEvent ✅

4. **信号事件** (通过 `onSignal(SIGWINCH)`)
   - TerminalResizedEvent ✅

### 🔍 实现细节

所有通过 STDIN 传入的事件（键盘、鼠标、终端控制序列）都由 `EventParser` 解析：

```php
// AsyncAggregateEventProvider::handleReadable()
private function handleReadable(): void
{
    $bytes = stream_get_contents(STDIN);

    // EventParser 自动解析所有类型的事件
    $parser->advance($bytes, more: false);
    $events = $parser->drain();  // 返回所有解析到的事件

    foreach ($events as $event) {
        $this->eventBuffer[] = $event;
    }
}
```

`EventParser` 支持的转义序列包括：
- `\x1B[A` - 上箭头（CodedKeyEvent）
- `\x1B[I` - 获得焦点（FocusEvent）
- `\x1B[<0;10;20M` - 鼠标点击（MouseEvent）
- `\x1B[R` - 光标位置报告（CursorPositionEvent）
- 等等...

## 结论

**AsyncAggregateEventProvider 完全支持 php-tui 的所有事件类型！**

因为：
1. ✅ 所有 STDIN 事件（键盘、鼠标、终端控制）都通过 `onReadable(STDIN)` 监听
2. ✅ `EventParser` 自动解析所有支持的转义序列
3. ✅ SIGWINCH 信号通过 `onSignal(SIGWINCH)` 监听

无需额外处理，`AsyncAggregateEventProvider` 已经完全兼容 php-tui 的事件系统！
