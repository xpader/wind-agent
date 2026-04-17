<?php

namespace App\Libs\Agent;

/**
 * SkillManager 类
 *
 * 负责 Skill 的扫描、加载和提示词生成
 */
class SkillManager
{
    /** Skills 基础目录 */
    private string $skillBaseDir;

    /** @var array<string, Skill> 已加载的 Skill 列表，以技能名称为索引 */
    private array $skills = [];

    /**
     * 构造函数
     *
     * @param string|null $skillBaseDir Skills 目录，默认为 workspace/skills
     */
    public function __construct(?string $skillBaseDir = null)
    {
        $this->skillBaseDir = $skillBaseDir ?? BASE_DIR . '/workspace/skills';
        $this->scan();
    }

    /**
     * 扫描并加载所有 Skills
     *
     * @return void
     */
    private function scan(): void
    {
        if (!is_dir($this->skillBaseDir)) {
            return;
        }

        $items = scandir($this->skillBaseDir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $skillPath = $this->skillBaseDir . '/' . $item;

            if (!is_dir($skillPath)) {
                continue;
            }

            if (!file_exists($skillPath . '/SKILL.md')) {
                continue;
            }

            try {
                $skill = Skill::fromDirectory($skillPath);
                $this->skills[$skill->name] = $skill;
            } catch (\Throwable $e) {
                error_log("Failed to load skill from {$skillPath}: {$e->getMessage()}");
            }
        }
    }

    /**
     * 获取所有已加载的 Skills
     *
     * @return array<string, Skill>
     */
    public function getAll(): array
    {
        return $this->skills;
    }

    /**
     * 根据 name 获取 Skill
     *
     * @param string $name Skill 名称
     * @return Skill|null
     */
    public function getByName(string $name): ?Skill
    {
        return $this->skills[$name] ?? null;
    }

    /**
     * 生成 Skill 列表的提示词
     *
     * @return string
     */
    public function generatePrompt(): string
    {
        if (empty($this->skills)) {
            return '';
        }

        $lines = ["## 以下技能扩展了你的能力\n\n"];
        $lines[] = "当需要使用对应技能时，请使用 `read_skill` 工具读取该技能的完整说明文档，然后按照文档中的步骤执行。\n\n";

        foreach ($this->skills as $skill) {
            $lines[] = "- " . $skill->toPromptString();
        }

        return implode("\n", $lines);
    }

    /**
     * 检查是否存在指定名称的 Skill
     *
     * @param string $name Skill 名称
     * @return bool
     */
    public function has(string $name): bool
    {
        return isset($this->skills[$name]);
    }

    /**
     * 获取 Skills 数量
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->skills);
    }
}
