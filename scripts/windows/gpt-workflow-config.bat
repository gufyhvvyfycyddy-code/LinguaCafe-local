@echo off
set "PROJECT_DIR=%~dp0..\.."
set "PHP_EXE=php"
set "NODE_EXE=node"
set "NPM_EXE=npm"
set "PYTHON_EXE=python"
set "BROWSER_EXE="
set "APP_URL=http://127.0.0.1:8000"
set "DB_HOST=127.0.0.1"
set "DB_PORT=3306"
set "DB_DATABASE=linguacafe_fsrs"
set "DB_USERNAME=root"
set "DB_PASSWORD="
set "MARIADB_SERVICE_NAME="
set "MARIADB_HOME=C:\Program Files\MariaDB 12.3"
set "MYSQL_EXE=%MARIADB_HOME%\bin\mysql.exe"
set "MYSQLD_EXE=%MARIADB_HOME%\bin\mysqld.exe"
set "MYSQL_DEFAULTS_FILE=%MARIADB_HOME%\data\my.ini"
set "TOKENIZER_URL=http://127.0.0.1:8678"
set "TOKENIZER_PORT=8678"
set "TOKENIZER_SCRIPT=%PROJECT_DIR%\tools\tokenizer.py"
set "TOKENIZER_VENV=%PROJECT_DIR%\.venv-tokenizer"
set "TOKENIZER_PROCESS_SCRIPT=%PROJECT_DIR%\scripts\windows\tokenizer-process.ps1"
set "TOKENIZER_RUNTIME_DIR=%PROJECT_DIR%\storage\app\tokenizer-runtime"
set "USER_ID=1"
set "LANGUAGE=english"
set "WORKFLOW_DIR=%PROJECT_DIR%\storage\app\gpt-workflow"
set "INPUT_FILE=%WORKFLOW_DIR%\input\new-material.txt"

if /i "%PHP_EXE%"=="php" (
    where php >nul 2>&1
    if not errorlevel 1 (
        php -r "exit(version_compare(PHP_VERSION, '8.4.0', '>=') ? 0 : 1);" >nul 2>&1
        if not errorlevel 1 goto linguacafe_php_found
    )

    rem LinguaCafe's supported runtime is PHP 8.4+. Do not silently accept an older PHP earlier on PATH.
    set "PHP_EXE="
    for /f "delims=" %%P in ('where /r "%LOCALAPPDATA%\Microsoft\WinGet\Packages" php.exe 2^>nul') do (
        "%%P" -r "exit(version_compare(PHP_VERSION, '8.4.0', '>=') ? 0 : 1);" >nul 2>&1
        if not errorlevel 1 (
            set "PHP_EXE=%%P"
            goto linguacafe_php_found
        )
    )
    for /f "delims=" %%P in ('where /r "%LOCALAPPDATA%\Microsoft\WinGet\Links" php.exe 2^>nul') do (
        "%%P" -r "exit(version_compare(PHP_VERSION, '8.4.0', '>=') ? 0 : 1);" >nul 2>&1
        if not errorlevel 1 (
            set "PHP_EXE=%%P"
            goto linguacafe_php_found
        )
    )

    echo [LinguaCafe] PHP 8.4+ was not found. Install a supported PHP runtime or set PHP_EXE explicitly.
)
:linguacafe_php_found

set "PHP_BIN=%PHP_EXE%"
set "LOCAL_APP_URL=%APP_URL%"
