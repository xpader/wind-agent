<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Hello Command Example
 */
class HelloCommand extends Command
{

    protected function configure()
    {
        $this->setName('hello:world')
            ->setDescription('Say hello world.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('Hello World!');
        $output->writeln('Thanks for using Wind Framework.');
        return self::SUCCESS;
    }

}
