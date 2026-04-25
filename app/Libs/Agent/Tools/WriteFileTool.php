<?php

namespace App\Libs\Agent\Tools;

use App\Libs\Agent\ToolInterface;

/**
 * 写入文件工具
 */
class WriteFileTool extends FileOperateTool implements ToolInterface
{
    public function getName(): string
    {
        return 'write_file';
    }

    public function getDescription(): string
    {
        return '将内容写入指定文件。如果文件不存在则创建，存在则根据 overwrite 参数决定是否覆盖';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => '文件路径（支持 ~/ 开头的相对路径）'
                ],
                'content' => [
                    'type' => 'string',
                    'description' => '要写入的内容'
                ],
                'overwrite' => [
                    'type' => 'boolean',
                    'description' => '是否覆盖已存在的文件，true=覆盖，false=不覆盖（默认为 false）',
                    'default' => false
                ]
            ],
            'required' => ['path', 'content']
        ];
    }

    public function execute(array $arguments): string
    {
        $path = $arguments['path'] ?? '';
        $content = $arguments['content'] ?? '';
        $overwrite = $arguments['overwrite'] ?? false;

        $this->validatePathNotEmpty($path);

        // 处理 ~/ 路径扩展
        $path = $this->expandPath($path);

        // 优先检查 overwrite 参数，避免不必要的文件系统调用
        if (!$overwrite) {
            clearstatcache(true, $path);
            if (file_exists($path)) {
                throw new \RuntimeException("文件已存在且 overwrite=false，拒绝覆盖：{$path}");
            }
        }

        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
            throw new \RuntimeException("无法创建目录：{$directory}");
        }

        $result = file_put_contents($path, $content);
        if ($result === false) {
            throw new \RuntimeException("无法写入文件：{$path}");
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
