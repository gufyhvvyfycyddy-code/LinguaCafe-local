# LinguaCafe Codex 交接报告

> 最后更新：2026-06-22

## 当前最新 commit

```
9cd786c add manual lemma-based multi-sense management
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

### 多释义管理 UI（本次修改，commit 9cd786c + 未提交改动）
- [x] 右侧点词面板分为 5 个区域：单词基础信息、普通词汇状态、释义(旧)、词典结果、词元释义
- [x] 词元释义按词性分组显示（v-expansion-panels，每组可展开/收起）
- [x] 已保存释义显示 FSRS 下次复习时间和学习状态
- [x] "添加新释义"表单：词性下拉、中文释义、英文解释、例句、近义译法、搭配
- [x] 词典结果每条旁 "+ 添加为新释义"按钮，可预填释义内容
- [x] "编辑该释义"按钮可编辑已有释义的 pos/中文/英文/近义译法/搭配
- [x] 旧词条释义（EncounteredWord.translation）在词元释义卡片中显示
- [x] 面板内容超出时可滚动（overflow-y: auto）
- [x] 英文 fallback tokenizer 词性推断（n./v./adj./adv. 等前缀匹配）

## 本次 UI 改动的组件

| 文件 | 改动类型 |
|------|----------|
| `resources/js/components/Text/WordSensesList.vue` | 重写（v-expansion-panels 分组、编辑/新增表单、FSRS 状态显示） |
| `resources/js/components/Text/VocabularySideBox.vue` | 中改（重构单词基础信息卡片、添加区域标题、修复事件传递和滚动） |
| `resources/js/components/Text/VocabularySearchBox.vue` | 中改（"+ 添加为新释义"按钮、词性推断、行布局） |
| `resources/js/components/Text/VocabularyBox.vue` | 小改（passthrough 事件） |
| `resources/sass/Text/VocabularySideBox.scss` | 小改（overflow-y: auto） |
| `app/Http/Controllers/SenseOccurrenceController.php` | 小改（FSRS 详细字段） |

## 右侧面板区域

```
1. 单词基础信息
   - 当前词形、词元、发音按钮、关闭按钮

2. 普通词汇状态
   - 等级按钮 1-7、已知(✓)、忽略(✗)
   - 忽略 / 标为已知 / 删除词条

3. 释义（旧）
   - 翻译 textarea（兼容旧 EncounteredWord.translation）
   - 词典搜索 input

4. 词典结果
   - ECDICT 查询结果
   - 每条结果：点击添加翻译 + "+ 添加为新释义"

5. 词元释义
   - 词元、词形、旧词条释义
   - 按词性分组展开面板
   - 每个义项：已保存标签、中文释义、下次复习、状态、编辑按钮
   - "+ 添加新释义"按钮 → 展开表单
```

## 仍未完成

- [ ] 手动多释义管理（一个词多个 sense 的 UI 管理界面）—— 基本完成，需浏览器验收
- [ ] 点正在学习的词时先复习其所有到期 sense card，依次弹出复习卡，全部答完后再进入查词/释义管理界面
- [ ] 添加新释义时即时生成新 sense card（当前通过 `/senses/review` 确认后才生成）—— 已通过 `/senses/manual` 直接创建
- [ ] 阅读页内嵌 GPT 词义包导出按钮
- [ ] 网页端 sense-mapping.json 上传→校验→预览→导入完整流程
- [ ] 颜色逻辑（新词蓝、学习中黄、已知无高亮、忽略无高亮，LingQ 风格）
- [ ] Phrase FSRS
- [ ] 非英文材料完整支持
- [ ] 浏览器 smoke test 全自动化
- [ ] "查看例句"功能（当前按钮为 disabled 占位）

## 导入格式问题：真实根因和最终修法

### 根因
导入文本的段落结构标记（`PARAGRAPH_BREAK`、`NEWLINE`、`_SECT_A_`）被 tokenizer 拆散为多个 token，导致结构信息丢失且标记字符串泄漏到用户可见文本。

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

1. **浏览器验收**：真实浏览器验证右侧面板滚动、释义添加/编辑、词典添加为新释义
2. **点词复习流程**：点正在学习词时，先检查该 lemma 下所有到期释义卡，依次弹出复习卡，全部答完后再进入查词/释义管理界面
3. **颜色整理**：新词/学习中/已知/忽略的可视化区分
4. **浏览器自动化测试**：用 Playwright 覆盖导入→阅读→点词→保存→review 全链路
5. **导入格式完整性**：确认所有新导入文章保留原始段落结构

## 必须保留的规则（不可破坏）

1. 不要破坏 FSRS 调度核心和 Word/Sense Review
2. 不要破坏 Vocabulary → WordSense → WordSenseOccurrence → Sense Review 链路
3. 不要把结构 token（`PARAGRAPH_BREAK`、`NEWLINE`、`[A]`-`[Z]`）当普通词渲染或加入词汇表
4. 不要执行 `migrate:fresh` 清空用户数据
5. 不要修改测试数据库隔离（`.env.testing`）
6. 不要提交完整 ECDICT CSV 到 git
7. 不要破坏英文导入和 tokenizer fallback
8. 不要把手动释义送入 `/senses/review` pending（手动释义通过 `/senses/manual` 直接创建为 confirmed）
