<?php

namespace App\Libs\Agent\Tools;

use App\Libs\Agent\ToolInterface;
use App\Libs\Agent\ToolManager;

/**
 * 读取 Skill 定义工具
 *
 * 允许 AI 读取完整 SKILL.md 文件内容
 */
class ReadSkillTool implements ToolInterface
{
    private const SKILL_BASE_DIR = BASE_DIR . '/workspace/skills';

    /**
     * 获取工具名称
     *
     * @return string
     */
    public function getName(): string
    {
        return 'read_skill';
    }

    /**
     * 获取工具描述
     *
     * @return string
     */
    public function getDescription(): string
    {
        $skillNames = $this->getAvailableSkillNames();
        if (empty($skillNames)) {
            return "读取指定技能的完整说明文档";
        }

        return "读取指定技能的完整说明文档。可用技能: " . implode(', ', $skillNames);
    }

    /**
     * 获取参数定义
     *
     * @return array
     */
    public function getParameters(): array
    {
        $skillNames = $this->getAvailableSkillNames();

        return [
            'type' => 'object',
            'properties' => [
                'skill_name' => [
                    'type' => 'string',
                    'description' => '技能名称',
                    'enum' => $skillNames,
                ]
            ],
            'required' => ['skill_name'],
        ];
    }

    /**
     * 执行工具
     *
     * @param array $arguments 参数数组
     * @return string 执行结果
     */
    public function execute(array $arguments): string
    {
        $skillName = $arguments['skill_name'] ?? '';

        if (empty($skillName)) {
            throw new \RuntimeException('skill_name is required');
        }

        $skillFile = self::SKILL_BASE_DIR . '/' . $skillName . '/SKILL.md';

        if (!file_exists($skillFile)) {
            $available = implode(', ', $this->getAvailableSkillNames());
            throw new \RuntimeException("Unknown skill '{$skillName}'. Available skills: {$available}");
        }

        $content = file_get_contents($skillFile);

        if ($content === false) {
            throw new \RuntimeException("Failed to read SKILL.md for skill '{$skillName}'");
        }

        return $content;
    }

    /**
     * 转换为数组格式
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->getName(),
                'description' => $this->getDescription(),
                'parameters' => $this->getParameters(),
            ],
        ];
    }

    /**
     * 获取可用的 Skill 名称列表
     *
     * @return array
     */
    private function getAvailableSkillNames(): array
    {
        if (!is_dir(self::SKILL_BASE_DIR)) {
            return [];
        }

        $names = [];
        $items = scandir(self::SKILL_BASE_DIR);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $skillPath = self::SKILL_BASE_DIR . '/' . $item;

            if (is_dir($skillPath) && file_exists($skillPath . '/SKILL.md')) {
                $names[] = $item;
            }
        }

        return $names;
    }
}
