# Shell 命令解析器

`ShellCommandParser` 是一个按照 Shell 操作符优先级逐层解析命令的解析器，生成精练的 AST（抽象语法树）结构。

## 解析层级

解析器按照以下优先级（从低到高）逐层拆解命令：

1. **`;` 分号** - 命令序列（多个命令用数组表示）
2. **`&&` AND** - 逻辑与操作（and）
3. **`||` OR** - 逻辑或操作（or）
4. **`|` 管道** - 管道操作（pipe）
5. **`()` 子 shell** 和 **`&` 后台** - 命令类型

## AST 结构规则

**核心规则**：
- **单个命令** → 对象 `{type: "command", ...}`
- **多个命令（`;` 分隔）** → 数组 `[{命令1}, {命令2}, ...]`
- **操作符节点** → 对象 `{type: "操作符类型", data: [...]}`

## AST 节点类型

### 1. command 节点

```json
{
    "type": "command",
    "background": false,
    "name": "ls",
    "args": ["-la", "/tmp"]
}
```

**字段说明**：
- `type`: 固定值 `"command"`
- `background`: 是否后台执行（`&`）
- `name`: 命令名称
- `args`: 参数数组

### 2. and 节点（`&&`）

```json
{
    "type": "and",
    "data": [节点1, 节点2, ...]
}
```

### 3. or 节点（`||`）

```json
{
    "type": "or",
    "data": [节点1, 节点2, ...]
}
```

### 4. pipe 节点（`|`）

```json
{
    "type": "pipe",
    "data": [节点1, 节点2, ...]
}
```

### 5. subshell 节点（`()`）

```json
{
    "type": "subshell",
    "background": false,
    "data": {AST 节点}
}
```

**字段说明**：
- `type`: 固定值 `"subshell"`
- `background`: 是否后台执行
- `data`: 子 shell 内的 AST（递归结构）

## 解析结果结构

```json
{
    "raw": "原始命令字符串",
    "ast": {AST 根节点或数组}
}
```

**`ast` 字段的类型**：
- 单个命令：对象 `{type: "command", ...}`
- 多个命令（`;` 分隔）：数组 `[{命令1}, {命令2}, ...]`

## 解析示例

### 1. 简单命令

**输入**: `ls -la`

**输出**:
```json
{
    "raw": "ls -la",
    "ast": {
        "type": "command",
        "background": false,
        "name": "ls",
        "args": ["-la"]
    }
}
```

### 2. AND 操作（`&&`）

**输入**: `sleep 2 && date`

**输出**:
```json
{
    "raw": "sleep 2 && date",
    "ast": {
        "type": "and",
        "data": [
            {
                "type": "command",
                "background": false,
                "name": "sleep",
                "args": ["2"]
            },
            {
                "type": "command",
                "background": false,
                "name": "date",
                "args": []
            }
        ]
    }
}
```

### 3. 管道操作（`|`）

**输入**: `ps aux | grep nginx`

**输出**:
```json
{
    "raw": "ps aux | grep nginx",
    "ast": {
        "type": "pipe",
        "data": [
            {
                "type": "command",
                "background": false,
                "name": "ps",
                "args": ["aux"]
            },
            {
                "type": "command",
                "background": false,
                "name": "grep",
                "args": ["nginx"]
            }
        ]
    }
}
```

### 4. 分号序列（`;`）

**输入**: `cd /tmp; ls`

**输出**:
```json
{
    "raw": "cd /tmp; ls",
    "ast": [
        {
            "type": "command",
            "background": false,
            "name": "cd",
            "args": ["/tmp"]
        },
        {
            "type": "command",
            "background": false,
            "name": "ls",
            "args": []
        }
    ]
}
```

**说明**: 多个命令用 `;` 分隔时，`ast` 直接是一个数组。

### 5. 子 shell（`()`）

**输入**: `(echo "nested")`

**输出**:
```json
{
    "raw": "(echo \"nested\")",
    "ast": {
        "type": "subshell",
        "background": false,
        "data": {
            "type": "command",
            "background": false,
            "name": "echo",
            "args": ["nested"]
        }
    }
}
```

### 6. 混合操作

**输入**: `ls && (pwd || echo "error") ; date`

**输出**:
```json
{
    "raw": "ls && (pwd || echo \"error\") ; date",
    "ast": [
        {
            "type": "and",
            "data": [
                {
                    "type": "command",
                    "background": false,
                    "name": "ls",
                    "args": []
                },
                {
                    "type": "subshell",
                    "background": false,
                    "data": {
                        "type": "or",
                        "data": [
                            {
                                "type": "command",
                                "background": false,
                                "name": "pwd",
                                "args": []
                            },
                            {
                                "type": "command",
                                "background": false,
                                "name": "echo",
                                "args": ["error"]
                            }
                        ]
                    }
                }
            ]
        },
        {
            "type": "command",
            "background": false,
            "name": "date",
            "args": []
        }
    ]
}
```

**说明**:
- 最外层是数组（`;` 分隔的两个命令）
- 第一个元素是 `and` 节点（`&&` 分隔）
- AND 的第二个操作数是 `subshell`
- 子 shell 内部是 `or` 节点（`||` 分隔）

### 7. 后台执行（`&`）

**输入**: `command &`

**输出**:
```json
{
    "raw": "command &",
    "ast": {
        "type": "command",
        "background": true,
        "name": "command",
        "args": []
    }
}
```

### 8. 多管道

**输入**: `cat log.txt | grep error | tail -10`

**输出**:
```json
{
    "raw": "cat log.txt | grep error | tail -10",
    "ast": {
        "type": "pipe",
        "data": [
            {
                "type": "command",
                "background": false,
                "name": "cat",
                "args": ["log.txt"]
            },
            {
                "type": "command",
                "background": false,
                "name": "grep",
                "args": ["error"]
            },
            {
                "type": "command",
                "background": false,
                "name": "tail",
                "args": ["-10"]
            }
        ]
    }
}
```

## 安全检查

### 危险命令检测

```php
use App\Libs\ShellCommandParser;

// 检测是否包含危险命令
$result = ShellCommandParser::parse('rm -rf /');
$isDangerous = ShellCommandParser::hasDangerousCommand($result);
// true

// 获取所有命令名
$commands = ShellCommandParser::getCommandNames($result);
// ['rm']
```

**内置危险命令列表**:
- `rm` - 删除文件/目录
- `dd` - 磁盘操作
- `mkfs` - 文件系统格式化
- `nc` - 网络工具（可能被滥用）

**特权命令检测**:
- `sudo`, `su`, `doas` 后跟危险命令也会被检测
- 例如：`sudo rm -rf /` 会被识别为危险

### 遍历 AST

```php
// 递归遍历 AST 节点或数组
function traverseAst($ast, callable $callback): void
{
    // 如果是数组，遍历每个元素
    if (is_array($ast) && isset($ast[0])) {
        foreach ($ast as $node) {
            traverseAst($node, $callback);
        }
        return;
    }

    // 如果是单个节点，调用回调
    if (is_array($ast) && isset($ast['type'])) {
        $callback($ast);

        // 递归处理 data 字段
        $data = $ast['data'] ?? [];
        if (is_array($data)) {
            traverseAst($data, $callback);
        }
    }
}

// 查找所有 command 节点
traverseAst($result['ast'], function($node) {
    if (($node['type'] ?? '') === 'command') {
        echo "命令: {$node['name']}\n";
        if ($node['background'] ?? false) {
            echo "  后台执行\n";
        }
    }
});
```

**处理数组和节点**：
- AST 可能是数组（多个命令）或节点对象（单个命令）
- 使用 `isset($ast[0])` 判断是否是数组
- 使用 `isset($ast['type'])` 判断是否是节点

## 使用方法

### 基本解析

```php
use App\Libs\ShellCommandParser;

$result = ShellCommandParser::parse('ls -la && pwd');
print_r($result);
```

### 安全检查

```php
$result = ShellCommandParser::parse($userCommand);

// 检查危险命令
if (ShellCommandParser::hasDangerousCommand($result)) {
    throw new \RuntimeException('命令包含危险操作');
}

// 获取命令名列表
$commandNames = ShellCommandParser::getCommandNames($result);
```

### 自定义危险命令列表

```php
$customDangerous = ['rm', 'dd', 'mkfs', 'nc', 'chmod', 'chown'];

if (ShellCommandParser::hasDangerousCommand($result, $customDangerous)) {
    // 拒绝执行
}
```

### 检查特定操作符

```php
function hasOperator($ast, string $operatorType): bool
{
    // 处理数组
    if (is_array($ast) && isset($ast[0])) {
        foreach ($ast as $node) {
            if (hasOperator($node, $operatorType)) {
                return true;
            }
        }
        return false;
    }

    // 处理节点
    if (is_array($ast) && isset($ast['type'])) {
        if ($ast['type'] === $operatorType) {
            return true;
        }

        $data = $ast['data'] ?? [];
        if (is_array($data)) {
            return hasOperator($data, $operatorType);
        }
    }

    return false;
}

// 检查是否包含管道
if (hasOperator($result['ast'], 'pipe')) {
    echo "警告: 命令包含管道操作\n";
}
```

## 测试

运行测试命令：

```bash
./wind test:shell:parser
```

测试用例包括：
- 简单命令
- 连续执行（`&&`）
- 管道（`|`）
- 后台执行（`&`）
- 复杂组合
- URL 参数（正确处理引号内的 `&`）
- 危险命令
- 多管道
- 条件执行（`||`）
- 分号执行（`;`）
- 子 shell（`()`）
- 混合操作

## 特性

1. **精练的 AST 结构**: 只在实际存在多个操作数时才创建操作符节点
2. **正确处理引号**: 引号内的特殊字符（`;`, `&`, `|`, `()`）不会被误判为操作符
3. **支持转义字符**: 使用 `\` 转义的字符会被正确处理
4. **层级结构**: 完全按照 Shell 操作符优先级解析
5. **递归安全检查**: 支持子 shell 内的危险命令检测
6. **特权命令检测**: 识别 `sudo`, `su` 等特权命令后的危险操作
7. **子 shell 内容解析**: 子 shell 的内容会被递归解析为 AST

## 局限性

1. **不解析变量**: `$VAR` 和 `${VAR}` 不会被展开
2. **不支持重定向**: `>`, `<`, `>>` 等重定向操作符未被解析
3. **不支持命令替换**: `` `command` `` 和 `$(command)` 未被特殊处理

## 与 ExecTool 集成

`ShellCommandParser` 可以集成到 `ExecTool` 中作为安全检查：

```php
public function safetyCheck(string $command): void
{
    // 使用 ShellCommandParser 进行安全检查
    $parsed = ShellCommandParser::parse($command);

    // 检查危险命令
    if (ShellCommandParser::hasDangerousCommand($parsed)) {
        throw new \RuntimeException('命令包含危险操作');
    }

    // 检查特定操作符
    $ast = $parsed['ast'];
    $type = $ast['type'] ?? '';

    // 根据策略允许或禁止特定操作符
    $allowedOperators = ['and', 'pipe', 'sequence'];
    if (!in_array($type, $allowedOperators) && $type !== 'command') {
        throw new \RuntimeException('不允许的操作符: ' . $type);
    }
}
```

## AST 结构优势

### 与第一版（4层嵌套）对比

**第一版结构**：
```json
{
    "sequences": [[[[{
        "type": "command",
        "command": {"name": "ls"}
    }]]]]
}
```
❌ 强制4层嵌套（sequences → ands → ors → pipes）
❌ 简单命令也有多层空数组
❌ 难以理解和维护

**当前结构**：
```json
{
    "ast": {
        "type": "command",
        "name": "ls"
    }
}
```
✅ 扁平化，无多余嵌套
✅ 单个命令直接是对象
✅ 多个命令用数组表示

### 核心设计原则

1. **去除冗余层级**：不再有外层的 `sequence` 包装
2. **统一表示规则**：
   - 单个命令 → `{type: "command", ...}`
   - 多个命令（`;`）→ `[{命令1}, {命令2}]`
   - 操作符 → `{type: "操作符", data: [...]}`
3. **易于遍历**：检查 `is_array($ast) && isset($ast[0])` 判断是否是数组
4. **节点类型明确**：通过 `type` 字段快速识别

### 遍历示例

```php
// 处理 AST（可能是数组或节点）
function processAst($ast): void
{
    if (is_array($ast) && isset($ast[0])) {
        // 多个命令的数组
        foreach ($ast as $node) {
            processNode($node);
        }
    } else if (is_array($ast) && isset($ast['type'])) {
        // 单个节点
        processNode($ast);
    }
}

function processNode(array $node): void
{
    switch ($node['type']) {
        case 'command':
            echo "执行命令: {$node['name']}\n";
            break;
        case 'and':
            // 依次执行，遇到失败停止
            foreach ($node['data'] as $child) {
                if (!processNode($child)) {
                    break;
                }
            }
            break;
        case 'or':
            // 依次执行，遇到成功停止
            foreach ($node['data'] as $child) {
                if (processNode($child)) {
                    break;
                }
            }
            break;
        // ... 其他类型
    }
}
```

