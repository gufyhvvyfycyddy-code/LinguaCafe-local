@echo off
setlocal
call "%~dp0gpt-workflow-config.bat"

echo [LinguaCafe] Starting Python tokenizer...
echo Tokenizer URL: %TOKENIZER_URL%
echo Tokenizer script: %TOKENIZER_SCRIPT%
echo.

if not exist "%TOKENIZER_SCRIPT%" (
    echo [LinguaCafe] Tokenizer script not found: %TOKENIZER_SCRIPT%
    if /i not "%~1"=="--no-pause" pause
    exit /b 1
)

powershell -NoProfile -ExecutionPolicy Bypass -Command "try { Invoke-WebRequest -Uri '%TOKENIZER_URL%/models/list' -UseBasicParsing -TimeoutSec 2 | Out-Null; exit 0 } catch { exit 1 }" >nul 2>&1
if not errorlevel 1 (
    echo [LinguaCafe] Tokenizer is already running.
    if /i not "%~1"=="--no-pause" pause
    exit /b 0
)

if exist "%TOKENIZER_VENV%\Scripts\python.exe" (
    set "TOKENIZER_PYTHON=%TOKENIZER_VENV%\Scripts\python.exe"
) else (
    set "TOKENIZER_PYTHON=%PYTHON_EXE%"
)

"%TOKENIZER_PYTHON%" --version >nul 2>&1
if errorlevel 1 (
    echo [LinguaCafe] Python is not available. Install Python 3 or set PYTHON_EXE in gpt-workflow-config.bat.
    if /i not "%~1"=="--no-pause" pause
    exit /b 1
)

"%TOKENIZER_PYTHON%" -c "import bottle, spacy, lxml, ebooklib, pykakasi, pinyin" >nul 2>&1
if errorlevel 1 (
    echo [LinguaCafe] Python tokenizer dependencies are incomplete.
    echo [LinguaCafe] Run scripts\windows\tokenizer-install-deps.bat or install scripts\windows\tokenizer-requirements.txt manually.
    if /i not "%~1"=="--no-pause" pause
    exit /b 1
)

echo [LinguaCafe] Opening tokenizer in a new window.
start "LinguaCafe Python Tokenizer" cmd /k "cd /d ""%PROJECT_DIR%"" && ""%TOKENIZER_PYTHON%"" ""%TOKENIZER_SCRIPT%"""

if /i not "%~1"=="--no-pause" pause
endlocal
