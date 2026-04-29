<?php

namespace App\Libs\Agent\Tools;

use App\Libs\Agent\ToolInterface;
use App\Libs\ShellCommandParser;

/**
 * 执行命令工具
 *
 * 安全策略：
 * - 基于 AST 深度分析命令结构
 * - 只阻止真正危险的操作
 * - 允许安全的管道、逻辑操作等
 */
class ExecTool implements ToolInterface
{
    // 安全配置
    private const ALLOWED_OPERATORS = ['and', 'or', 'pipe', 'sequence']; // 允许的操作符类型
    private const DANGEROUS_COMMANDS = ['rm', 'dd', 'mkfs', 'fdisk', 'nc', 'nmap', 'format']; // 危险命令列表
    private const PRIVILEGE_COMMANDS = ['sudo', 'su', 'doas']; // 特权命令列表
    private const MAX_COMMANDS = 10; // 单次执行的最大命令数量

    public function getName(): string
    {
        return 'exec_command';
    }

    public function getDescription(): string
    {
        return '执行 shell 命令并返回结果';
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

        if ($command === '') {
            throw new \RuntimeException('命令不能为空');
        }

        // 执行深度安全检查
        $this->deepSafetyCheck($command);

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

    /**
     * 深度安全检查（基于 AST 分析）
     *
     * @param string $command 要检查的命令
     * @throws \RuntimeException 当命令不安全时抛出异常
     */
    private function deepSafetyCheck(string $command): void
    {
        // 解析命令为 AST
        $ast = ShellCommandParser::parse($command);

        // 1. 检查命令数量限制
        $commandCount = $this->countCommands($ast['ast']);
        if ($commandCount > self::MAX_COMMANDS) {
            throw new \RuntimeException("命令数量超过限制（最多 " . self::MAX_COMMANDS . " 个）");
        }

        // 2. 检查危险操作符
        $this->checkOperators($ast['ast']);

        // 3. 检查危险命令
        $this->checkDangerousCommands($ast['ast']);

        // 4. 检查特权命令
        $this->checkPrivilegeEscalation($ast['ast']);

        // 5. 检查危险参数组合
        $this->checkDangerousArgs($ast['ast']);
    }

    /**
     * 统计命令数量
     *
     * @param mixed $ast AST 节点或数组
     * @return int 命令数量
     */
    private function countCommands($ast): int
    {
        $count = 0;

        $this->traverseAst($ast, function($node) use (&$count) {
            if (($node['type'] ?? '') === 'command') {
                $count++;
            }
        });

        return $count;
    }

    /**
     * 检查操作符
     *
     * @param mixed $ast AST 节点或数组
     * @throws \RuntimeException 当发现不允许的操作符时抛出异常
     */
    private function checkOperators($ast): void
    {
        $this->traverseAst($ast, function($node) {
            $type = $node['type'] ?? '';

            // 检查是否是允许的操作符
            if ($type !== '' && $type !== 'command' && $type !== 'subshell') {
                if (!in_array($type, self::ALLOWED_OPERATORS)) {
                    throw new \RuntimeException("不允许的操作符类型: {$type}");
                }
            }

            // 检查后台执行
            if (($node['background'] ?? false) === true) {
                throw new \RuntimeException('不允许后台执行（&）');
            }
        });
    }

    /**
     * 检查危险命令
     *
     * @param mixed $ast AST 节点或数组
     * @throws \RuntimeException 当发现危险命令时抛出异常
     */
    private function checkDangerousCommands($ast): void
    {
        $workspaceDir = $this->getWorkspaceDir();

        $this->traverseAst($ast, function($node) use ($workspaceDir) {
            if (($node['type'] ?? '') === 'command') {
                $commandName = $node['name'] ?? '';

                // 特殊处理 rm 命令
                if ($commandName === 'rm') {
                    $this->checkRmCommand($node, $workspaceDir);
                    return;
                }

                // 检查其他危险命令（支持精确匹配和前缀匹配）
                foreach (self::DANGEROUS_COMMANDS as $dangerous) {
                    // 跳过 rm（已单独处理）
                    if ($dangerous === 'rm') {
                        continue;
                    }

                    // 精确匹配
                    if ($commandName === $dangerous) {
                        throw new \RuntimeException("不允许执行危险命令: {$commandName}");
                    }

                    // 前缀匹配（如 mkfs.ext4 匹配 mkfs）
                    if (str_starts_with($commandName, $dangerous . '.')) {
                        throw new \RuntimeException("不允许执行危险命令: {$commandName}");
                    }

                    // 路径中的危险命令（如 /sbin/mkfs）
                    if (str_contains($commandName, '/' . $dangerous)) {
                        throw new \RuntimeException("不允许执行危险命令: {$commandName}");
                    }
                }
            }
        });
    }

    /**
     * 检查 rm 命令的安全性
     *
     * @param array $node 命令节点
     * @param string $workspaceDir workspace 目录路径
     * @throws \RuntimeException 当 rm 命令不安全时抛出异常
     */
    private function checkRmCommand(array $node, string $workspaceDir): void
    {
        $args = $node['args'] ?? [];

        // 检查危险选项
        $hasRecursive = in_array('-r', $args) || in_array('-rf', $args) ||
                        in_array('--recursive', $args) || in_array('-fr', $args);
        $hasForce = in_array('-f', $args) || in_array('--force', $args);
        $hasNoPreserveRoot = in_array('--no-preserve-root', $args);

        if ($hasNoPreserveRoot) {
            throw new \RuntimeException("不允许使用 --no-preserve-root 选项");
        }

        // 提取路径参数（跳过选项）
        $paths = [];
        for ($i = 0; $i < count($args); $i++) {
            $arg = $args[$i];
            // 跳过选项（以 - 开头）
            if (!str_starts_with($arg, '-')) {
                $paths[] = $arg;
            }
        }

        // 检查所有路径是否都在 workspace 内
        foreach ($paths as $path) {
            // 解析路径
            $realPath = $this->resolvePath($path);

            // 检查路径是否在 workspace 内
            if (!$this->isPathInWorkspace($realPath, $workspaceDir)) {
                throw new \RuntimeException("不允许删除 workspace 目录外的文件: {$path}");
            }

            // 额外保护：禁止删除 workspace 根目录本身
            if ($realPath === rtrim($workspaceDir, '/')) {
                throw new \RuntimeException("不允许删除 workspace 根目录");
            }
        }
    }

    /**
     * 获取 workspace 目录路径
     *
     * @return string workspace 目录的绝对路径
     */
    private function getWorkspaceDir(): string
    {
        $workspaceDir = config('agents.workspace_dir', getcwd() . '/workspace');
        return realpath($workspaceDir) ?: $workspaceDir;
    }

    /**
     * 解析路径为绝对路径
     *
     * @param string $path 文件路径
     * @return string 绝对路径
     */
    private function resolvePath(string $path): string
    {
        // 处理 ~ 符号
        if (str_starts_with($path, '~/')) {
            $home = $_SERVER['HOME'] ?? '';
            if ($home !== '') {
                $path = $home . substr($path, 1);
            }
        }

        // 如果是绝对路径
        if (str_starts_with($path, '/')) {
            return $path;
        }

        // 相对路径，基于当前工作目录
        $cwd = getcwd();
        $fullPath = $cwd . '/' . $path;

        // 规范化路径（处理 .. 和 .）
        $parts = explode('/', $fullPath);
        $resolved = [];

        foreach ($parts as $part) {
            if ($part === '.' || $part === '') {
                continue;
            } elseif ($part === '..') {
                if (count($resolved) > 0) {
                    array_pop($resolved);
                }
            } else {
                $resolved[] = $part;
            }
        }

        return '/' . implode('/', $resolved);
    }

    /**
     * 检查路径是否在 workspace 目录内
     *
     * @param string $path 要检查的路径
     * @param string $workspaceDir workspace 目录路径
     * @return bool 如果路径在 workspace 内返回 true
     */
    private function isPathInWorkspace(string $path, string $workspaceDir): bool
    {
        // 规范化路径
        $normalizedPath = rtrim($path, '/');
        $normalizedWorkspace = rtrim($workspaceDir, '/');

        // 检查路径是否以 workspace 目录开头
        return str_starts_with($normalizedPath, $normalizedWorkspace . '/');
    }

    /**
     * 检查特权提升
     *
     * @param mixed $ast AST 节点或数组
     * @throws \RuntimeException 当发现特权提升时抛出异常
     */
    private function checkPrivilegeEscalation($ast): void
    {
        $this->traverseAst($ast, function($node) {
            if (($node['type'] ?? '') === 'command') {
                $commandName = $node['name'] ?? '';

                // 检查是否是特权命令
                if (in_array($commandName, self::PRIVILEGE_COMMANDS)) {
                    throw new \RuntimeException("不允许执行特权命令: {$commandName}");
                }
            }
        });
    }

    /**
     * 检查危险参数组合
     *
     * @param mixed $ast AST 节点或数组
     * @throws \RuntimeException 当发现危险参数时抛出异常
     */
    private function checkDangerousArgs($ast): void
    {
        $this->traverseAst($ast, function($node) {
            if (($node['type'] ?? '') !== 'command') {
                return;
            }

            $commandName = $node['name'] ?? '';
            $args = $node['args'] ?? [];

            // 检查特定命令的危险参数
            switch ($commandName) {
                case 'chmod':
                    // 检查是否有 777 等过于宽松的权限
                    foreach ($args as $arg) {
                        if (preg_match('/^777$|^775$/', $arg)) {
                            throw new \RuntimeException("不允许设置过于宽松的文件权限: {$arg}");
                        }
                    }
                    break;

                case 'chown':
                    // 检查是否试图修改系统文件所有者
                    foreach ($args as $arg) {
                        if (str_starts_with($arg, '/etc/') || str_starts_with($arg, '/usr/')) {
                            throw new \RuntimeException("不允许修改系统文件所有者: {$arg}");
                        }
                    }
                    break;

                case 'mv':
                case 'cp':
                    // 检查是否覆盖系统文件
                    foreach ($args as $arg) {
                        if (str_starts_with($arg, '/etc/') || str_starts_with($arg, '/usr/bin/')) {
                            throw new \RuntimeException("不允许操作系统文件: {$arg}");
                        }
                    }
                    break;

                case 'curl':
                case 'wget':
                    // 检查是否下载到系统目录
                    foreach ($args as $arg) {
                        if (str_starts_with($arg, '-O') && isset($args[$i + 1])) {
                            $nextArg = $args[$i + 1];
                            if (str_starts_with($nextArg, '/usr/') || str_starts_with($nextArg, '/etc/')) {
                                throw new \RuntimeException("不允许下载到系统目录: {$nextArg}");
                            }
                        }
                    }
                    break;
            }
        });
    }

    /**
     * 遍历 AST 节点
     *
     * @param mixed $ast AST 节点或数组
     * @param callable $callback 回调函数
     */
    private function traverseAst($ast, callable $callback): void
    {
        // 处理数组
        if (is_array($ast) && isset($ast[0])) {
            foreach ($ast as $node) {
                $this->traverseAst($node, $callback);
            }
            return;
        }

        // 处理节点
        if (is_array($ast) && isset($ast['type'])) {
            $callback($ast);

            // 递归处理 data 字段
            $data = $ast['data'] ?? [];
            if (is_array($data)) {
                $this->traverseAst($data, $callback);
            }
        }
    }

    /**
     * 旧的安全检查方法（保留用于向后兼容，但已弃用）
     * @deprecated 使用 deepSafetyCheck 代替
     */
    public function safetyCheck(string $command): void
    {
        $this->deepSafetyCheck($command);
    }
}
