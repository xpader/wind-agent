<?php

namespace App\Libs\Agent\Tools;

use App\Libs\Agent\ToolInterface;

/**
 * 文件编辑工具
 *
 * 通过替换内容的方式来修改文件，避免需要传递完整文件内容
 */
class EditFileTool extends FileOperateTool implements ToolInterface
{
    public function getName(): string
    {
        return 'edit_file';
    }

    public function getDescription(): string
    {
        return '通过替换内容来修改文件，适用于大文件的部分修改，避免传递完整文件内容';
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
                'old_content' => [
                    'type' => 'string',
                    'description' => '要被替换的旧内容'
                ],
                'new_content' => [
                    'type' => 'string',
                    'description' => '替换后的新内容'
                ],
                'replace_all' => [
                    'type' => 'boolean',
                    'description' => '是否替换所有匹配项，默认只替换第一个匹配项',
                    'default' => false
                ]
            ],
            'required' => ['path', 'old_content', 'new_content']
        ];
    }

    public function execute(array $arguments): string
    {
        $path = $arguments['path'] ?? '';
        $oldContent = $arguments['old_content'] ?? '';
        $newContent = $arguments['new_content'] ?? '';
        $replaceAll = $arguments['replace_all'] ?? false;

        $this->validatePathNotEmpty($path);

        // 处理 ~/ 路径扩展
        $path = $this->expandPath($path);

        // 检查文件是否存在
        if (!file_exists($path)) {
            throw new \RuntimeException("文件不存在：{$path}");
        }

        // 读取文件内容
        $fileContent = file_get_contents($path);
        if ($fileContent === false) {
            throw new \RuntimeException("无法读取文件：{$path}");
        }

        // 检查旧内容是否存在
        if (!str_contains($fileContent, $oldContent)) {
            throw new \RuntimeException("在文件中未找到要替换的内容：{$oldContent}");
        }

        // 执行替换
        if ($replaceAll) {
            $newFileContent = str_replace($oldContent, $newContent, $fileContent);
            $replaceCount = substr_count($fileContent, $oldContent);
        } else {
            $newFileContent = $this->replaceFirst($oldContent, $newContent, $fileContent);
            $replaceCount = 1;
        }

        // 写入文件
        $result = file_put_contents($path, $newFileContent);
        if ($result === false) {
            throw new \RuntimeException("无法写入文件：{$path}");
        }

        return "成功：已修改文件 {$path}\n" .
               "替换了 {$replaceCount} 处内容\n" .
               "写入 {$result} 字节";
    }

    /**
     * 替换字符串中第一个匹配项
     */
    private function replaceFirst(string $search, string $replace, string $subject): string
    {
        $pos = strpos($subject, $search);
        if ($pos === false) {
            return $subject;
        }

        return substr($subject, 0, $pos) . $replace . substr($subject, $pos + strlen($search));
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
