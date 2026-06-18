@echo off
call "%~dp0gpt-workflow-config.bat"
echo Opening LinguaCafe home...
if not "%BROWSER_EXE%"=="" (
    start "" "%BROWSER_EXE%" "%APP_URL%"
) else (
    start "" "%APP_URL%"
)
exit /b 0
