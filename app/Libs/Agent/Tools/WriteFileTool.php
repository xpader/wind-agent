<?php

namespace App\Libs\Agent\Tools;

use App\Libs\Agent\ToolInterface;

/**
 * 写入文件工具
 */
class WriteFileTool implements ToolInterface
{
    public function getName(): string
    {
        return 'write_file';
    }

    public function getDescription(): string
    {
        return '将内容写入指定文件（如果文件不存在则创建，存在则覆盖）';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => '文件路径'
                ],
                'content' => [
                    'type' => 'string',
                    'description' => '要写入的内容'
                ]
            ],
            'required' => ['path', 'content']
        ];
    }

    public function execute(array $arguments): string
    {
        $path = $arguments['path'] ?? '';
        $content = $arguments['content'] ?? '';

        if (empty($path)) {
            return '错误：文件路径不能为空';
        }

        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
            return "错误：无法创建目录：{$directory}";
        }

        $result = file_put_contents($path, $content);
        if ($result === false) {
            return "错误：无法写入文件：{$path}";
        }

        return "成功：已写入 {$result} 字节到文件：{$path}";
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
