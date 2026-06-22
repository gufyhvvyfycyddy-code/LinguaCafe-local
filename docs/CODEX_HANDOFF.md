# LinguaCafe Codex 交接报告

> 最后更新：2026-06-22

## 当前最新 commit

```
82bc6d1 fix imported text paragraph structure rendering
```

（注：如果此后有新提交，以 `git log --oneline -1` 为准。）

## 已完成功能

### 基础环境
- [x] Windows 本地运行（PHP 8.2, MariaDB 12.3, `linguacafe_fsrs` 库）
- [x] Python tokenizer + 英文 fallback
- [x] `.env.testing` 隔离测试数据库（`linguacafe_fsrs_test`）

### 阅读 & 词汇
- [x] 英文纯文本导入（保留段落、`[A]-[Z]` 段落标记）
- [x] 阅读页点词、词典查询（ECDICT 完整词典 768,739 条）
- [x] Learning 词自动创建 FSRS Word Card + WordSense + WordSenseOccurrence
- [x] 词汇管理（忽略、删除、批量操作、CJK token 过滤）

### FSRS
- [x] Word Review（Again/Hard/Good/Easy）
- [x] Sense Review（`/reviews/senses`）
- [x] `fsrs:doctor` 检查/补全缺失 review card

### Sense Mapping
- [x] `/senses/review` 词义确认（确认/改绑/新建/拒绝/忽略/批量）
- [x] Vocabulary → WordSense → WordSenseOccurrence → Sense Review 完整链路
- [x] GPT sense-mapping 命令行（prepare / validate / import）

### 数据库自愈
- [x] `db:doctor`：检查 settings、goals、ECDICT、测试隔离
- [x] `setStage()` 缺失 `reviewIntervals` 时自动运行 `SettingsSeeder`
- [x] `GoalService` 缺失 goals 时自动创建

## 仍未完成

- [ ] 手动多释义管理（一个词多个 sense 的 UI 管理界面）
- [ ] 点正在学习的词时先复习其所有到期 sense card
- [ ] 添加新释义时即时生成新 sense card（当前通过 `/senses/review` 确认后才生成）
- [ ] 阅读页内嵌 GPT 词义包导出按钮
- [ ] 网页端 sense-mapping.json 上传→校验→预览→导入完整流程
- [ ] 颜色逻辑（新词蓝、学习中黄、已知无高亮、忽略无高亮，LingQ 风格）
- [ ] Phrase FSRS
- [ ] 非英文材料完整支持
- [ ] 浏览器 smoke test 全自动化

## 导入格式问题：真实根因和最终修法

### 根因
导入文本的段落结构标记（`PARAGRAPH_BREAK`、`NEWLINE`、`_SECT_A_`）被 tokenizer 拆散为多个 token，导致结构信息丢失且标记字符串泄漏到用户可见文本。

具体：
1. 标记 `PARAGRAPH_BREAK` 经 fallback tokenizer 被拆为 `PARAGRAPH` + `_` + `BREAK`
2. 标记 `_SECT_A_` 同理被拆散
3. 前端 `isSectionMarker()` 长度判断为 7 而非正确的 8
4. 旧的 `PARAGRAPH_BREAK` 渲染 `<div>` 高度为 0，CSS margin 塌陷导致段落间距不可见

### 最终修法（方案 A：预处理分块 + 安全标记）
1. `tokenizeRawText()` 将换行和 `[A]-[Z]` 转换为 tokenizer **安全标记**（全大写字母：`ZZPARAZZ`、`ZZNEWLZZ`、`ZZSECTxZ`）
2. 安全标记不会被任何 tokenizer 拆散（纯字母，无下划线/标点）
3. `mapStructuralTokens()` 在 tokenize 之后将安全标记替换为显式结构 token（`pos = 'STRUCT'`，`w = 'PARAGRAPH_BREAK'` / `'[A]'` 等）
4. `processTokenizedWords()` 设置 `is_structure = true`
5. 前端通过 `word.is_structure` 和 `word.word` 可靠识别并渲染结构标记
6. `VocabularyTokenFilter` 跳过所有结构标记格式（新旧格式 + 安全标记兜底）

## 本地环境注意事项

### ECDICT 词典
- 完整词典是数据库运行时数据，**不在 git 版本控制内**
- 恢复：`php artisan dictionary:import-ecdict`
- 检查：`php artisan dictionary:import-ecdict --status`
- 强制重建：`php artisan dictionary:import-ecdict --force`

### 测试数据库隔离
- **`php artisan test` 必须使用独立 testing 数据库**
- `.env.testing` 指向 `linguacafe_fsrs_test`
- 首次使用前需创建测试库：`CREATE DATABASE IF NOT EXISTS linguacafe_fsrs_test`
- 检查：`php artisan db:doctor`

### db:doctor 检查内容
| 检查项 | 说明 |
|--------|------|
| Environment | `APP_ENV`、`DB_DATABASE`、`.env.testing` 隔离 |
| Settings | `reviewIntervals` 是否存在 |
| Goals | user 1 english 的 review / read_words / learn_words |
| ECDICT | 表存在、条数 ≥ 700,000、元数据 |

### 自愈机制
- 保存 Learning 词时若 `reviewIntervals` 缺失 → 自动运行 `SettingsSeeder`
- 保存 Learning 词时若 goals 缺失 → 自动创建
- `db:doctor --fix` 可手动修复

## 下一步建议

1. **阅读页已有词义显示**：当前点词不显示已确认的 word_senses
2. **多释义管理 UI**：一个词可有多个 sense，需要界面管理
3. **浏览器自动化测试**：用 Playwright 覆盖导入→阅读→点词→保存→review 全链路
4. **颜色整理**：新词/学习中/已知/忽略的可视化区分
5. **导入格式完整性**：确认所有新导入文章保留原始段落结构

## 必须保留的规则（不可破坏）

1. 不要破坏 FSRS 调度核心和 Word/Sense Review
2. 不要破坏 Vocabulary → WordSense → WordSenseOccurrence → Sense Review 链路
3. 不要把结构 token（`PARAGRAPH_BREAK`、`NEWLINE`、`[A]`-`[Z]`）当普通词渲染或加入词汇表
4. 不要执行 `migrate:fresh` 清空用户数据
5. 不要修改测试数据库隔离（`.env.testing`）
6. 不要提交完整 ECDICT CSV 到 git
7. 不要破坏英文导入和 tokenizer fallback
