<?php

namespace App\Command;

use App\Libs\Agent\ToolManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 测试 EditFileTool
 */
class TestEditFileCommand extends Command
{
    protected function configure()
    {
        $this->setName('test:edit-file')
            ->setDescription('测试文件编辑工具（内容替换式）');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>EditFileTool 测试</info>');
        $output->writeln('================================');

        // 创建测试文件
        $testFile = RUNTIME_DIR . '/test_edit.txt';
        $testContent = "第一行\n第二行\n第三行\n第二行\n第五行\n";

        if (file_exists($testFile)) {
            unlink($testFile);
        }

        file_put_contents($testFile, $testContent);
        $output->writeln("创建测试文件: {$testFile}");
        $output->writeln("原始内容:\n" . $testContent);
        $output->writeln('--------------------------------');

        // 获取工具
        $tool = ToolManager::get('edit_file');
        if ($tool === null) {
            $output->writeln('<error>工具未找到</error>');
            return 1;
        }

        // 测试1: 替换第一个匹配项
        $output->writeln('<info>测试1: 替换第一个"第二行"</info>');
        try {
            $result = $tool->execute([
                'path' => $testFile,
                'old_content' => "第二行\n",
                'new_content' => "修改后的第二行\n"
            ]);
            $output->writeln($result);
            $output->writeln('修改后内容: ' . file_get_contents($testFile));
        } catch (\Exception $e) {
            $output->writeln("<error>测试失败: {$e->getMessage()}</error>");
            return 1;
        }

        $output->writeln('--------------------------------');

        // 测试2: 替换所有匹配项
        $output->writeln('<info>测试2: 替换所有"第二行"</info>');
        // 重置文件
        file_put_contents($testFile, $testContent);
        try {
            $result = $tool->execute([
                'path' => $testFile,
                'old_content' => "第二行\n",
                'new_content' => "修改后的第二行\n",
                'replace_all' => true
            ]);
            $output->writeln($result);
            $output->writeln('修改后内容: ' . file_get_contents($testFile));
        } catch (\Exception $e) {
            $output->writeln("<error>测试失败: {$e->getMessage()}</error>");
            return 1;
        }

        $output->writeln('--------------------------------');

        // 测试3: 替换多行内容
        $output->writeln('<info>测试3: 替换多行内容</info>');
        file_put_contents($testFile, $testContent);
        try {
            $result = $tool->execute([
                'path' => $testFile,
                'old_content' => "第二行\n第三行\n",
                'new_content' => "合并的一行\n"
            ]);
            $output->writeln($result);
            $output->writeln('修改后内容: ' . file_get_contents($testFile));
        } catch (\Exception $e) {
            $output->writeln("<error>测试失败: {$e->getMessage()}</error>");
            return 1;
        }

        $output->writeln('--------------------------------');

        // 测试4: 内容不存在（应该失败）
        $output->writeln('<info>测试4: 替换不存在的内容（应该失败）</info>');
        try {
            $tool->execute([
                'path' => $testFile,
                'old_content' => '不存在的内容',
                'new_content' => '新内容'
            ]);
            $output->writeln('<error>测试失败：应该抛出异常但没有</error>');
            return 1;
        } catch (\RuntimeException $e) {
            $output->writeln('<comment>预期失败: ' . $e->getMessage() . '</comment>');
        }

        $output->writeln('--------------------------------');
        $output->writeln('<info>测试完成！</info>');

        // 清理测试文件
        if (file_exists($testFile)) {
            unlink($testFile);
            $output->writeln('已清理测试文件');
        }

        return 0;
    }
}
