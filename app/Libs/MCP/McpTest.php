<?php

require __DIR__ . '/../../vendor/wind-framework/wind-framework/src/base/function.php';

use Amp\Process\Process;
use Amp\ByteStream\BufferedReader;
use Amp\ByteStream\WritableResourceStream;
use Amp\TimeoutCancellation;

// 测试 MCP fetch 服务器通信

echo "=== 启动 MCP fetch 服务器 ===\n";

$command = 'npx -y @tokenizin/mcp-npx-fetch';
$process = Process::start($command);

$stdin = $process->getStdin();
$stdout = new BufferedReader($process->getStdout());

echo "服务器已启动\n\n";

// 1. 发送 initialize 请求
echo "1. 发送 initialize 请求\n";
$request1 = json_encode([
    'jsonrpc' => '2.0',
    'id' => 1,
    'method' => 'initialize',
    'params' => [
        'protocolVersion' => '2024-11-05',
        'capabilities' => new \stdClass(),
        'clientInfo' => [
            'name' => 'test',
            'version' => '1.0.0'
        ]
    ]
], JSON_UNESCAPED_UNICODE);

$stdin->write($request1 . "\n");

Amp\delay(0.5);

$response1 = $stdout->readUntil("\n", new TimeoutCancellation(5000));
echo "响应: " . ($response1 ?? 'null') . "\n\n";

// 2. 发送 initialized 通知
echo "2. 发送 initialized 通知\n";
$notification = json_encode([
    'jsonrpc' => '2.0',
    'method' => 'notifications/initialized',
    'params' => []
], JSON_UNESCAPED_UNICODE);

$stdin->write($notification . "\n");

Amp\delay(1);

// 3. 发送 tools/list 请求
echo "3. 发送 tools/list 请求\n";
$request2 = json_encode([
    'jsonrpc' => '2.0',
    'id' => 2,
    'method' => 'tools/list',
    'params' => []
], JSON_UNESCAPED_UNICODE);

$stdin->write($request2 . "\n");

Amp\delay(0.5);

$response2 = $stdout->readUntil("\n", new TimeoutCancellation(5000));
echo "响应: " . ($response2 ?? 'null') . "\n\n";

// 清理
$process->kill();

echo "=== 测试完成 ===\n";
