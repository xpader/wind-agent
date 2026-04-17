<?php

namespace App\Command;

use App\Libs\LLM\LLMClient;
use App\Libs\LLM\LLMResponse;
use App\Libs\LLM\Clients\OpenAiClient;
use App\Libs\LLM\Clients\OllamaClient;
use App\Libs\LLM\LLMRequest;
use App\Libs\Agent\ToolManager;
use App\Libs\Agent\SkillManager;
use Amp\Http\Client\HttpClientBuilder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 统一的聊天测试命令
 * 整合 LLM、工具、技能的所有测试功能
 */
class TestChatCommand extends Command
{
    protected function configure()
    {
        $this->setName('test:chat')
            ->setDescription('统一的聊天测试命令（支持 LLM、工具、技能）')
            ->addOption('client', 'c', InputOption::VALUE_OPTIONAL, '客户端类型 (openai/ollama)', 'ollama')
            ->addOption('host', 'H', InputOption::VALUE_OPTIONAL, '服务地址', '172.19.208.203:11434')
            ->addOption('model', 'm', InputOption::VALUE_OPTIONAL, '模型名称', 'qwen3.5:9b-q8_0')
            ->addOption('prompt', 'p', InputOption::VALUE_OPTIONAL, '提示词', '你好，请简单介绍一下你自己')
            ->addOption('temperature', 't', InputOption::VALUE_OPTIONAL, '温度参数 (0-2)', '0.7')
            ->addOption('max-tokens', null, InputOption::VALUE_OPTIONAL, '最大 token 数', '2000')
            ->addOption('stream', 's', InputOption::VALUE_NONE, '使用流式输出')
            ->addOption('think', null, InputOption::VALUE_OPTIONAL, '启用思考模式 (true/false/high/medium/low)')
            ->addOption('with-tools', null, InputOption::VALUE_NONE, '启用工具调用')
            ->addOption('with-skills', null, InputOption::VALUE_NONE, '启用技能支持')
            ->addOption('list-models', 'l', InputOption::VALUE_NONE, '列出可用模型')
            ->addOption('show-prompt', null, InputOption::VALUE_NONE, '显示系统提示词和请求内容')
            ->addOption('single-step', null, InputOption::VALUE_NONE, '单步模式（每次工具调用后暂停）');
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

            // 显示系统提示词（如果请求）
            if ($config['showPrompt']) {
                $this->displaySystemPrompt($request, $output);
            }

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
            'prompt' => $input->getOption('prompt'),
            'temperature' => (float) $input->getOption('temperature'),
            'maxTokens' => (int) $input->getOption('max-tokens'),
            'useStream' => $input->getOption('stream'),
            'think' => $input->getOption('think'),
            'withTools' => $input->getOption('with-tools') || $input->getOption('with-skills'),
            'withSkills' => $input->getOption('with-skills'),
            'listModels' => $input->getOption('list-models'),
            'showPrompt' => $input->getOption('show-prompt'),
            'singleStep' => $input->getOption('single-step'),
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
        $clientName = $config['clientType'] === 'openai' ? 'OpenAI 兼容客户端' : 'Ollama 原生客户端';

        $output->writeln('');
        $output->writeln('<fg=blue;options=bold>========================================</>');
        $output->writeln('<fg=blue;options=bold>      统一聊天测试工具</>');
        $output->writeln('<fg=blue;options=bold>========================================</>');
        $output->writeln('');
        $output->writeln("<info>客户端类型:</info> {$clientName}");
        $output->writeln("<info>服务地址:</info> {$config['host']}");
        $output->writeln("<info>模型名称:</info> {$config['model']}");
        $output->writeln("<info>提示词:</info> {$config['prompt']}");
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
        if ($config['singleStep']) {
            $output->writeln("<info>单步模式:</info> 启用");
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
            ->addUser($config['prompt'])
            ->model($config['model'])
            ->temperature($config['temperature'])
            ->maxTokens($config['maxTokens']);

        // 启用思考模式
        if ($config['think'] !== null) {
            $request->think = $config['think'] === 'true' || $config['think'] === 'false'
                ? $config['think'] === 'true'
                : $config['think'];
        }

        // 添加工具
        if ($config['withTools']) {
            foreach (ToolManager::getAll() as $tool) {
                $request->addTool($tool);
            }
            $output->writeln("<info>已加载工具:</info> " . count($request->tools) . " 个");
        }

        // 添加技能
        if ($config['withSkills']) {
            $skillManager = new SkillManager();
            $skillsPrompt = $skillManager->generatePrompt();
            if ($skillsPrompt !== '') {
                $request->messages = array_merge([
                    ['role' => 'system', 'content' => $skillsPrompt]
                ], $request->messages);
                $output->writeln("<info>已加载技能:</info> {$skillManager->count()} 个");
            }
        }

        $output->writeln('');
        $output->writeln('<fg=yellow>发送请求...</>');
        $output->writeln('');

        return $request;
    }

    /**
     * 执行对话（支持多轮）
     */
    private function executeChat(LLMClient $client, LLMRequest $request, array $config, OutputInterface $output): LLMResponse
    {
        $maxIterations = $config['withTools'] ? 10 : 1;
        $response = null;

        for ($iteration = 0; $iteration < $maxIterations; $iteration++) {
            if ($config['withTools']) {
                $output->writeln("<fg=cyan;options=bold>--- 第 " . ($iteration + 1) . " 轮对话 ---</>");
            }

            // 获取响应
            $response = $config['useStream']
                ? $this->getStreamResponse($client, $request, $config, $output)
                : $client->chat($request);

            // 显示响应（流式模式已在 getStreamResponse 中显示，这里只处理非流式）
            if (!$config['useStream']) {
                $this->displayResponse($response, $output);
            }

            // 如果没有工具调用，结束对话
            if (!$response->hasToolCalls()) {
                break;
            }

            // 执行工具调用
            $this->executeToolCalls($response, $request, $output);

            // 单步模式
            if ($config['singleStep']) {
                $output->writeln('<fg=yellow>按回车继续...</>');
                fgets(STDIN);
            }
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

        $client->chatStream($request, function(LLMResponse $response)
            use ($output, &$fullResponse, &$fullThinking, &$hasThinking, &$hasContent, &$allToolCalls, &$allRawData) {

            // 收集原始数据
            if ($response->raw !== null) {
                $allRawData[] = $response->raw;
            }

            // 收集工具调用
            if (count($response->toolCalls) > 0) {
                $allToolCalls = array_merge($allToolCalls, $response->toolCalls);
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
            ->model($config['model'])
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

        // 显示工具调用信息
        if (count($response->toolCalls) > 0) {
            $output->writeln('<fg=magenta;options=bold>========== 工具调用 ==========</>');
            $output->writeln("<fg=magenta>检测到 " . count($response->toolCalls) . " 个工具调用</>");
            $output->writeln('<fg=magenta;options=bold>================================</>');
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
     * 执行工具调用
     */
    private function executeToolCalls(LLMResponse $response, LLMRequest $request, OutputInterface $output): void
    {
        $output->writeln('<fg=yellow>========== 执行工具调用 ==========');

        // 添加助手响应到请求中
        $request->addAssistant($response->content);

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
            $output->writeln("<fg=magenta>工具 {$toolNum}: {$toolName}</fg=magenta>");
            $output->writeln("<fg=magenta>参数:</fg=magenta> " . json_encode($toolArgs, JSON_UNESCAPED_UNICODE));

            if (isset($result['error'])) {
                $output->writeln("  <fg=red>错误:</fg=red> {$result['error']}");
                $request->addToolMessage($result['tool_call_id'], "错误：{$result['error']}");
            } else {
                $output->writeln("  <fg=green>结果:</fg=green>");
                $output->writeln("  <fg=green>" . $result['result'] . '</fg=green>');
                $request->addToolMessage($result['tool_call_id'], $result['result']);
            }
            $output->writeln('');
        }

        $output->writeln('<fg=yellow>========================================</>');
        $output->writeln('');
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

        $output->writeln('<fg=cyan;options=bold>================================</>');
        $output->writeln('');
    }

    /**
     * 显示系统提示词和请求内容
     */
    private function displaySystemPrompt(LLMRequest $request, OutputInterface $output): void
    {
        $output->writeln('');
        $output->writeln('<fg=blue;options=bold>========== 系统提示词和请求内容 ==========</>');
        $output->writeln('');

        $messages = $request->getMessages();

        if (count($messages) === 0) {
            $output->writeln('<fg=gray>(无消息)</fg=gray>');
            $output->writeln('');
            return;
        }

        foreach ($messages as $index => $message) {
            $role = $message['role'] ?? 'unknown';
            $content = $message['content'] ?? '';

            $roleLabel = match($role) {
                'system' => '系统',
                'user' => '用户',
                'assistant' => '助手',
                'tool' => '工具',
                default => $role
            };

            $roleColor = match($role) {
                'system' => 'cyan',
                'user' => 'green',
                'assistant' => 'blue',
                'tool' => 'magenta',
                default => 'gray'
            };

            $output->writeln("<fg={$roleColor};options=bold>========== 第 " . ($index + 1) . " 条消息 ({$roleLabel}) ==========</>");

            if ($role === 'system') {
                // 系统消息可能很长，分段显示
                $lines = explode("\n", $content);
                foreach ($lines as $line) {
                    $output->writeln("<fg={$roleColor}>{$line}</fg={$roleColor}>");
                }
            } else {
                $output->writeln("<fg={$roleColor}>{$content}</fg={$roleColor}>");
            }
            $output->writeln('');
        }

        // 显示工具定义
        if (count($request->tools) > 0) {
            $output->writeln('<fg=magenta;options=bold>========== 可用工具 ==========</>');
            foreach ($request->tools as $tool) {
                $toolArray = $tool->toArray();
                $toolName = $toolArray['function']['name'] ?? 'unknown';
                $toolDesc = $toolArray['function']['description'] ?? '';
                $toolParams = $toolArray['function']['parameters'] ?? [];

                $output->writeln("<fg=magenta>工具名称:</fg=magenta> {$toolName}");
                $output->writeln("<fg=magenta>工具描述:</fg=magenta> {$toolDesc}");
                $output->writeln("<fg=magenta>参数定义:</fg=magenta> " . json_encode($toolParams, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                $output->writeln('');
            }
        }

        // 显示其他参数
        $output->writeln('<fg=yellow;options=bold>========== 请求参数 ==========</>');
        $output->writeln("<fg=yellow>模型:</fg=yellow> {$request->model}");
        $output->writeln("<fg=yellow>温度:</fg=yellow> {$request->temperature}");
        $output->writeln("<fg=yellow>最大tokens:</fg=yellow> {$request->maxTokens}");
        if ($request->think !== null) {
            $output->writeln("<fg=yellow>思考模式:</fg=yellow> {$request->think}");
        }

        $output->writeln('<fg=blue;options=bold>==========================================</>');
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
