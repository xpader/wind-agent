<?php

namespace App\Libs\Agent;

/**
 * 会话数据类
 *
 * 封装会话数据和元数据
 */
class Session
{
    private string $sessionId;
    private array $metadata;
    private array $messages;

    /**
     * @param string $sessionId 会话 UUID
     * @param array $metadata 会话元数据
     * @param array $messages 消息列表
     */
    public function __construct(string $sessionId, array $metadata, array $messages = [])
    {
        $this->sessionId = $sessionId;
        $this->metadata = $metadata;
        $this->messages = $messages;
    }

    /**
     * 获取会话 ID
     */
    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    /**
     * 获取会话元数据
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * 更新会话元数据
     */
    public function updateMetadata(array $metadata): void
    {
        $this->metadata = array_merge($this->metadata, $metadata);
    }

    /**
     * 获取消息列表
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    /**
     * 添加消息
     */
    public function addMessage(array $message): void
    {
        $this->messages[] = $message;
    }

    /**
     * 清空消息
     */
    public function clearMessages(): void
    {
        $this->messages = [];
    }

    /**
     * 转换为数组
     */
    public function toArray(): array
    {
        return [
            'session_id' => $this->sessionId,
            'metadata' => $this->metadata,
            'messages' => $this->messages,
        ];
    }

    /**
     * 从数组创建实例
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['session_id'],
            $data['metadata'],
            $data['messages'] ?? []
        );
    }
}
