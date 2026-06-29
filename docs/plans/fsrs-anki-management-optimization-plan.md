# FSRS Anki 管理优化计划

> 本文档描述 FSRS 管理区中长期优化目标，逐步接近 Anki 的管理体验。

---

## 1. 当前目标

让 FSRS 管理区逐步接近 Anki 的体验，包括参数优化、重排控制、参数诊断、工作量模拟和预设分组管理。

---

## 2. 已确认事实

- FSRS 默认参数保持 19 个。
- 19 个是算法参数数量，不是学习材料、复习卡或复习记录数量。
- 删除 / rejected 的词义卡旧 review_logs 不参与参数优化（通过 `word_senses.status = CONFIRMED` 过滤保障）。
- 恢复默认参数只删除 FSRS 参数 settings，不删除学习数据。

---

## 3. 与 Anki 的主要差距

| 差距 | 说明 |
|------|------|
| 缺少 preset / 分组参数体系 | Anki 支持不同 preset 管理不同组的参数设置 |
| 缺少参数优化诊断面板 | 当前只展示参数数量，缺少诊断信息 |
| Desired Retention 工作量模拟还不够直观 | 需更接近 Anki Help Me Decide 模拟器 |
| 重排风险展示还可以更接近 Anki | 当前风险提示已实现但还有改进空间 |
| 缺少恢复默认参数按钮 | Anki 参数界面有恢复默认按钮 |

---

## 4. 后续优化阶段

| 阶段 | 内容 | 状态 |
|------|------|------|
| FSRS-Anki-Mgmt-1 | 恢复默认参数按钮 | ✅ 当前阶段 |
| FSRS-Anki-Mgmt-2 | 参数优化诊断面板 | 📋 计划中 |
| FSRS-Anki-Mgmt-3 | 重排风险面板优化 | 📋 计划中 |
| FSRS-Anki-Mgmt-4 | Desired Retention 工作量模拟器 | 📋 计划中 |
| FSRS-Anki-Mgmt-5 | Preset / 分组参数长期评估 | 📋 计划中 |

---

## 5. 当前下一步

进入 **FSRS-Anki-Mgmt-1：恢复默认参数按钮**

后端：
- `SettingsService::restoreFsrsDefaultParameters()` — 删除 4 个 global settings
- `SettingsController::restoreFsrsDefaultParameters()` — 接口方法
- `routes/web.php` — `POST /settings/fsrs/restore-default`

前端：
- `AdminReviewSettings.vue` — 参数来源区域增加恢复默认参数按钮

按钮行为：
- "恢复默认参数"按钮**始终显示**，文字始终为"恢复默认参数"。
- 即使当前已经是默认参数，点击后也只返回安全提示，不删除任何学习数据。
- 点击后弹出确认弹窗，确认后调用后端接口。

测试：
- 恢复后 settings 被删除
- 恢复后状态回到 default
- 恢复后调度使用默认参数
- 不删除学习数据
- 未保存参数时安全调用

---

## 6. 禁止事项

- 禁止删除 review_logs。
- 禁止删除 review_cards。
- 禁止删除 word_senses。
- 禁止删除 encountered_words。
- 禁止删除词典表。
- 禁止清库。
- 禁止自动重排卡片。
