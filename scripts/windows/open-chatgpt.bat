@echo off
call "%~dp0gpt-workflow-config.bat"
echo Opening ChatGPT. Upload or paste the package manually.
if not "%BROWSER_EXE%"=="" (
    start "" "%BROWSER_EXE%" "https://chatgpt.com/"
) else (
    start "" "https://chatgpt.com/"
)
exit /b 0
