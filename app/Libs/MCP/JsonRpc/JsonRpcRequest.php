<?php

namespace App\Libs\MCP\JsonRpc;

/**
 * JSON-RPC 2.0 请求类
 */
class JsonRpcRequest
{
    /** @var string JSON-RPC 版本 */
    private string $jsonrpc = '2.0';

    /** @var string 方法名 */
    private string $method;

    /** @var array|null 参数 */
    private ?array $params;

    /** @var int|string|null 请求 ID */
    private int|string|null $id;

    /** @var int 请求 ID 计数器 */
    private static int $idCounter = 0;

    /**
     * 构造函数
     *
     * @param string $method 方法名
     * @param array|null $params 参数
     * @param int|string|null $id 请求 ID（null 表示通知）
     */
    public function __construct(string $method, ?array $params = null, int|string|null $id = null)
    {
        $this->method = $method;
        $this->params = $params;
        $this->id = $id ?? self::$idCounter++;
    }

    /**
     * 创建请求
     *
     * @param string $method 方法名
     * @param array|null $params 参数
     * @return self
     */
    public static function create(string $method, ?array $params = null): self
    {
        return new self($method, $params);
    }

    /**
     * 创建通知（无响应的请求）
     *
     * @param string $method 方法名
     * @param array|null $params 参数
     * @return self
     */
    public static function notification(string $method, ?array $params = null): self
    {
        return new self($method, $params, null);
    }

    /**
     * 转换为数组
     *
     * @return array
     */
    public function toArray(): array
    {
        $data = [
            'jsonrpc' => $this->jsonrpc,
            'method' => $this->method,
        ];

        // 如果有参数，添加到请求中
        if ($this->params !== null) {
            $data['params'] = $this->params;
        }

        // 如果有 ID，添加到请求中（通知没有 ID）
        if ($this->id !== null) {
            $data['id'] = $this->id;
        }

        return $data;
    }

    /**
     * 转换为 JSON 字符串
     *
     * @return string
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE);
    }

    /**
     * 获取方法名
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * 获取参数
     */
    public function getParams(): ?array
    {
        return $this->params;
    }

    /**
     * 获取请求 ID
     */
    public function getId(): int|string|null
    {
        return $this->id;
    }

    /**
     * 是否为通知
     */
    public function isNotification(): bool
    {
        return $this->id === null;
    }
}
