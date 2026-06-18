@echo off
call "%~dp0gpt-workflow-config.bat"
cd /d "%PROJECT_DIR%"
echo Preparing GPT sense package...
%PHP_BIN% artisan senses:gpt-workflow prepare --user_id=%USER_ID% --language=%LANGUAGE% --input=storage/app/gpt-workflow/input/new-material.txt
if errorlevel 1 exit /b 1
echo Opening package folder...
explorer "%WORKFLOW_DIR%\package"
call "%~dp0open-chatgpt.bat"
