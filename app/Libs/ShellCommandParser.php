<?php

namespace App\Libs;

/**
 * Shell 命令解析器
 * 按照层级结构解析 Shell 命令：; -> && -> || -> | -> ()
 */
class ShellCommandParser
{
    // 操作符类型常量
    private const OP_SEQUENCE = 'sequence';  // ;
    private const OP_AND = 'and';            // &&
    private const OP_OR = 'or';              // ||
    private const OP_PIPE = 'pipe';          // |
    private const OP_COMMAND = 'command';    // 命令
    private const OP_SUBSHELL = 'subshell';  // 子 shell

    /**
     * 解析 Shell 命令为精练的层级结构
     *
     * @param string $command Shell 命令字符串
     * @return array 解析结果
     */
    public static function parse(string $command): array
    {
        // 直接解析为命令树数组
        $ast = self::parseSequenceItems($command);

        return [
            'raw' => $command,
            'ast' => $ast,
        ];
    }

    /**
     * 解析分号分隔的命令序列
     *
     * @param string $command 命令字符串
     * @return array 序列项数组（每个元素是一个 AND 节点）
     */
    private static function parseSequenceItems(string $command): array
    {
        $parts = self::splitByOperator($command, ';');
        $filtered = array_filter($parts, fn($part) => trim($part) !== '');

        // 如果只有一个部分，直接返回 AND 解析结果
        if (count($filtered) === 1) {
            return self::parseAnds($filtered[0]);
        }

        // 多个部分，用 AND 节点数组表示
        return array_map([self::class, 'parseAnds'], $filtered);
    }

    /**
     * 解析 AND 操作符（&&）
     *
     * @param string $command 命令字符串
     * @return array AST 节点
     */
    private static function parseAnds(string $command): array
    {
        $parts = self::splitByOperator($command, '&&');
        $filtered = array_filter($parts, fn($part) => trim($part) !== '');

        if (count($filtered) === 1) {
            return self::parseOrs($filtered[0]);
        }

        return [
            'type' => self::OP_AND,
            'data' => array_map([self::class, 'parseOrs'], $filtered),
        ];
    }

    /**
     * 解析 OR 操作符（||）
     *
     * @param string $command 命令字符串
     * @return array AST 节点
     */
    private static function parseOrs(string $command): array
    {
        $parts = self::splitByOperator($command, '||');
        $filtered = array_filter($parts, fn($part) => trim($part) !== '');

        if (count($filtered) === 1) {
            return self::parsePipes($filtered[0]);
        }

        return [
            'type' => self::OP_OR,
            'data' => array_map([self::class, 'parsePipes'], $filtered),
        ];
    }

    /**
     * 解析管道操作符（|）
     *
     * @param string $command 命令字符串
     * @return array AST 节点
     */
    private static function parsePipes(string $command): array
    {
        $parts = self::splitByOperator($command, '|');
        $filtered = array_filter($parts, fn($part) => trim($part) !== '');

        if (count($filtered) === 1) {
            return self::parseAtom($filtered[0]);
        }

        return [
            'type' => self::OP_PIPE,
            'data' => array_map([self::class, 'parseAtom'], $filtered),
        ];
    }

    /**
     * 解析原子命令（command 或 subshell）
     *
     * @param string $command 命令字符串
     * @return array AST 节点
     */
    private static function parseAtom(string $command): array
    {
        $command = trim($command);
        if ($command === '') {
            throw new \RuntimeException('空命令');
        }

        // 检查后台执行符
        $background = false;
        if (str_ends_with($command, '&')) {
            if (!str_ends_with(trim($command), '&&')) {
                $background = true;
                $command = rtrim(substr($command, 0, -1));
            }
        }

        // 检查子 shell
        if (self::isSubshell($command)) {
            $content = self::extractSubshellContent($command);
            return [
                'type' => self::OP_SUBSHELL,
                'background' => $background,
                'data' => self::parseSequenceItems($content), // 递归解析子 shell 内容
            ];
        }

        // 解析普通命令
        $tokens = self::tokenizeCommand($command);
        if (empty($tokens)) {
            throw new \RuntimeException('无效命令: ' . $command);
        }

        return [
            'type' => self::OP_COMMAND,
            'background' => $background,
            'name' => $tokens[0],
            'args' => array_slice($tokens, 1),
        ];
    }

    /**
     * 将命令字符串 tokenize 为参数数组
     *
     * @param string $command 命令字符串
     * @return array 参数数组
     */
    private static function tokenizeCommand(string $command): array
    {
        $tokens = [];
        $currentToken = '';
        $inQuotes = false;
        $quoteChar = '';
        $escaped = false;

        $chars = str_split($command);
        foreach ($chars as $char) {
            // 处理转义字符
            if ($escaped) {
                $currentToken .= $char;
                $escaped = false;
                continue;
            }

            if ($char === '\\') {
                $escaped = true;
                continue;
            }

            // 处理引号
            if ($char === '"' || $char === "'") {
                if (!$inQuotes) {
                    $inQuotes = true;
                    $quoteChar = $char;
                } elseif ($quoteChar === $char) {
                    $inQuotes = false;
                    $quoteChar = '';
                } else {
                    $currentToken .= $char;
                }
                continue;
            }

            // 处理空格（分隔符）
            if (ctype_space($char)) {
                if (!$inQuotes && $currentToken !== '') {
                    $tokens[] = $currentToken;
                    $currentToken = '';
                }
                continue;
            }

            $currentToken .= $char;
        }

        // 添加最后一个 token
        if ($currentToken !== '') {
            $tokens[] = $currentToken;
        }

        return $tokens;
    }

    /**
     * 按操作符分割命令字符串（考虑引号和子 shell）
     *
     * @param string $command 命令字符串
     * @param string $operator 操作符
     * @return array 分割后的数组
     */
    private static function splitByOperator(string $command, string $operator): array
    {
        $result = [];
        $current = '';
        $chars = str_split($command);
        $i = 0;
        $length = count($chars);

        while ($i < $length) {
            $char = $chars[$i];

            // 处理引号
            if ($char === '"' || $char === "'") {
                $quoteChar = $char;
                $current .= $char;
                $i++;

                // 找到匹配的引号
                while ($i < $length) {
                    $current .= $chars[$i];
                    if ($chars[$i] === $quoteChar && ($i === 0 || $chars[$i - 1] !== '\\')) {
                        break;
                    }
                    $i++;
                }
                $i++;
                continue;
            }

            // 处理子 shell
            if ($char === '(') {
                $current .= $char;
                $i++;
                $depth = 1;

                while ($i < $length && $depth > 0) {
                    $current .= $chars[$i];
                    if ($chars[$i] === '(') {
                        $depth++;
                    } elseif ($chars[$i] === ')') {
                        $depth--;
                    }
                    $i++;
                }
                continue;
            }

            // 检查操作符
            if (self::matchOperator($chars, $i, $operator)) {
                $result[] = $current;
                $current = '';
                $i += strlen($operator);
                continue;
            }

            $current .= $char;
            $i++;
        }

        if ($current !== '') {
            $result[] = $current;
        }

        return $result;
    }

    /**
     * 检查指定位置是否匹配操作符
     *
     * @param array $chars 字符数组
     * @param int $position 位置
     * @param string $operator 操作符
     * @return bool 是否匹配
     */
    private static function matchOperator(array $chars, int $position, string $operator): bool
    {
        $opLength = strlen($operator);
        $length = count($chars);

        for ($i = 0; $i < $opLength; $i++) {
            if ($position + $i >= $length) {
                return false;
            }
            if ($chars[$position + $i] !== $operator[$i]) {
                return false;
            }
        }

        return true;
    }

    /**
     * 检查是否是子 shell
     *
     * @param string $command 命令字符串
     * @return bool 是否是子 shell
     */
    private static function isSubshell(string $command): bool
    {
        $command = trim($command);
        return str_starts_with($command, '(') && str_ends_with($command, ')');
    }

    /**
     * 提取子 shell 内容
     *
     * @param string $command 命令字符串
     * @return string 子 shell 内容
     */
    private static function extractSubshellContent(string $command): string
    {
        return trim(substr(trim($command), 1, -1));
    }

    /**
     * 将命令分解为 Token
     *
     * @param string $command 命令字符串
     * @return array Token 列表
     */
    private static function tokenize(string $command): array
    {
        $tokens = [];
        $length = strlen($command);
        $i = 0;

        while ($i < $length) {
            $char = $command[$i];

            // 跳过空白字符
            if (ctype_space($char)) {
                $i++;
                continue;
            }

            // 处理引号
            if ($char === '"' || $char === "'") {
                $tokens[] = ['type' => 'quote', 'value' => $char];
                $i++;
                continue;
            }

            // 处理操作符
            if ($char === '&') {
                if ($i + 1 < $length && $command[$i + 1] === '&') {
                    $tokens[] = ['type' => 'operator', 'value' => '&&'];
                    $i += 2;
                } else {
                    $tokens[] = ['type' => 'operator', 'value' => '&'];
                    $i++;
                }
                continue;
            }

            if ($char === '|') {
                if ($i + 1 < $length && $command[$i + 1] === '|') {
                    $tokens[] = ['type' => 'operator', 'value' => '||'];
                    $i += 2;
                } else {
                    $tokens[] = ['type' => 'operator', 'value' => '|'];
                    $i++;
                }
                continue;
            }

            if ($char === ';') {
                $tokens[] = ['type' => 'operator', 'value' => ';'];
                $i++;
                continue;
            }

            // 处理普通字符（命令名或参数）
            $word = '';
            while ($i < $length && !ctype_space($command[$i]) && !in_array($command[$i], ['&', '|', ';', '"', "'"])) {
                $word .= $command[$i];
                $i++;
            }

            if ($word !== '') {
                $tokens[] = ['type' => 'word', 'value' => $word];
            }
        }

        return $tokens;
    }

    /**
     * 构建 Command 结构
     *
     * @param array $tokens Token 列表
     * @return array 命令结构
     */
    private static function buildCommand(array $tokens): array
    {
        $command = [
            'name' => '',
            'arguments' => [],
            'has_quotes' => false,
        ];

        $args = [];
        $currentArg = '';
        $inQuotes = false;

        foreach ($tokens as $token) {
            if ($token['type'] === 'quote') {
                $command['has_quotes'] = true;
                $inQuotes = !$inQuotes;
                continue;
            }

            if ($token['type'] === 'word') {
                if ($command['name'] === '') {
                    $command['name'] = $token['value'];
                } else {
                    $args[] = $token['value'];
                }
            }
        }

        $command['arguments'] = $args;
        return $command;
    }

    /**
     * 检查命令是否包含危险命令
     *
     * @param array $parseResult 解析结果
     * @param array $dangerousCommands 危险命令列表
     * @return bool 是否包含危险命令
     */
    public static function hasDangerousCommand(array $parseResult, array $dangerousCommands = ['rm', 'dd', 'mkfs', 'nc']): bool
    {
        $ast = $parseResult['ast'] ?? null;
        if ($ast === null) {
            return false;
        }

        return self::checkDangerousRecursive($ast, $dangerousCommands);
    }

    /**
     * 递归检查 AST 是否包含危险命令
     *
     * @param array $node AST 节点
     * @param array $dangerousCommands 危险命令列表
     * @return bool 是否包含危险命令
     */
    private static function checkDangerousRecursive(array $node, array $dangerousCommands): bool
    {
        $type = $node['type'] ?? '';

        // 检查命令节点
        if ($type === self::OP_COMMAND) {
            $commandName = $node['name'] ?? '';

            // 直接匹配危险命令
            if (in_array($commandName, $dangerousCommands)) {
                return true;
            }

            // 检查特权命令（sudo, su, doas）
            if (in_array($commandName, ['sudo', 'su', 'doas'])) {
                $args = $node['args'] ?? [];
                foreach ($args as $arg) {
                    // 跳过选项
                    if (str_starts_with($arg, '-')) {
                        continue;
                    }
                    // 检查参数是否是危险命令
                    if (in_array($arg, $dangerousCommands)) {
                        return true;
                    }
                }
            }

            return false;
        }

        // 递归检查子节点
        $data = $node['data'] ?? [];
        if (is_array($data)) {
            foreach ($data as $child) {
                if (is_array($child) && self::checkDangerousRecursive($child, $dangerousCommands)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 获取命令中的所有命令名
     *
     * @param array $parseResult 解析结果
     * @return array 命令名列表
     */
    public static function getCommandNames(array $parseResult): array
    {
        $names = [];
        $ast = $parseResult['ast'] ?? null;

        if ($ast !== null) {
            self::extractNamesRecursive($ast, $names);
        }

        return array_unique($names);
    }

    /**
     * 递归提取命令名
     *
     * @param array $node AST 节点
     * @param array $names 命令名数组（引用）
     */
    private static function extractNamesRecursive(array $node, array &$names): void
    {
        $type = $node['type'] ?? '';

        // 提取命令名
        if ($type === self::OP_COMMAND) {
            $commandName = $node['name'] ?? '';
            if ($commandName !== '') {
                $names[] = $commandName;
            }
        }

        // 递归处理子节点
        $data = $node['data'] ?? [];
        if (is_array($data)) {
            foreach ($data as $child) {
                if (is_array($child)) {
                    self::extractNamesRecursive($child, $names);
                }
            }
        }
    }
}
