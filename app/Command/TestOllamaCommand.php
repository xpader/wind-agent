<?php

namespace App\Command;

use App\Libs\LLM\LLMResponse;
use App\Libs\LLM\Clients\OllamaClient;
use App\Libs\LLM\LLMRequest;
use Amp\Http\Client\HttpClientBuilder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 测试 Ollama 连接命令
 */
class TestOllamaCommand extends Command
{
    protected function configure()
    {
        $this->setName('test:ollama')
            ->setDescription('测试 Ollama 服务连接')
            ->addOption(
                'host',
                null,
                InputOption::VALUE_OPTIONAL,
                'Ollama 服务地址',
                '172.19.208.203:11434'
            )
            ->addOption(
                'model',
                null,
                InputOption::VALUE_OPTIONAL,
                '模型名称',
                'qwen3.5:9b-q8_0'
            )
            ->addOption(
                'prompt',
                null,
                InputOption::VALUE_OPTIONAL,
                '测试提示词',
                '你好，请用一句话介绍自己。'
            )
            ->addOption(
                'stream',
                's',
                InputOption::VALUE_NONE,
                '使用流式输出'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $host = $input->getOption('host');
        $model = $input->getOption('model');
        $prompt = $input->getOption('prompt');
        $useStream = $input->getOption('stream');

        $output->writeln('');
        $output->writeln('<fg=blue;options=bold>========================================</>');
        $output->writeln('<fg=blue;options=bold>      Ollama 连接测试</>');
        $output->writeln('<fg=blue;options=bold>========================================</>');
        $output->writeln('');
        $output->writeln("<info>主机:</info> {$host}");
        $output->writeln("<info>模型:</info> {$model}");
        $output->writeln("<info>提示:</info> {$prompt}");
        if ($useStream) {
            $output->writeln("<info>流式输出:</info> 启用");
        }
        $output->writeln('');

        // 创建 HTTP 客户端
        $httpClient = HttpClientBuilder::buildDefault();

        // 创建 Ollama 原生客户端
        $client = new OllamaClient(
            httpClient: $httpClient,
            baseUrl: "http://{$host}",
            timeout: 60
        );

        try {
            // 创建请求对象
            $request = LLMRequest::create()
                ->addUser($prompt)
                ->model($model)
                ->temperature(0.7)
                ->maxTokens(1000);

            $output->writeln('<fg=yellow>发送请求...</>');
            $output->writeln('');

            if ($useStream) {
                // 流式调用
                $output->writeln('<fg=green;options=bold>========== 流式响应 ==========</>');
                $fullResponse = '';
                $fullThinking = '';

                $client->chatStream($request, function(LLMResponse $response) use (&$fullResponse, &$fullThinking) {
                    if ($response->thinking !== '') {
                        echo $response->thinking;
                        $fullThinking .= $response->thinking;
                    }
                    if ($response->content !== '') {
                        echo $response->content;
                        $fullResponse .= $response->content;
                    }
                });

                $output->writeln('');
                $output->writeln('<fg=green;options=bold>================================</>');
                $output->writeln('');

                // 创建响应对象
                $response = LLMResponse::create()
                    ->content($fullResponse)
                    ->thinking($fullThinking)
                    ->model($model)
                    ->done(true);

            } else {
                // 普通调用
                $response = $client->chat($request);

                $output->writeln('<fg=green;options=bold>========== 响应详情 ==========</>');
                $output->writeln('<comment>响应长度:</comment> ' . $response->getContentLength() . ' 字符');
                $output->writeln('<comment>响应内容:</comment>');
                $output->writeln('');
                $output->writeln('<fg=green>' . $response->content . '</fg=green>');
                $output->writeln('');
                $output->writeln('<fg=green;options=bold>==============================</>');
                $output->writeln('');
            }

            // 显示思考内容
            if ($response->thinking !== '') {
                $output->writeln('<fg=cyan;options=bold>========== 思考过程 ==========</>');
                $output->writeln('<fg=cyan>' . $response->thinking . '</fg=cyan>');
                $output->writeln('<fg=cyan;options=bold>================================</>');
                $output->writeln('');
            }

            // 显示 Token 统计
            if ($response->usage !== null) {
                $output->writeln('<fg=cyan;options=bold>========== Token 统计 ==========</>');
                $output->writeln("<info>提示词 Tokens:</info> {$response->usage->promptTokens}");
                $output->writeln("<info>补全 Tokens:</info> {$response->usage->completionTokens}");
                $output->writeln("<info>总 Tokens:</info> {$response->usage->totalTokens}");
                $output->writeln('<fg=cyan;options=bold>==============================</>');
                $output->writeln('');
            }

            $output->writeln("<info>✅ 测试完成！Ollama 原生 API 调用成功。</info>");

            return self::SUCCESS;

        } catch (\Throwable $e) {
            $output->writeln('');
            $output->writeln("<error>请求失败！</error>");
            $output->writeln("<error>错误信息: {$e->getMessage()}</error>");
            $output->writeln('');
            $output->writeln('<comment>请检查：</comment>');
            $output->writeln('  1. Ollama 服务是否正常运行');
            $output->writeln('  2. 主机地址和端口是否正确');
            $output->writeln('  3. 模型是否已下载');
            $output->writeln('  4. 网络连接是否正常');

            return self::FAILURE;
        }
    }
}
