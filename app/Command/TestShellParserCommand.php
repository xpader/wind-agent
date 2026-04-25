<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Libs\ShellCommandParser;

class TestShellParserCommand extends Command
{

    protected function configure()
    {
        $this->setName('test:shell:parser')
            ->setDescription('测试 Shell 命令解析器');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $testCases = [
            '简单命令' => 'ls -la',
            '连续执行' => 'sleep 2 && date',
            '管道' => 'ps aux | grep nginx',
            '后台执行' => 'command &',
            '复杂组合' => 'ls && pwd && echo "done"',
            '带URL参数' => 'curl -s "https://api.example.com?param1=value1&param2=value2"',
            '危险命令' => 'rm -rf /',
            '多管道' => 'cat log.txt | grep error | tail -10',
            '条件执行' => 'ls || echo "failed"',
            '分号执行' => 'cd /tmp; ls',
            '子 shell' => '(echo "nested")',
            '混合操作' => 'ls && (pwd || echo "error") ; date',
            '子 shell 带 pipe' => '(cat file | grep test) && echo done',
        ];

        $output->writeln("<info>=== Shell 命令解析器测试 ===</info>");
        $output->writeln('');

        foreach ($testCases as $name => $command) {
            $output->writeln("<comment>【{$name}】</comment>");
            $output->writeln("命令: {$command}");

            try {
                $result = ShellCommandParser::parse($command);
                $this->displayParseResult($output, $result);
            } catch (\Exception $e) {
                $output->writeln("<error>解析错误: {$e->getMessage()}</error>");
            }

            $output->writeln('');
        }

        // 测试危险命令检测
        $output->writeln("<info>=== 危险命令检测测试 ===</info>");
        $output->writeln('');

        $dangerousTests = [
            'safe' => ['ls', 'pwd', 'date', 'cat file.txt', 'echo "test"'],
            'dangerous' => ['rm -rf /', 'dd if=/dev/zero of=/dev/sda', 'rm /tmp/file', 'sudo rm -rf /'],
        ];

        foreach ($dangerousTests as $type => $commands) {
            $output->writeln("<comment>" . ($type === 'safe' ? '安全命令' : '危险命令') . "</comment>");

            foreach ($commands as $cmd) {
                $result = ShellCommandParser::parse($cmd);
                $hasDangerous = ShellCommandParser::hasDangerousCommand($result);
                $expected = $type === 'dangerous';
                $status = ($hasDangerous === $expected) ? '<info>✓</info>' : '<error>✗</error>';

                $output->writeln("{$status} {$cmd} - " . ($hasDangerous ? '检测到危险' : '安全'));
            }
            $output->writeln('');
        }

        return self::SUCCESS;
    }

    /**
     * 显示解析结果（输出原始数据）
     */
    private function displayParseResult(OutputInterface $output, array $result): void
    {
        $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $output->writeln($json);
    }

}
