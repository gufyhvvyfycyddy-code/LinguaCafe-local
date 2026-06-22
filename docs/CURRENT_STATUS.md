# LinguaCafe 本地改造项目 — 当前完成状态

> 最后更新：2026-06-22

## 环境摘要

| 项目 | 状态 |
|------|------|
| PHP | 8.2.31 (CLI, ZTS, VS C++ 2019 x64) |
| MariaDB | 12.3 (`C:\Program Files\MariaDB 12.3`)，端口 3306 |
| 数据库 | `linguacafe_fsrs`，连接正常 |
| .env | `APP_ENV=local`, `DB_HOST=127.0.0.1`, `BROADCAST_DRIVER=log` |
| 前端构建 | Laravel Mix 6.0.49，webpack 编译成功 |
| Git | 分支 master，upstream = `simjanos-dev/LinguaCafe.git` |
| 测试 | ReviewFsrsTest (5/5)、FsrsSchedulingServiceTest (3/3)、WordSense (50/50) 全绿 |

## Git 最近提交

```
8d58193 fix Windows database startup check
22dc35a verify and fix FSRS review flow
8af432e fix local pusher fallback and restore web registration
7b92bd1 restore first-user setup page
7375e2c fix vocabulary cleanup and word deletion workflow
615e880 complete remaining Chinese UI translations
5553df4 verify and fix text import flow
7acec2d fix ProcessChapter vocabulary service resolution
3a1b2ff fix text import tokenizer startup and English fallback
b13193e fix global loading states and complete Chinese UI
3cd9e68 fix language selector and add Chinese UI defaults
```

## 已完成功能

### 基础环境
- [x] Windows 本地运行适配（PHP 8.2, MariaDB, Composer, Node, npm）。
- [x] `.env` 配置：本地数据库、Pusher 降级为 log driver。
- [x] 前端 webpack 编译成功（Sass 有 335 个 deprecation warning，不影响功能）。

### tokenizer & 导入
- [x] Python tokenizer（`tools/tokenizer.py`，端口 8678）及 Windows 启动/诊断脚本。
- [x] 英文基础 fallback：tokenizer 不可用时仍能导入英文纯文本（简单切句、分词、小写 lemma）。
- [x] 英文材料导入可用（前端带失败兜底，不无限 loading）。

### 阅读页 & 点词
- [x] 阅读页可打开（`TextReader.vue` + `TextBlockGroup.vue`）。
- [x] 点词侧栏可打开（`VocabularySideBox.vue`）。
- [x] 侧栏词典查询正常（ECDICT 本地词典 30000 条 + API 词典）。
- [x] Pusher/websocket 本地降级已处理。

### 词汇管理
- [x] 词汇垃圾 token 过滤、忽略、软删除已完成。
- [x] `user:create` 命令（创建本地用户，初始化 `uiLanguage=zh-CN`，`selected_language=english`）。

### FSRS
- [x] Word FSRS Review：Again/Hard/Good/Easy，review_cards + review_logs。
- [x] Sense FSRS Review：独立页面 `/reviews/senses`，sense card 独立复习。
- [x] WordReview 队列保持 word-only（不混入 sense card）。

### Sense Mapping
- [x] `word_senses` 和 `word_sense_occurrences` 数据表及模型。
- [x] Sense Mapping Review 页面（`/senses/review`）：确认、改绑、新建词义、拒绝、忽略。
- [x] 批量操作：批量确认（含高置信度）、批量忽略、批量拒绝。
- [x] Possible duplicates 提示。
- [x] GPT sense-mapping package 生成（Markdown + JSON）。
- [x] GPT workflow 命令：prepare / validate-latest / import-latest / doctor。
- [x] Windows bat 脚本 + Quicker 半自动串联。

### 界面
- [x] 中文界面优先（`uiLanguage=zh-CN`）。
- [x] 登录页、注册页、导航、学习语言选择、阅读导入、书库、阅读页、词汇页、Review 页面均已覆盖中文文案。

### 注册
- [x] 网页注册入口已恢复（`/register` 路由可用）。

## 未完成功能

- [ ] **阅读页点词后，还不能显示已有 word_senses。**
- [ ] 词典释义还不能直接保存为 word_sense。
- [ ] 当前句子里的词还不能在阅读页直接绑定到某个词义 occurrence。
- [ ] 阅读材料页面还没有清晰的网页按钮导出 GPT 词义包。
- [ ] 网页端还没有完整上传 sense-mapping.json → 校验 → 预览导入 → 正式导入流程。
- [ ] 颜色逻辑还没整理成 LingQ 式：新词蓝色、学习中黄色、已知无高亮、忽略无高亮。
- [ ] 浏览器 smoke test 还没建立。
- [ ] phrase FSRS 未启用。
- [ ] ChatGPT 网页端自动控制（明确不做）。

## ECDICT 完整词典

| 项目 | 状态 |
|------|------|
| 词典名 | `ECDICT EN-ZH` |
| 表名 | `dict_en_ecdict_full` |
| 预期条数 | ~768,739 |
| 词典类型 | 本地 `custom_csv`，english→chinese |
| 导入命令 | `php artisan dictionary:import-ecdict` |
| 检查命令 | `php artisan dictionary:import-ecdict --status` |
| 强制重建 | `php artisan dictionary:import-ecdict --force` |
| CSV 路径 | `C:\Users\Administrator\Desktop\linguacafe\linguacafe_ecdict_en_zh_pipe.csv` |

> **重要**：词典是数据库运行时数据，不会被 git 保存。以下操作会导致词典消失：
> - 运行 `php artisan test`（`RefreshDatabase` 的 `migrate:fresh` 会删除非 migration 表）
> - 执行 `php artisan migrate:fresh` 或 `migrate:refresh`
> - 手动删除 `dict_en_ecdict_full` 表
> - 切换或重建数据库

**恢复方法**：
```bash
# 先检查状态
php artisan dictionary:import-ecdict --status

# 如果缺失，重新导入（默认跳过已健康存在的词典）
php artisan dictionary:import-ecdict

# 如果表存在但条数异常，强制重建
php artisan dictionary:import-ecdict --force
```

**测试隔离**：已创建 `.env.testing` 使用独立数据库 `linguacafe_fsrs_test`，防止测试清除主库词典。

## 已知风险

1. `fsrs-rs-php` 原生扩展在当前 Windows 环境未编译加载（使用 PHP fallback，功能正常但非原生性能）。
2. Sass deprecation warning（`darken()` → `color.adjust()` 等）共 335 条，来自 Bootstrap/Vuetify 旧版，不影响功能，暂不处理。
3. `scripts/windows/linguacafe-start.bat` 有未提交修改（移除了 tokenizer 自动检查启动逻辑，简化了端口检测 PowerShell 命令）。
4. 全量 Laravel 旧测试（非 FSRS/Sense 相关）可能仍有 Auth/首页相关失败，未逐一排查。
5. `matched_sense_id` 在 GPT workflow 中依赖 package 里导出的 `sense_id`；如果用户用 demo JSON 可能指向不存在的 ID。
6. 词典数据不在 git 版本控制内；测试后可能需重新导入 `php artisan dictionary:import-ecdict`。

## 数据库自愈

以下组件若缺失，系统会自动修复：

| 组件 | 缺失症状 | 自愈方式 |
|------|----------|----------|
| `reviewIntervals` setting | 保存词汇等级失败 | `EncounteredWord::setStage()` 自动运行 `SettingsSeeder` |
| Goals（review/read_words/learn_words） | 保存词汇等级失败 | `GoalService::updateGoalAchievement()` 自动调用 `createGoalsForLanguage()` |

手动检查：
```bash
php artisan db:doctor           # 检查 settings / goals / ECDICT / 测试隔离
php artisan db:doctor --fix     # 检查并自动修复
```

## 保护清单（不可破坏）

- 英文导入、tokenizer English fallback。
- 阅读页 + 点词侧栏 + 词典。
- Word Review / Sense Review 及对应测试。
- GPT sense-mapping workflow（prepare → validate → dry-run → import）。
- 注册、登录、用户创建。
- Pusher 本地降级（`BROADCAST_DRIVER=log`）。
- 现有 FSRS/Sense 测试必须全绿。
- `php artisan test` 必须使用独立测试数据库，不得清空开发数据库。
- 保存 Learning 词不得因 settings/goals 缺失而崩溃。
