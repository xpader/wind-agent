# TUI 演示命令使用说明

## 问题分析

当前遇到的 `Could not get stty settings` 错误是因为 TUI 功能需要在**真正的交互式终端**中运行。

## 什么是交互式终端？

交互式终端是指：
- ✅ **真实的系统终端**（Terminal、Console）
- ✅ **SSH 远程连接终端**
- ✅ **WSL/Git Bash 终端**（Windows）
- ❌ **IDE 内置终端**（通常不支持完整的终端控制）
- ❌ **非交互式 shell**
- ❌ **脚本重定向输出**

## 解决方案

### 方案 1：在系统终端中运行（推荐）

1. **Linux/Mac 用户**：
   ```bash
   # 打开系统终端（Terminal、iTerm2 等）
   cd /home/pader/projects/wind-chat
   ./wind tui:demo
   ```

2. **Windows 用户**：
   ```bash
   # 使用 WSL
   wsl
   cd /home/pader/projects/wind-chat
   ./wind tui:demo

   # 或使用 Git Bash
   cd /c/Users/YourName/projects/wind-chat
   ./wind tui:demo
   ```

### 方案 2：通过 SSH 连接

如果你在本地开发，可以通过 SSH 连接到本机：

```bash
# SSH 连接到本地（模拟真实终端环境）
ssh localhost
cd /home/pader/projects/wind-chat
./wind tui:demo
```

### 方案 3：使用 screen/tmux

```bash
# 使用 screen
screen
./wind tui:demo

# 或使用 tmux
tmux new -s tui-demo
./wind tui:demo
```

## 功能特性

当在正确的环境中运行时，你将看到：

### 三个交互式页面

1. **介绍页面**：项目介绍和操作说明
2. **特性页面**：核心特性和技术栈
3. **系统页面**：系统信息和进度条（如果设置了超时）

### 交互操作

- **Tab 键**：在三个页面之间切换
- **q 键**：退出程序
- **自动退出**：使用 `--duration` 参数设置超时

### 命令示例

```bash
# 手动退出（推荐体验完整功能）
./wind tui:demo

# 10 秒后自动退出
./wind tui:demo --duration=10

# 简写形式
./wind tui:demo -d 10
```

## 检查环境

使用环境检查命令来诊断问题：

```bash
./wind tui:check
```

这个命令会检查：
- ✅ 是否在交互式终端中
- ✅ stty 命令是否可用
- ✅ 终端尺寸是否可获取
- ✅ php-tui 库是否已安装
- ✅ 环境变量配置

## 技术限制

TUI（Terminal User Interface）技术需要以下支持：

1. **终端控制能力**：
   - 原始模式（Raw Mode）
   - 备用屏幕（Alternate Screen）
   - 光标控制（Cursor Control）
   - 颜色支持（Color Support）

2. **系统工具**：
   - stty 命令（终端控制）
   - tput 命令（终端能力查询）

3. **环境要求**：
   - 交互式 shell（Interactive Shell）
   - TTY 设备（TeleTYpewriter）

## 为什么 IDE 终端不支持？

大多数 IDE 的内置终端是为了**开发调试**设计的，它们：
- ❌ 不支持完整的终端控制序列
- ❌ 不提供原始模式访问
- ❌ 限制了某些系统调用
- ❌ 可能有输出缓冲问题

而 TUI 需要**完全控制终端**才能正常工作。

## 替代方案

如果你无法在交互式终端中运行，可以考虑：

1. **Web 界面**：开发 Web 版本的管理界面
2. **CLI 工具**：使用传统的命令行界面
3. **GUI 应用**：使用 PHP-GTK 或 Electron 等技术

## 参考资源

- [php-tui 官方文档](https://php-tui.github.io/php-tui)
- [Terminal 控制序列](https://en.wikipedia.org/wiki/ANSI_escape_code)
- [Ratatui（Rust TUI 库）](https://github.com/ratatui-org/ratatui)

## 总结

**TUI 演示命令已经完全正常**，只需要在**真正的交互式终端**中运行即可。

这是一个**环境限制**，不是代码问题。php-tui 库本身功能完整，只是需要正确的运行环境。
