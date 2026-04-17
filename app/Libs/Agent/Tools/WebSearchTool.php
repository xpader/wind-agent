<?php

namespace App\Libs\Agent\Tools;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use App\Libs\Agent\ToolInterface;

/**
 * Web 搜索工具（基于 Tavily Search API）
 *
 * Tavily API 文档：https://docs.tavily.com/docs/tavily-api/rest_api
 */
class WebSearchTool implements ToolInterface
{
    private HttpClient $httpClient;
    private string $apiKey;
    private string $baseUrl;
    private int $timeout;
    private int $maxResults;

    /**
     * 构造函数
     * 自动从配置文件读取 Tavily API 配置
     */
    public function __construct()
    {
        $config = config('tools.web_search');

        $httpClientBuilder = new \Amp\Http\Client\HttpClientBuilder();
        $this->httpClient = $httpClientBuilder->build();

        $this->apiKey = $config['api_key'] ?? '';
        $this->baseUrl = 'https://api.tavily.com';
        $this->timeout = (int)($config['timeout'] ?? 30);
        $this->maxResults = (int)($config['max_results'] ?? 10);
    }

    public function getName(): string
    {
        return 'web_search';
    }

    public function getDescription(): string
    {
        return '在互联网上搜索信息，返回相关的搜索结果和内容摘要';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => '搜索查询关键词或问题'
                ],
                'search_depth' => [
                    'type' => 'string',
                    'description' => '搜索深度：basic（快速）或 advanced（深度）',
                    'enum' => ['basic', 'advanced'],
                    'default' => 'basic'
                ],
                'max_results' => [
                    'type' => 'integer',
                    'description' => '最大返回结果数（1-10）',
                    'default' => 10,
                    'minimum' => 1,
                    'maximum' => 10
                ],
                'include_answer' => [
                    'type' => 'boolean',
                    'description' => '是否包含 AI 生成的答案',
                    'default' => true
                ],
                'include_raw_content' => [
                    'type' => 'boolean',
                    'description' => '是否包含原始内容',
                    'default' => false
                ],
                'days' => [
                    'type' => 'integer',
                    'description' => '限制搜索最近几天的内容（3天内需要 advanced 搜索深度）',
                    'default' => 3
                ]
            ],
            'required' => ['query']
        ];
    }

    public function execute(array $arguments): string
    {
        $query = $arguments['query'] ?? '';

        if (empty($query)) {
            return $this->formatError('搜索查询不能为空');
        }

        // 构建请求参数
        $params = [
            'api_key' => $this->apiKey,
            'query' => $query,
            'search_depth' => $arguments['search_depth'] ?? 'basic',
            'max_results' => min($arguments['max_results'] ?? 10, $this->maxResults),
            'include_answer' => $arguments['include_answer'] ?? true,
            'include_raw_content' => $arguments['include_raw_content'] ?? false,
            'days' => $arguments['days'] ?? 3,
        ];

        // 执行搜索
        try {
            $result = $this->search($params);
            return $this->formatResult($result);
        } catch (\Exception $e) {
            return $this->formatError('搜索失败：' . $e->getMessage());
        }
    }

    public function toArray(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->getName(),
                'description' => $this->getDescription(),
                'parameters' => $this->getParameters()
            ]
        ];
    }

    /**
     * 执行搜索请求
     *
     * @param array $params 请求参数
     * @return array 搜索结果
     * @throws \Exception
     */
    private function search(array $params): array
    {
        $url = $this->baseUrl . '/search';
        $request = new Request($url, 'POST');
        $request->setHeader('Content-Type', 'application/json');
        $request->setBody(json_encode($params));

        // 设置超时
        $request->setHeader('te', 'trailers');
        $request->setTransferTimeout($this->timeout * 1000);

        /** @var Response $response */
        $response = $this->httpClient->request($request);
        $statusCode = $response->getStatus();

        if ($statusCode < 200 || $statusCode >= 300) {
            $errorBody = $response->getBody()->buffer();
            $errorData = json_decode($errorBody, true);
            $message = $errorData['message'] ?? $errorBody;
            throw new \Exception("HTTP {$statusCode}: {$message}");
        }

        $body = $response->getBody()->buffer();
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('无效的 JSON 响应');
        }

        return $data;
    }

    /**
     * 格式化搜索结果
     *
     * @param array $result 原始搜索结果
     * @return string 格式化后的结果
     */
    private function formatResult(array $result): string
    {
        $output = [];

        // 添加 AI 答案（如果有）
        if (!empty($result['answer'])) {
            $output[] = "## AI 答案\n\n{$result['answer']}\n";
        }

        // 添加搜索结果
        if (!empty($result['results'])) {
            $output[] = "## 搜索结果\n\n";

            foreach ($result['results'] as $index => $item) {
                $num = $index + 1;
                $title = $item['title'] ?? '无标题';
                $url = $item['url'] ?? '';
                $content = $item['content'] ?? '';
                $score = $item['score'] ?? 0;

                $output[] = "{$num}. **{$title}**";
                $output[] = "   - URL: {$url}";
                $output[] = "   - 相关度: " . round($score * 100, 1) . "%";

                if ($content !== '') {
                    // 截取内容摘要（最多 200 字符）
                    $snippet = mb_substr($content, 0, 200, 'UTF-8');
                    if (mb_strlen($content, 'UTF-8') > 200) {
                        $snippet .= '...';
                    }
                    $output[] = "   - 摘要: {$snippet}";
                }

                $output[] = "";
            }
        }

        // 添加查询时间（如果有）
        if (!empty($result['usage'])) {
            $output[] = "---\n";
            $output[] .= "查询耗时: {$result['usage']}秒";
        }

        return implode("\n", $output);
    }

    /**
     * 格式化错误信息
     *
     * @param string $message 错误消息
     * @return string
     */
    private function formatError(string $message): string
    {
        return "❌ 搜索错误: {$message}";
    }
}
