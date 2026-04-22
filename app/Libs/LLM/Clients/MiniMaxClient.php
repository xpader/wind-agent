<?php

namespace App\Libs\LLM\Clients;

use App\Libs\LLM\LLMRequest;
use App\Libs\LLM\LLMResponse;

/**
 * MiniMax 客户端
 *
 * 基于 OpenAI 兼容接口，支持 MiniMax 的特殊思考内容格式
 * 思考内容嵌入在响应的 `

` 标签中
 */
class MiniMaxClient extends OpenAiClient
{
    protected string $baseUrl = 'https://api.minimax.chat/v1';

    /**
     * 重写 chatStream 方法以处理流式思考标签和 MiniMax 特殊的 usage 块
     */
    public function chatStream(LLMRequest $request, callable $callback): void
    {
        // 添加 stream_options 参数以获取 usage 信息
        $request->parameters['stream_options'] = ['include_usage' => true];

        // MiniMax 特殊处理：如果传了 include_usage 参数，使用是否有 usage 来判断是否真正完成
        // 因为 MiniMax 在 finish_reason: stop 后还会发送一个包含 usage 的数据块
        $isDoneCallback = function(array $data): bool {
            return isset($data['usage']) && $data['usage'] !== null;
        };

        $inThinking = false;
        $thinkOpen = '<think>';      // 开始标签 < think>
        $thinkClose = '</think>';     // 结束标签 </think>

        $this->sendChatStream($request, function(LLMResponse $response) use ($callback, &$inThinking, $thinkOpen, $thinkClose) {
            if ($response->content === '') {
                $callback($response);
                return;
            }

            $content = $response->content;
            $newContent = '';
            $newThinking = '';

            if (!$inThinking) {
                // 不在思考模式中，查找开始标签
                $openPos = strpos($content, $thinkOpen);
                if ($openPos !== false) {
                    // 找到开始标签，标签后的内容可能有思考内容、结束标签、普通内容
                    $remaining = substr($content, $openPos + strlen($thinkOpen));

                    // 查找结束标签
                    $closePos = strpos($remaining, $thinkClose);
                    if ($closePos !== false) {
                        // 一次性包含：开始标签 + 思考内容 + 结束标签 + 普通内容
                        $newThinking = substr($remaining, 0, $closePos);
                        $newContent = substr($remaining, $closePos + strlen($thinkClose));
                        // 不需要进入思考模式，因为已经结束了
                    } else {
                        // 只有开始标签 + 思考内容，进入思考模式
                        $newThinking = $remaining;
                        $inThinking = true;
                    }
                } else {
                    // 没有开始标签，全部都是普通内容
                    $newContent = $content;
                }
            } else {
                // 在思考模式中，查找结束标签
                $closePos = strpos($content, $thinkClose);
                if ($closePos !== false) {
                    // 找到结束标签，标签前的内容是思考内容，标签后的是普通内容
                    $newThinking = substr($content, 0, $closePos);
                    $newContent = substr($content, $closePos + strlen($thinkClose));
                    $inThinking = false;
                } else {
                    // 还没找到结束标签，全部都是思考内容
                    $newThinking = $content;
                }
            }

            $newResponse = LLMResponse::createChunk($newContent, $newThinking, $response->done)
                ->model($response->model);

            if ($response->usage !== null) {
                $newResponse->usage($response->usage);
            }

            $callback($newResponse);
        }, $isDoneCallback);
    }

    /**
     * 提取思考内容（从 think 标签中）- 用于非流式响应
     */
    private function extractThinking(string $content): string
    {
        $thinkOpen = '<think>';
        $thinkClose = '</think>';

        if (preg_match('/' . preg_quote($thinkOpen, '/') . '(.*?)' . preg_quote($thinkClose, '/') . '/s', $content, $matches)) {
            return trim($matches[1]);
        }
        return '';
    }

    /**
     * 移除思考标签（从内容中删除 think 标签）- 用于非流式响应
     */
    private function stripThinkingTags(string $content): string
    {
        $thinkOpen = '<think>';
        $thinkClose = '</think>';

        $result = preg_replace('/' . preg_quote($thinkOpen, '/') . '.*?' . preg_quote($thinkClose, '/') . '/s', '', $content);
        return $result ?? '';
    }

    /**
     * 解析聊天补全响应（重写以处理思考标签）- 用于非流式响应
     */
    protected function parseChatResponse(array $data): LLMResponse
    {
        $response = parent::parseChatResponse($data);

        // 提取思考内容
        $thinking = $this->extractThinking($response->content);
        if ($thinking !== '') {
            $response->thinking($thinking);
        }

        // 移除思考标签后的内容
        $content = $this->stripThinkingTags($response->content);
        $response->content($content);

        return $response;
    }
}
