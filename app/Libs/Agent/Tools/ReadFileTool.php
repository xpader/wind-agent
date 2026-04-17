<?php

namespace App\Libs\Agent\Tools;

use App\Libs\Agent\ToolInterface;

/**
 * 读取文件工具
 */
class ReadFileTool implements ToolInterface
{
    public function getName(): string
    {
        return 'read_file';
    }

    public function getDescription(): string
    {
        return '读取指定文件的内容';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => '文件路径'
                ]
            ],
            'required' => ['path']
        ];
    }

    public function execute(array $arguments): string
    {
        $path = $arguments['path'] ?? '';

        if (empty($path)) {
            return '错误：文件路径不能为空';
        }

        if (!file_exists($path)) {
            return "错误：文件不存在：{$path}";
        }

        if (!is_readable($path)) {
            return "错误：文件不可读：{$path}";
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return "错误：无法读取文件：{$path}";
        }

        return $content;
    }

    public function toArray(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->getName(),
                'description' => $this->getDescription(),
                'parameters' => $this->getParameters()
            ]
        ];
    }
}
