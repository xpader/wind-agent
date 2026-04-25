<?php

namespace App\Libs\Agent\Tools;

/**
 * 文件操作工具抽象基类
 *
 * 提供文件路径处理等公共功能
 */
abstract class FileOperateTool
{
    /**
     * 扩展路径中的 ~ 为用户主目录
     *
     * @param string $path 文件路径
     * @return string 扩展后的路径
     */
    protected function expandPath(string $path): string
    {
        if (str_starts_with($path, '~/')) {
            return $_SERVER['HOME'] . '/' . substr($path, 2);
        }
        return $path;
    }

    /**
     * 验证文件路径是否为空
     *
     * @param string $path 文件路径
     * @throws \RuntimeException 当路径为空时
     */
    protected function validatePathNotEmpty(string $path): void
    {
        if ($path === '') {
            throw new \RuntimeException('文件路径不能为空');
        }
    }
}
