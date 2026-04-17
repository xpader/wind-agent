<?php

namespace App\Libs\Agent;

/**
 * Skill 类
 *
 * 从 SKILL.md 文件的 YAML front matter 中解析技能配置
 */
class Skill
{
    /** 技能名称 (必需, 1-64字符, 小写字母数字连字符) */
    public string $name;

    /** 技能描述 (必需, 第三人称格式) */
    public string $description;

    /** 许可证 (可选) */
    public ?string $license = null;

    /** 元数据 (可选) */
    public array $metadata = [];

    /** SKILL.md 完整内容 */
    public string $content = '';

    /** 技能目录路径 */
    public string $directory;

    /**
     * 从目录加载 Skill 配置
     *
     * @param string $skillDirectory Skill 目录路径
     * @return self
     * @throws \InvalidArgumentException
     */
    public static function fromDirectory(string $skillDirectory): self
    {
        $skillFile = $skillDirectory . '/SKILL.md';

        if (!is_dir($skillDirectory)) {
            throw new \InvalidArgumentException("Skill directory does not exist: {$skillDirectory}");
        }

        if (!is_file($skillFile)) {
            throw new \InvalidArgumentException("SKILL.md not found in: {$skillDirectory}");
        }

        $content = file_get_contents($skillFile);
        if ($content === false) {
            throw new \InvalidArgumentException("Failed to read SKILL.md: {$skillFile}");
        }

        // 解析 YAML front matter
        if (!str_starts_with($content, '---')) {
            throw new \InvalidArgumentException("SKILL.md must start with YAML front matter (---): {$skillFile}");
        }

        $endPos = strpos($content, "\n---", 3);
        if ($endPos === false) {
            throw new \InvalidArgumentException("YAML front matter not closed (---): {$skillFile}");
        }

        $yamlPart = substr($content, 3, $endPos - 3);
        $markdownPart = substr($content, $endPos + 4);

        $metadata = self::parseYaml($yamlPart);

        // 验证必需字段
        if (empty($metadata['name'])) {
            throw new \InvalidArgumentException("SKILL.md missing required field 'name': {$skillFile}");
        }

        if (empty($metadata['description'])) {
            throw new \InvalidArgumentException("SKILL.md missing required field 'description': {$skillFile}");
        }

        // 验证 name 格式
        if (!preg_match('/^[a-z0-9-]+$/', $metadata['name'])) {
            throw new \InvalidArgumentException("Skill name must contain only lowercase letters, numbers, and hyphens: {$metadata['name']}");
        }

        if (strlen($metadata['name']) > 64) {
            throw new \InvalidArgumentException("Skill name must be 64 characters or less: {$metadata['name']}");
        }

        $config = new self();
        $config->name = $metadata['name'];
        $config->description = $metadata['description'];
        $config->license = $metadata['license'] ?? null;
        $config->metadata = $metadata['metadata'] ?? [];
        $config->content = trim($markdownPart);
        $config->directory = $skillDirectory;

        return $config;
    }

    /**
     * 简单的 YAML 解析器
     *
     * 支持基本的键值对、标量值和嵌套对象
     *
     * @param string $yaml YAML 字符串
     * @return array
     */
    private static function parseYaml(string $yaml): array
    {
        $lines = explode("\n", $yaml);
        $result = [];
        $currentKey = null;
        $inMultiline = false;
        $multilineLines = [];
        $inNested = false;
        $nestedKey = null;
        $nestedData = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // 跳过空行和注释
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                if ($inMultiline && $trimmed === '') {
                    $multilineLines[] = '';
                }
                continue;
            }

            // 检查缩进级别（用于嵌套对象）
            $indent = strlen($line) - strlen(ltrim($line));

            // 如果在嵌套对象中
            if ($inNested) {
                if ($indent > 0) {
                    // 嵌套内容行
                    if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_-]*)\s*:\s*(.+)$/', $trimmed, $matches)) {
                        $value = trim($matches[2]);
                        // 去除引号
                        if (str_starts_with($value, '"') || str_starts_with($value, "'")) {
                            $nestedData[$matches[1]] = substr($value, 1, -1);
                        } else {
                            $nestedData[$matches[1]] = $value;
                        }
                    }
                    continue;
                } else {
                    // 嵌套结束
                    $result[$nestedKey] = $nestedData;
                    $inNested = false;
                    $nestedKey = null;
                    $nestedData = [];
                }
            }

            // 处理多行字符串
            if ($inMultiline) {
                if (str_starts_with($trimmed, '|') || str_starts_with($trimmed, '>')) {
                    continue;
                }
                $multilineLines[] = $line;
                continue;
            }

            // 检查是否是键值对
            if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_-]*)\s*:\s*(.*)$/', $line, $matches)) {
                $currentKey = $matches[1];
                $value = trim($matches[2]);

                // 空值，可能是嵌套对象的开始
                if ($value === '') {
                    $inNested = true;
                    $nestedKey = $currentKey;
                    continue;
                }

                // 处理多行标记
                if ($value === '|' || $value === '>') {
                    $inMultiline = true;
                    $multilineLines = [];
                    continue;
                }

                // 处理引用值
                if (str_starts_with($value, '"') || str_starts_with($value, "'")) {
                    $result[$currentKey] = substr($value, 1, -1);
                } else {
                    $result[$currentKey] = $value;
                }
            }
        }

        // 处理多行字符串结果
        if ($inMultiline && $currentKey !== null) {
            $result[$currentKey] = implode("\n", $multilineLines);
        }

        // 处理未关闭的嵌套对象
        if ($inNested && $nestedKey !== null) {
            $result[$nestedKey] = $nestedData;
        }

        return $result;
    }

    /**
     * 转换为提示词格式
     *
     * @return string
     */
    public function toPromptString(): string
    {
        // 包含 name、description 和文件路径
        return "**{$this->name}**: {$this->description} (文件: {$this->directory}/SKILL.md)";
    }

    /**
     * 转换为数组
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'license' => $this->license,
            'metadata' => $this->metadata,
            'directory' => $this->directory,
        ];
    }

    /**
     * 获取 SKILL.md 完整内容
     *
     * @return string
     */
    public function getFullContent(): string
    {
        $metadata = "---\n";
        $metadata .= "name: {$this->name}\n";
        $metadata .= "description: {$this->description}\n";

        if ($this->license) {
            $metadata .= "license: {$this->license}\n";
        }

        if (!empty($this->metadata)) {
            $metadata .= "metadata:\n";
            foreach ($this->metadata as $key => $value) {
                if (is_array($value)) {
                    $metadata .= "  {$key}: " . json_encode($value, JSON_UNESCAPED_UNICODE) . "\n";
                } else {
                    $metadata .= "  {$key}: {$value}\n";
                }
            }
        }

        $metadata .= "---\n\n";

        return $metadata . $this->content;
    }
}
