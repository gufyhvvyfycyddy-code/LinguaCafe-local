@echo off
call "%~dp0gpt-workflow-config.bat"
echo Opening LinguaCafe Sense Review...
if not "%BROWSER_EXE%"=="" (
    start "" "%BROWSER_EXE%" "%APP_URL%/reviews/senses"
) else (
    start "" "%APP_URL%/reviews/senses"
)
exit /b 0
