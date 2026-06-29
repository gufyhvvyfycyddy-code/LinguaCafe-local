# Night-Convergence-Lab-1: 夜间架构收敛实验

> **实验分支**：`convergence/night-lab-20260630-044925`
> **基于 master**：`1f9700f`（docs: add MCP visual validation rules）
> **实验性质**：多轮自动收敛，安全分支，不 push，不 merge
> **最大轮次**：3 轮

---

## 1. 实验目标

在安全分支中寻找 LinguaCafe 当前最适合收敛的低风险复杂度：

1. 删除优先于新增
2. 收敛优先于扩展
3. 保留最小闭环能力
4. 不为了未来感保留伪扩展点
5. 不为了代码行数减少破坏功能

## 2. 当前最小闭环

LinguaCafe 阅读体验最小闭环：

1. 阅读页（TextReader.vue / TextBlockGroup.vue）— 显示英文原文
2. 点词查词栏（VocabularySideBox.vue）— 显示词典 + AI 建议 + 释义管理
3. 添加释义（AddSenseForm.vue / AiSuggestionPanel.vue）— 不创建学习数据
4. Python smoke guard — 保护前 3 项核心行为
5. MCP 视觉验证 — 真实浏览器验收

## 3. 本轮最多 3 次迭代

| 轮次 | 名称 | 范围 | 预计修改 | 验证方式 |
|------|------|------|----------|----------|
| R1 | Bookkeeping 基线更新 | docs only | roadmap / master plan Latest commit | git diff, status |
| R2 | 验证层文档收敛 | docs only | smoke-guard 关系表 | git diff, status |
| R3 | 只读架构扫描 | docs only | 收敛候选写入 | git diff, status |

## 4. 每轮选择标准

- 文档修正 / commit 更新 → 低风险 → 自动执行
- 纯 helper 注释修正 → 低风险 → 谨慎执行
- 重复常量 / 纯函数收敛，不碰 TextBlockGroup → 中风险 → 必须验证
- UI 展示层小型提取 → 中风险 → 必须 smoke + MCP 验证
- 其余 → 停止

## 5. 禁止区域

绝对禁止修改：

| 区域 | 文件 |
|------|------|
| TextBlockGroup.vue | `resources/js/components/Text/TextBlockGroup.vue` |
| VocabularyBox.js | `resources/js/components/Text/VocabularyBox.js` |
| WordSense 逻辑 | `app/Services/WordSenseService.php`, `app/Models/WordSense.php` |
| ReviewCard 逻辑 | `app/Services/ReviewCardService.php`, `app/Models/ReviewCard.php` |
| FSRS 调度 | `app/Services/FsrsSchedulingService.php` |
| AI lookup | `app/Services/AiAssistService.php` |
| source context | `app/Services/SourceContextService.php` |
| import/export | `app/Http/Controllers/ChapterController.php`, 导入相关 |
| database schema | `database/migrations/` |
| routes | `routes/` |
| backend endpoint | `app/Http/Controllers/`（业务控制器） |
| auth / user | `app/Models/User.php`, `app/Http/Controllers/Auth/` |
| tokenizer | `app/Services/TokenizerService.php` |
| Lemma-Origin | `app/Services/LemmaService.php` |
| AI-Reading-Assist-6 | 任何与词组识别相关 |
| review scheduling | `app/Services/ReviewSchedulingService.php` |

## 6. 验证规则

| 修改类型 | 必须验证 |
|----------|----------|
| 只改文档 | git diff --check, git diff --stat, git status |
| 改 JS/Vue/CSS/smoke 脚本 | npm run development + smoke guard |
| 改 reader 可观察行为 | Python smoke + MCP 视觉验收 + Network POST 检查 |
| 改禁止区域 | ❌ 不允许修改 |

## 7. 停止条件

出现以下任一情况，立即停止：

1. 工作区 dirty 且无法解释
2. 需要修改禁止文件
3. 需要修改 TextBlockGroup
4. 需要修改 Vuex
5. 需要修改后端业务逻辑
6. 需要新增依赖
7. 需要清库或迁移
8. smoke 失败无法归因
9. MCP 视觉验收失败且超出本轮范围
10. 出现账号密码泄漏
11. 一轮修改超过 3 个代码文件
12. 一轮需要改超过 5 个文件总数
13. 产生超过 300 行代码 diff
14. 模型不确定是否安全
15. 已完成 3 轮

## 8. 回滚条件

- 任一轮验证失败且无法修复 → `git reset --hard HEAD~1`
- 任一轮 diff 超过 300 行 → `git checkout .` 回退
- 任一轮误改禁止区域 → `git checkout -- <file>` 恢复

## 9. 每轮记录模板

### R<N> 设计

- **目标**：...
- **允许修改文件**：...
- **禁止修改文件**：...
- **预计收益**：...
- **验证方式**：...
- **执行结果**：...
- **diff 文件**：...
- **commit**：...
- **是否通过**：...

---

## R1 设计

- **目标**：更新 roadmap / master plan 中过期 Latest commit，记录本轮实验存在
- **允许修改文件**：
  - `docs/plans/linguacafe-fsrs-roadmap.md`
  - `docs/plans/linguacafe-master-plan.md`
  - `docs/convergence/night-convergence-lab-1.md`（本文档本身）
- **禁止修改文件**：所有其他文件
- **预计收益**：消除文档与 git HEAD 的不一致，为后续轮次提供准确基线
- **验证方式**：git diff --check, git diff --stat, git status
- **执行结果**：
  - 更新 roadmap Latest commit：`c468d6b` → `1f9700f` ✅
  - master plan 新增 Night-Convergence-Lab-1 记录 ✅
  - roadmap 新增 Night-Convergence-Lab-1 记录 ✅
  - git diff --check：无实质错误 ✅
  - git diff --stat：3 文件，3 insertions ✅
  - 敏感内容搜索：无泄漏 ✅
  - **通过** ✅

## R2 设计

- **目标**：整理 Python smoke 与 MCP visual 的验证层关系表格，增强可读性
- **允许修改文件**：
  - `docs/testing/text-reader-smoke-guard.md`
  - `docs/convergence/night-convergence-lab-1.md`
- **禁止修改文件**：所有代码文件、脚本文件
- **预计收益**：让后续开发者清晰知道何时跑什么验证
- **验证方式**：git diff --check, git diff --stat, git status
- **执行结果**：
  - 新增验证层选择表（7 种修改类型 × 3 种验证方式） ✅
  - 维护了 smoke-guard.md 结构完整性 ✅
  - git diff --check：无实质错误 ✅
  - git diff --stat：2 文件 ✅
  - 敏感内容搜索：无泄漏 ✅
  - **通过** ✅

## R3 设计

- **目标**：只读架构扫描，找出下一轮低风险收敛候选
- **允许修改文件**：
  - `docs/convergence/night-convergence-lab-1.md`（仅添加扫描结果）
- **禁止修改文件**：所有其他文件
- **预计收益**：为后续人工决策提供架构数据
- **验证方式**：git diff --check, git diff --stat, git status
- **执行结果**：

---

*本文档先于所有代码修改创建。*
