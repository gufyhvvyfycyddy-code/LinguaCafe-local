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

if not exist "%TOKENIZER_PROCESS_SCRIPT%" (
    echo [LinguaCafe] Tokenizer process launcher not found: %TOKENIZER_PROCESS_SCRIPT%
    if /i not "%~1"=="--no-pause" pause
    exit /b 1
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

powershell -NoProfile -ExecutionPolicy Bypass -File "%TOKENIZER_PROCESS_SCRIPT%" ^
    -ProjectDir "%PROJECT_DIR%" ^
    -TokenizerScript "%TOKENIZER_SCRIPT%" ^
    -PythonExe "%TOKENIZER_PYTHON%" ^
    -TokenizerUrl "%TOKENIZER_URL%" ^
    -RuntimeDir "%TOKENIZER_RUNTIME_DIR%"

if errorlevel 1 (
    echo [LinguaCafe] Tokenizer failed to start. Review logs under:
    echo %TOKENIZER_RUNTIME_DIR%
    if /i not "%~1"=="--no-pause" pause
    exit /b 1
)

if /i not "%~1"=="--no-pause" pause
endlocal
exit /b 0
