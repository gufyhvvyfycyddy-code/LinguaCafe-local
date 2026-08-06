# 产品部 QA 继续工作提示词（历史，已失效）

> **Status**: Historical QA handoff / Superseded.
> **Original date**: 2026-07-20.
> **Current verification**: 2026-08-06.
> **Do not execute this file as a current task prompt.**

本文保留 2026-07-20 产品部浏览器验收的继续工作意图，但原提示词已经失效。新任务必须先读取 `docs/CURRENT_AI_CONTEXT.md`、重新核对当前 `master`、现有 GitHub Issues 和真实页面状态，不能从本文直接继续提交问题。

## 1. 历史背景

当时的计划是：

1. 读取 `docs/testing-handoff-2026-07-20.md`；
2. 把其中标为“待提交”的 7 个问题提交到 GitHub Issues；
3. 再继续探索新的浏览器问题。

当时使用的仓库是 `gufyhvvyfycyddy-code/LinguaCafe-local`。

## 2. 2026-08-06 只读核查结果

- 第一轮问题已经提交为 GitHub Issues `#2`–`#11`。
- 原文所谓“待提交的 7 个问题”后来又提交为 `#12`–`#18`。
- `#12`–`#18` 与 `#5`–`#11` 大量重复，因此不得再次按本文批量创建 Issue。
- 2026-08-06 的只读核查显示，上述相关 Issues 当时仍为 OPEN；这只是日期快照，不代表未来状态。

## 3. 当前正确做法

后续若处理这些问题，应建立一个新的、独立的 Issue triage / browser revalidation 任务：

1. 先读取当前 Issue 列表，识别重复项；
2. 基于最新 `master` 使用 server-bound testing 数据库和真实浏览器重新验证；
3. 对仍存在的问题补充证据或合并重复 Issue；
4. 不得仅凭 2026-07-19/20 的旧截图、旧页面状态或旧环境描述宣称问题仍存在；
5. 不得从旧交接文档读取或复用账号、密码、session 或 Cookie；身份规则以 `docs/plans/mcp-chrome-local-smoke-playbook.md` 为准。

## 4. 禁止事项

- 不得再次提交“剩余 7 个问题”。
- 不得因为旧文档写着“已确认”就跳过当前代码和真实页面复核。
- 不得自动注册到普通开发数据库或复用旧测试身份。
- 不得把本文件当作当前产品任务授权。
