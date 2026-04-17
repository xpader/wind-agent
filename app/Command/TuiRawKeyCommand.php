<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 原始键盘输入测试命令
 *
 * 直接读取底层键盘输入，绕过 php-tui 的事件系统
 */
class TuiRawKeyCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('tui:raw-key')
            ->setDescription('测试原始键盘输入（绕过 php-tui）');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln("<info>原始键盘输入测试</info>");
        $output->writeln("<comment>按 Ctrl+C 退出</comment>");
        $output->writeln("");
        $output->writeln("<comment>现在按键，我会显示底层读取的原始数据</comment>");
        $output->writeln("<comment>请测试 Shift+Enter, Ctrl+J, 普通Enter 等按键</comment>");
        $output->writeln("");

        // 设置终端为原始模式
        system('stty -icanon -echo');
        $stdin = fopen('php://stdin', 'r');

        $keyCount = 0;

        try {
            while (true) {
                $char = fgetc($stdin);
                if ($char === false) {
                    continue;
                }

                $keyCount++;
                $ord = ord($char);
                $hex = sprintf("0x%02x", $ord);
                $binary = sprintf("%08b", $ord);
                $charDisplay = ctype_print($char) ? $char : sprintf("\\x%02x", $ord);

                // 检查是否是特殊字符
                $specialInfo = '';
                if ($ord === 3) {
                    $specialInfo = ' (Ctrl+C - 退出)';
                } elseif ($ord === 10) {
                    $specialInfo = ' (Line Feed/Enter)';
                } elseif ($ord === 13) {
                    $specialInfo = ' (Carriage Return)';
                } elseif ($ord === 9) {
                    $specialInfo = ' (Tab)';
                } elseif ($ord === 127) {
                    $specialInfo = ' (Delete)';
                } elseif ($ord === 8) {
                    $specialInfo = ' (Backspace)';
                }

                $output->writeln(sprintf(
                    "<fg=green>按键 #%d:</> <fg=yellow>'%s'</> | ASCII: <fg=cyan>%d</> | 十六进制: <fg=magenta>%s</> | 二进制: <fg=blue>%s</>%s",
                    $keyCount,
                    $charDisplay,
                    $ord,
                    $hex,
                    $binary,
                    $specialInfo
                ));

                // 检查是否是 Ctrl+C
                if ($ord === 3) {
                    $output->writeln("");
                    $output->writeln("<info>检测到 Ctrl+C，退出...</info>");
                    break;
                }
            }
        } finally {
            // 恢复终端设置
            system('stty icanon echo');
            fclose($stdin);
        }

        return self::SUCCESS;
    }
}
