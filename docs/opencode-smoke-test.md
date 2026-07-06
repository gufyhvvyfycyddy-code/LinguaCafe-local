# OpenCode Go CI 冒烟测试

本文件在 CI 冒烟测试中由 OpenCode 自动创建。

## 目的

验证 OpenCode Go CI 完整链路是否正常。

## 检查项

- [ ] /opencode 评论能触发 GitHub Actions workflow
- [ ] OpenCode 使用 `opencode-go/deepseek-v4-flash` 模型
- [ ] OpenCode 报告包含 `[skills:]` 字段
- [ ] OpenCode 创建了独立分支并提交 PR
- [ ] PR **没有**自动合并
- [ ] 报告和机器标记 `<!-- opencode-loop: ... -->` 出现在评论区
