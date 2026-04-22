<?php

namespace App\Libs\LLM;

use Amp\Http\Client\HttpClient;
use App\Libs\LLM\Clients\OpenAiClient;
use App\Libs\LLM\Clients\OllamaClient;
use App\Libs\LLM\Clients\MiniMaxClient;

/**
 * LLM 客户端工厂类
 *
 * 根据客户端名称创建对应的 LLM 客户端实例
 */
class ClientFactory
{
    // 自定义 Provider（有专门的 Client 类）
    private const CUSTOM_PROVIDERS = [
        'minimax' => MiniMaxClient::class,
    ];

    // Ollama 兼容接口 Provider
    private const OLLAMA_PROVIDERS = [
        'ollama' => [
            'base_url' => 'http://localhost:11434',
        ],
    ];

    // OpenAI 兼容接口 Provider
    private const OPENAI_COMPATIBLES = [
        'openai' => [
            'base_url' => 'https://api.openai.com/v1',
        ],
        'deepseek' => [
            'base_url' => 'https://api.deepseek.com/v1',
        ],
    ];

    /**
     * 创建 LLM 客户端实例
     *
     * @param string $provider Provider 名称
     * @param string $model 模型名称
     * @param HttpClient $httpClient HTTP 客户端
     * @param array $options 额外选项（可选）
     *                        - api_key: API 密钥（OpenAI 兼容接口需要）
     *                        - base_url: API 基础 URL
     *                        - default_options: 默认选项（OpenAI 兼容接口）
     *                        - timeout: 超时时间（秒）
     * @return LLMClient
     * @throws \InvalidArgumentException 当 provider 不支持时抛出
     */
    public static function create(string $provider, string $model, HttpClient $httpClient, array $options = []): LLMClient
    {
        $provider = strtolower($provider);

        // 先查找自定义 Provider
        if (isset(self::CUSTOM_PROVIDERS[$provider])) {
            return self::createCustomProvider($provider, $httpClient, $options);
        }

        // 再查找 Ollama 兼容接口
        if (isset(self::OLLAMA_PROVIDERS[$provider])) {
            return self::createOllamaProvider($provider, $httpClient, $options);
        }

        // 最后查找 OpenAI 兼容接口
        if (isset(self::OPENAI_COMPATIBLES[$provider])) {
            return self::createOpenAiCompatible($provider, $httpClient, $options);
        }

        throw new \InvalidArgumentException(
            "不支持的 provider: {$provider}，" .
            "支持的 provider: " . implode(', ', self::getSupportedProviders())
        );
    }

    /**
     * 创建自定义 Provider 客户端
     *
     * @param string $provider Provider 名称
     * @param HttpClient $httpClient HTTP 客户端
     * @param array $options 选项
     * @return LLMClient
     */
    private static function createCustomProvider(string $provider, HttpClient $httpClient, array $options): LLMClient
    {
        $className = self::CUSTOM_PROVIDERS[$provider];

        // 优先使用传入的 api_key，否则从配置中读取
        $apiKey = $options['api_key'] ?? config("llm.providers.{$provider}.api_key", '');
        $baseUrl = $options['base_url'] ?? '';  // 空字符串使用类定义的默认值
        $defaultOptions = $options['default_options'] ?? [];
        $timeout = $options['timeout'] ?? 60;

        return new $className($httpClient, $apiKey, $baseUrl, '', $defaultOptions, $timeout);
    }

    /**
     * 创建 Ollama 兼容接口客户端
     *
     * @param string $provider Provider 名称
     * @param HttpClient $httpClient HTTP 客户端
     * @param array $options 选项
     * @return OllamaClient
     */
    private static function createOllamaProvider(string $provider, HttpClient $httpClient, array $options): OllamaClient
    {
        $config = self::OLLAMA_PROVIDERS[$provider];
        $baseUrl = $options['base_url'] ?? $config['base_url'];
        $timeout = $options['timeout'] ?? 60;

        return new OllamaClient($httpClient, $baseUrl, $timeout);
    }

    /**
     * 创建 OpenAI 兼容接口客户端
     *
     * @param string $provider Provider 名称
     * @param HttpClient $httpClient HTTP 客户端
     * @param array $options 选项
     * @return OpenAiClient
     */
    private static function createOpenAiCompatible(string $provider, HttpClient $httpClient, array $options): OpenAiClient
    {
        $config = self::OPENAI_COMPATIBLES[$provider];

        // 优先使用传入的 api_key，否则从配置中读取
        $apiKey = $options['api_key'] ?? config("llm.providers.{$provider}.api_key", '');
        $baseUrl = $options['base_url'] ?? $config['base_url'];
        $defaultOptions = $options['default_options'] ?? [];
        $timeout = $options['timeout'] ?? 60;

        return new OpenAiClient($httpClient, $apiKey, $baseUrl, '', $defaultOptions, $timeout);
    }

    /**
     * 获取所有支持的 Provider 名称列表
     *
     * @return array
     */
    public static function getSupportedProviders(): array
    {
        return array_merge(
            array_keys(self::CUSTOM_PROVIDERS),
            array_keys(self::OLLAMA_PROVIDERS),
            array_keys(self::OPENAI_COMPATIBLES)
        );
    }

    /**
     * 检查 Provider 是否支持
     *
     * @param string $provider Provider 名称
     * @return bool
     */
    public static function isProviderSupported(string $provider): bool
    {
        return in_array(strtolower($provider), self::getSupportedProviders());
    }
}
