# FSRS Phase 9 Status

## 本阶段目标

Phase 9 不新增大功能，只做端到端验收、doctor 检查命令、最小 demo 材料、用户手册和最终状态总结。

## 新增内容

- `senses:gpt-workflow doctor`
- `scripts/windows/gpt-workflow-doctor.bat`
- `storage/app/gpt-workflow/input/demo-material.txt`
- `storage/app/gpt-workflow/downloads/demo-sense-mapping.json`
- `docs/FSRS_USER_GUIDE.md`
- `docs/FSRS_FINAL_STATUS.md`

## Demo 流程

```bash
php artisan senses:gpt-workflow prepare --user_id=1 --language=english --input=storage/app/gpt-workflow/input/demo-material.txt
php artisan senses:gpt-workflow validate-latest --user_id=1 --language=english
php artisan senses:gpt-workflow import-latest --user_id=1 --language=english --dry-run
php artisan senses:gpt-workflow import-latest --user_id=1 --language=english
```

说明：静态 demo mapping 使用 `matched_sense_id=1` 演示 `match_existing_sense`。真实运行时需要保证该 ID 属于当前用户和当前语言；自动测试会动态生成合法 sense id 来完整跑通流程。

## Doctor 检查项

- PHP 版本。
- `fsrs-rs-php` 原生扩展加载情况。
- FSRS 和 sense 相关表是否存在。
- workflow 目录是否存在。
- learned-senses export 是否可用。
- make-gpt-package 是否可用。
- validate-latest 是否可用。
- import-latest --dry-run 是否可用。

## 当前限制

- 不做 phrase FSRS。
- 不做浏览器自动点击。
- 不保存账号、cookie。
- 不自动上传或下载 ChatGPT 文件。
- duplicate 检测仍只是提示，不自动合并。
