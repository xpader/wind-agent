<?php

namespace App\Libs\Agent\Tools;

use App\Libs\Agent\ToolInterface;

/**
 * 追加文件工具
 */
class AppendFileTool extends FileOperateTool implements ToolInterface
{
    public function getName(): string
    {
        return 'append_file';
    }

    public function getDescription(): string
    {
        return '将内容追加到指定文件末尾（文件必须存在）';
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
                    'description' => '要追加的内容'
                ]
            ],
            'required' => ['path', 'content']
        ];
    }

    public function execute(array $arguments): string
    {
        $path = $arguments['path'] ?? '';
        $content = $arguments['content'] ?? '';

        $this->validatePathNotEmpty($path);

        // 处理 ~/ 路径扩展
        $path = $this->expandPath($path);

        clearstatcache(true, $path);

        // 检查文件是否存在
        if (!file_exists($path)) {
            throw new \RuntimeException("文件不存在，无法追加：{$path}");
        }

        // 追加内容到文件
        $result = file_put_contents($path, $content, FILE_APPEND);
        if ($result === false) {
            throw new \RuntimeException("无法写入文件：{$path}");
        }

        return "成功：已追加 {$result} 字节到文件：{$path}";
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
