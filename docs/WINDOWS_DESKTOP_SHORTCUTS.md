# LinguaCafe Windows 桌面快捷方式

## 第一次使用前

请先确认这些环境可用：

- PHP 8.2 或项目要求的 PHP 版本。
- Composer 依赖已经安装，即项目根目录存在 `vendor/`。
- MariaDB / MySQL 已启动，并且 `.env` 数据库配置正确。
- `fsrs-rs-php` 原生扩展已加载。
- Node / npm 已安装，用于前端构建。

可以先运行：

```bat
scripts\windows\linguacafe-doctor.bat
```

如果 doctor 出现 `FAIL`，先按提示修复。

如果打开登录页后无法登录，且当前数据库还没有用户，请在项目根目录运行：

```bat
php artisan user:create --email=test@example.com --password=12345678
```

当前项目没有网页注册入口。第一个用户会自动成为 admin，密码会用 Laravel Hash 加密。之后在登录页使用 `test@example.com` 和 `12345678` 登录。换数据库或清空数据库后，需要重新创建用户。

## 修改配置

统一配置文件：

```text
scripts/windows/gpt-workflow-config.bat
```

常用变量：

- `PROJECT_DIR`：项目目录。
- `PHP_EXE`：PHP 可执行文件，默认 `php`。
- `NODE_EXE`：Node 可执行文件，默认 `node`。
- `NPM_EXE`：npm 可执行文件，默认 `npm`。
- `BROWSER_EXE`：浏览器可执行文件，可空。
- `APP_URL`：默认 `http://127.0.0.1:8000`。
- `USER_ID`：默认 `1`。
- `LANGUAGE`：默认 `english`。
- `INPUT_FILE`：GPT workflow 的新材料路径。

如果双击脚本提示找不到 PHP，把 `PHP_EXE` 改成完整路径，例如：

```bat
set "PHP_EXE=C:\path\to\php.exe"
```

## 创建桌面快捷方式

双击：

```text
scripts/windows/create-desktop-shortcuts.bat
```

它会在当前 Windows 用户桌面创建 LinguaCafe 快捷方式。

## 桌面快捷方式用途

- `LinguaCafe 启动`：检查环境、运行迁移、启动 Laravel，并打开首页。
- `LinguaCafe 停止`：尽量停止监听 8000 端口的 Laravel `artisan serve`。
- `LinguaCafe 首页`：打开 LinguaCafe 首页。
- `LinguaCafe Word Review`：打开原 word-only Review 页面。
- `LinguaCafe Sense Review`：打开词义 FSRS Review 页面。
- `LinguaCafe 词义确认`：打开 Sense Mapping Review 页面。
- `LinguaCafe Doctor`：运行 workflow doctor 检查。
- `LinguaCafe 生成 GPT 包`：生成 `gpt-sense-package.md` 并打开 package 文件夹和 ChatGPT。
- `LinguaCafe 校验 GPT 下载`：校验 downloads 中最新的 GPT JSON。
- `LinguaCafe 导入 Dry Run`：预演导入，不写数据库。
- `LinguaCafe 正式导入`：正式导入最新 validated JSON。
- `LinguaCafe 打开 ChatGPT`：打开 ChatGPT 网页端。

## 正常使用流程

1. 双击 `LinguaCafe 启动`。
2. 把新英文材料放入 `storage/app/gpt-workflow/input/new-material.txt`。
3. 双击 `LinguaCafe 生成 GPT 包`。
4. 在 ChatGPT 网页端上传或粘贴 package 内容。
5. 把 GPT 输出保存为 `sense-mapping.json`，放入 `storage/app/gpt-workflow/downloads/`。
6. 双击 `LinguaCafe 校验 GPT 下载`。
7. 双击 `LinguaCafe 导入 Dry Run`，检查 summary。
8. 确认无误后双击 `LinguaCafe 正式导入`。
9. 双击 `LinguaCafe 词义确认`，处理 pending occurrence。
10. 双击 `LinguaCafe Sense Review`，复习到期词义卡。

## 常见错误

### php 不在 PATH

现象：脚本提示 PHP 不可用。

处理：修改 `gpt-workflow-config.bat` 的 `PHP_EXE` 为完整路径。

### 8000 端口被占用

现象：启动失败或打开了别的服务。

处理：先双击 `LinguaCafe 停止`。如果它提示不是 Laravel `artisan serve`，请手动确认占用 8000 的程序。

### MariaDB 没启动

现象：迁移失败或页面数据库连接失败。

处理：启动 MariaDB / MySQL，检查 `.env` 中的数据库用户名、密码和端口。

### fsrs-rs-php 未加载

现象：doctor 出现 FSRS 原生扩展 WARN 或 FAIL。

处理：按当前 PHP 版本编译并加载 `fsrs-rs-php`。本地测试可以临时 fallback，但真实使用不应把 fallback 当生产方案。

### sense-mapping.json 放错目录

现象：校验脚本提示找不到 JSON。

处理：把文件放到：

```text
storage/app/gpt-workflow/downloads/
```

### validate 失败

现象：校验脚本把文件复制到 `failed/`。

处理：打开同目录的 `.errors.json`，根据错误让 GPT 修正输出。

### ECDICT 词典消失

现象：阅读页点词查不到中文释义，或词典列表里没有 `ECDICT EN-ZH`。

原因：
- 运行 `php artisan test` 时 `RefreshDatabase` 会执行 `migrate:fresh` 清除非 migration 表。
- 手动执行 `php artisan migrate:fresh`。
- 切换或重建数据库。

检查：
```bash
php artisan dictionary:import-ecdict --status
```

恢复：
```bash
# 如果表不存在，直接导入
php artisan dictionary:import-ecdict

# 如果表存在但条数异常（< 700,000），强制重建
php artisan dictionary:import-ecdict --force
```

> **注意**：`.env.testing` 已使用独立数据库 `linguacafe_fsrs_test`，测试不会再清除主库词典。

### 保存 Learning 词失败 / 等级保存报错

现象：词汇页或阅读页设置单词等级后，提示"保存失败，请稍后重试"。

原因：`settings.reviewIntervals` 或用户 goals 被数据库重建清空。

检查：
```bash
php artisan db:doctor
```

恢复：
```bash
# 检查并自动修复 settings 和 goals
php artisan db:doctor --fix

# 或手动分别修复
php artisan db:seed --class=SettingsSeeder
php artisan tinker --execute="(new App\Services\GoalService())->createGoalsForLanguage(1, 'english');"
```

> 系统已内置自愈：保存 Learning 词时如果 settings 或 goals 缺失，会自动补齐而不崩溃。但如果手动修复更可控，建议运行 `db:doctor --fix`。

### 中文显示乱码

现象：命令行中文显示异常。

处理：在 PowerShell 中先运行：

```powershell
chcp 65001
```

或使用 Windows Terminal。

## 界面语言和学习语言

LinguaCafe 桌面版默认使用中文界面。左下角显示的“学习语言”是学习材料语言，不是界面语言。

第一次使用前如果无法登录，请先在项目根目录运行：

```bat
php artisan user:create --email=test@example.com --password=12345678
```

这个命令会创建本地用户，把界面语言初始化为 `zh-CN`，并把默认学习语言初始化为 `english`。换数据库或清空数据库后，需要重新创建用户。

如果左下角“学习语言”一直转圈，请检查：

- 是否已经登录。
- 是否已经创建本地用户。
- Laravel 后端服务是否正在运行。
- 当前用户是否有学习语言，默认应为 `english`。
## 中文界面与首次登录

桌面快捷方式打开的 LinguaCafe 默认显示中文界面。左下角“学习语言”用于选择学习材料语言，不是界面语言；默认学习语言是 `english`。

如果第一次打开登录页无法登录，请在项目根目录运行：

```bat
php artisan user:create --email=test@example.com --password=12345678
```

该命令会创建本地用户，把第一个用户设为 admin，用 Laravel Hash 加密密码，并初始化：

- 界面语言：`zh-CN`
- 学习语言：`english`

如果左下角“学习语言”一直转圈，请检查 Laravel 是否启动、是否已登录、数据库是否有用户，以及后端是否能访问语言接口。导入阅读材料一直加载时，请检查 Python tokenizer 服务是否可用。

## Python tokenizer 启动

阅读材料导入的高级切句、分词、词形还原依赖 Python tokenizer：

```text
tools/tokenizer.py
```

桌面快捷方式“LinguaCafe 启动”会先检查：

```text
http://127.0.0.1:8678/models/list
```

如果 tokenizer 没启动，会自动调用：

```bat
scripts\windows\tokenizer-start.bat
```

如果 Python 或依赖缺失，运行：

```bat
scripts\windows\tokenizer-install-deps.bat
```

如果只导入英文文本，即使 tokenizer 没启动，也可以使用基础英文 fallback 完成导入；但日语、中文等其他学习语言仍需要 tokenizer 才能获得正确分词。
# MariaDB / MySQL 启动检查

当前 Windows 本地版会在启动 LinguaCafe 前先检查 `.env` 中的数据库连接。当前推荐配置：

```env
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=linguacafe_fsrs
DB_USERNAME=root
DB_PASSWORD=
```

本机 MariaDB 安装在：

```text
C:\Program Files\MariaDB 12.3
```

该安装没有注册 Windows 服务，因此启动脚本会在数据库端口不可连接时尝试运行：

```text
C:\Program Files\MariaDB 12.3\bin\mysqld.exe --defaults-file="C:\Program Files\MariaDB 12.3\data\my.ini"
```

如果启动失败，请先运行：

```bat
scripts\windows\database-doctor.bat
```

常见原因：

- `.env` 仍然写着 `DB_PORT=3309`，但 MariaDB 实际监听 `3306`。
- `.env` 写了 `DB_PASSWORD=root`，但本机 root 账号实际为空密码。
- 3306 端口被其他 MySQL/MariaDB 占用。
- `C:\Program Files\MariaDB 12.3\data\my.ini` 不存在或端口配置被改动。

启动脚本现在会先确认数据库可连接，再执行 migration；如果数据库不可连接，会停止启动并提示运行 `database-doctor.bat`，不会继续盲目迁移。
