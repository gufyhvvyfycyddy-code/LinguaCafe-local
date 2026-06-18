@echo off
setlocal
call "%~dp0gpt-workflow-config.bat"

echo [LinguaCafe] Checking GPT sense workflow health...
echo Project: %PROJECT_DIR%
echo User: %USER_ID%
echo Language: %LANGUAGE%
echo.

cd /d "%PROJECT_DIR%"
"%PHP_EXE%" artisan senses:gpt-workflow doctor --user_id=%USER_ID% --language=%LANGUAGE%

if errorlevel 1 (
    echo.
    echo [LinguaCafe] Doctor found problems. Please read the FAIL/WARN lines above.
    exit /b 1
)

echo.
echo [LinguaCafe] Doctor finished.
endlocal
