<?php

namespace App\Command;

use App\Libs\Agent\Agent;
use App\Libs\LLM\LLMClient;
use App\Libs\LLM\LLMResponse;
use App\Libs\LLM\Clients\OpenAiClient;
use App\Libs\LLM\Clients\OllamaClient;
use App\Libs\LLM\Clients\MiniMaxClient;
use App\Libs\Agent\ToolManager;
use Amp\Http\Client\HttpClientBuilder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Agent 测试命令
 * 用于测试 Agent 类的功能
 */
class TestAgentCommand extends Command
{
    protected function configure()
    {
        $this->setName('test:agent')
            ->setDescription('测试 Agent 类功能')
            ->addOption('client', 'c', InputOption::VALUE_OPTIONAL, '客户端类型 (openai/ollama/minimax/deepseek)', 'ollama')
            ->addOption('host', 'H', InputOption::VALUE_OPTIONAL, '服务地址', '172.19.208.203:11434')
            ->addOption('model', 'm', InputOption::VALUE_OPTIONAL, '模型名称', 'qwen3.5:9b-q8_0')
            ->addOption('prompt', 'p', InputOption::VALUE_OPTIONAL, '系统提示词', '你是一个专业的 AI 助手，可以帮助用户解决各种问题。')
            ->addOption('message', null, InputOption::VALUE_OPTIONAL, '用户消息', '你好，请介绍一下你自己')
            ->addOption('max-tokens', null, InputOption::VALUE_OPTIONAL, '最大 token 数', '32768')
            ->addOption('temperature', 't', InputOption::VALUE_OPTIONAL, '温度参数 (0-2)', '0.7')
            ->addOption('stream', 's', InputOption::VALUE_NONE, '使用流式输出')
            ->addOption('think', null, InputOption::VALUE_OPTIONAL, '启用思考模式 (true/false/high/medium/low)')
            ->addOption('with-tools', null, InputOption::VALUE_NONE, '启用工具调用')
            ->addOption('with-skills', null, InputOption::VALUE_NONE, '启用技能支持')
            ->addOption('show-history', null, InputOption::VALUE_NONE, '显示完整消息历史');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // 解析参数
        $config = $this->parseConfig($input);

        // 创建客户端
        $client = $this->createClient($config['clientType'], $config['host']);

        // 准备工具
        $tools = $config['withTools'] ? ToolManager::getAll() : [];

        // 创建 Agent
        $agent = new Agent(
            systemPrompt: $config['systemPrompt'],
            tools: $tools,
            withSkills: $config['withSkills'],
            model: $config['model'],
            provider: $client,
            maxTokens: $config['maxTokens'],
            temperature: $config['temperature'],
            think: $config['think']
        );

        // 显示标题和配置信息
        $this->displayHeader($output, $config);

        try {
            // 执行对话
            if ($config['useStream']) {
                $response = $this->executeStreamChat($agent, $config, $output);
            } else {
                $response = $this->executeNormalChat($agent, $config, $output);
            }

            // 显示响应详情
            $this->displayResponseDetails($response, $config, $output);

            $output->writeln('<fg=green;options=bold>✅ 测试完成！</>');

            // 打印完整的消息历史（如果启用了选项）
            if ($config['showHistory']) {
                $messages = $agent->getMessages();
                $output->writeln('');
                $output->writeln('<fg=blue;options=bold>========== 完整消息历史 ==========');
                $output->writeln('<fg=blue>总消息数: ' . count($messages) . '</fg=blue>');
                $output->writeln('<fg=blue>' . print_r($messages, true) . '</fg=blue>');
                $output->writeln('');
            }

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
        $clientType = $input->getOption('client');
        $host = $input->getOption('host');

        // 根据客户端类型设置正确的 host
        if ($clientType === 'minimax') {
            $host = 'https://api.minimaxi.com/v1'; // MiniMax 地址固定
        }

        if ($clientType === 'deepseek') {
            $host = 'https://api.deepseek.com'; // DeepSeek 地址固定
        }

        return [
            'clientType' => $clientType,
            'host' => $host,
            'model' => $input->getOption('model'),
            'systemPrompt' => $input->getOption('prompt'),
            'userMessage' => $input->getOption('message'),
            'maxTokens' => (int) $input->getOption('max-tokens'),
            'temperature' => (float) $input->getOption('temperature'),
            'useStream' => $input->getOption('stream'),
            'think' => $input->getOption('think'),
            'withTools' => $input->getOption('with-tools'),
            'withSkills' => $input->getOption('with-skills'),
            'showHistory' => $input->getOption('show-history'),
        ];
    }

    /**
     * 创建客户端
     */
    private function createClient(string $type, string $host): LLMClient
    {
        $httpClient = HttpClientBuilder::buildDefault();

        if ($type === 'openai') {
            return new OpenAiClient(
                httpClient: $httpClient,
                apiKey: 'ollama',
                baseUrl: "http://{$host}/v1",
                timeout: 60
            );
        }

        if ($type === 'minimax') {
            // MiniMax TokenPlan 使用专用客户端
            $apiKey = config('llm.providers.minimax.api_key');
            if (!$apiKey) {
                throw new \RuntimeException('MiniMax API Key 未设置，请在 .env 中设置 MINIMAX_API_KEY');
            }

            return new MiniMaxClient(
                httpClient: $httpClient,
                apiKey: $apiKey,
                baseUrl: $host,
                timeout: 60
            );
        }

        if ($type === 'deepseek') {
            // DeepSeek 使用 OpenAI 兼容客户端
            $apiKey = config('llm.providers.deepseek.api_key');
            if (!$apiKey) {
                throw new \RuntimeException('DeepSeek API Key 未设置，请在 .env 中设置 DEEPSEEK_API_KEY');
            }

            return new OpenAiClient(
                httpClient: $httpClient,
                apiKey: $apiKey,
                baseUrl: $host,
                timeout: 60
            );
        }

        return new OllamaClient(
            httpClient: $httpClient,
            baseUrl: "http://{$host}",
            timeout: 60
        );
    }

    /**
     * 显示标题和配置信息
     */
    private function displayHeader(OutputInterface $output, array $config): void
    {
        $clientNames = [
            'openai' => 'OpenAI 兼容客户端',
            'minimax' => 'MiniMax TokenPlan 客户端',
            'deepseek' => 'DeepSeek 客户端',
            'ollama' => 'Ollama 原生客户端'
        ];

        $clientName = $clientNames[$config['clientType']] ?? '未知客户端';

        $output->writeln('');
        $output->writeln('<fg=blue;options=bold>========================================</>');
        $output->writeln('<fg=blue;options=bold>      Agent 测试工具</>');
        $output->writeln('<fg=blue;options=bold>========================================</>');
        $output->writeln('');
        $output->writeln("<info>客户端类型:</info> {$clientName}");
        $output->writeln("<info>服务地址:</info> {$config['host']}");
        $output->writeln("<info>模型名称:</info> {$config['model']}");
        $output->writeln("<info>系统提示词:</info> {$config['systemPrompt']}");
        $output->writeln("<info>用户消息:</info> {$config['userMessage']}");
        $output->writeln("<info>温度参数:</info> {$config['temperature']}");
        $output->writeln("<info>最大 tokens:</info> {$config['maxTokens']}");

        if ($config['useStream']) {
            $output->writeln("<info>流式输出:</info> 启用");
        }
        if ($config['think']) {
            $output->writeln("<info>思考模式:</info> {$config['think']}");
        }
        if ($config['withTools']) {
            $output->writeln("<info>工具调用:</info> 启用");
        }
        if ($config['withSkills']) {
            $output->writeln("<info>技能支持:</info> 启用");
        }

        $output->writeln('');
    }

    /**
     * 执行普通对话
     */
    private function executeNormalChat(Agent $agent, array $config, OutputInterface $output): LLMResponse
    {
        $output->writeln('<fg=yellow>开始对话...</>');
        $output->writeln('');

        $shownToolCalls = []; // 跟踪已显示的工具调用

        // 使用迭代回调来显示每一步的执行过程
        $response = $agent->chat($config['userMessage'], function($iteration, $response, $toolResults) use ($output, &$shownToolCalls) {
            // 先检查是否有工具调用，在显示响应前就显示工具信息
            if ($response->hasToolCalls()) {
                $output->writeln("<fg=cyan;options=bold>--- 第 {$iteration} 轮对话 ---</>");

                foreach ($response->toolCalls as $toolCall) {
                    $toolId = md5(json_encode($toolCall));

                    if (!isset($shownToolCalls[$toolId])) {
                        $shownToolCalls[$toolId] = true;

                        $toolName = $toolCall['function']['name'];
                        $toolArgs = $toolCall['function']['arguments'];

                        if (is_string($toolArgs)) {
                            $toolArgs = json_decode($toolArgs, true) ?? [];
                        }

                        $output->writeln('<fg=magenta;options=bold>========== 工具调用 ==========');
                        $output->writeln("<fg=magenta>工具:</fg=magenta> {$toolName}");
                        $output->writeln("<fg=magenta>参数:</fg=magenta> " . json_encode($toolArgs, JSON_UNESCAPED_UNICODE));
                        $output->writeln('');
                    }
                }
            }

            // 显示工具调用消息（如果有工具执行结果）
            if (count($toolResults) > 0) {
                $output->writeln('<fg=gray;options=bold>[发送工具调用消息]</fg=gray;options=bold>');
                foreach ($toolResults as $result) {
                    $toolMessage = [
                        'role' => 'tool',
                        'tool_call_id' => $result['tool_call_id'],
                        'content' => $result['result'] ?? $result['error']
                    ];
                    $output->writeln('<fg=gray>' . print_r($toolMessage, true) . '</fg=gray>');
                }
                $output->writeln('');
            }

            // 显示响应
            $this->displayResponse($response, $output);
        });

        return $response;
    }

    /**
     * 执行流式对话
     */
    private function executeStreamChat(Agent $agent, array $config, OutputInterface $output): LLMResponse
    {
        $output->writeln('<fg=yellow>开始流式对话...</>');
        $output->writeln('');

        $hasThinking = false;
        $hasContent = false;
        $processedToolCalls = []; // 跟踪已处理的工具调用
        $currentIteration = 0; // 跟踪当前迭代次数

        // 调用 Agent 的流式对话
        $finalResponse = $agent->chatStream($config['userMessage'], function(LLMResponse $response, array $toolMessages = [])
            use ($output, &$hasThinking, &$hasContent, &$processedToolCalls, &$currentIteration) {

            // 检测是否是新一轮对话（通过检查是否有工具消息来判断）
            // 如果有工具消息，说明上一轮已经结束，需要重置状态
            if (count($toolMessages) > 0) {
                $currentIteration++;
                $hasThinking = false;
                $hasContent = false;
            }

            // 处理工具调用
            if (count($response->toolCalls) > 0) {
                foreach ($response->toolCalls as $toolCall) {
                    $toolId = md5(json_encode($toolCall));

                    // 只处理新检测到的工具调用
                    if (!isset($processedToolCalls[$toolId])) {
                        $processedToolCalls[$toolId] = true;

                        $toolName = $toolCall['function']['name'];
                        $toolArgs = $toolCall['function']['arguments'];
                        if (is_string($toolArgs)) {
                            $toolArgs = json_decode($toolArgs, true) ?? [];
                        }

                        // 显示工具调用信息
                        $output->writeln('');
                        $output->writeln('<fg=magenta;options=bold>========== 工具调用 ==========');
                        $output->writeln("<fg=magenta>工具: {$toolName}</fg=magenta>");
                        $output->writeln("<fg=magenta>参数: " . json_encode($toolArgs, JSON_UNESCAPED_UNICODE) . "</fg=magenta>");
                        $output->writeln('');
                    }
                }
            }

            // 显示工具消息（如果有的话）
            if (count($toolMessages) > 0) {
                $output->writeln('<fg=gray;options=bold>[发送工具调用消息]</fg=gray;options=bold>');
                foreach ($toolMessages as $toolMessage) {
                    $output->writeln('<fg=gray>' . print_r($toolMessage, true) . '</fg=gray>');
                }
                $output->writeln('');
            }

            // 显示思考过程
            if ($response->thinking !== '') {
                // 每次开始新的思考过程时都显示标题
                if (!$hasThinking) {
                    $hasThinking = true;
                    $output->writeln('<fg=cyan;options=bold>========== Thinking ==========');
                }
                // 使用 writeln 避免样式标签嵌套问题
                $output->write('<fg=cyan>' . $response->thinking . '</fg=cyan>');
            }

            // 显示响应内容
            if ($response->content !== '') {
                // 每次开始新的响应内容时都显示标题
                if (!$hasContent) {
                    $hasContent = true;
                    // 如果有思考过程，先添加一些空行分隔
                    if ($hasThinking) {
                        $output->writeln('');
                        $output->writeln('');
                    }
                    $output->writeln('<fg=green;options=bold>========== 响应内容 ==========');
                }
                // 使用 writeln 避免样式标签嵌套问题
                $output->write('<fg=green>' . $response->content . '</fg=green>');
            }
        });

        $output->writeln('');
        $output->writeln('');

        return $finalResponse;
    }

    /**
     * 显示响应内容
     */
    private function displayResponse(LLMResponse $response, OutputInterface $output): void
    {
        // 显示思考过程
        if ($response->thinking !== '') {
            $output->writeln('<fg=cyan;options=bold>========== Thinking ==========');
            $output->writeln('<fg=cyan>' . $response->thinking . '</fg=cyan>');
            $output->writeln('');
        }

        // 显示最终输出
        if ($response->content !== '') {
            $output->writeln('<fg=green;options=bold>========== 内容输出 ==========');
            $output->writeln('');
            $output->writeln('<fg=green>' . $response->content . '</fg=green>');
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
        $output->writeln("<info>响应长度:</info> {$response->getContentLength()} 字符");
        $output->writeln("<info>工具调用数:</info> " . count($response->toolCalls));

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
        $output->writeln('');
    }
}
