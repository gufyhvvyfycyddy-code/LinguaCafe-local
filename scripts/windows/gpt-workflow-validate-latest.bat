@echo off
call "%~dp0gpt-workflow-config.bat"
cd /d "%PROJECT_DIR%"
echo Validating latest GPT JSON download...
"%PHP_EXE%" artisan senses:gpt-workflow validate-latest --user_id=%USER_ID% --language=%LANGUAGE%
if errorlevel 1 (
  echo Validation failed. Check storage\app\gpt-workflow\failed.
  exit /b 1
)
echo Validation passed. You can run the dry-run import next.
