@echo off
setlocal
call "%~dp0gpt-workflow-config.bat"

if /i not "%~1"=="--quiet" (
    echo [LinguaCafe] Python tokenizer doctor
    echo Project: %PROJECT_DIR%
    echo URL: %TOKENIZER_URL%
    echo.
)

"%PYTHON_EXE%" --version >nul 2>&1
if errorlevel 1 (
    if /i not "%~1"=="--quiet" (
        echo FAIL Python is not available. Install Python 3 or set PYTHON_EXE in gpt-workflow-config.bat.
        pause
    )
    exit /b 1
)

if not exist "%TOKENIZER_SCRIPT%" (
    if /i not "%~1"=="--quiet" (
        echo FAIL Tokenizer script not found: %TOKENIZER_SCRIPT%
        pause
    )
    exit /b 1
)

powershell -NoProfile -ExecutionPolicy Bypass -Command "try { $r = Invoke-WebRequest -Uri '%TOKENIZER_URL%/models/list' -UseBasicParsing -TimeoutSec 3; if ($r.StatusCode -ge 200 -and $r.StatusCode -lt 300) { exit 0 }; exit 1 } catch { exit 1 }" >nul 2>&1
if errorlevel 1 (
    if /i not "%~1"=="--quiet" (
        echo FAIL tokenizer service is not reachable.
        echo Fix:
        echo 1. Run scripts\windows\tokenizer-start.bat
        echo 2. If modules are missing, install dependencies:
        echo    scripts\windows\tokenizer-install-deps.bat
        echo 3. Check whether port %TOKENIZER_PORT% is occupied.
        pause
    )
    exit /b 1
)

if /i not "%~1"=="--quiet" (
    echo OK tokenizer service is reachable.
    pause
)
exit /b 0
