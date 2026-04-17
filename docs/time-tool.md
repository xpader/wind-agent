# Time 工具使用文档

## 概述

Time 工具为 LLM Agent 提供了获取系统时间的能力，支持多种时间格式和时区设置。

## 功能特性

- ✅ 获取系统当前时间
- ✅ 支持多种时间格式
- ✅ 支持自定义时区
- ✅ 纯 PHP 实现，无需外部依赖

## 支持的时间格式

| 格式 | 说明 | 示例输出 |
|------|------|----------|
| `default` | 默认格式（中文） | `2026年4月17日 13:03:20` |
| `iso` | ISO 8601 格式 | `2026-04-17T13:03:20+08:00` |
| `timestamp` | Unix 时间戳 | `1776402200` |
| `date` | 仅日期 | `2026-04-17` |
| `time` | 仅时间 | `13:03:20` |
| `full` | 完整格式（含时区） | `2026 年 4 月 17 日 13:03:20 PRC` |

## 使用方法

### 通过 LLM 对话使用

```bash
# 基本时间查询
./wind test:chat --prompt "现在几点了？" --with-tools

# 获取日期
./wind test:chat --prompt "今天是几月几号？" --with-tools

# 获取完整时间信息
./wind test:chat --prompt "告诉我现在的详细时间" --with-tools

# 计算时间差
./wind test:chat --prompt "距离2027年还有多少天？" --with-tools
```

### 直接调用工具

```php
use App\Libs\Agent\ToolManager;

// 获取默认格式时间
$time = ToolManager::execute('time', []);
echo $time; // 输出：2026年4月17日 13:03:20

// 获取 ISO 格式
$time = ToolManager::execute('time', ['format' => 'iso']);
echo $time; // 输出：2026-04-17T13:03:20+08:00

// 获取特定时区的时间
$time = ToolManager::execute('time', [
    'format' => 'default',
    'timezone' => 'UTC'
]);
echo $time; // 输出：2026年4月17日 05:03:20
```

## 参数说明

### format（可选）

时间格式类型，默认为 `default`

- `default` - 中文格式（推荐用于中文对话）
- `iso` - ISO 8601 标准格式
- `timestamp` - Unix 时间戳
- `date` - 仅返回日期
- `time` - 仅返回时间
- `full` - 完整格式，包含时区信息

### timezone（可选）

时区设置，默认使用系统时区

常用时区：
- `Asia/Shanghai` - 中国标准时间
- `UTC` - 协调世界时
- `America/New_York` - 美国东部时间
- `Europe/London` - 英国时间
- `Asia/Tokyo` - 日本时间

## 常见使用场景

### 场景1：获取当前时间

```bash
./wind test:chat --prompt "现在几点了？" --with-tools
```

**LLM 可能的调用方式：**
```json
{
  "function": "time",
  "arguments": {
    "format": "time"
  }
}
```

### 场景2：获取完整日期

```bash
./wind test:chat --prompt "今天是几号？" --with-tools
```

**LLM 可能的调用方式：**
```json
{
  "function": "time",
  "arguments": {
    "format": "date"
  }
}
```

### 场景3：时间计算

```bash
./wind test:chat --prompt "距离年底还有多少天？" --with-tools
```

**LLM 可能的调用方式：**
```json
{
  "function": "time",
  "arguments": {
    "format": "timestamp"
  }
}
```

然后 LLM 会使用时间戳进行计算。

## 技术实现

- **核心类**：`App\Libs\Agent\Tools\TimeTool`
- **依赖**：PHP DateTime 和 DateTimeZone 类
- **时区支持**：使用 PHP 内置时区数据库
- **错误处理**：捕获异常并返回友好错误信息

## 错误处理

工具会捕获以下异常：

- 无效的时区名称
- DateTime 对象创建失败

错误信息格式：
```
获取时间失败： [具体错误信息]
```

## 注意事项

1. **时区设置**：如果不指定时区，使用系统默认时区
2. **格式选择**：中文对话建议使用 `default` 或 `full` 格式
3. **时间戳**：返回的是秒级 Unix 时间戳
4. **系统时间**：获取的是运行 PHP 的服务器时间

## 开发计划

- [ ] 支持更多日期格式
- [ ] 添加时区转换功能
- [ ] 支持相对时间（如"明天"、"下周"）
- [ ] 添加日历功能
- [ ] 支持时间计算（加减天数）
