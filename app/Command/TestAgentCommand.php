<?php

namespace App\Command;

use App\Libs\Agent\Agent;
use App\Libs\LLM\LLMClient;
use App\Libs\LLM\LLMResponse;
use App\Libs\LLM\ClientFactory;
use App\Libs\Agent\ToolManager;
use App\Libs\MCP\McpManager;
use Amp\Http\Client\HttpClientBuilder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Helper\QuestionHelper;

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
            ->addOption('client', 'c', InputOption::VALUE_OPTIONAL, '客户端类型 (openai/ollama/minimax/deepseek/anthropic/minimax-anthropic/deepseek-anthropic)', 'ollama')
            ->addOption('host', 'H', InputOption::VALUE_OPTIONAL, '服务地址', '')
            ->addOption('model', 'm', InputOption::VALUE_OPTIONAL, '模型名称', '')
            ->addOption('prompt', 'p', InputOption::VALUE_OPTIONAL, '系统提示词', '你是一个专业的 AI 助手，可以帮助用户解决各种问题。')
            ->addOption('message', null, InputOption::VALUE_OPTIONAL, '用户消息')
            ->addOption('max-tokens', null, InputOption::VALUE_OPTIONAL, '最大 token 数', '32768')
            ->addOption('temperature', 't', InputOption::VALUE_OPTIONAL, '温度参数 (0-2)', '0.7')
            ->addOption('stream', 's', InputOption::VALUE_NONE, '使用流式输出')
            ->addOption('interactive', 'i', InputOption::VALUE_NONE, '启用交互式多轮对话模式')
            ->addOption('think', null, InputOption::VALUE_OPTIONAL, '启用思考模式 (true/false/high/medium/low)')
            ->addOption('with-tools', null, InputOption::VALUE_NONE, '启用工具调用')
            ->addOption('with-skills', null, InputOption::VALUE_NONE, '启用技能支持')
            ->addOption('with-mcp', null, InputOption::VALUE_NONE, '启用 MCP 工具支持')
            ->addOption('mcp-servers', null, InputOption::VALUE_OPTIONAL, '指定启用的 MCP 服务器（逗号分隔）')
            ->addOption('show-history', null, InputOption::VALUE_NONE, '显示完整消息历史')
            ->addOption('session', 'S', InputOption::VALUE_OPTIONAL, 'Session ID (恢复已有会话，留空则创建新会话)')
            ->addOption('list-sessions', null, InputOption::VALUE_NONE, '列出所有会话');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // 解析参数
        $config = $this->parseConfig($input);

        // 创建客户端
        $client = $this->createClient($config['clientType'], $config['host']);

        // 准备工具
        $tools = $config['withTools'] ? ToolManager::getAll() : [];

        // 初始化 MCP 服务器（如果启用）
        if ($config['withMcp']) {
            try {
                $enabledServers = $config['mcpServers'] !== '' ?
                    explode(',', $config['mcpServers']) : null;
                McpManager::init($enabledServers);
            } catch (\Throwable $e) {
                $output->writeln("<fg=yellow>⚠️  MCP 初始化失败: {$e->getMessage()}</>");
                if (!($config['continueOnError'] ?? true)) {
                    throw $e;
                }
            }
        }

        // 创建 Agent
        $agent = new Agent(
            systemPromptFiles: [
                BASE_DIR . '/workspace/AGENTS.md',
                BASE_DIR . '/workspace/MEMORY.md'
            ],
            tools: $tools,
            withSkills: $config['withSkills'],
            model: $config['model'],
            provider: $client,
            maxTokens: $config['maxTokens'],
            temperature: $config['temperature'],
            think: $config['think'],
            withMcp: $config['withMcp']
        );

        // 显示标题和配置信息
        $this->displayHeader($output, $config);

        // 处理 --list-sessions 参数
        if ($config['listSessions']) {
            $this->displaySessionsList($output);
            return self::SUCCESS;
        }

        // 处理会话参数
        $sessionId = $config['session'];
        $autoCreateSession = false;  // 标记是否需要自动创建会话

        if (!$sessionId) {
            // 延迟创建会话：在第一条消息时才创建
            $autoCreateSession = true;
            $agent->setAutoSave(true);
            $output->writeln("<info>会话模式: 新会话</info>");
            $output->writeln('');
        } else {
            // 检查是否使用数字编号恢复会话
            $resolvedSessionId = $this->resolveSessionId($sessionId);
            if ($resolvedSessionId === null) {
                $output->writeln("<error>✗ 无效的会话编号或 ID: {$sessionId}</error>");
                $output->writeln("<comment>提示: 使用 --list-sessions 查看所有会话</>");
                return self::FAILURE;
            }

            // 加载已有会话
            if (\App\Libs\Agent\SessionManager::exists($resolvedSessionId)) {
                $agent->loadSession($resolvedSessionId);
                $agent->setAutoSave(true);

                // 显示会话信息
                if ($sessionId !== $resolvedSessionId) {
                    $output->writeln("<info>会话模式: 恢复会话 #{$sessionId} ({$resolvedSessionId})</info>");
                } else {
                    $output->writeln("<info>会话模式: 恢复会话 {$resolvedSessionId}</info>");
                }
                $output->writeln('');
            } else {
                $output->writeln("<error>✗ 会话不存在: {$resolvedSessionId}</error>");
                return self::FAILURE;
            }
        }

        try {
            // 如果提供了消息，执行对话
            if ($config['userMessage']) {
                // 如果需要自动创建会话，先创建
                if ($autoCreateSession) {
                    $metadata = [
                        'model' => $config['model'],
                        'temperature' => $config['temperature'],
                        'max_tokens' => $config['maxTokens'],
                        'think' => $config['think'],
                        'with_tools' => $config['withTools'],
                        'with_skills' => $config['withSkills'],
                        'with_mcp' => $config['withMcp'],
                        'client_type' => $config['clientType'],
                    ];
                    $sessionId = $agent->createSession($metadata);
                    $output->writeln("<info>✓ 创建会话: {$sessionId}</info>");
                    $output->writeln('');
                }

                // 执行对话
                if ($config['useStream']) {
                    $response = $this->executeStreamChat($agent, $config, $output);
                    // 显示响应详情（包含累计 Token）
                    $this->displayResponseDetails($response, $config, $output, $agent);
                } else {
                    $response = $this->executeNormalChat($agent, $config, $output);
                    // 显示响应详情（包含累计 Token）
                    $this->displayResponseDetails($response, $config, $output, $agent);
                }

                $output->writeln('');
            }

            // 判断是否进入交互模式：指定了 --interactive 或没有提供消息
            if ($config['interactive'] || !$config['userMessage']) {
                $output->writeln('<fg=yellow;options=bold>进入交互模式 (Ctrl+C 退出)</>');
                $output->writeln('');
                $this->runInteractiveLoop($agent, $config, $input, $output);
            }

            return self::SUCCESS;

        } catch (\Throwable $e) {
            $this->displayError($e, $output);
            return self::FAILURE;
        } finally {
            // 确保 MCP 连接被关闭
            if ($config['withMcp']) {
                try {
                    McpManager::closeAll();
                } catch (\Throwable $e) {
                    // 忽略关闭时的错误
                }
            }
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
            'systemPrompt' => $input->getOption('prompt'),
            'userMessage' => $input->getOption('message'),
            'maxTokens' => (int) $input->getOption('max-tokens'),
            'temperature' => (float) $input->getOption('temperature'),
            'useStream' => $input->getOption('stream'),
            'interactive' => $input->getOption('interactive'),
            'think' => $input->getOption('think'),
            'withTools' => $input->getOption('with-tools'),
            'withSkills' => $input->getOption('with-skills'),
            'withMcp' => $input->getOption('with-mcp'),
            'mcpServers' => $input->getOption('mcp-servers') ?? '',
            'showHistory' => $input->getOption('show-history'),
            'session' => $input->getOption('session'),
            'listSessions' => $input->getOption('list-sessions'),
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
        $output->writeln('<fg=blue;options=bold>      Agent 测试工具</>');
        $output->writeln('<fg=blue;options=bold>========================================</>');
        $output->writeln('');
        $output->writeln("<info>客户端类型:</info> {$clientName}");
        $output->writeln("<info>服务地址:</info> {$config['host']}");
        $output->writeln("<info>模型名称:</info> {$config['model']}");
        $output->writeln("<info>系统提示词文件:</info> AGENTS.md, MEMORY.md");
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
        if ($config['withMcp']) {
            $mcpServersList = $config['mcpServers'] !== '' ? $config['mcpServers'] : '全部';
            $output->writeln("<info>MCP 工具:</info> 启用 (服务器: {$mcpServersList})");
            if ($config['withMcp']) {
                try {
                    $clientCount = McpManager::getClientCount();
                    $toolCount = McpManager::getToolCount();
                    $output->writeln("<info>MCP 客户端:</info> {$clientCount}");
                    $output->writeln("<info>MCP 工具数:</info> {$toolCount}");
                } catch (\Throwable $e) {
                    $output->writeln("<info>MCP 状态:</info> 初始化失败");
                }
            }
        }

        $output->writeln('');
    }

    /**
     * 执行普通对话
     */
    private function executeNormalChat(Agent $agent, array $config, OutputInterface $output, ?string $userMessage = null): LLMResponse
    {
        $message = $userMessage ?? $config['userMessage'];

        $output->writeln('<fg=yellow>开始对话...</>');
        $output->writeln('');

        $shownToolCalls = []; // 跟踪已显示的工具调用

        // 使用迭代回调来显示每一步的执行过程
        $response = $agent->chat($message, function($iteration, $response, $toolResults) use ($output, &$shownToolCalls) {
            // 显示轮次标题
            $output->writeln("<fg=cyan;options=bold>--- 第 {$iteration} 轮对话 ---</>");

            // 1. 先显示思考内容
            if ($response->thinking !== '') {
                $output->writeln('<fg=cyan;options=bold>========== Thinking ==========');
                $output->writeln('<fg=cyan>' . $response->thinking . '</fg=cyan>');
                $output->writeln('');
                $output->writeln('');
            }

            // 2. 再显示响应内容
            if ($response->content !== '') {
                $output->writeln('<fg=green;options=bold>========== 内容输出 ==========');
                $output->writeln('');
                $output->writeln('<fg=green>' . $response->content . '</fg=green>');
                $output->writeln('');
            }

            // 3. 最后显示工具调用
            if ($response->hasToolCalls()) {
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

            // 4. 显示工具调用消息（如果有工具执行结果）
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
        });

        return $response;
    }

    /**
     * 执行流式对话
     */
    private function executeStreamChat(Agent $agent, array $config, OutputInterface $output, ?string $userMessage = null): LLMResponse
    {
        $message = $userMessage ?? $config['userMessage'];

        $output->writeln('<fg=yellow>开始流式对话...</>');
        $output->writeln('');

        $hasThinking = false;
        $hasContent = false;
        $processedToolCalls = []; // 跟踪已处理的工具调用
        $currentIteration = 0; // 跟踪当前迭代次数

        // 调用 Agent 的流式对话
        $finalResponse = $agent->chatStream($message, function(LLMResponse $response, array $toolMessages = [])
            use ($output, &$hasThinking, &$hasContent, &$processedToolCalls, &$currentIteration) {

            // 检测是否是新一轮对话（通过检查是否有工具消息来判断）
            // 如果有工具消息，说明上一轮已经结束，需要重置状态
            if (count($toolMessages) > 0) {
                $currentIteration++;
                $hasThinking = false;
                $hasContent = false;
            }

            // 1. 先显示思考过程
            if ($response->thinking !== '') {
                // 每次开始新的思考过程时都显示标题
                if (!$hasThinking) {
                    $hasThinking = true;
                    $output->writeln('<fg=cyan;options=bold>========== Thinking ==========');
                }
                // 使用 writeln 避免样式标签嵌套问题
                $output->write('<fg=cyan>' . $response->thinking . '</fg=cyan>');
            }

            // 2. 再显示响应内容
            if ($response->content !== '') {
                // 每次开始新的响应内容时都显示标题
                if (!$hasContent) {
                    $hasContent = true;
                    // 如果有思考过程，先添加一些空行分隔
                    if ($hasThinking) {
                        $output->writeln('');
                        $output->writeln('');
                    }
                    $output->writeln('<fg=green;options=bold>========== 内容输出 ==========');
                }
                // 使用 writeln 避免样式标签嵌套问题
                $output->write('<fg=green>' . $response->content . '</fg=green>');
            }

            // 3. 然后处理工具调用
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

            // 4. 最后显示工具消息（如果有的话）
            if (count($toolMessages) > 0) {
                $output->writeln('<fg=gray;options=bold>[发送工具调用消息]</fg=gray;options=bold>');
                foreach ($toolMessages as $toolMessage) {
                    $output->writeln('<fg=gray>' . print_r($toolMessage, true) . '</fg=gray>');
                }
                $output->writeln('');
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
    private function displayResponseDetails(LLMResponse $response, array $config, OutputInterface $output, ?Agent $agent = null): void
    {
        $output->writeln('<fg=cyan;options=bold>========== 响应详情 ==========</>');
        $output->writeln("<info>模型:</info> {$response->model}");

        // 显示上下文 Token 使用情况
        if ($agent !== null) {
            $totalTokens = $agent->getTotalTokens();
            $contextLength = $this->estimateContextLimit($response->model);
            $percentage = $totalTokens > 0 && $contextLength > 0 ? round(($totalTokens / $contextLength) * 100, 1) : 0;
            $output->writeln("<info>上下文:</info> {$totalTokens}/{$contextLength} ({$percentage}%)");
        }

        $output->writeln('');
    }

    /**
     * 估算模型的上下文长度
     * 使用启发式方法根据模型名称判断
     */
    private function estimateContextLimit(string $model): int
    {
        // Anthropic Claude 系列 - 200k
        if (preg_match('/claude-3/i', $model)) {
            return 200000;
        }

        // MiniMax 系列 - 200k
        if (preg_match('/MiniMax-M2\.7/i', $model) || preg_match('/abab6/i', $model)) {
            return 200000;
        }

        // DeepSeek 系列 - 100k+
        if (preg_match('/deepseek/i', $model)) {
            return 100000;
        }

        // Qwen 3.5 小参数系列 - 64k
        if (preg_match('/^qwen3\.5:(0\.8|4|9)b/i', $model)) {
            return 64000;
        }

        // 默认值（保守估计）
        return 32768;
    }

    /**
     * 交互式多轮对话循环
     */
    private function runInteractiveLoop(Agent $agent, array $config, InputInterface $input, OutputInterface $output): void
    {
        $questionHelper = new QuestionHelper();
        $autoCreateSession = !$config['session'];  // 是否需要自动创建会话
        $sessionCreated = false;  // 会话是否已创建

        $output->writeln('<fg=white;options=bold>========================================</>');
        $output->writeln('<fg=white;options=bold>输入你的消息，按 Enter 发送</>');
        $output->writeln('<fg=white;options=bold>输入 "clear" 清空对话上下文</>');
        $output->writeln('<fg=white;options=bold>输入 "save" 保存当前会话</>');
        $output->writeln('<fg=white;options=bold>输入 "info" 显示会话信息</>');
        $output->writeln('<fg=white;options=bold>输入 "quit" 或 "exit" 退出</>');
        $output->writeln('<fg=white;options=bold>按 Ctrl+C 强制退出</>');
        $output->writeln('<fg=white;options=bold>========================================</>');
        $output->writeln('');

        while (true) {
            // 创建问题
            $question = new Question('<fg=yellow;options=bold>You: </>');

            // 不允许空输入（但 QuestionHelper 会一直询问，所以我们需要自定义验证器）
            $question->setValidator(function ($answer) {
                if ($answer === 'quit' || $answer === 'exit' || $answer === 'q') {
                    return '__QUIT__';  // 特殊标记表示退出
                }

                if ($answer === 'clear') {
                    return '__CLEAR__';  // 特殊标记表示清空上下文
                }

                if ($answer === 'save') {
                    return '__SAVE__';  // 特殊标记表示保存会话
                }

                if ($answer === 'info') {
                    return '__INFO__';  // 特殊标记表示显示会话信息
                }

                if ($answer === null || $answer === '') {
                    return '';  // 返回空字符串，让循环继续
                }

                return $answer;
            });

            $question->setMaxAttempts(null);  // 无限次尝试

            try {
                // 询问用户
                $userMessage = $questionHelper->ask($input, $output, $question);

                // 检查是否退出
                if ($userMessage === '__QUIT__') {
                    $output->writeln('<fg=green>👋 再见！</>');
                    break;
                }

                // 检查是否清空上下文
                if ($userMessage === '__CLEAR__') {
                    $oldSessionId = $agent->getSessionId();
                    $agent->clearMessages();

                    // 标记需要创建新会话（在下次发送消息时创建）
                    $autoCreateSession = true;
                    $sessionCreated = false;

                    if ($oldSessionId) {
                        $output->writeln("<fg=cyan>🗑️  对话上下文已清空</>");
                        $output->writeln("<fg=cyan>📁 旧会话保留: {$oldSessionId}</>");
                        $output->writeln("<fg=cyan>✨ 将创建新会话</>");
                    } else {
                        $output->writeln("<fg=cyan>🗑️  对话上下文已清空</>");
                        $output->writeln("<fg=cyan>✨ 将创建新会话</>");
                    }
                    $output->writeln('');
                    continue;
                }

                // 检查是否保存会话
                if ($userMessage === '__SAVE__') {
                    $agent->saveSession();
                    $output->writeln('<fg=cyan>💾 会话已保存</>');
                    $output->writeln('');
                    continue;
                }

                // 检查是否显示会话信息
                if ($userMessage === '__INFO__') {
                    $this->displaySessionInfo($agent, $output);
                    continue;
                }

                // 跳过空输入
                if ($userMessage === '') {
                    continue;
                }

                // 如果需要自动创建会话，在第一条消息时创建
                if ($autoCreateSession && !$sessionCreated) {
                    $metadata = [
                        'model' => $config['model'],
                        'temperature' => $config['temperature'],
                        'max_tokens' => $config['maxTokens'],
                        'think' => $config['think'],
                        'with_tools' => $config['withTools'],
                        'with_skills' => $config['withSkills'],
                        'with_mcp' => $config['withMcp'],
                        'client_type' => $config['clientType'],
                    ];
                    $sessionId = $agent->createSession($metadata);
                    $output->writeln("<info>✓ 创建会话: {$sessionId}</info>");
                    $output->writeln('');
                    $sessionCreated = true;
                }

                $output->writeln('');

                // 执行对话
                if ($config['useStream']) {
                    $response = $this->executeStreamChat($agent, $config, $output, $userMessage);
                    // 显示响应详情（包含累计 Token）
                    $this->displayResponseDetails($response, $config, $output, $agent);
                } else {
                    $response = $this->executeNormalChat($agent, $config, $output, $userMessage);
                    // 显示响应详情（包含累计 Token）
                    $this->displayResponseDetails($response, $config, $output, $agent);
                }

                $output->writeln('');

            } catch (\Throwable $e) {
                $this->displayError($e, $output);
                $output->writeln('<fg=yellow>⚠️  对话出错，请重试</>');
                $output->writeln('');
            }
        }
    }

    /**
     * 显示 token 使用情况
     */
    private function displayTokenUsage(Agent $agent, array $config, OutputInterface $output): void
    {
        $totalTokens = $agent->getTotalTokens();
        $maxTokens = $config['maxTokens'];
        $percentage = $totalTokens > 0 && $maxTokens > 0 ? round(($totalTokens / $maxTokens) * 100, 1) : 0;
        $output->writeln("<fg=cyan;options=bold>📊 Token 使用: {$totalTokens}/{$maxTokens} ({$percentage}%)</>");
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

    /**
     * 显示会话信息
     */
    private function displaySessionInfo(Agent $agent, OutputInterface $output): void
    {
        $sessionId = $agent->getSessionId();

        if ($sessionId === null) {
            $output->writeln('<fg=yellow>⚠️  当前没有活动的会话</>');
            $output->writeln('');
            return;
        }

        $session = \App\Libs\Agent\SessionManager::load($sessionId);

        if ($session === null) {
            $output->writeln('<fg=yellow>⚠️  无法加载会话信息</>');
            $output->writeln('');
            return;
        }

        $metadata = $session->getMetadata();
        $messages = $session->getMessages();

        $output->writeln('');
        $output->writeln('<fg=white;options=bold>========== 会话信息 ==========</>');
        $output->writeln("<fg=cyan>会话 ID:</> {$sessionId}");
        $output->writeln("<fg=cyan>创建时间:</> " . ($metadata['created_at'] ?? 'N/A'));
        $output->writeln("<fg=cyan>更新时间:</> " . ($metadata['updated_at'] ?? 'N/A'));
        $output->writeln("<fg=cyan>模型:</> " . ($metadata['model'] ?? 'N/A'));
        $output->writeln("<fg=cyan>温度:</> " . ($metadata['temperature'] ?? 'N/A'));
        $output->writeln("<fg=cyan>最大 Token:</> " . ($metadata['max_tokens'] ?? 'N/A'));
        $output->writeln("<fg=cyan>思考模式:</> " . ($metadata['think'] ?? 'N/A'));
        $output->writeln("<fg=cyan>工具调用:</> " . ($metadata['with_tools'] ? '启用' : '禁用'));
        $output->writeln("<fg=cyan>技能支持:</> " . ($metadata['with_skills'] ? '启用' : '禁用'));
        $output->writeln("<fg=cyan>MCP 支持:</> " . ($metadata['with_mcp'] ? '启用' : '禁用'));
        $output->writeln("<fg=cyan>消息数量:</> " . count($messages));
        $output->writeln('');
    }

    /**
     * 显示所有会话列表
     */
    private function displaySessionsList(OutputInterface $output): void
    {
        $sessions = \App\Libs\Agent\SessionManager::listAll();

        $output->writeln('');
        $output->writeln('<fg=white;options=bold>========== 会话列表 ==========</>');
        $output->writeln('');

        if (count($sessions) === 0) {
            $output->writeln('<fg=yellow>⚠️  暂无会话</>');
            $output->writeln('');
            return;
        }

        $output->writeln("<fg=cyan>找到 " . count($sessions) . " 个会话:</>");
        $output->writeln('');

        foreach ($sessions as $index => $session) {
            $num = $index + 1;
            $title = $session['title'] !== '' ? $session['title'] : '<fg=black;bg=white>未命名</>';

            $output->writeln("<fg=white;options=bold>[{$num}]</> <fg=green;options=bold>{$title}</>");
            $output->writeln("    <fg=cyan>会话 ID:</> <fg=yellow>{$session['session_id']}</>");

            // 格式化时间显示
            $createdAt = date('Y-m-d H:i:s', strtotime($session['created_at']));
            $updatedAt = date('Y-m-d H:i:s', strtotime($session['updated_at']));

            $output->writeln("    <fg=cyan>模型:</> {$session['model']}");
            $output->writeln("    <fg=cyan>创建时间:</> {$createdAt}");
            $output->writeln("    <fg=cyan>更新时间:</> {$updatedAt}");
            $output->writeln("    <fg=cyan>消息数量:</> {$session['message_count']}");
            $output->writeln('');
        }

        $output->writeln('<fg=green>提示:</> 使用 <fg=yellow>--session <编号|会话ID></> 来恢复会话（例如：--session 1 或 --session xxx-xxx-xxx）');
        $output->writeln('');
    }

    /**
     * 解析会话 ID（支持数字编号）
     *
     * @param string $input 用户输入的会话编号或 ID
     * @return string|null 解析后的会话 ID，无效时返回 null
     */
    private function resolveSessionId(string $input): ?string
    {
        // 如果是纯数字，当作会话编号处理
        if (ctype_digit($input)) {
            $index = (int)$input - 1;  // 转换为数组索引（从 0 开始）
            $sessions = \App\Libs\Agent\SessionManager::listAll();

            if ($index >= 0 && $index < count($sessions)) {
                return $sessions[$index]['session_id'];
            }
            return null;
        }

        // 否则直接当作会话 ID 返回
        return $input;
    }
}
