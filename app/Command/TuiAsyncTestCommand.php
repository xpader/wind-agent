<?php

declare(strict_types=1);

namespace App\Command;

use App\Libs\Tui\AsyncTtyEventProvider;
use PhpTui\Term\Terminal;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 异步 TTY 事件测试
 */
class TuiAsyncTestCommand extends Command
{
    protected static $defaultName = 'tui:async-test';
    protected static $defaultDescription = '异步 TTY 事件测试';

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln("<info>异步 TTY 事件测试</info>");
        $output->writeln("<comment>请按下 5 个键进行测试...</comment>");
        $output->writeln("");

        $terminal = Terminal::new(
            eventProvider: new AsyncTtyEventProvider()
        );

        $terminal->enableRawMode();

        try {
            $future = \Amp\async(function () use ($terminal, $output) {
                for ($i = 1; $i <= 5; $i++) {
                    $event = $terminal->events()->next();

                    if ($event !== null) {
                        $eventClass = get_class($event);
                        $eventType = basename(str_replace('\\', '/', $eventClass));

                        $output->writeln("事件 #{$i}: <comment>{$eventType}</comment> - {$event}");
                    }
                }

                return 5;
            });

            \Amp\Future\awaitFirst([$future]);

        } finally {
            $terminal->disableRawMode();
        }

        $output->writeln("");
        $output->writeln("<info>测试完成！</info>");

        return 0;
    }
}
