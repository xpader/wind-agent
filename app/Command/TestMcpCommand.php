<?php

namespace App\Command;

use App\Libs\MCP\McpManager;
use App\Libs\MCP\McpClient;
use App\Libs\Agent\ToolInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * MCP 测试命令
 *
 * 用于测试 MCP (Model Context Protocol) 功能
 */
class TestMcpCommand extends Command
{
    protected function configure()
    {
        $this->setName('test:mcp')
            ->setDescription('测试 MCP 功能')
            ->addOption('list-servers', 'l', InputOption::VALUE_NONE, '列出所有配置的 MCP 服务器')
            ->addOption('list-tools', 't', InputOption::VALUE_NONE, '列出所有可用的 MCP 工具')
            ->addOption('show-config', 'c', InputOption::VALUE_NONE, '显示服务器配置详情（包括环境变量）')
            ->addOption('server', 's', InputOption::VALUE_OPTIONAL, '指定 MCP 服务器名称')
            ->addOption('test-call', null, InputOption::VALUE_OPTIONAL, '测试工具调用（格式：server_tool_name 或 tool_name）')
            ->addOption('test-args', null, InputOption::VALUE_OPTIONAL, '工具调用参数（JSON 格式）', '{}')
            ->addOption('init-only', null, InputOption::VALUE_NONE, '仅初始化，不执行任何操作');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('');
        $output->writeln('<fg=blue;options=bold>========================================</>');
        $output->writeln('<fg=blue;options=bold>      MCP 测试工具</>');
        $output->writeln('<fg=blue;options=bold>========================================</>');
        $output->writeln('');

        try {
            // 初始化 MCP 管理器
            $server = $input->getOption('server');
            // 只有当明确指定了服务器名称时才限制初始化的服务器列表
            $enabledServers = ($server !== null && $server !== '') ? [$server] : null;

            $output->writeln('<fg=yellow>正在初始化 MCP 管理器...</>');
            if ($enabledServers !== null) {
                $output->writeln("<info>仅初始化服务器: " . implode(', ', $enabledServers) . "</info>");
            }
            McpManager::init($enabledServers);
            $output->writeln('<fg=green>✓ MCP 管理器初始化成功</>');
            $output->writeln('');

            // 显示统计信息
            $this->displayStats($output);

            // 列出服务器
            if ($input->getOption('list-servers')) {
                $this->listServers($output);
            }

            // 显示配置详情
            if ($input->getOption('show-config')) {
                $this->showConfig($output);
            }

            // 列出工具
            if ($input->getOption('list-tools')) {
                $this->listTools($output);
            }

            // 测试工具调用
            if ($input->getOption('test-call')) {
                $this->testToolCall($input, $output);
            }

            // 仅初始化
            if ($input->getOption('init-only')) {
                $output->writeln('<fg=green>✓ 初始化完成，退出</>');
            }

            return self::SUCCESS;

        } catch (\Throwable $e) {
            $this->displayError($e, $output);
            return self::FAILURE;
        } finally {
            // 确保 MCP 连接被关闭
            try {
                McpManager::closeAll();
            } catch (\Throwable $e) {
                // 忽略关闭时的错误
            }
        }
    }

    /**
     * 显示统计信息
     */
    private function displayStats(OutputInterface $output): void
    {
        $clientCount = McpManager::getClientCount();
        $toolCount = McpManager::getToolCount();

        $output->writeln('<fg=cyan;options=bold>========== 统计信息 ==========</>');
        $output->writeln("<info>MCP 客户端数:</info> {$clientCount}");
        $output->writeln("<info>MCP 工具数:</info> {$toolCount}");
        $output->writeln('');
    }

    /**
     * 列出所有服务器
     */
    private function listServers(OutputInterface $output): void
    {
        $clients = McpManager::getClients();

        $output->writeln('<fg=cyan;options=bold>========== MCP 服务器列表 ==========</>');

        if (count($clients) === 0) {
            $output->writeln('<fg=yellow>没有可用的 MCP 服务器</>');
            $output->writeln('');
            return;
        }

        foreach ($clients as $name => $client) {
            $output->writeln("<fg=green>服务器:</fg=green> {$name}");
            $output->writeln("<info>  命令:</info> " . get反射类属性($client, 'command'));
            $output->writeln("<info>  能力:</info> " . json_encode($client->getCapabilities(), JSON_UNESCAPED_UNICODE));
            $output->writeln('');
        }
    }

    /**
     * 显示服务器配置详情
     */
    private function showConfig(OutputInterface $output): void
    {
        $clients = McpManager::getClients();

        $output->writeln('<fg=cyan;options=bold>========== MCP 配置详情 ==========</>');

        if (count($clients) === 0) {
            $output->writeln('<fg=yellow>没有配置的 MCP 服务器</>');
            $output->writeln('');
            return;
        }

        foreach ($clients as $name => $client) {
            $output->writeln("<fg=green>服务器:</fg=green> {$name}");

            // 显示命令
            $command = get反射类属性($client, 'command');
            $args = get反射类属性($client, 'args');
            $output->writeln("<info>  命令:</info> {$command}");
            $output->writeln("<info>  参数:</info> " . implode(' ', array_map(fn($arg) => escapeshellarg($arg), $args)));

            // 显示环境变量
            $env = get反射类属性($client, 'env');
            if (count($env) > 0) {
                $output->writeln("<info>  环境变量:</info>");
                foreach ($env as $key => $value) {
                    // 隐藏敏感信息
                    if ($value !== '') {
                        $displayValue = strlen($value) > 20
                            ? substr($value, 0, 10) . '...' . substr($value, -7)
                            : $value;
                        $output->writeln("    {$key} = {$displayValue}");
                    } else {
                        $output->writeln("    {$key} = (空)");
                    }
                }
            } else {
                $output->writeln("<info>  环境变量:</info> (无)");
            }

            $output->writeln('');
        }
    }

    /**
     * 列出所有工具
     */
    private function listTools(OutputInterface $output): void
    {
        $tools = McpManager::getAllTools();

        $output->writeln('<fg=cyan;options=bold>========== MCP 工具列表 ==========</>');

        if (count($tools) === 0) {
            $output->writeln('<fg=yellow>没有可用的 MCP 工具</>');
            $output->writeln('');
            return;
        }

        foreach ($tools as $tool) {
            $output->writeln("<fg=green>工具:</fg=green> {$tool->getName()}");
            $output->writeln("<info>  描述:</info> {$tool->getDescription()}");

            $params = $tool->getParameters();
            $properties = $params['properties'] ?? [];
            $required = $params['required'] ?? [];

            if (count($properties) > 0) {
                $output->writeln("<info>  参数:</info>");
                foreach ($properties as $paramName => $paramDef) {
                    $isRequired = in_array($paramName, $required) ? ' (必需)' : '';
                    $paramType = $paramDef['type'] ?? 'unknown';
                    $paramDesc = $paramDef['description'] ?? '';
                    $output->writeln("    - {$paramName}: {$paramType}{$isRequired} - {$paramDesc}");
                }
            }

            $output->writeln('');
        }
    }

    /**
     * 测试工具调用
     */
    private function testToolCall(InputInterface $input, OutputInterface $output): void
    {
        $toolName = $input->getOption('test-call');
        $argsJson = $input->getOption('test-args');

        $args = json_decode($argsJson, true);
        if ($args === null && json_last_error() !== JSON_ERROR_NONE) {
            $output->writeln("<fg=red>✗ 参数 JSON 解析失败: " . json_last_error_msg() . "</>");
            return;
        }

        $output->writeln('<fg=cyan;options=bold>========== 测试工具调用 ==========</>');
        $output->writeln("<info>工具:</info> {$toolName}");
        $output->writeln("<info>参数:</info> " . json_encode($args, JSON_UNESCAPED_UNICODE));
        $output->writeln('');

        $tool = McpManager::getTool($toolName);

        if ($tool === null) {
            $output->writeln("<fg=red>✗ 工具不存在: {$toolName}</>");
            return;
        }

        try {
            $output->writeln('<fg=yellow>正在调用工具...</>');
            $result = $tool->execute($args);
            $output->writeln('<fg=green>✓ 工具调用成功</>');
            $output->writeln('');
            $output->writeln('<fg=cyan;options=bold>========== 执行结果 ==========</>');
            $output->writeln($result);
            $output->writeln('');
        } catch (\Throwable $e) {
            $output->writeln('<fg=red>✗ 工具调用失败</>');
            $output->writeln("<error>错误: {$e->getMessage()}</>");
        }
    }

    /**
     * 显示错误信息
     */
    private function displayError(\Throwable $e, OutputInterface $output): void
    {
        $output->writeln('');
        $output->writeln('<fg=red;options=bold>========== 错误信息 ==========</>');
        $output->writeln("<error>错误类型:</error> " . get_class($e));
        $output->writeln("<error>错误消息:</error> {$e->getMessage()}");
        $output->writeln("<error>错误位置:</error> {$e->getFile()}:{$e->getLine()}");
        $output->writeln('');
    }
}

/**
 * 获取对象的私有属性值
 *
 * @param object $obj 对象
 * @param string $property 属性名
 * @return mixed
 */
function get反射类属性(object $obj, string $property): mixed
{
    $reflection = new \ReflectionClass($obj);
    $property = $reflection->getProperty($property);
    $property->setAccessible(true);
    return $property->getValue($obj);
}
