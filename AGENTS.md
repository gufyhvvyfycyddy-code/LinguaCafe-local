# Codex project instructions for LinguaCafe local

## Project role

You are working on the user's local LinguaCafe fork. The current product direction is a sense-only review system.

## Main product direction

1. Daily review should focus on WordSense / sense cards.
2. EncounteredWord is used for reading-page color, familiarity overview, and legacy compatibility.
3. WordSense is the actual review object.
4. ReviewCard target_type=word is legacy unless a task explicitly asks to maintain legacy behavior.
5. Do not break existing reading import, review, vocabulary, and sense mapping flows.

## Local environment

This project runs on Windows.
Do not assume Docker or WSL.
Prefer commands that work in Windows PowerShell.

## Safety rules

1. Do not delete user data.
2. Do not reset the database without explicit approval.
3. Do not run destructive migrations without explaining the risk.
4. Do not modify .env secrets.
5. Do not commit secrets.
6. Do not auto-push to GitHub.
7. Before large refactors, inspect current code and propose a patch plan.

## Coding workflow

1. Inspect the current branch and git status first.
2. Read relevant files before editing.
3. Make the smallest coherent change.
4. Run targeted tests when available.
5. If tests cannot run, explain why.
6. Give a final summary with changed files, test results, and remaining risks.

## User preference

Respond in Chinese unless code or logs require English.
Keep explanations concrete and operational.
Avoid vague praise.
When writing implementation instructions for other tools, produce one complete instruction block.
