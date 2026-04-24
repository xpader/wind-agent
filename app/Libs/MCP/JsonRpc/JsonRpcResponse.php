<?php

namespace App\Libs\MCP\JsonRpc;

/**
 * JSON-RPC 2.0 响应类
 */
class JsonRpcResponse
{
    /** @var string JSON-RPC 版本 */
    private string $jsonrpc = '2.0';

    /** @var int|string|null 请求 ID */
    private int|string|null $id;

    /** @var mixed 结果（成功时） */
    private mixed $result = null;

    /** @var array|null 错误（失败时） */
    private ?array $error = null;

    /**
     * 构造函数
     *
     * @param int|string|null $id 请求 ID
     * @param mixed $result 结果
     * @param array|null $error 错误
     */
    private function __construct(int|string|null $id, mixed $result = null, ?array $error = null)
    {
        $this->id = $id;
        $this->result = $result;
        $this->error = $error;
    }

    /**
     * 创建成功响应
     *
     * @param int|string $id 请求 ID
     * @param mixed $result 结果
     * @return self
     */
    public static function success(int|string $id, mixed $result): self
    {
        return new self($id, $result);
    }

    /**
     * 创建错误响应
     *
     * @param int|string $id 请求 ID
     * @param array $error 错误信息
     * @return self
     */
    public static function error(int|string $id, array $error): self
    {
        return new self($id, null, $error);
    }

    /**
     * 从 JSON 字符串解析响应
     *
     * @param string $json JSON 字符串
     * @return self
     * @throws JsonRpcException
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new JsonRpcException('Invalid JSON: ' . json_last_error_msg(), JsonRpcException::ERROR_PARSE_ERROR);
        }

        return self::fromArray($data);
    }

    /**
     * 从数组创建响应
     *
     * @param array $data 响应数据
     * @return self
     * @throws JsonRpcException
     */
    public static function fromArray(array $data): self
    {
        // 验证 JSON-RPC 版本
        if (!isset($data['jsonrpc']) || $data['jsonrpc'] !== '2.0') {
            throw new JsonRpcException('Invalid or missing jsonrpc version', JsonRpcException::ERROR_INVALID_REQUEST);
        }

        // 检查是否有错误
        if (isset($data['error'])) {
            if (!is_array($data['error'])) {
                throw new JsonRpcException('Error must be an object', JsonRpcException::ERROR_INVALID_REQUEST);
            }

            if (!isset($data['error']['code']) || !isset($data['error']['message'])) {
                throw new JsonRpcException('Error must contain code and message', JsonRpcException::ERROR_INVALID_REQUEST);
            }

            return self::error($data['id'] ?? null, $data['error']);
        }

        // 成功响应
        if (!isset($data['result'])) {
            throw new JsonRpcException('Response must contain either result or error', JsonRpcException::ERROR_INVALID_REQUEST);
        }

        if (!isset($data['id'])) {
            throw new JsonRpcException('Response must contain id', JsonRpcException::ERROR_INVALID_REQUEST);
        }

        return self::success($data['id'], $data['result']);
    }

    /**
     * 检查是否为错误响应
     */
    public function isError(): bool
    {
        return $this->error !== null;
    }

    /**
     * 获取结果
     *
     * @return mixed
     * @throws JsonRpcException 如果是错误响应
     */
    public function getResult(): mixed
    {
        if ($this->isError()) {
            throw JsonRpcException::fromErrorResponse($this->error);
        }

        return $this->result;
    }

    /**
     * 获取错误
     *
     * @return array|null
     */
    public function getError(): ?array
    {
        return $this->error;
    }

    /**
     * 获取请求 ID
     */
    public function getId(): int|string|null
    {
        return $this->id;
    }

    /**
     * 转换为数组
     */
    public function toArray(): array
    {
        $data = [
            'jsonrpc' => $this->jsonrpc,
            'id' => $this->id,
        ];

        if ($this->isError()) {
            $data['error'] = $this->error;
        } else {
            $data['result'] = $this->result;
        }

        return $data;
    }

    /**
     * 转换为 JSON 字符串
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE);
    }
}
