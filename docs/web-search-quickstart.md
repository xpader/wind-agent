# WebSearch 工具快速开始

## 1. 获取 API 密钥

访问 [Tavily](https://tavily.com/) 注册并获取免费的 API 密钥。

## 2. 配置环境变量

```bash
# 复制环境变量模板
cp .env.example .env

# 编辑 .env 文件，添加你的 Tavily API 密钥
echo "TAVILY_API_KEY=your_actual_api_key_here" >> .env
```

## 3. 测试工具

### 列出所有可用工具
```bash
./wind test:tools --list-tools
```

### 测试搜索功能
```bash
# 基本搜索测试
./wind test:chat --prompt "搜索一下 PHP 8.2 的新特性" --with-tools

# 实时信息查询
./wind test:chat --prompt "今天北京天气怎么样？" --with-tools

# 技术文档搜索
./wind test:chat --prompt "搜索 Wind Framework 的最新文档" --with-tools
```

## 4. 在代码中使用

### 直接调用工具
```php
use App\Libs\Agent\ToolManager;

// 执行搜索
$result = ToolManager::execute('web_search', [
    'query' => 'PHP 8.2 新特性',
    'search_depth' => 'basic',
    'max_results' => 5
]);

echo $result;
```

### 通过 LLM 调用
```php
use App\Libs\LLM\LLMRequest;
use App\Libs\Agent\ToolManager;

// 创建请求并添加工具
$request = LLMRequest::create()
    ->addUser('搜索最新的 PHP 框架排名')
    ->model('qwen3.5:9b-q8_0');

// 添加所有工具
foreach (ToolManager::getAll() as $tool) {
    $request->addTool($tool);
}

// 发送到 LLM
$response = $llmClient->chat($request);

// 如果有工具调用，执行它们
if (!empty($response->toolCalls)) {
    $result = $response->executeToolCalls();
    echo $result;
}
```

## 5. 常见问题

**Q: 工具没有加载？**
```bash
# 检查配置是否正确
./wind test:tools --list-tools

# 确保 WebSearchTool 在列表中
```

**Q: API 密钥错误？**
```bash
# 检查环境变量
echo $TAVILY_API_KEY

# 或直接测试
./wind test:chat --prompt "搜索测试" --with-tools
```

**Q: 搜索无结果？**
- 检查网络连接
- 确认 API 密钥有效
- 尝试不同的搜索关键词

## 6. 下一步

- 查看完整文档：[WebSearch 工具使用文档](web-search-tool.md)
- 了解 LLM 集成：[LLM 客户端文档](../CLAUDE.md#llm-客户端架构)
- 探索其他工具：`./wind test:tools --list-tools`

## 提示

- 首次使用建议从简单查询开始
- `basic` 模式更快，`advanced` 模式更准确
- 可以通过 `max_results` 控制返回数量
- 启用技能会自动加载工具：`--with-skills`
