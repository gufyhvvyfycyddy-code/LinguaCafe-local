import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const root = process.cwd();
const read = (relativePath) => readFileSync(join(root, relativePath), 'utf8');

const config = read('scripts/windows/gpt-workflow-config.bat');
const processScript = read('scripts/windows/tokenizer-process.ps1');
const taskRunner = read('scripts/windows/tokenizer-task-runner.ps1');
const tokenizerStart = read('scripts/windows/tokenizer-start.bat');
const tokenizerStop = read('scripts/windows/tokenizer-stop.bat');
const linguacafeStart = read('scripts/windows/linguacafe-start.bat');
const linguacafeStop = read('scripts/windows/linguacafe-stop.bat');

test('Windows workflow config refuses an older PATH PHP and resolves the supported PHP 8.4 runtime', () => {
  assert.match(config, /version_compare\(PHP_VERSION, '8\.4\.0', '>='\)/);
  assert.match(config, /where \/r "%LOCALAPPDATA%\\Microsoft\\WinGet\\Packages" php\.exe/);
  assert.match(config, /where \/r "%LOCALAPPDATA%\\Microsoft\\WinGet\\Links" php\.exe/);
  assert.match(config, /Do not silently accept an older PHP earlier on PATH/);
  assert.match(config, /PHP 8\.4\+ was not found/);
});

test('tokenizer startup is owned by a self-healing scheduled task with readiness evidence', () => {
  assert.match(config, /TOKENIZER_PROCESS_SCRIPT=.*tokenizer-process\.ps1/i);
  assert.match(config, /TOKENIZER_RUNTIME_DIR=/i);

  assert.match(processScript, /LinguaCafeTokenizerStarter/);
  assert.match(processScript, /Register-ScheduledTask/);
  assert.match(processScript, /New-ScheduledTaskTrigger -AtLogOn/);
  assert.match(processScript, /-RestartCount 3/);
  assert.match(processScript, /-MultipleInstances IgnoreNew/);
  assert.match(processScript, /Start-ScheduledTask/);
  assert.match(processScript, /models\/list/);
  assert.match(processScript, /StartupTimeoutSeconds/);
  assert.match(processScript, /port \$port is already occupied/i);

  assert.match(taskRunner, /Start-Process/);
  assert.match(taskRunner, /-WindowStyle Hidden/);
  assert.match(taskRunner, /-RedirectStandardOutput/);
  assert.match(taskRunner, /-RedirectStandardError/);
  assert.match(taskRunner, /\$pidPath = Join-Path \$runtimePath 'tokenizer\.pid'/);
  assert.match(taskRunner, /Set-Content -LiteralPath \$pidPath -Value \$process\.Id/);
  assert.match(taskRunner, /\$process\.WaitForExit\(\)/);
});

test('tokenizer-start delegates to the verified launcher instead of an unverified cmd window', () => {
  assert.match(tokenizerStart, /-File "%TOKENIZER_PROCESS_SCRIPT%"/i);
  assert.match(tokenizerStart, /-TokenizerUrl "%TOKENIZER_URL%"/i);
  assert.match(tokenizerStart, /-RuntimeDir "%TOKENIZER_RUNTIME_DIR%"/i);
  assert.doesNotMatch(tokenizerStart, /cmd \/k/i);
  assert.doesNotMatch(tokenizerStart, /Opening tokenizer in a new window/i);
});

test('LinguaCafe startup requires tokenizer health before treating the app as started', () => {
  const tokenizerIndex = linguacafeStart.indexOf('tokenizer-start.bat');
  const appProbeIndex = linguacafeStart.indexOf("Invoke-WebRequest -Uri '%APP_URL%'");

  assert.ok(tokenizerIndex >= 0, 'linguacafe-start.bat must call tokenizer-start.bat');
  assert.ok(appProbeIndex >= 0, 'linguacafe-start.bat must retain the application health probe');
  assert.ok(tokenizerIndex < appProbeIndex, 'tokenizer health must be established before the app startup shortcut');
  assert.match(linguacafeStart, /Tokenizer startup failed\. LinguaCafe was not started\./);
});

test('tokenizer shutdown stops the scheduled owner before scoped process cleanup', () => {
  assert.match(tokenizerStop, /Stop-ScheduledTask -TaskName \$taskName/i);
  assert.match(tokenizerStop, /Get-NetTCPConnection -LocalPort %TOKENIZER_PORT%/i);
  assert.match(tokenizerStop, /tools\\tokenizer\.py/i);
  assert.match(tokenizerStop, /non-LinguaCafe process/i);
  assert.match(tokenizerStop, /tokenizer\.pid/i);
  assert.match(linguacafeStop, /tokenizer-stop\.bat" --no-pause/i);
});
