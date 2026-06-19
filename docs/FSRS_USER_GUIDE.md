# LinguaCafe FSRS 用户手册

## 1. 现在能做什么

当前版本已经支持两套复习流程：

- 单词复习：原 Review 页面继续复习 word card。
- 词义复习：Sense Review 页面单独复习 sense card。

系统也支持把新英文材料打包给 GPT 网页端，让 GPT 输出 `sense-mapping.json`，再由 LinguaCafe 校验、导入、人工确认，最后进入词义复习。

## 首次登录 / 创建本地用户

当前项目没有网页注册入口。登录页只显示 email 和 password。

第一次使用本地数据库时，先在项目根目录运行：

```bash
php artisan user:create --email=test@example.com --password=12345678
```

说明：

- 第一个用户会自动成为 admin。
- 密码会用 Laravel Hash 加密保存。
- 登录页使用这个 email 和 password 登录。
- 换数据库或清空数据库后，需要重新创建用户。

创建后可以用：

```text
邮箱：test@example.com
密码：12345678
```

## 2. Word-only Review 怎么用

打开原来的 Review 页面即可。这个页面只显示 `target_type=word` 的到期卡片。

评分按钮：

- Again
- Hard
- Good
- Easy

点击后由后端 FSRS 服务更新 `review_cards`，并写入 `review_logs`。

## 3. Sense Mapping Review 怎么用

入口：

```text
/senses/review
```

这个页面用于处理导入后的 `word_sense_occurrences`。

常见状态：

- `pending`：需要人工确认。
- `bound`：已经绑定到一个词义。
- `ignored`：已忽略，不进入 FSRS。
- `rejected`：已拒绝，不进入 FSRS。

可执行操作：

- 确认当前绑定。
- 改绑到已有词义。
- 从 occurrence 新建词义。
- 忽略。
- 拒绝。
- 批量确认、批量忽略、批量拒绝。
- 批量确认高置信度匹配。

## 4. Sense FSRS Review 怎么用

入口：

```text
/reviews/senses
```

这个页面只复习 `target_type=sense` 的到期卡片，不会混入 word card。

进入队列的条件：

- 当前用户。
- 当前语言。
- `target_type=sense`。
- `fsrs_enabled=true`。
- `fsrs_due_at <= now()`。
- 对应 `word_sense.status=confirmed`。

不会进入队列的内容：

- `ai_suggested` sense。
- `rejected` sense。
- phrase card。
- word card。

## 5. 如何准备新英文材料

把新材料保存为纯文本文件，例如：

```text
storage/app/gpt-workflow/input/new-material.txt
```

每句英文建议单独一行。材料越清晰，GPT 返回的 `sentence_id` 和匹配结果越容易校验。

## 6. 如何生成 GPT package

运行：

```bash
php artisan senses:gpt-workflow prepare --user_id=1 --language=english --input=storage/app/gpt-workflow/input/new-material.txt
```

生成文件：

- `storage/app/gpt-workflow/package/gpt-sense-package.md`
- `storage/app/gpt-workflow/package/prompt.txt`

也可以直接生成 package：

```bash
php artisan senses:make-gpt-package --user_id=1 --language=english --input=storage/app/gpt-workflow/input/new-material.txt --output=storage/app/gpt-sense-package.md
```

## 7. 如何把 package 发给 GPT

打开 ChatGPT 网页端，把 `prompt.txt` 和 `gpt-sense-package.md` 的内容发给 GPT。

要求 GPT：

- 只输出严格 JSON。
- 不输出解释。
- 不输出 Markdown。
- 不省略 `schema_version`。

## 8. GPT 返回 JSON 后放在哪里

把 GPT 输出保存为 `.json` 文件，放到：

```text
storage/app/gpt-workflow/downloads/
```

推荐文件名：

```text
sense-mapping.json
```

## 9. 如何 validate

运行：

```bash
php artisan senses:gpt-workflow validate-latest --user_id=1 --language=english
```

校验成功后，文件会复制到：

```text
storage/app/gpt-workflow/validated/
```

校验失败后，文件会复制到：

```text
storage/app/gpt-workflow/failed/
```

同时生成 `.errors.json` 错误报告。

## 10. 如何 dry-run

正式导入前先运行：

```bash
php artisan senses:gpt-workflow import-latest --user_id=1 --language=english --dry-run
```

dry-run 只显示将要导入的 summary，不写数据库。

## 11. 如何 import

确认 dry-run 结果后运行：

```bash
php artisan senses:gpt-workflow import-latest --user_id=1 --language=english
```

导入成功后，文件会复制到：

```text
storage/app/gpt-workflow/imported/
```

## 12. 如何进入人工确认

导入后打开：

```text
/senses/review
```

在这里处理 pending occurrences。

## 13. 如何进入词义复习

确认词义并启用 FSRS 后打开：

```text
/reviews/senses
```

这里会显示到期的 sense card。

## 14. Quicker 如何串联 bat 脚本

Windows 脚本在：

```text
scripts/windows/
```

推荐 Quicker 动作：

- 准备 GPT 包：`gpt-workflow-prepare.bat`
- 打开 ChatGPT：`open-chatgpt.bat`
- 校验下载结果：`gpt-workflow-validate-latest.bat`
- 导入 dry-run：`gpt-workflow-import-latest-dry-run.bat`
- 正式导入：`gpt-workflow-import-latest.bat`
- 打开确认页面：`open-sense-review.bat`
- 检查环境：`gpt-workflow-doctor.bat`

默认配置在：

```text
scripts/windows/gpt-workflow-config.bat
```

## 15. 哪些地方不能全自动

当前不会做这些事：

- 不自动控制 ChatGPT 网页端。
- 不自动上传文件。
- 不自动下载文件。
- 不保存账号、cookie 或浏览器会话。
- 不自动合并疑似重复 sense。
- phrase 只做 occurrence 标记，不进入 FSRS。

建议流程仍然是：先 validate，再 dry-run，再 import，最后人工确认。

## 16. 常见错误和处理

### JSON 格式错误

现象：`validate-latest` 失败。

处理：查看 `storage/app/gpt-workflow/failed/*.errors.json`，让 GPT 重新输出严格 JSON。

### matched_sense_id 不存在

现象：校验报 `matched_sense_id` 无效。

处理：重新生成 package，确保 GPT 使用 package 中存在的 `sense_id`。

### confidence 太低

现象：`confidence < 0.90` 且 `auto_fsrs_allowed=true` 会失败。

处理：低置信度项目必须设置 `auto_fsrs_allowed=false`，导入后在确认页面人工处理。

### auto_fsrs_allowed 不合法

现象：校验要求它是 boolean。

处理：只能使用 `true` 或 `false`，不能用字符串 `"true"`。

### PHP 不在 PATH

现象：bat 脚本提示找不到 `php`。

处理：把 PHP 加到系统 PATH，或修改 `scripts/windows/gpt-workflow-config.bat` 中的 `PHP_BIN`。

### fsrs-rs-php 未加载

现象：doctor 输出 `WARN fsrs-rs-php native extension loaded`。

处理：编译并加载 `fsrs-rs-php` 原生扩展。本地测试可以临时 fallback，但生产验证不能把 fallback 当正式方案。

### PowerShell 中文显示乱码

现象：终端中文看起来异常，但文件本身可能是 UTF-8。

处理：使用支持 UTF-8 的终端，或先运行：

```powershell
chcp 65001
```
## 界面语言和学习语言

当前界面默认显示中文。新用户通过本地命令创建后，系统会初始化两个不同的语言设置：

- 界面语言：默认 `zh-CN`，用于菜单、按钮、提示等界面文字。
- 学习语言：默认 `english`，用于阅读材料、词汇、Review、Sense Review、GPT workflow 的语言参数。

左下角的“学习语言”不是界面语言，它只表示当前正在学习的材料语言，例如英语或日语。不要把学习语言改成中文来实现中文界面。

如果左下角“学习语言”一直转圈，请按顺序检查：

- 是否已经登录。
- 是否已经通过 `php artisan user:create --email=test@example.com --password=12345678` 创建本地用户。
- 当前用户是否有默认学习语言，默认应为 `english`。
- 后端服务是否已经启动。
- Python 模型服务不可用时，页面现在会回退显示已支持的基础学习语言，不应无限转圈。
## 界面语言与学习语言

LinguaCafe 现在默认使用中文界面，界面语言记为 `uiLanguage=zh-CN`。左下角显示的“学习语言”不是界面语言，而是阅读材料、词汇、Review、Sense Review、GPT workflow 使用的学习语言，例如 `english` 或 `japanese`。

第一次创建本地用户时，命令会初始化：

- 界面语言：`zh-CN`
- 默认学习语言：`english`

如果换数据库或清空数据库，需要重新运行：

```bash
php artisan user:create --email=test@example.com --password=12345678
```

## 加载卡住排查

如果页面一直显示加载中，请先检查：

- 是否已经登录。
- 后端 Laravel 服务是否启动。
- 数据库是否可连接。
- 当前用户是否有学习语言，默认应为 `english`。
- 导入阅读材料时，Python tokenizer 服务是否可用。

阅读材料导入依赖文本处理服务。如果服务不可用，页面会退出加载状态并显示“文本处理服务不可用”，不会再无限转圈。

## Python tokenizer 与英文 fallback

LinguaCafe 的高级文本处理服务在：

```text
tools/tokenizer.py
```

它监听：

```text
http://127.0.0.1:8678
```

Windows 桌面启动脚本会先检查 tokenizer，如果不可访问，会尝试运行：

```bat
scripts\windows\tokenizer-start.bat
```

如果提示缺少 Python 依赖，先运行：

```bat
scripts\windows\tokenizer-install-deps.bat
```

英文学习语言下，如果 Python tokenizer 不可用，导入纯英文文本会自动使用基础 fallback：按 `. ? !` 切句，按空格和标点做简单分词，lemma 暂时用小写单词。页面会提示“已使用基础英文分词导入。高级词形分析需要 Python tokenizer 服务。”

其他语言仍建议启动 tokenizer，否则会显示明确错误，不会无限加载。

手动检查 tokenizer：

```bat
scripts\windows\tokenizer-doctor.bat
```

常见问题：

- Python 不在 PATH：安装 Python 3，或在 `scripts/windows/gpt-workflow-config.bat` 设置 `PYTHON_EXE`。
- 依赖未安装：运行 `scripts/windows/tokenizer-install-deps.bat`。
- 端口被占用：检查 8678 端口，或先运行 `scripts/windows/tokenizer-stop.bat`。
- URL 配置错误：Laravel 默认使用 `http://127.0.0.1:8678`；Docker 环境由 `PYTHON_CONTAINER_NAME` 覆盖。

左下角“学习语言”一直转圈时，通常是学习语言接口失败、用户缺少默认学习语言，或后端服务不可用。现在新用户和旧用户都会兜底使用 `english` 作为学习语言，并使用 `zh-CN` 作为界面语言。
## 词典释义为空时怎么办

阅读页点击单词后，如果侧栏显示“词典未配置，请先导入或配置词典数据。”，说明当前学习语言还没有可用的本地词典或 API 词典。可以进入“管理员设置”里的词典相关页面导入本地词典 CSV，或配置项目已有的 API 词典。

如果显示“暂无词典结果。”，说明词典已经响应，但当前词条没有匹配结果。这不是点击单词失败，可以手动填写释义后保存。

本阶段没有接入新的在线翻译 API。
