<?php

namespace App\Libs\LLM;

use Amp\Http\Client\HttpClient;
use App\Libs\LLM\Clients\OpenAiClient;
use App\Libs\LLM\Clients\OllamaClient;
use App\Libs\LLM\Clients\MiniMaxClient;
use App\Libs\LLM\Clients\AnthropicClient;

/**
 * LLM 客户端工厂类
 *
 * 根据客户端名称创建对应的 LLM 客户端实例
 */
class ClientFactory
{
    // Client 类别名映射
    private const CLIENT_ALIASES = [
        'openai' => OpenAiClient::class,
        'anthropic' => AnthropicClient::class,
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

        // 从配置文件获取 Provider 配置
        $providerConfig = config("llm.providers.{$provider}");

        if ($providerConfig === null) {
            throw new \InvalidArgumentException(
                "不支持的 provider: {$provider}，" .
                "支持的 provider: " . implode(', ', self::getSupportedProviders())
            );
        }

        // 获取 Client 类
        $clientClass = self::resolveClientClass($providerConfig['client'] ?? 'openai');

        // 创建客户端实例
        return self::createClient($clientClass, $provider, $providerConfig, $httpClient, $options);
    }

    /**
     * 解析 Client 类名
     *
     * @param string $client Client 类别名或完整类名
     * @return string 完整的 Client 类名
     */
    private static function resolveClientClass(string $client): string
    {
        // 如果是别名，返回对应的类名
        if (isset(self::CLIENT_ALIASES[$client])) {
            return self::CLIENT_ALIASES[$client];
        }

        // 如果是完整类名，直接返回
        if (class_exists($client)) {
            return $client;
        }

        throw new \InvalidArgumentException("未知的 client 类型: {$client}");
    }

    /**
     * 创建客户端实例
     *
     * @param string $clientClass Client 类名
     * @param string $provider Provider 名称
     * @param array $providerConfig Provider 配置
     * @param HttpClient $httpClient HTTP 客户端
     * @param array $options 额外选项
     * @return LLMClient
     */
    private static function createClient(
        string $clientClass,
        string $provider,
        array $providerConfig,
        HttpClient $httpClient,
        array $options = []
    ): LLMClient {
        // 优先使用传入的选项，否则从配置中读取
        $apiKey = $options['api_key'] ?? $providerConfig['api_key'] ?? '';
        $baseUrl = $options['base_url'] ?? $providerConfig['base_url'] ?? '';
        $defaultOptions = $options['default_options'] ?? $providerConfig['default_options'] ?? [];
        $timeout = $options['timeout'] ?? $providerConfig['timeout'] ?? 60;
        $version = $options['version'] ?? $providerConfig['version'] ?? null;

        // 根据不同的 Client 类创建实例
        if ($clientClass === OllamaClient::class) {
            return new OllamaClient($httpClient, $baseUrl, $timeout);
        }

        if ($clientClass === AnthropicClient::class) {
            $version = $version ?? '2023-06-01'; // 默认 API 版本
            return new AnthropicClient($httpClient, $apiKey, $baseUrl, $version, $defaultOptions, $timeout);
        }

        if ($clientClass === MiniMaxClient::class) {
            return new MiniMaxClient($httpClient, $apiKey, $baseUrl, '', $defaultOptions, $timeout);
        }

        // 默认使用 OpenAiClient
        return new OpenAiClient($httpClient, $apiKey, $baseUrl, '', $defaultOptions, $timeout);
    }

    /**
     * 获取所有支持的 Provider 名称列表
     *
     * @return array
     */
    public static function getSupportedProviders(): array
    {
        return array_keys(config('llm.providers', []));
    }

    /**
     * 检查 Provider 是否支持
     *
     * @param string $provider Provider 名称
     * @return bool
     */
    public static function isProviderSupported(string $provider): bool
    {
        return config("llm.providers.{$provider}") !== null;
    }
}
