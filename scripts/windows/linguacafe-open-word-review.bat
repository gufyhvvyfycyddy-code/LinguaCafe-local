@echo off
call "%~dp0gpt-workflow-config.bat"
echo Opening LinguaCafe Word Review...
if not "%BROWSER_EXE%"=="" (
    start "" "%BROWSER_EXE%" "%APP_URL%/review"
) else (
    start "" "%APP_URL%/review"
)
exit /b 0
