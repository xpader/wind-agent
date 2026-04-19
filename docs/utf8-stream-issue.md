# UTF-8 流式处理问题记录

## 问题描述

在 `StreamResponseTrait.php` 第 94 行的 `iconv()` 调用中，处理流式响应时会出现以下错误：

```
PHP Notice: iconv(): Detected an incomplete multibyte character in input string
PHP Notice: iconv(): Detected an illegal character in input string
```

## 问题原因

流式响应是逐块接收数据的，而 UTF-8 编码使用多字节字符（如中文、emoji 等）。当一个多字节字符被分割在两个数据块之间时：

- 第一个数据块可能只包含字符的前 1-3 个字节
- 第二个数据块包含剩余的字节

在这种情况下，`iconv()` 遇到不完整的多字节序列就会报错。

### 示例

假设 UTF-8 字符 "你" 的编码是 `E4 BD A0`（3 个字节）：

```
数据块 1: "...some content \xE4\xBD"  (不完整，缺少最后一个字节)
数据块 2: "\xA0 some more content..." (剩余的字节)
```

当处理数据块 1 时，`iconv()` 检测到 `\xE4\xBD` 是一个不完整的 UTF-8 字符，就会报错。

## 当前解决方案（临时）

使用 `//IGNORE` 标志忽略错误：

```php
// 第 94 行
$chunk = iconv('UTF-8', 'UTF-8//IGNORE', $chunk);
```

这种方式会静默删除无效或不完整的字符，避免报错，但可能导致数据丢失。

## 更好的解决方案（待实现）

### 方案 1: 使用不完整字符缓冲区

在数据块之间维护一个缓冲区，存储不完整的多字节字符：

```php
protected function processStreamByChunk($streamBody, callable $lineProcessor): void
{
    $buffer = '';
    $incompleteByteBuffer = ''; // 存储不完整的多字节字符

    while (($chunk = $streamBody->read()) !== null && $chunk !== '') {
        // 处理可能不完整的多字节字符
        $chunk = $this->sanitizeUtf8Chunk($chunk, $incompleteByteBuffer);
        $buffer .= $chunk;
        // ...
    }
}

protected function sanitizeUtf8Chunk(string $chunk, string &$incompleteBuffer): string
{
    // 1. 如果有不完整字符，先拼接到当前块
    // 2. 从后向前检查 UTF-8 字节的完整性
    // 3. 发现不完整字符时，保存到 $incompleteBuffer
    // 4. 下次读取时拼接
    // 5. 清理其他无效的 UTF-8 序列
}
```

### 方案 2: 使用 mbstring 扩展

使用 `mb_check_encoding()` 和 `mb_convert_encoding()`：

```php
if (!mb_check_encoding($chunk, 'UTF-8')) {
    $chunk = mb_convert_encoding($chunk, 'UTF-8', 'UTF-8');
}
```

### 方案 3: 延迟处理

累积所有数据块，在最终处理时才进行 UTF-8 验证和清理（不适用于流式输出）。

## 影响范围

- `StreamResponseTrait::processStreamByChunk()`
- 使用该 trait 的所有客户端：
  - `OpenAiClient`
  - `MiniMaxClient`
  - `OllamaClient`

## 优先级

中等。当前使用 `//IGNORE` 可以避免报错，但可能导致少量字符丢失。需要实现更完善的处理逻辑。

## 参考

- PHP iconv 文档: https://www.php.net/manual/en/function.iconv.php
- UTF-8 编码规范: RFC 3629
- 相关 Issue: [待添加]
