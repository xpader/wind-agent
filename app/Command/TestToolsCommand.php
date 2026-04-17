<?php

namespace App\Command;

use App\Libs\LLM\LLMRequest;
use App\Libs\LLM\LLMResponse;
use App\Libs\LLM\Clients\OllamaClient;
use App\Libs\Agent\ToolManager;
use Amp\Http\Client\HttpClientBuilder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 测试工具调用功能
 */
class TestToolsCommand extends Command
{
    protected function configure()
    {
        $this->setName('test:tools')
            ->setDescription('测试 LLM 工具调用功能')
            ->addOption(
                'host',
                'H',
                InputOption::VALUE_OPTIONAL,
                'LLM 服务地址',
                '172.19.208.203:11434'
            )
            ->addOption(
                'model',
                'm',
                InputOption::VALUE_OPTIONAL,
                '模型名称',
                'qwen3.5:9b-q8_0'
            )
            ->addOption(
                'prompt',
                'p',
                InputOption::VALUE_OPTIONAL,
                '测试提示词',
                '请读取 README.md 文件的内容，然后告诉我里面提到了什么关键信息'
            )
            ->addOption(
                'list-tools',
                'l',
                InputOption::VALUE_NONE,
                '列出所有可用工具'
            )
            ->addOption(
                'single-step',
                's',
                InputOption::VALUE_NONE,
                '单步模式，每次工具调用后暂停'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $host = $input->getOption('host');
        $model = $input->getOption('model');
        $prompt = $input->getOption('prompt');
        $listTools = $input->getOption('list-tools');
        $singleStep = $input->getOption('single-step');

        $output->writeln('');
        $output->writeln('<fg=blue;options=bold>========================================</>');
        $output->writeln('<fg=blue;options=bold>      LLM 工具调用测试</>');
        $output->writeln('<fg=blue;options=bold>========================================</>');
        $output->writeln('');

        // 列出工具
        if ($listTools) {
            $output->writeln('<fg=yellow>获取可用工具列表...</>');
            $tools = ToolManager::getAll();

            $output->writeln('');
            $output->writeln('<fg=green;options=bold>========== 可用工具 ==========</>');
            foreach ($tools as $tool) {
                $output->writeln("  <info>名称:</info> {$tool->getName()}");
                $output->writeln("  <info>描述:</info> {$tool->getDescription()}");
                $output->writeln("  <info>参数:</info> " . json_encode($tool->getParameters()['properties'] ?? [], JSON_UNESCAPED_UNICODE));
                $output->writeln('');
            }
            $output->writeln('<fg=green;options=bold>==============================</>');
            return self::SUCCESS;
        }

        $output->writeln("<info>服务地址:</info> {$host}");
        $output->writeln("<info>模型名称:</info> {$model}");
        $output->writeln("<info>提示词:</info> {$prompt}");
        $output->writeln('');

        try {
            // 创建客户端
            $httpClient = HttpClientBuilder::buildDefault();
            $client = new OllamaClient(
                httpClient: $httpClient,
                baseUrl: "http://{$host}",
                timeout: 120  // 工具调用可能需要更长时间
            );

            // 创建请求对象并添加工具
            $request = LLMRequest::create()
                ->addUser($prompt)
                ->model($model)
                ->temperature(0.7)
                ->maxTokens(2000);

            // 添加所有可用工具
            foreach (ToolManager::getAll() as $tool) {
                $request->addTool($tool);
            }

            $output->writeln('<fg=yellow>已加载工具:</> ' . count($request->tools) . ' 个');

            // 调试：显示工具定义
            if ($output->isVerbose()) {
                $output->writeln('<fg=gray>工具定义:</fg>');
                $output->writeln(json_encode($request->tools, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $output->writeln('');
            }

            $output->writeln('');

            // 开始多轮对话
            $maxIterations = 10; // 防止无限循环
            $iteration = 0;

            while ($iteration < $maxIterations) {
                $iteration++;
                $output->writeln("<fg=cyan;options=bold>--- 第 {$iteration} 轮对话 ---</>");

                // 发送请求
                $output->writeln('<fg=yellow>发送请求...</>');
                $response = $client->chat($request);

                // 调试：输出响应的完整结构
                $output->writeln('');
                $output->writeln('<fg=cyan;options=bold>========== 响应结构调试 ==========</>');
                $output->writeln("<info>content 是否为空:</info> " . ($response->content === '' ? '是' : '否'));
                $output->writeln("<info>content 长度:</info> " . strlen($response->content));
                $output->writeln("<info>content 内容:</info> " . var_export($response->content, true));
                $output->writeln("<info>hasToolCalls:</info> " . ($response->hasToolCalls() ? '是' : '否'));
                $output->writeln("<info>toolCalls 数量:</info> " . count($response->toolCalls));

                if (count($response->toolCalls) > 0) {
                    $output->writeln("<info>toolCalls 详情:</info>");
                    foreach ($response->toolCalls as $index => $toolCall) {
                        $output->writeln("  工具调用 " . ($index + 1) . ":");
                        $output->writeln("    " . json_encode($toolCall, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                    }
                }

                // 显示原始响应数据
                if ($response->raw !== null) {
                    $output->writeln("<info>原始响应数据:</info>");
                    $output->writeln(json_encode($response->raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                }
                $output->writeln('<fg=cyan;options=bold>==================================</>');
                $output->writeln('');

                $output->writeln('<fg=green;options=bold>========== 响应内容 ==========</>');
                if ($response->content !== '') {
                    $output->writeln($response->content);
                } else {
                    $output->writeln('<fg=gray>(无文本内容，只有工具调用)</>');
                }
                $output->writeln('<fg=green;options=bold>================================</>');
                $output->writeln('');

                // 添加助手响应到请求中
                $request->addAssistant($response->content);

                // 检查是否有工具调用
                if (!$response->hasToolCalls()) {
                    $output->writeln('<fg=green>✅ 对话完成，没有更多工具调用</>');
                    break;
                }

                $output->writeln('<fg=yellow>检测到工具调用:</>');
                $output->writeln('');

                // 执行工具调用
                $toolResults = $response->executeToolCalls();

                foreach ($toolResults as $index => $result) {
                    $toolCall = $response->toolCalls[$index];
                    $toolName = $toolCall['function']['name'];
                    $toolArgs = $toolCall['function']['arguments'];

                    if (is_string($toolArgs)) {
                        $toolArgs = json_decode($toolArgs, true) ?? [];
                    }

                    $toolNum = $index + 1;
                    $output->writeln("  <fg=magenta>工具 {$toolNum}:</fg=magenta> {$toolName}");
                    $output->writeln("  <fg=magenta>参数:</fg=magenta> " . json_encode($toolArgs, JSON_UNESCAPED_UNICODE));

                    if (isset($result['error'])) {
                        $output->writeln("  <fg=red>错误:</fg=red> {$result['error']}");
                        $request->addToolMessage($result['tool_call_id'], "错误：{$result['error']}");
                    } else {
                        $output->writeln("  <fg=green>结果:</fg=green> " . mb_substr($result['result'], 0, 200) . (mb_strlen($result['result']) > 200 ? '...' : ''));
                        $request->addToolMessage($result['tool_call_id'], $result['result']);
                    }
                    $output->writeln('');
                }

                // 单步模式
                if ($singleStep) {
                    $output->writeln('<fg=yellow>按回车继续...</>');
                    fgets(STDIN);
                }
            }

            if ($iteration >= $maxIterations) {
                $output->writeln('<fg=yellow>⚠️ 达到最大迭代次数限制</>');
            }

            $output->writeln('');
            $output->writeln('<fg=green;options=bold>✅ 测试完成！</>');

            return self::SUCCESS;

        } catch (\Throwable $e) {
            $output->writeln('');
            $output->writeln('<fg=red;options=bold>========== 错误信息 ==========</>');
            $output->writeln("<error>错误类型:</error> " . get_class($e));
            $output->writeln("<error>错误消息:</error> {$e->getMessage()}");
            $output->writeln("<error>错误位置:</error> {$e->getFile()}:{$e->getLine()}");
            $output->writeln('<fg=red;options=bold>================================</>');

            return self::FAILURE;
        }
    }
}
