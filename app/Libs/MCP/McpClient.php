<?php

namespace App\Libs\MCP;

/**
 * MCP 客户端类
 *
 * 实现与 MCP 服务器的 JSON-RPC 通信（stdio 传输）
 */
class McpClient
{
    private string $name;
    private string $command;
    private array $args;
    private array $env;
    private $process = null;
    private array $pipes = [];
    private int $requestId = 1;
    private bool $initialized = false;
    private array $capabilities = [];

    public function __construct(
        string $name,
        string $command,
        array $args = [],
        array $env = []
    ) {
        $this->name = $name;
        $this->command = $command;
        $this->args = $args;
        $this->env = $env;
    }

    public function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        try {
            $this->startProcess();
            $this->performHandshake();
            $this->initialized = true;
        } catch (\Throwable $e) {
            $this->close();
            throw new \Exception("MCP 客户端初始化失败 ({$this->name}): " . $e->getMessage(), 0, $e);
        }
    }

    private function startProcess(): void
    {
        // 构建命令行
        $commandLine = $this->command;
        foreach ($this->args as $arg) {
            $commandLine .= ' ' . $arg;
        }

        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ];

        // 设置环境变量（必须包含 PATH 以便 npx 找到 MCP 服务器）
        $env = [
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin:/usr/local/bin',
            'HOME' => getenv('HOME') ?: '/tmp',
        ];

        // 合并配置的环境变量
        foreach ($this->env as $key => $value) {
            $env[$key] = $value;
        }

        $this->process = proc_open($commandLine, $descriptorspec, $this->pipes, null, $env);

        if (!is_resource($this->process)) {
            throw new \Exception("无法启动 MCP 服务器进程");
        }
    }

    private function performHandshake(): void
    {
        // 发送 initialize 请求
        $requestId = $this->requestId++;
        $request = [
            'jsonrpc' => '2.0',
            'id' => $requestId,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => new \stdClass(),
                'clientInfo' => [
                    'name' => 'wind-chat',
                    'version' => '1.0.0'
                ]
            ]
        ];

        $this->sendRequest($request);
        $response = $this->readResponse();

        if ($response === null) {
            throw new \Exception("初始化失败：无响应");
        }

        $data = json_decode($response, true);
        if (!isset($data['result']['capabilities'])) {
            throw new \Exception("初始化响应格式错误: " . $response);
        }

        $this->capabilities = $data['result']['capabilities'];

        // 发送 initialized 通知
        $notification = [
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
            'params' => new \stdClass()
        ];

        $this->sendNotification($notification);

        // 等待服务器处理 initialized 状态
        sleep(1);
    }

    public function listTools(): array
    {
        $this->ensureInitialized();

        $requestId = $this->requestId++;
        $request = [
            'jsonrpc' => '2.0',
            'id' => $requestId,
            'method' => 'tools/list',
            'params' => new \stdClass()
        ];

        $this->sendRequest($request);
        $response = $this->readResponse();

        if ($response === null) {
            throw new \Exception("tools/list 失败：无响应");
        }

        $data = json_decode($response, true);
        return $data['result']['tools'] ?? [];
    }

    public function callTool(string $name, array $arguments = []): string
    {
        $this->ensureInitialized();

        $requestId = $this->requestId++;
        $request = [
            'jsonrpc' => '2.0',
            'id' => $requestId,
            'method' => 'tools/call',
            'params' => [
                'name' => $name,
                'arguments' => $arguments
            ]
        ];

        $this->sendRequest($request);
        $response = $this->readResponse();

        if ($response === null) {
            throw new \Exception("tools/call 失败：无响应");
        }

        $data = json_decode($response, true);

        if (isset($data['result']['content']) && is_array($data['result']['content'])) {
            $result = '';
            foreach ($data['result']['content'] as $item) {
                if (isset($item['text'])) {
                    $result .= $item['text'];
                }
            }
            return $result;
        }

        return json_encode($data['result'] ?? [], JSON_UNESCAPED_UNICODE);
    }

    public function listResources(): array
    {
        $this->ensureInitialized();

        $requestId = $this->requestId++;
        $request = [
            'jsonrpc' => '2.0',
            'id' => $requestId,
            'method' => 'resources/list',
            'params' => new \stdClass()
        ];

        $this->sendRequest($request);
        $response = $this->readResponse();

        if ($response === null) {
            throw new \Exception("resources/list 失败：无响应");
        }

        $data = json_decode($response, true);
        return $data['result']['resources'] ?? [];
    }

    public function readResource(string $uri): string
    {
        $this->ensureInitialized();

        $requestId = $this->requestId++;
        $request = [
            'jsonrpc' => '2.0',
            'id' => $requestId,
            'method' => 'resources/read',
            'params' => [
                'uri' => $uri
            ]
        ];

        $this->sendRequest($request);
        $response = $this->readResponse();

        if ($response === null) {
            throw new \Exception("resources/read 失败：无响应");
        }

        $data = json_decode($response, true);

        if (isset($data['result']['contents'][0]['text'])) {
            return $data['result']['contents'][0]['text'];
        }

        return json_encode($data['result'] ?? [], JSON_UNESCAPED_UNICODE);
    }

    public function listPrompts(): array
    {
        $this->ensureInitialized();

        $requestId = $this->requestId++;
        $request = [
            'jsonrpc' => '2.0',
            'id' => $requestId,
            'method' => 'prompts/list',
            'params' => new \stdClass()
        ];

        $this->sendRequest($request);
        $response = $this->readResponse();

        if ($response === null) {
            throw new \Exception("prompts/list 失败：无响应");
        }

        $data = json_decode($response, true);
        return $data['result']['prompts'] ?? [];
    }

    public function getPrompt(string $name, array $arguments = []): string
    {
        $this->ensureInitialized();

        $requestId = $this->requestId++;
        $request = [
            'jsonrpc' => '2.0',
            'id' => $requestId,
            'method' => 'prompts/get',
            'params' => [
                'name' => $name,
                'arguments' => $arguments
            ]
        ];

        $this->sendRequest($request);
        $response = $this->readResponse();

        if ($response === null) {
            throw new \Exception("prompts/get 失败：无响应");
        }

        $data = json_decode($response, true);

        if (isset($data['result']['messages'])) {
            $result = '';
            foreach ($data['result']['messages'] as $message) {
                if (isset($message['content']['text'])) {
                    $result .= $message['content']['text'] . "\n";
                }
            }
            return rtrim($result);
        }

        return json_encode($data['result'] ?? [], JSON_UNESCAPED_UNICODE);
    }

    private function sendRequest(array $request): void
    {
        $json = json_encode($request, JSON_UNESCAPED_UNICODE);
        fwrite($this->pipes[0], $json . "\n");
        fflush($this->pipes[0]);
    }

    private function sendNotification(array $notification): void
    {
        $json = json_encode($notification, JSON_UNESCAPED_UNICODE);
        fwrite($this->pipes[0], $json . "\n");
        fflush($this->pipes[0]);
    }

    private function readResponse(): ?string
    {
        $line = fgets($this->pipes[1]);

        if ($line === false || $line === '') {
            return null;
        }

        return trim($line);
    }

    private function ensureInitialized(): void
    {
        if (!$this->initialized) {
            throw new \Exception("MCP 客户端未初始化");
        }
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCapabilities(): array
    {
        return $this->capabilities;
    }

    public function close(): void
    {
        if ($this->process !== null) {
            foreach ($this->pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($this->process);
            $this->process = null;
            $this->pipes = [];
            $this->initialized = false;
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
