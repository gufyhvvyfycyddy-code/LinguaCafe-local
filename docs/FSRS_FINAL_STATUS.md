# LinguaCafe FSRS 最终状态总结

## Phase 1 到 Phase 8 完成内容

Phase 1：完成 word-only FSRS 接入。新增 `review_cards`、`review_logs`，Review 页面支持 Again / Hard / Good / Easy，初始化命令支持 dry-run，word 队列按用户和语言隔离。

Phase 2：新增 sense-level 基础层。增加 `word_senses`、`WordSenseService`、sense review card 能力、learned-senses 导出和 mapping 校验。

Phase 3：新增 `sense-mapping.json` 真实导入。增加 `word_sense_occurrences`、导入命令、dry-run、pending/bound/ignored/rejected 状态。

Phase 4：新增 Sense Mapping Review 页面和后端确认接口，支持确认、改绑、新建词义、拒绝和忽略。

Phase 5：新增独立 Sense Review 页面。原 Review 页面继续只复习 word card，Sense Review 只复习 sense card。

Phase 6：新增批量确认、批量忽略、批量拒绝、高置信度批量确认和 possible duplicates 提示。

Phase 7：新增 GPT work package 生成器，支持 Markdown 和 JSON package，并提供示例 mapping。

Phase 8：新增本地半自动 GPT workflow 命令、Windows 脚本和 Quicker 使用说明。

## 当前 Git commit 列表

- `03963aa phase 8: add GPT workflow scripts`
- `fce8c0c phase 7: add GPT sense package generator`
- `dc15d9c phase 6: add bulk sense occurrence workflow`
- `18adc7d phase 5: add sense FSRS review page`
- `2a6cebb phase 4: add sense mapping review UI`
- `d00c1dc phase 3: import sense mapping and create occurrences`
- `b0929fc phase 2: add sense-level review foundation`
- `c0f430a checkpoint: phase 1 word-only FSRS complete`

## 当前可用功能

- word-only FSRS Review。
- sense-level 数据层。
- learned senses 导出。
- `sense-mapping.json` 校验。
- `sense-mapping.json` dry-run 和正式导入。
- Sense Mapping Review 人工确认页面。
- 独立 Sense Review 页面。
- 批量处理 pending occurrences。
- 疑似重复 sense 提示。
- GPT package 生成。
- 本地 GPT workflow prepare / validate-latest / import-latest。
- Windows bat 脚本和 Quicker 半自动串联。
- workflow doctor 检查。

## 当前不可用功能

- phrase FSRS 未启用。
- 没有自动控制 ChatGPT 网页端。
- 没有自动上传下载。
- 没有保存账号、cookie 或浏览器会话。
- duplicate 检测只是提示，不自动合并。
- 全量 Laravel 旧测试仍可能存在 Auth / 首页相关失败。

## 当前风险

- `fsrs-rs-php` 原生扩展在部分 Windows 环境可能显示 WARN，需要按本机 PHP 版本编译加载。
- GPT 输出质量仍需 validate、dry-run 和人工确认兜底。
- 静态 demo JSON 中的 `matched_sense_id=1` 只是示例；真实使用时应以当前 package 中导出的 `sense_id` 为准。
- 词义确认页面已有批量能力，但大批量导入前仍建议先按 lemma 过滤检查。

## 下一阶段可选功能

- 前端导入向导，把 prepare、validate、dry-run、import 的结果集中展示。
- 更强的 duplicate sense 合并工作流，但仍需人工确认。
- phrase review 的独立数据层和独立页面。
- 更细的 GPT package 分片和按 lemma 过滤导出。
- 真实用户数据上的长周期 FSRS 参数观察和报表。
## 中文界面与稳定性收口

当前界面已切换为中文优先：登录页、首页、导航、学习语言选择、阅读材料导入、书库、阅读页、词汇页、Word Review、Sense Mapping Review、Sense Review 以及常用弹窗都已覆盖主要中文文案。

本次收口明确区分：

- `uiLanguage=zh-CN`：界面语言。
- `selected_language=english/japanese/...`：学习语言，用于阅读材料、词汇、Review、Sense Review 和 GPT workflow。

阅读导入和语言选择已加入失败兜底。Python tokenizer 或接口不可用时，前端会退出 loading 并显示中文错误，不再无限转圈。旧用户缺少 UI language 时，首页会补 `zh-CN`；新用户通过 `user:create` 创建时会初始化 `selected_language=english` 和 `uiLanguage=zh-CN`。

仍需注意：当前环境若 PHP 不在 PATH，则 Laravel 测试、doctor、migration 和浏览器真实后端流程无法执行。前端构建可以单独通过，但真实导入和 Review 接口仍需 PHP/Laravel 环境可用。

## 文本导入 tokenizer 状态

Python tokenizer 位于 `tools/tokenizer.py`，监听端口 `8678`。Windows 启动脚本现在会先检查 tokenizer，再启动 Laravel。

新增脚本：

- `scripts/windows/tokenizer-start.bat`
- `scripts/windows/tokenizer-stop.bat`
- `scripts/windows/tokenizer-doctor.bat`
- `scripts/windows/tokenizer-install-deps.bat`
- `scripts/windows/tokenizer-requirements.txt`

英文学习语言下新增基础 fallback：tokenizer 不可用时仍能导入英文纯文本，但只做简单切句、分词和小写 lemma。高级词形分析仍需要 Python tokenizer。其他语言 tokenizer 不可用时会给出明确错误。
