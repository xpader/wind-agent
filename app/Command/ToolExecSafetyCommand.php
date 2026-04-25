<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Libs\Agent\Tools\ExecTool;

class ToolExecSafetyCommand extends Command
{

    protected function configure()
    {
        $this->setName('tool:exec:safety')
            ->setDescription('测试 ExecTool 的安全检查');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $tool = new ExecTool();

        // 测试用例定义
        $testCases = [
            '应该允许的命令' => [
                'date',
                'ls -la',
                'pwd',
                'echo "hello world"',
                'sleep 2 && date',
                'ls && pwd',
                'echo "test" && echo "done"',
                'curl -s "https://api.example.com?param1=value1&param2=value2"',
                'curl -X POST "http://example.com/api?key=abc&data=test"',
                'grep "error" log.txt | tail -20',
                'ps aux | grep nginx',
                'cat file.txt | grep "pattern"',
                'echo "test & test"',       // 引号内的 &
                'cd /tmp; ls',              // 分号序列
                'ls || echo "failed"',      // OR 操作
                '(echo "nested")',          // 子 shell
                'find /tmp -type f | head -5', // 复杂管道
                'chmod 644 file.txt',       // 正常权限
                'chmod 755 script.sh',      // 正常脚本权限
                'chown user:group file.txt', // 正常所有者修改
            ],
            '应该禁止的命令' => [
                'rm -rf /',
                'rm file.txt',
                'command &',
                'ls &',
                'dd if=/dev/zero of=test.dat',
                'mkfs.ext4 /dev/sda1',
                'nc -l -p 8888',
                'nmap localhost',
                'sudo rm -rf /',
                'su -c "rm file.txt"',
                'doas cat /etc/shadow',
                'chmod 777 /etc/passwd',    // 危险权限
                'chmod 775 /etc/shadow',
                'chown root /etc/hosts',    // 修改系统文件
                'cp file.txt /etc/passwd',  // 覆盖系统文件
                'mv file.txt /etc/hosts',   // 覆盖系统文件
            ],
        ];

        $output->writeln("<info>=== ExecTool 安全检查测试 ===</info>");
        $output->writeln('');

        $totalPassed = 0;
        $totalFailed = 0;

        foreach ($testCases as $category => $commands) {
            $output->writeln("<info>--- {$category} ---</info>");

            $categoryPassed = 0;
            $categoryFailed = 0;

            foreach ($commands as $command) {
                $expected = str_contains($category, '允许');
                $actual = $this->testSafetyCheck($tool, $command);
                $passed = ($expected === $actual);

                if ($passed) {
                    $categoryPassed++;
                    $status = '<info>✓</info>';
                } else {
                    $categoryFailed++;
                    $status = '<error>✗</error>';
                }

                $output->writeln("{$status} {$command} " . ($actual ? '(允许)' : '(禁止)'));
            }

            $output->writeln("<comment>分类结果: 通过 {$categoryPassed}, 失败 {$categoryFailed}</comment>");
            $output->writeln('');

            $totalPassed += $categoryPassed;
            $totalFailed += $categoryFailed;
        }

        $output->writeln("<info>=== 总计 ===</info>");
        $output->writeln("<info>通过: {$totalPassed}</info>");
        $output->writeln("<error>失败: {$totalFailed}</error>");

        return self::SUCCESS;
    }

    /**
     * 测试安全检查（不真正执行命令）
     */
    private function testSafetyCheck(ExecTool $tool, string $command): bool
    {
        try {
            $tool->safetyCheck($command);
            return true;  // 允许
        } catch (\RuntimeException $e) {
            return false; // 禁止
        }
    }

}
