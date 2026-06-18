@echo off
call "%~dp0gpt-workflow-config.bat"
echo Opening Sense Mapping Review page...
start "" "%LOCAL_APP_URL%/senses/review"
