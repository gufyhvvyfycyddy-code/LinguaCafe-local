@echo off
setlocal
call "%~dp0gpt-workflow-config.bat"

echo [LinguaCafe] Installing Python tokenizer dependencies...
echo This may take a while.
echo.

"%PYTHON_EXE%" --version >nul 2>&1
if errorlevel 1 (
    echo [LinguaCafe] Python is not available. Install Python 3 or set PYTHON_EXE in gpt-workflow-config.bat.
    pause
    exit /b 1
)

if not exist "%TOKENIZER_VENV%\Scripts\python.exe" (
    echo [LinguaCafe] Creating tokenizer virtual environment...
    "%PYTHON_EXE%" -m venv "%TOKENIZER_VENV%"
    if errorlevel 1 (
        echo [LinguaCafe] Failed to create virtual environment.
        pause
        exit /b 1
    )
)

set "TOKENIZER_PYTHON=%TOKENIZER_VENV%\Scripts\python.exe"
"%TOKENIZER_PYTHON%" -m pip install --upgrade pip setuptools wheel
"%TOKENIZER_PYTHON%" -m pip install -r "%~dp0tokenizer-requirements.txt"
"%TOKENIZER_PYTHON%" -m spacy download en_core_web_sm
echo [LinguaCafe] Installing LemmInflect for English lemmatization enhancement...
"%TOKENIZER_PYTHON%" -m pip install lemminflect

if errorlevel 1 (
    echo [LinguaCafe] Dependency installation failed. Check the error above.
    pause
    exit /b 1
)

echo [LinguaCafe] Tokenizer dependencies installed.
pause
endlocal
