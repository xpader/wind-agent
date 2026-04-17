<?php

namespace App\Libs\Agent\Tools;

use App\Libs\Agent\ToolInterface;

/**
 * 执行命令工具
 */
class ExecTool implements ToolInterface
{
    public function getName(): string
    {
        return 'exec_command';
    }

    public function getDescription(): string
    {
        return '执行 shell 命令并返回结果（注意：有安全风险，谨慎使用）';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'command' => [
                    'type' => 'string',
                    'description' => '要执行的命令'
                ],
                'timeout' => [
                    'type' => 'integer',
                    'description' => '超时时间（秒），默认30秒',
                    'default' => 30
                ]
            ],
            'required' => ['command']
        ];
    }

    public function execute(array $arguments): string
    {
        $command = $arguments['command'] ?? '';
        $timeout = $arguments['timeout'] ?? 30;

        if (empty($command)) {
            throw new \RuntimeException('命令不能为空');
        }

        // 禁止执行 rm 命令（硬编码安全限制）
        if (preg_match('/\brm\b/', $command)) {
            throw new \RuntimeException('禁止删除文件');
        }

        // 基本的安全检查
        if (preg_match('/[;&|`$()]/', $command)) {
            throw new \RuntimeException('命令包含不安全的字符');
        }

        $descriptorspec = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w']   // stderr
        ];

        $process = proc_open($command, $descriptorspec, $pipes);

        if (!is_resource($process)) {
            throw new \RuntimeException("无法执行命令：{$command}");
        }

        // 设置超时
        stream_set_timeout($pipes[1], $timeout);
        stream_set_timeout($pipes[2], $timeout);

        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);

        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit_code = proc_close($process);

        $result = "命令: {$command}\n";
        $result .= "退出码: {$exit_code}\n";

        if ($output !== '') {
            $result .= "输出:\n{$output}\n";
        }

        if ($error !== '') {
            $result .= "错误:\n{$error}\n";
        }

        return $result;
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
}
