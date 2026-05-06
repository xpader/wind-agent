<?php

namespace App\Libs\Traits;

use function Amp\delay;

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
        $incompleteBytes = '';  // 保存不完整的字节

        while (($chunk = $streamBody->read()) !== null && $chunk !== '') {
            // 将之前保存的不完整字节与当前 chunk 拼接
            if ($incompleteBytes !== '') {
                $chunk = $incompleteBytes . $chunk;
                $incompleteBytes = '';
            }

            // 检查并处理末尾可能不完整的 UTF-8 字符
            // 在流式响应中，多字节字符（如中文）可能被拆分到两个 chunk 中
            // 例如：一个 3 字节的中文可能被拆成 1+2 或 2+1 的形式
            $incompleteCount = $this->detectIncompleteUtf8($chunk);
            if ($incompleteCount > 0) {
                // 将不完整的字节保存到下一次处理
                $incompleteBytes = substr($chunk, -$incompleteCount);
                $chunk = substr($chunk, 0, -$incompleteCount);
            }

            $buffer .= $chunk;

            // 按行分割，保留最后一个可能不完整的行
            $lines = explode("\n", $buffer);
            $buffer = array_pop($lines);

            // 处理完整的行
            foreach ($lines as $line) {
                if ($line !== '') {
                    $continue = $lineProcessor($line);
                    if ($continue === false) {
                        return;
                    }
                }
            }
        }

        // 处理最后剩余的不完整字节（如果有）
        if ($incompleteBytes !== '') {
            $buffer .= $incompleteBytes;
        }

        // 处理最后一行（即使没有换行符）
        if ($buffer !== '') {
            $lineProcessor($buffer);
        }
    }

    /**
     * 检测字符串末尾不完整的 UTF-8 字符的字节数
     *
     * UTF-8 编码规则：
     * - 0xxxxxxx: 1字节 (ASCII)
     * - 110xxxxx: 2字节字符的开始
     * - 1110xxxx: 3字节字符的开始
     * - 11110xxx: 4字节字符的开始
     * - 10xxxxxx: 多字节字符的后续字节
     *
     * @param string $data 输入字符串
     * @return int 不完整的字节数，0 表示完整
     */
    protected function detectIncompleteUtf8(string $data): int
    {
        $len = strlen($data);
        if ($len === 0) {
            return 0;
        }

        $incompleteBytes = 0;

        // 只检查末尾最多 3 个字节（UTF-8 最多 4 字节，去掉首字节）
        $maxCheck = min(3, $len);
        for ($i = 0; $i < $maxCheck; $i++) {
            $byte = ord($data[$len - 1 - $i]);

            // 10xxxxxx: 多字节字符的后续字节
            if (($byte & 0xC0) === 0x80) {
                $incompleteBytes++;
            } else {
                // 找到非后续字节，这是可能的开始字节位置
                $startIndex = $len - 1 - $i;

                // 判断开始字节对应的字符总字节数
                if (($byte & 0x80) === 0x00) {
                    // ASCII 字符，不可能是多字节字符
                    return 0;
                } elseif (($byte & 0xE0) === 0xC0) {
                    $expectedBytes = 2;  // 110xxxxx: 2字节
                } elseif (($byte & 0xF0) === 0xE0) {
                    $expectedBytes = 3;  // 1110xxxx: 3字节
                } elseif (($byte & 0xF8) === 0xF0) {
                    $expectedBytes = 4;  // 11110xxx: 4字节
                } else {
                    // 无效的 UTF-8 开始字节
                    return 0;
                }

                // 当前字符的总字节数 = 1（开始字节）+ 后续字节数
                $currentCharBytes = 1 + $incompleteBytes;

                // 如果当前字符不完整（字节数少于期望值）
                if ($currentCharBytes < $expectedBytes) {
                    return $currentCharBytes;
                }

                return 0;  // 字符完整
            }
        }

        // 整个字符串都是后续字节，全部不完整
        return $incompleteBytes;
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
