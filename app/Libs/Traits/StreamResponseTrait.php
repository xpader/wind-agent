<?php

namespace App\Libs\Traits;

/**
 * 流式响应处理 Trait
 *
 * 提供流式响应的通用处理逻辑
 */
trait StreamResponseTrait
{
    /**
     * 处理 SSE (Server-Sent Events) 格式的流式响应
     * 用于 OpenAI 兼容 API
     *
     * @param string $body 流式响应体
     * @param callable $callback 回调函数 function(string $content, string $thinking, bool $done)
     * @return void
     */
    protected function handleSSEStream(string $body, callable $callback): void
    {
        $buffer = '';
        $lines = explode("\n", $body);
        $lines[] = array_pop($buffer); // 添加最后的缓冲区内容

        foreach ($lines as $line) {
            if ($line === '' || trim($line) === 'data: [DONE]') {
                continue;
            }

            if (str_starts_with($line, 'data: ')) {
                $data = json_decode(substr($line, 6), true);
                if ($data !== null) {
                    $content = $data['choices'][0]['delta']['content'] ?? '';
                    $thinking = $data['choices'][0]['delta']['thinking'] ?? '';
                    $done = ($data['choices'][0]['finish_reason'] ?? null) !== null;
                    $callback($content, $thinking, $done);
                }
            }
        }

        // 发送完成信号
        $callback('', '', true);
    }

    /**
     * 处理逐行 JSON 格式的流式响应
     * 用于 Ollama 原生 API
     *
     * @param string $body 流式响应体
     * @param callable $callback 回调函数 function(string $content, string $thinking, bool $done)
     * @return void
     */
    protected function handleJsonLineStream(string $body, callable $callback): void
    {
        $lines = explode("\n", $body);

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            $data = json_decode($line, true);
            if ($data !== null) {
                $content = $data['message']['content'] ?? '';
                $thinking = $data['message']['thinking'] ?? '';
                $done = $data['done'] ?? false;

                $callback($content, $thinking, $done);

                if ($done) {
                    return;
                }
            }
        }
    }

    /**
     * 处理流式响应（支持逐块读取）
     *
     * 自动处理不完整的行，确保 lineProcessor 每次接收到的都是完整的行
     *
     * @param mixed $streamBody 响应体对象
     * @param callable $lineProcessor 行处理回调 function(string $line): bool
     *                              返回 false 可提前终止流式处理
     * @return void
     */
    protected function processStreamByChunk($streamBody, callable $lineProcessor): void
    {
        $buffer = '';

        while (($chunk = $streamBody->read()) !== null && $chunk !== '') {
            // UTF-8 编码处理（使用 //IGNORE 忽略不完整的多字节字符）
            // 注意：这可能会导致少量字符丢失，但可以避免 PHP Notice
            // TODO: 实现更完善的 UTF-8 流式处理（参考 docs/utf8-stream-issue.md）
            $chunk = iconv('UTF-8', 'UTF-8//IGNORE', $chunk);
            $buffer .= $chunk;

            // 按行分割，保留最后一个可能不完整的行
            $lines = explode("\n", $buffer);
            $buffer = array_pop($lines);

            // 处理完整的行
            foreach ($lines as $line) {
                if ($line !== '') {
                    $continue = $lineProcessor($line);
                    if ($continue === false) {
                        return; // 提前终止
                    }
                }
            }
        }

        // 处理最后一行（即使没有换行符）
        if ($buffer !== '') {
            $lineProcessor($buffer);
        }
    }

    /**
     * 修复 UTF-8 编码问题
     *
     * @param string $data 原始数据
     * @return string 修复后的数据
     */
    protected function fixUtf8Encoding(string $data): string
    {
        return iconv('UTF-8', 'UTF-8//TRANSLIT', $data);
    }

    /**
     * 安全解析 JSON
     *
     * @param string $json JSON 字符串
     * @return array|null 解析后的数组，失败返回 null
     */
    protected function safeJsonDecode(string $json): ?array
    {
        $data = json_decode($json, true);
        return json_last_error() === JSON_ERROR_NONE ? $data : null;
    }

    /**
     * 构建通用的请求载荷
     *
     * @param \App\Libs\LLM\LLMRequest $request 请求对象
     * @param array $additionalData 额外的数据
     * @return array 完整的请求载荷
     */
    protected function buildPayload(\App\Libs\LLM\LLMRequest $request, array $additionalData = []): array
    {
        $payload = $request->toArray();
        return array_merge($payload, $additionalData);
    }
}
