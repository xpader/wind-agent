<?php

namespace App\Command;

use App\Libs\LLM\LLMRequest;
use App\Libs\LLM\Clients\OllamaClient;
use App\Libs\Agent\Skill;
use App\Libs\Agent\SkillManager;
use App\Libs\Agent\ToolManager;
use Amp\Http\Client\HttpClientBuilder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 测试 Skill 功能
 */
class TestSkillsCommand extends Command
{
    protected function configure()
    {
        $this->setName('test:skills')
            ->setDescription('测试 LLM Skill 功能')
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
                '请介绍一下你有哪些技能'
            )
            ->addOption(
                'list-skills',
                'l',
                InputOption::VALUE_NONE,
                '列出所有已加载的 Skills'
            )
            ->addOption(
                'read-skill',
                'r',
                InputOption::VALUE_OPTIONAL,
                '读取指定 Skill 的完整 SKILL.md'
            )
            ->addOption(
                'single-step',
                null,
                InputOption::VALUE_NONE,
                '单步模式，每次工具调用后暂停'
            )
            ->addOption(
                'show-tools',
                't',
                InputOption::VALUE_NONE,
                '显示已加载的工具定义'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $host = $input->getOption('host');
        $model = $input->getOption('model');
        $prompt = $input->getOption('prompt');
        $listSkills = $input->getOption('list-skills');
        $readSkill = $input->getOption('read-skill');
        $singleStep = $input->getOption('single-step');

        $output->writeln('');
        $output->writeln('<fg=blue;options=bold>========================================</>');
        $output->writeln('<fg=blue;options=bold>      LLM Skill 功能测试</>');
        $output->writeln('<fg=blue;options=bold>========================================</>');
        $output->writeln('');

        // 创建请求对象 (Skills 会自动加载)
        $request = LLMRequest::create()
            ->model($model)
            ->temperature(0.7)
            ->maxTokens(2000);

        // 添加所有可用工具
        foreach (ToolManager::getAll() as $tool) {
            $request->addTool($tool);
        }

        // 列出 Skills
        if ($listSkills) {
            return $this->listSkills($request, $output);
        }

        // 读取指定 Skill
        if ($readSkill) {
            return $this->readSkill($request, $readSkill, $output);
        }

        // 测试对话
        return $this->testChat($request, $prompt, $host, $singleStep, $input, $output);
    }

    /**
     * 列出所有已加载的 Skills
     */
    private function listSkills(LLMRequest $request, OutputInterface $output): int
    {
        $skillManager = new SkillManager();
        $skills = $skillManager->getAll();

        if (empty($skills)) {
            $output->writeln('<fg=yellow>没有加载任何 Skills</>');
            $output->writeln('');
            $output->writeln('<fg=gray>Skills 目录: ' . BASE_DIR . '/workspace/skills</>');
            return self::SUCCESS;
        }

        $output->writeln('<fg=gray>Skills 目录: ' . BASE_DIR . '/workspace/skills</>');
        $output->writeln('');
        $output->writeln('<fg=green;options=bold>========== 已加载的 Skills ==========</>');
        $output->writeln('');

        foreach ($skills as $skill) {
            $output->writeln("  <fg=cyan;options=bold>名称:</fg=cyan;options=bold> {$skill->name}");
            $output->writeln("  <info>描述:</info> {$skill->description}");
            if ($skill->license) {
                $output->writeln("  <info>许可证:</info> {$skill->license}");
            }
            if (!empty($skill->metadata)) {
                $metadataStr = [];
                foreach ($skill->metadata as $key => $value) {
                    $metadataStr[] = "{$key}: {$value}";
                }
                if (!empty($metadataStr)) {
                    $output->writeln("  <info>元数据:</info> " . implode(', ', $metadataStr));
                }
            }
            $output->writeln("  <info>目录:</info> {$skill->directory}");
            $output->writeln("  <info>内容长度:</info> " . mb_strlen($skill->content) . " 字符");
            $output->writeln('');
        }

        $output->writeln('<fg=yellow>========== 生成的提示词 ==========</>');
        $output->writeln('');
        $output->writeln($skillManager->generatePrompt());
        $output->writeln('');
        $output->writeln('<fg=green;options=bold>=======================================</>');

        return self::SUCCESS;
    }

    /**
     * 读取指定 Skill 的完整 SKILL.md
     */
    private function readSkill(LLMRequest $request, string $skillName, OutputInterface $output): int
    {
        $skillManager = new SkillManager();
        $skill = $skillManager->getByName($skillName);

        if (!$skill) {
            $skills = $skillManager->getAll();
            $available = implode(', ', array_map(fn($s) => $s->name, $skills));
            $output->writeln("<fg=red>✗ 未找到 Skill: {$skillName}</>");
            $output->writeln("<fg=yellow>可用的 Skills: {$available}</>");
            return self::FAILURE;
        }

        $output->writeln('<fg=green;options=bold>========== Skill 完整内容 ==========</>');
        $output->writeln('');
        $output->writeln("<fg=cyan;options=bold>名称:</fg=cyan;options=bold> {$skill->name}");
        $output->writeln("<info>描述:</info> {$skill->description}");
        $output->writeln("<info>目录:</info> {$skill->directory}");
        $output->writeln('');
        $output->writeln('<fg=yellow>========== SKILL.md 内容 ==========</>');
        $output->writeln('');
        $output->writeln($skill->getFullContent());
        $output->writeln('');
        $output->writeln('<fg=green;options=bold>=====================================</>');

        return self::SUCCESS;
    }

    /**
     * 测试对话
     */
    private function testChat(LLMRequest $request, string $prompt, string $host, bool $singleStep, InputInterface $input, OutputInterface $output): int
    {
        $showTools = $input->getOption('show-tools');
        $skillManager = new SkillManager();
        $skillsCount = $skillManager->count();

        $output->writeln("<info>服务地址:</info> {$host}");
        $output->writeln("<info>模型名称:</info> {$request->model}");
        $output->writeln("<info>提示词:</info> {$prompt}");
        $output->writeln("<info>已加载 Skills:</info> {$skillsCount} 个");
        $output->writeln("<info>已加载 Tools:</info> " . count($request->tools) . " 个");
        $output->writeln('');

        // 显示已加载的工具
        if ($showTools) {
            $output->writeln('<fg=yellow>========== 已加载的工具 ==========</>');
            $output->writeln('');
            foreach ($request->tools as $tool) {
                $output->writeln("  <fg=cyan>名称:</fg=cyan> " . $tool->getName());
                $output->writeln("  <info>描述:</info> " . $tool->getDescription());
                $output->writeln("  <info>参数:</info> " . json_encode($tool->getParameters(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $output->writeln('');
            }
            $output->writeln('<fg=green;options=bold>=====================================</>');
            $output->writeln('');
        }

        // 显示生成的系统提示词
        $output->writeln('<fg=yellow>========== 系统提示词 ==========</>');
        $output->writeln('');
        $output->writeln($request->getSkillsPrompt());
        $output->writeln('');
        $output->writeln('<fg=green;options=bold>====================================</>');
        $output->writeln('');

        try {
            // 创建客户端
            $httpClient = HttpClientBuilder::buildDefault();
            $client = new OllamaClient(
                httpClient: $httpClient,
                baseUrl: "http://{$host}",
                timeout: 120
            );

            // 开始多轮对话
            $request->addUser($prompt);

            $maxIterations = 10;
            $iteration = 0;

            while ($iteration < $maxIterations) {
                $iteration++;
                $output->writeln("<fg=cyan;options=bold>--- 第 {$iteration} 轮对话 ---</>");

                // 发送请求
                $output->writeln('<fg=yellow>发送请求...</>');
                $response = $client->chat($request);

                $output->writeln('');
                $output->writeln('<fg=green;options=bold>========== 响应内容 ==========</>');
                $output->writeln($response->content);
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
                        $resultPreview = mb_substr($result['result'], 0, 500);
                        if (mb_strlen($result['result']) > 500) {
                            $resultPreview .= '... (省略 ' . (mb_strlen($result['result']) - 500) . ' 字符)';
                        }
                        $output->writeln("  <fg=green>结果:</fg=green> {$resultPreview}");
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

            if ($output->isVerbose()) {
                $output->writeln('');
                $output->writeln('<fg=gray>堆栈跟踪:</fg>');
                $output->writeln($e->getTraceAsString());
            }

            return self::FAILURE;
        }
    }
}
