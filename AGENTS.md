# AGENTS.md — LinguaCafe 本地改造项目长期规则

## 上游参考

- 上游仓库：<https://github.com/simjanos-dev/LinguaCafe.git>
- 旧功能出问题时，**优先对照 upstream 原代码**，能直接恢复/照抄 upstream 逻辑就不要自己重写。

## 核心原则

1. **稳定可用优先**，不追求一次性大改。
2. **能抄就抄**：遇到 upstream 已有逻辑，直接 copy 或恢复，不另起炉灶。
3. **不新增无关功能**：只做和当前学习流程直接相关的改造。
4. **不大范围重构**：保护原项目导入、阅读、章节、Pusher、词汇扫描、tokenizer 主流程。
5. **每次只做一个明确任务**。

## 范围限定

### 只做
- 英文学习 / 英文材料导入 / 英文阅读 / 英文词典 / 英文 FSRS / 英文 GPT sense-mapping。

### 不做
- DeepL 集成。
- 非英文语言（日语、中文等）的材料处理。
- 全站深度汉化。
- 自动控制 ChatGPT 网页端、自动发送、自动下载。
- phrase FSRS（当前只做 word 和 sense）。

### 自动化边界
- 自动化只做到：网页导出 GPT 包 → 上传 sense-mapping.json → 校验 → 预览导入 → 正式导入。
- GPT 输出仍需人工在 ChatGPT 网页端操作获得。

## 受保护模块

以下模块已有稳定功能，**修改时必须保持通过测试**，不破坏现有行为：

- 英文材料导入（`/chapters` 导入流程）。
- 阅读页（`TextReader.vue`、`TextBlockGroup.vue`）。
- 点词侧栏（`VocabularySideBox.vue`、`VocabularyBox.vue`、`VocabularySearchBox.vue`）。
- 词典查询（`DictionaryController`、ECDICT 本地词典）。
- Word FSRS Review（`ReviewController`、`ReviewFsrsTest`）。
- Sense FSRS Review（`SenseReviewController`、WordSense 相关测试）。
- GPT sense-mapping workflow（prepare / validate / dry-run / import）。
- 注册（网页注册入口已恢复）。
- 本地 tokenizer / fallback（tokenizer.py + 英文基础 fallback）。
- Pusher/websocket 本地降级（`BROADCAST_DRIVER=log`）。

## 代码风格

- 匹配项目已有风格：Laravel 控制器 + Service 层 + Vue 2 + Vuex + Vuetify。
- 中文注释仅在必要时添加，不批量翻译已有注释。
- 参考现有测试（`tests/Feature/`、`tests/Unit/`）的写法。

## 测试要求

- 每次改动后运行：
  ```bash
  php artisan test --filter=ReviewFsrsTest
  php artisan test --filter=FsrsSchedulingServiceTest
  php artisan test --filter=WordSense
  npm run development
  ```
- 三项测试必须全绿。前端构建必须成功。

## Git 规则

- 不做 force push。
- 不改动 upstream 的 git 历史。
- commit message 用英文描述做了什么（沿用 Codex 的 `fix:`, `feat:`, `docs:` 前缀风格）。

## Architecture Gate: improve-codebase-architecture

Before any cross-module change, large refactor, API/interface change, Vue component split, review-card logic change, WordSense logic change, FSRS logic change, import/export change, or reader-page state-flow change, run:

- improve-codebase-architecture

The architecture review must identify module responsibilities, boundaries, coupling, risks, allowed files, forbidden files, tests, and whether an ADR is required.

## Architecture and Engineering Skills

This project uses project-level OpenCode skills under `.opencode/skills/`.

Installed skills:

1. `improve-codebase-architecture`
   - Use before cross-module changes, large refactors, component splits, architecture repair, and module-boundary reviews.

2. `context-engineering`
   - Use at the start of a session, when switching tasks, when the task has too much context, or when output quality drops.

3. `api-and-interface-design`
   - Use before changing APIs, module interfaces, Vue props/events, Vuex/store contracts, backend endpoints, request/response payloads, validation rules, or public module boundaries.

4. `documentation-and-adrs`
   - Use when making architectural decisions, changing APIs, changing data models, changing module boundaries, or shipping features whose rationale must be preserved.

5. `doubt-driven-development`
   - Use for high-risk decisions, confident but unverified plans, production-sensitive logic, unfamiliar code, irreversible changes, or changes involving core review-card / WordSense / FSRS / import-export logic.

6. `code-review-and-quality`
   - Use before considering any task complete, especially before commit or handoff.

## Required Workflow for High-Risk Tasks

For high-risk tasks, use skills in this order:

1. `context-engineering`
2. `improve-codebase-architecture`
3. `api-and-interface-design` if any interface, API, store, component contract, or data contract is touched
4. `documentation-and-adrs` if the decision affects architecture, data model, or long-term maintenance
5. `doubt-driven-development` for adversarial review before implementation
6. implementation only after user confirmation
7. `code-review-and-quality` after implementation

## High-Risk Areas

The following areas require architecture review before coding:

- `TextBlockGroup.vue`
- `VocabularySideBox.vue`
- `WordSensesList.vue`
- reader-page state flow
- Vuex/store logic
- WordSense
- ReviewCard
- FSRS
- AI lookup
- sense-only review
- import/export flow
- backend endpoints
- database migrations
- review scheduling
- source context / original chapter location logic

## Stop Rules

Stop and ask for confirmation before coding if:

- The task touches more than one architectural boundary.
- The task requires a new service, controller, store module, database table, or migration.
- The task changes API semantics or payload shape.
- The task changes review scheduling, FSRS behavior, WordSense binding, or AI lookup.
- The architecture review says ADR is required.
- The planned file list grows beyond the approved boundary.
