<?php

namespace App\Libs\LLM;

/**
 * 模型信息类
 *
 * 用于识别模型的各种参数，如上下文长度等
 */
class ModelInfo
{
    /**
     * 估算模型的上下文长度
     * 使用启发式方法根据模型名称判断
     *
     * @param string $model 模型名称
     * @return int 上下文长度（token 数）
     */
    public static function estimateContextLimit(string $model): int
    {
        // 小米 MIMO 系列 - 1M (百万上下文)
        if (preg_match('/^mimo-/i', $model)) {
            return 1000000;
        }

        // Kimi 系列 - 256k
        if (preg_match('/^kimi-/i', $model)) {
            return 256000;
        }

        // Anthropic Claude 系列 - 200k
        if (preg_match('/claude-3/i', $model)) {
            return 200000;
        }

        // MiniMax 系列 - 200k
        if (preg_match('/MiniMax-M2\.7/i', $model) || preg_match('/abab6/i', $model)) {
            return 200000;
        }

        // GLM 系列
        // glm-4.6+ 或 glm-5. 开头 - 200k（先判断，避免被 4/4.5 规则拦截）
        if (preg_match('/^glm-4\.([6-9]|[1-9][0-9])/i', $model) || preg_match('/^glm-5(\.|$)/i', $model)) {
            return 200000;
        }
        // glm-4 或 glm-4.5 开头 - 128k
        if (preg_match('/^glm-4(\.5)?($|[^0-9])/i', $model)) {
            return 128000;
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
}
