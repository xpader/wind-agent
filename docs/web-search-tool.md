# WebSearch 工具使用文档

## 概述

WebSearch 工具为 LLM Agent 提供了网络搜索能力，基于 [Tavily Search API](https://tavily.com/) 实现。

## 配置

### 1. 获取 API 密钥

访问 [Tavily](https://tavily.com/) 注册账号并获取 API 密钥。

### 2. 配置环境变量

在 `.env` 文件中添加以下配置：

```bash
# 必需：Tavily API 密钥
TAVILY_API_KEY=your_api_key_here

# 可选：请求超时时间（默认 30 秒）
TAVILY_TIMEOUT=30

# 可选：最大返回结果数（默认 10，范围 1-10）
TAVILY_MAX_RESULTS=10
```

### 3. 启用工具

工具已在 `config/tools.php` 中启用：

```php
'enabled' => [
    // ... 其他工具
    \App\Libs\Agent\Tools\WebSearchTool::class,
],
```

## 使用方法

### 通过 LLM 对话使用

```bash
# 启用工具和技能
./wind test:chat --prompt "搜索一下 PHP 8.2 的新特性" --with-tools

# 或者启用技能（会自动加载工具）
./wind test:chat --prompt "帮我搜索最新的 Wind Framework 文档" --with-skills
```

### 工具参数

WebSearch 工具支持以下参数：

| 参数 | 类型 | 必需 | 默认值 | 说明 |
|------|------|------|--------|------|
| `query` | string | ✅ | - | 搜索查询关键词或问题 |
| `search_depth` | string | ❌ | `basic` | 搜索深度：`basic`（快速）或 `advanced`（深度） |
| `max_results` | integer | ❌ | `10` | 最大返回结果数（1-10） |
| `include_answer` | boolean | ❌ | `true` | 是否包含 AI 生成的答案 |
| `include_raw_content` | boolean | ❌ | `false` | 是否包含原始内容 |
| `days` | integer | ❌ | `3` | 限制搜索最近几天的内容（3天内需要 `advanced` 搜索深度） |

### 返回格式

工具返回 Markdown 格式的搜索结果，包含：

- **AI 答案**：Tavily 生成的总结性答案（如果启用）
- **搜索结果**：
  - 标题
  - URL
  - 相关度分数
  - 内容摘要
- **查询耗时**：搜索所花费的时间

示例输出：

```markdown
## AI 答案

PHP 8.2 引入了许多新特性，包括只读类、DNF 类型、随机扩展改进等...

## 搜索结果

1. **PHP 8.2 新特性详解**
   - URL: https://example.com/php82-features
   - 相关度: 95.0%
   - 摘要: PHP 8.2 于 2022 年 12 月发布，引入了只读类、DNF 类型等新特性...

2. **PHP 8.2 迁移指南**
   - URL: https://example.com/php82-migration
   - 相关度: 88.5%
   - 摘要: 从 PHP 8.1 升级到 8.2 需要注意以下变更...

---
查询耗时: 1.2秒
```

## 示例场景

### 场景 1：实时信息查询

```bash
./wind test:chat --prompt "今天北京天气怎么样？" --with-tools
```

### 场景 2：技术文档搜索

```bash
./wind test:chat --prompt "搜索 Wind Framework 的最新文档和教程" --with-skills
```

### 场景 3：新闻查询

```bash
./wind test:chat --prompt "搜索最近一周的人工智能相关新闻" --with-tools
```

## 技术实现

### 核心类

- `App\Libs\Agent\Tools\WebSearchTool` - 工具实现类
- 使用 AMPHP HTTP Client 进行异步请求
- 兼容 OpenAI Function Calling 格式

### API 接口

- **端点**：`https://api.tavily.com/search`
- **方法**：POST
- **认证**：API Key
- **文档**：https://docs.tavily.com/docs/tavily-api/rest_api

### 错误处理

工具会捕获并格式化以下错误：

- API 密钥未配置
- 网络请求失败
- API 返回错误
- JSON 解析失败

错误信息会以清晰的方式返回给 LLM。

## 注意事项

1. **API 密钥安全**：不要将 API 密钥提交到版本控制系统
2. **配额限制**：Tavily API 有免费配额限制，请注意使用量
3. **搜索深度**：`advanced` 模式消耗更多配额但结果更准确
4. **时间限制**：`days` 参数在 `basic` 模式下最多 3 天
5. **超时设置**：默认超时 30 秒，可根据网络情况调整

## 故障排除

### 工具未加载

```bash
# 检查工具是否已启用
./wind test:tools --list-tools
```

### API 密钥错误

```bash
# 检查环境变量
echo $TAVILY_API_KEY

# 或检查配置
./wind test:tools --prompt "搜索测试"
```

### 搜索无结果

- 检查网络连接
- 尝试使用不同的搜索关键词
- 增加结果数量限制
- 使用 `advanced` 搜索深度

## 开发计划

- [ ] 支持更多搜索来源（Google、Bing 等）
- [ ] 添加搜索历史记录
- [ ] 支持搜索结果缓存
- [ ] 添加图片搜索功能
- [ ] 支持自定义搜索过滤器
