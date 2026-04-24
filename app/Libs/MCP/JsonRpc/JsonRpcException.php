<?php

namespace App\Libs\MCP\JsonRpc;

/**
 * JSON-RPC 2.0 异常类
 */
class JsonRpcException extends \Exception
{
    /** @var int|null 错误代码 */
    private ?int $code = null;

    /** @var array|null 错误数据 */
    private ?array $data = null;

    /**
     * 构造函数
     *
     * @param string $message 错误消息
     * @param int $code 错误代码
     * @param array|null $data 错误数据
     * @param \Throwable|null $previous 前一个异常
     */
    public function __construct(string $message = "", int $code = 0, ?array $data = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->code = $code;
        $this->data = $data;
    }

    /**
     * 获取错误代码
     */
    public function getErrorCode(): ?int
    {
        return $this->code;
    }

    /**
     * 获取错误数据
     */
    public function getErrorData(): ?array
    {
        return $this->data;
    }

    /**
     * 从 JSON-RPC 错误响应创建异常
     *
     * @param array $error 错误响应
     * @return self
     */
    public static function fromErrorResponse(array $error): self
    {
        $code = $error['code'] ?? 0;
        $message = $error['message'] ?? 'Unknown error';
        $data = $error['data'] ?? null;

        return new self($message, $code, $data);
    }

    /**
     * 标准的 JSON-RPC 错误代码
     */
    public const ERROR_PARSE_ERROR = -32700;
    public const ERROR_INVALID_REQUEST = -32600;
    public const ERROR_METHOD_NOT_FOUND = -32601;
    public const ERROR_INVALID_PARAMS = -32602;
    public const ERROR_INTERNAL_ERROR = -32603;
}
