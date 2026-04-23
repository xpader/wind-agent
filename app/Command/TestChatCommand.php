<?php

namespace App\Command;

use App\Libs\LLM\LLMClient;
use App\Libs\LLM\LLMResponse;
use App\Libs\LLM\ClientFactory;
use App\Libs\LLM\LLMRequest;
use Amp\Http\Client\HttpClientBuilder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 聊天测试命令
 * 专注于 LLM 聊天功能测试
 */
class TestChatCommand extends Command
{
    protected function configure()
    {
        $this->setName('test:chat')
            ->setDescription('聊天测试命令（支持多种 LLM 平台）')
            ->addOption('client', 'c', InputOption::VALUE_OPTIONAL, '客户端类型 (openai/ollama/minimax/deepseek/anthropic/claude)', 'ollama')
            ->addOption('host', 'H', InputOption::VALUE_OPTIONAL, '服务地址', '')
            ->addOption('model', 'm', InputOption::VALUE_OPTIONAL, '模型名称', 'qwen3.5:4b')
            ->addOption('system', 's', InputOption::VALUE_OPTIONAL, '系统提示词', '')
            ->addOption('prompt', 'p', InputOption::VALUE_OPTIONAL, '用户提示词', '你好，请简单介绍一下你自己')
            ->addOption('temperature', 't', InputOption::VALUE_OPTIONAL, '温度参数 (0-2)', '0.7')
            ->addOption('max-tokens', null, InputOption::VALUE_OPTIONAL, '最大 token 数', '2000')
            ->addOption('stream', null, InputOption::VALUE_NONE, '使用流式输出')
            ->addOption('think', null, InputOption::VALUE_OPTIONAL, '启用思考模式 (true/false/high/medium/low)')
            ->addOption('list-models', 'l', InputOption::VALUE_NONE, '列出可用模型');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // 解析参数
        $config = $this->parseConfig($input);

        // 创建客户端
        $client = $this->createClient($config['clientType'], $config['host']);

        // 显示标题和配置信息
        $this->displayHeader($output, $config);

        // 处理模型列表
        if ($config['listModels']) {
            return $this->handleListModels($client, $output);
        }

        try {
            // 创建请求
            $request = $this->buildRequest($config, $output);

            // 执行对话
            $response = $this->executeChat($client, $request, $config, $output);

            // 显示响应详情
            $this->displayResponseDetails($response, $config, $output);

            $output->writeln('<fg=green;options=bold>✅ 测试完成！</>');
            return self::SUCCESS;

        } catch (\Throwable $e) {
            $this->displayError($e, $output);
            return self::FAILURE;
        }
    }

    /**
     * 解析输入参数
     */
    private function parseConfig(InputInterface $input): array
    {
        return [
            'clientType' => $input->getOption('client'),
            'host' => $input->getOption('host'),
            'model' => $input->getOption('model'),
            'systemPrompt' => $input->getOption('system'),
            'prompt' => $input->getOption('prompt'),
            'temperature' => (float) $input->getOption('temperature'),
            'maxTokens' => (int) $input->getOption('max-tokens'),
            'useStream' => $input->getOption('stream'),
            'think' => $input->getOption('think'),
            'listModels' => $input->getOption('list-models'),
        ];
    }

    /**
     * 创建客户端
     */
    private function createClient(string $type, string $host): LLMClient
    {
        $httpClient = HttpClientBuilder::buildDefault();

        $options = ['timeout' => 60];

        // 只有 ollama 才设置 base_url
        if ($type === 'ollama') {
            $options['base_url'] = $host !== '' ? $host : 'http://172.19.208.203:11434';
        }

        return ClientFactory::create($type, '', $httpClient, $options);
    }

    /**
     * 显示标题和配置信息
     */
    private function displayHeader(OutputInterface $output, array $config): void
    {
        $clientNames = [
            'openai' => 'OpenAI 兼容客户端',
            'minimax' => 'MiniMax TokenPlan 客户端',
            'minimax-anthropic' => 'MiniMax Anthropic 兼容客户端',
            'deepseek' => 'DeepSeek 客户端',
            'deepseek-anthropic' => 'DeepSeek Anthropic 兼容客户端',
            'ollama' => 'Ollama 原生客户端',
            'anthropic' => 'Anthropic Claude 客户端',
            'claude' => 'Anthropic Claude 客户端'
        ];

        $clientName = $clientNames[$config['clientType']] ?? '未知客户端';

        $output->writeln('');
        $output->writeln('<fg=blue;options=bold>========================================</>');
        $output->writeln('<fg=blue;options=bold>      统一聊天测试工具</>');
        $output->writeln('<fg=blue;options=bold>========================================</>');
        $output->writeln('');
        $output->writeln("<info>客户端类型:</info> {$clientName}");
        $output->writeln("<info>服务地址:</info> {$config['host']}");
        $output->writeln("<info>模型名称:</info> {$config['model']}");
        if ($config['systemPrompt']) {
            $output->writeln("<info>系统提示词:</info> {$config['systemPrompt']}");
        }
        $output->writeln("<info>用户提示词:</info> {$config['prompt']}");
        $output->writeln("<info>温度参数:</info> {$config['temperature']}");
        $output->writeln("<info>最大 tokens:</info> {$config['maxTokens']}");

        if ($config['useStream']) {
            $output->writeln("<info>流式输出:</info> 启用");
        }
        if ($config['think']) {
            $output->writeln("<info>思考模式:</info> {$config['think']}");
        }
        $output->writeln('');
    }

    /**
     * 处理模型列表请求
     */
    private function handleListModels(LLMClient $client, OutputInterface $output): int
    {
        $output->writeln('');
        $output->writeln('<fg=yellow;options=bold>获取可用模型列表...</>');
        try {
            $models = $client->listModels();
            $output->writeln('');
            $output->writeln('<fg=green;options=bold>========== 可用模型 ==========</>');
            foreach ($models as $modelData) {
                $modelName = $modelData['name'] ?? $modelData['id'] ?? 'Unknown';
                $output->writeln("  - {$modelName}");
            }
            $output->writeln('<fg=green;options=bold>==============================</>');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln("<error>获取模型列表失败: {$e->getMessage()}</error>");
            return self::FAILURE;
        }
    }

    /**
     * 构建请求对象
     */
    private function buildRequest(array $config, OutputInterface $output): LLMRequest
    {
        $request = LLMRequest::create()
            ->model($config['model'])
            ->temperature($config['temperature'])
            ->maxTokens($config['maxTokens']);

        // 添加系统提示词
        if ($config['systemPrompt']) {
            $request->addSystem($config['systemPrompt']);
        }

        // 添加用户提示词
        $request->addUser($config['prompt']);

        // 启用思考模式
        if ($config['think'] !== null) {
            $request->think = $config['think'] === 'true' || $config['think'] === 'false'
                ? $config['think'] === 'true'
                : $config['think'];
        }

        $output->writeln('');
        $output->writeln('<fg=yellow>发送请求...</>');
        $output->writeln('');

        return $request;
    }

    /**
     * 执行对话
     */
    private function executeChat(LLMClient $client, LLMRequest $request, array $config, OutputInterface $output): LLMResponse
    {
        // 获取响应
        $response = $config['useStream']
            ? $this->getStreamResponse($client, $request, $config, $output)
            : $client->chat($request);

        // 显示响应（流式模式已在 getStreamResponse 中显示，这里只处理非流式）
        if (!$config['useStream']) {
            $this->displayResponse($response, $output);
        }

        return $response;
    }

    /**
     * 获取流式响应
     */
    private function getStreamResponse(LLMClient $client, LLMRequest $request, array $config, OutputInterface $output): LLMResponse
    {
        $fullResponse = '';
        $fullThinking = '';
        $hasThinking = false;
        $hasContent = false;
        $allToolCalls = [];
        $allRawData = [];
        $responseModel = ''; // 收集响应中的模型

        $client->chatStream($request, function(LLMResponse $response)
            use ($output, &$fullResponse, &$fullThinking, &$hasThinking, &$hasContent, &$allToolCalls, &$allRawData, &$responseModel) {

            // 收集原始数据
            if ($response->raw !== null) {
                $allRawData[] = $response->raw;
            }

            // 收集工具调用
            if (count($response->toolCalls) > 0) {
                $allToolCalls = array_merge($allToolCalls, $response->toolCalls);
            }

            // 收集响应中的模型（使用最后一个非空的模型）
            if ($response->model !== '') {
                $responseModel = $response->model;
            }

            // 显示思考过程
            if ($response->thinking !== '') {
                if (!$hasThinking) {
                    $hasThinking = true;
                    $output->writeln('<fg=cyan;options=bold>========== 思考过程 ==========</>');
                    $output->write('<fg=cyan>');
                }
                $output->write($response->thinking);
                $fullThinking .= $response->thinking;
            }

            // 显示响应内容
            if ($response->content !== '') {
                if ($hasThinking && !$hasContent) {
                    // 从思考内容切换到最终输出，需要先关闭思考标签
                    $output->writeln('</fg=cyan>');
                    $output->writeln('<fg=cyan;options=bold>================================</>');
                    $output->writeln('');
                    $output->writeln('<fg=green;options=bold>========== 最终输出 ==========</>');
                    $output->write('<fg=green>');
                    $hasContent = true;
                } elseif (!$hasContent) {
                    // 没有思考内容，直接显示最终输出
                    $output->writeln('<fg=green;options=bold>========== 最终输出 ==========</>');
                    $output->write('<fg=green>');
                    $hasContent = true;
                }
                $output->write($response->content);
                $fullResponse .= $response->content;
            }
        });

        // 结束标签和分隔线
        if ($hasContent) {
            // 有最终输出，关闭绿色标签
            $output->writeln('</fg=green>');
            $output->writeln('<fg=green;options=bold>================================</>');
        } elseif ($hasThinking) {
            // 只有思考内容，关闭青色标签并显示分隔线
            $output->writeln('</fg=cyan>');
            $output->writeln('<fg=cyan;options=bold>================================</>');
        } else {
            // 既没有思考也没有输出
            $output->writeln('<fg=yellow;options=bold>========== 响应为空 ==========</>');
            $output->writeln('<fg=yellow;options=bold>================================</>');
        }
        $output->writeln('');

        return LLMResponse::create()
            ->content($fullResponse)
            ->thinking($fullThinking)
            ->model($responseModel !== '' ? $responseModel : $config['model'])
            ->done(true)
            ->toolCalls($allToolCalls);
    }

    /**
     * 显示响应内容
     */
    private function displayResponse(LLMResponse $response, OutputInterface $output): void
    {
        // 显示思考过程
        if ($response->thinking !== '') {
            $output->writeln('<fg=cyan;options=bold>========== 思考过程 ==========</>');
            $output->writeln('<fg=cyan>' . $response->thinking . '</fg=cyan>');
            $output->writeln('<fg=cyan;options=bold>================================</>');
            $output->writeln('');
        }

        // 显示最终输出
        if ($response->content !== '') {
            $output->writeln('<fg=green;options=bold>========== 最终输出 ==========</>');
            $output->writeln('');
            $output->writeln('<fg=green>' . $response->content . '</fg=green>');
            $output->writeln('');
            $output->writeln('<fg=green;options=bold>================================</>');
            $output->writeln('');
        }
    }

    /**
     * 显示响应详情
     */
    private function displayResponseDetails(LLMResponse $response, array $config, OutputInterface $output): void
    {
        $output->writeln('<fg=cyan;options=bold>========== 响应详情 ==========</>');
        $output->writeln("<info>模型:</info> {$response->model}");
        $output->writeln("<info>完成状态:</info> " . ($response->done ? '是' : '否'));
        $output->writeln("<info>完成原因:</info> " . ($response->finishReason ?? 'N/A'));
        $output->writeln("<info>响应长度:</info> {$response->getContentLength()} 字符");

        if ($response->usage !== null) {
            $output->writeln('');
            $output->writeln('<fg=yellow;options=bold>---------- Token 使用情况 ----------</>');
            $output->writeln("<info>提示词 Tokens:</info> {$response->usage->promptTokens}");
            $output->writeln("<info>补全 Tokens:</info> {$response->usage->completionTokens}");
            $output->writeln("<info>总 Tokens:</info> {$response->usage->totalTokens}");

            $cost = $response->usage->getTotalCost($config['model']);
            if ($cost > 0) {
                $output->writeln("<info>估算成本:</info> $" . number_format($cost, 6));
            }
        }

        $output->writeln('<fg=cyan;options=bold>================================</>');
        $output->writeln('');
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
        $output->writeln('<fg=red;options=bold>================================</>');
        $output->writeln('');
        $output->writeln('<comment>请检查：</comment>');
        $output->writeln('  1. 服务是否正常运行');
        $output->writeln('  2. 主机地址和端口是否正确');
        $output->writeln('  3. 模型名称是否正确');
        $output->writeln('  4. 网络连接是否正常');
    }
}
