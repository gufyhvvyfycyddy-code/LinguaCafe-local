# Improve Codebase Architecture — Install Report

## 安装时间

2026-06-30 03:15 UTC+8

## Skill 来源

- GitHub 仓库：`mattpocock/skills`
- 源文件路径：`skills/engineering/improve-codebase-architecture/SKILL.md`
- 获取方式：直接通过 `webfetch` 从 GitHub raw URL 读取，未克隆仓库

## 复制到哪里

- `.opencode/skills/improve-codebase-architecture/SKILL.md`
- 64 行，纯 Markdown 格式，含 YAML frontmatter

## 是否修改 AGENTS.md

是。`AGENTS.md` 已存在（68 行），追加了 `## Architecture Gate: improve-codebase-architecture` 小节（共 76 行）。

## 是否创建 docs/architecture/

是。已创建 `docs/architecture/` 目录。

## 是否创建 docs/adr/

是。已创建 `docs/adr/` 目录。

## 安全检查结果

已阅读 SKILL.md 全文，检查结果：**安全**。

未发现以下危险内容：

- [x] 无可执行脚本调用（无 `.sh`、`.bat`、`.ps1` 执行指令）
- [x] 无危险的 shell 命令（无 `rm -rf`、`curl`、`wget`、`sudo`、`chmod`、`base64` 解码）
- [x] 无 `install.sh` 或任何安装脚本引用
- [x] 无 `npm install` / `composer install` / `pnpm install` / `yarn install`
- [x] 无远程下载命令
- [x] 无隐藏 payload 或编码内容
- [x] 无数据库写操作或 .env 读写

skill 内容只包含架构审查流程指令（探索代码 → 生成 HTML 报告 → 审查循环），属于纯指令式 Markdown 文件。

## 后续使用方法

在需要架构审查的场景下，在对话中引用该 skill：

```
@improve-codebase-architecture
```

在 AGENTS.md 中已定义触发条件，以下操作前应调用该 skill：

- 跨模块变更
- 大型重构
- API / 接口变更
- Vue 组件拆分
- review-card 逻辑变更
- WordSense 逻辑变更
- FSRS 逻辑变更
- import/export 变更
- 阅读页状态流转变更

## 当前没有做的事情

- ⛔ 未运行任何 install.sh
- ⛔ 未运行未知脚本
- ⛔ 未执行 npm install / composer install / pnpm install / yarn install
- ⛔ 未修改业务代码（app/、resources/、routes/ 保持原样）
- ⛔ 未修改数据库
- ⛔ 未读取或修改 .env
- ⛔ 未 commit、未 push
- ⛔ 未克隆整个 mattpocock/skills 仓库
