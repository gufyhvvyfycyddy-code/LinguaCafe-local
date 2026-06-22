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
