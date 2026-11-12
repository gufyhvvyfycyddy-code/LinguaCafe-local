import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const read = (...parts) => fs.readFileSync(path.join(root, ...parts), 'utf8');

const project = read('desktop', 'windows-pc-test', 'LinguaCafe.PcTest.csproj');
const runtime = read('desktop', 'windows-pc-test', 'RuntimeManager.cs');
const windowCode = read('desktop', 'windows-pc-test', 'MainWindow.xaml.cs');
const compose = read('desktop', 'windows-pc-test', 'docker-compose.pc-test.yml');
const installer = read('scripts', 'windows', 'install-pc-test.ps1');
const plan = read('docs', 'plans', 'windows-pc-test-build-plan-2026-09-01.md');
const goal = read('docs', 'plans', 'LinguaCafe_Goal_Mode_All_Milestones_Sol_Medium_2026-08-09.md');
const feedback = read('docs', 'testing', 'windows-pc-test-feedback-log.md');
const routes = read('routes', 'web.php');
const dockerignore = read('.dockerignore');
const gitignore = read('.gitignore');

assert.match(project, /<TargetFramework>net8\.0-windows<\/TargetFramework>/);
assert.match(project, /<PackageReference Include="Microsoft\.Web\.WebView2" Version="1\.0\.4191\.47"/);

assert.match(compose, /name: linguacafe-pc-test-next/);
assert.match(compose, /linguacafe-pc-test-web:\$\{PC_TEST_RUNTIME_VERSION:-dev\}/);
assert.match(compose, /linguacafe-pc-test-python:\$\{PC_TEST_RUNTIME_VERSION:-dev\}/);
assert.match(compose, /MYSQL_DATABASE: linguacafe_pc_test/);
// PC import must not start while the tokenizer process is running but its
// English model is missing or the HTTP service is not ready.
assert.match(compose, /python:\s*\n\s*condition: service_healthy/);
assert.match(compose, /http:\/\/127\.0\.0\.1:8678\/tokenizer\/health/);
assert.match(compose, /info\.get\('english',\{\}\)\.get\('model_loaded'\)/);
assert.match(compose, /info\.get\('english',\{\}\)\.get\('lemminflect_available'\)/);
assert.match(compose, /127\.0\.0\.1:9391:80/);
assert.match(compose, /127\.0\.0\.1:6001:6001/);
assert.match(compose, /mysql-data:\/var\/lib\/mysql/);
assert.match(compose, /PC_TEST_SOURCE_COMMIT: \$\{PC_TEST_RUNTIME_VERSION:-dev\}/);
assert.match(runtime, /linguacafe-pc-test-next/);
assert.match(runtime, /LinguaCafePCTestNext/);
assert.equal((windowCode.match(/LinguaCafePCTestNext/g) ?? []).length, 2);
assert.doesNotMatch(windowCode, /"LinguaCafePCTest"/);
for (const dockerfile of [read('docker', 'PhpDockerfile'), read('docker', 'PythonDockerfile')]) {
  assert.match(dockerfile, /LABEL org\.opencontainers\.image\.revision=\$PC_TEST_SOURCE_COMMIT/);
}
assert.match(compose, /storage-data:\/var\/www\/html\/storage/);
assert.match(compose, /\$\{PC_TEST_ECDICT_CSV:-\.\/empty-ecdict\.csv\}:\/pc-test\/ecdict\.csv:ro/);
assert.doesNotMatch(compose, /C:\/Users\/Administrator|C:\\\\Users\\\\Administrator/);
assert.match(dockerignore, /^\/desktop$/m);
assert.match(gitignore, /^\/desktop\/windows-pc-test\/bin\/$/m);
assert.match(gitignore, /^\/desktop\/windows-pc-test\/obj\/$/m);
assert.match(gitignore, /^\.env\*$/m);
assert.match(dockerignore, /^\.env\*$/m);
assert.match(dockerignore, /^\*\*\/\.env\*$/m);
const attributes = read('.gitattributes');
assert.match(attributes, /^\.env\* -diff export-ignore$/m);
assert.match(attributes, /^\*\*\/\.env\* -diff export-ignore$/m);
for (const name of ['.envrc', '.env_backup', '.env-prod', 'nested/.envrc']) {
  const pattern = /(^|\/)\.env[^/]*/i;
  assert.match(name, pattern, `Unsafe environment filename should be detected: ${name}`);
}
assert.match(installer, /ZipFile\]::OpenRead\(\$runtimeZip\)/);
assert.match(installer, /\.env\[\^\/\]\*/);
assert.match(installer, /unsafeCount -ne 0/);
assert.match(installer, /ExtractToDirectory\(\$runtimeZip, \$sourceDir\)/);
assert.match(installer, /-f \$composeFile build web python/);
assert.match(installer, /org\.opencontainers\.image\.revision/);
assert.match(installer, /COMPOSE_DISABLE_ENV_FILE/);
assert.match(installer, /Push-Location \$sourceDir/);
assert.match(installer, /LinguaCafePCTestNext/);
assert.match(installer, /app-.*commit\.Substring/);
assert.match(installer, /shortcut-before-/);
assert.doesNotMatch(installer, /Remove-Item -Recurse -Force \$installDir/);
assert.match(installer, /\[System\.IO\.File\]::Replace\(\$tempShortcut, \$shortcutPath, \$shortcutBackup\)/);
assert.match(installer, /Move-Item -LiteralPath \$releaseDir -Destination \$stageDir/);
assert.doesNotMatch(compose, /env_file:|\.env/);

assert.match(runtime, /APPDATA|LocalApplicationData/);
assert.match(runtime, /runtime-version\.txt/);
assert.match(runtime, /DockerImageMatchesRevisionAsync/);
// Cancellation must terminate the spawned Docker CLI, not merely abandon its wait.
assert.match(runtime, /catch \(OperationCanceledException\) when \(cancellationToken\.IsCancellationRequested\)/);
assert.match(runtime, /process\.Kill\(entireProcessTree: true\)/);
assert.match(runtime, /await process\.WaitForExitAsync\(CancellationToken\.None\)/);
assert.match(runtime, /"image", "inspect", "--format", "\{\{ index \.Config\.Labels/);
assert.match(runtime, /result\.ExitCode == 0 && result\.StandardOutput\.Trim\(\) == revision/);
assert.match(runtime, /PC_TEST_RUNTIME_VERSION/);
assert.match(runtime, /runtimeImageTag != "dev"/);
assert.match(runtime, /"up", "-d", "--wait", "--wait-timeout", "120", "mysql", "redis", "python"/);
assert.match(runtime, /"migrate", "--force"/);
assert.match(runtime, /"db:seed", "--force"/);
assert.match(runtime, /dictionary:import-ecdict/);
assert.match(runtime, /PC_TEST_ECDICT_CSV/);
assert.doesNotMatch(runtime, /migrate:fresh|migrate:refresh|migrate:reset|db:wipe/);

assert.match(windowCode, /\/setup/);
assert.match(windowCode, /\/login/);
assert.match(windowCode, /input\[autocomplete="email"\]/);
assert.match(windowCode, /linguacafe-email/);
assert.match(windowCode, /linguacafe-password/);
assert.doesNotMatch(windowCode, /!button \|\| button\.disabled/);
assert.equal((windowCode.match(/if \(button\.disabled\) return 'filled';/g) ?? []).length, 2);
assert.match(windowCode, /const loginLink = document\.querySelector\('a\[href="\/login"\]'\);/);
assert.match(windowCode, /if \(!email && loginLink\) \{\s*loginLink\.click\(\);\s*return 'submitted';/);
assert.doesNotMatch(windowCode, /__pc-test|Auth::login|loginUsingId/);
assert.doesNotMatch(routes, /__pc-test|pc-test-login|desktop-auto-login/);

assert.match(installer, /git status --porcelain=v1 --untracked-files=all/);
assert.doesNotMatch(installer, /git diff --quiet|git diff --cached --quiet/);
assert.match(installer, /staging-/);
assert.match(installer, /dotnet publish[\s\S]*-o \$stageDir/);
assert.match(installer, /git archive --format=zip --output=\$runtimeZip HEAD/);
assert.match(installer, /\$env:PC_TEST_RUNTIME_VERSION = \$commit/);
assert.match(installer, /docker compose[\s\S]*build web python/);
assert.match(installer, /Move-Item -LiteralPath \$stageDir -Destination \$releaseDir/);
assert.match(installer, /runtime-version\.txt/);
assert.match(installer, /LinguaCafe PC Test\.lnk/);
assert.match(installer, /LinguaCafe PC Test\.exe/);

// Run the actual release/shortcut switch in temporary paths. A failed shortcut
// replacement must leave the old shortcut intact and permit a retry of the SHA.
if (process.platform === 'win32') {
  const begin = installer.indexOf('    Move-Item -LiteralPath $stageDir -Destination $releaseDir');
  const end = installer.indexOf('    Write-Host "Installed', begin);
  assert.ok(begin > 0 && end > begin, 'release switch block missing');
  const actualSwitch = installer.slice(begin, end);
  const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'lc-i05-switch-'));
  const quote = (s) => "'" + s.replaceAll("'", "''") + "'";
  try {
    // Exercise the installer scanner against a real ZIP: empty excluded directory
    // entries are harmless, while nested secret files must still fail closed.
    const scanStart = installer.indexOf('        $forbiddenName = ');
    const scanEnd = installer.indexOf('        if ($unsafeCount -ne 0)', scanStart);
    assert.ok(scanStart >= 0 && scanEnd > scanStart, 'archive filename scanner missing');
    const scanner = installer.slice(scanStart, scanEnd);
    const scanFile = path.join(temp, 'archive-scan.ps1');
    fs.writeFileSync(scanFile, `$ErrorActionPreference='Stop'
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
foreach ($unsafe in @($false, $true)) {
  $zipPath = Join-Path ${quote(temp)} ($unsafe.ToString() + '.zip')
  $zip = [System.IO.Compression.ZipFile]::Open($zipPath, [System.IO.Compression.ZipArchiveMode]::Create)
  [void]$zip.CreateEntry('.playwright-cli/')
  [void]$zip.CreateEntry('safe.txt')
  if ($unsafe) { [void]$zip.CreateEntry('nested/.envrc') }
  $zip.Dispose()
  $archive = [System.IO.Compression.ZipFile]::OpenRead($zipPath)
${scanner}
  $archive.Dispose()
  if ($unsafeCount -ne [int]$unsafe) { throw 'Archive scan did not distinguish empty directory from secret file' }
}
`, 'utf8');
    const scanResult = spawnSync('powershell.exe', ['-NoProfile', '-NonInteractive', '-File', scanFile],
      { encoding: 'utf8', timeout: 20000, windowsHide: true });
    assert.equal(scanResult.status, 0, `archive scan: ${scanResult.stderr || scanResult.stdout || scanResult.error}`);

    for (const fail of [false, true]) {
      const rootDir = path.join(temp, fail ? 'fail' : 'success');
      const stageDir = path.join(rootDir, 'staging-test');
      const releaseDir = path.join(rootDir, 'app-a1b2c3');
      const shortcut = path.join(rootDir, 'old.lnk');
      const tempShortcut = path.join(rootDir, 'new.lnk');
      fs.mkdirSync(stageDir, { recursive: true });
      fs.writeFileSync(path.join(stageDir, 'app.exe'), 'new');
      fs.writeFileSync(tempShortcut, 'new-link');
      if (fail) fs.mkdirSync(shortcut);
      else fs.writeFileSync(shortcut, 'old-link');
      const probeFile = path.join(rootDir, 'switch.ps1');
      fs.writeFileSync(probeFile, `$ErrorActionPreference='Stop'
$stageDir=${quote(stageDir)}
$releaseDir=${quote(releaseDir)}
$shortcutPath=${quote(shortcut)}
$tempShortcut=${quote(tempShortcut)}
$installRoot=${quote(rootDir)}
$nonce='test'
try {
${actualSwitch}
} catch {
  if (${fail ? '$true' : '$false'}) { exit 19 }
  throw
}
`, 'utf8');
      const result = spawnSync('powershell.exe', ['-NoProfile', '-NonInteractive', '-File', probeFile],
        { encoding: 'utf8', timeout: 20000, windowsHide: true });
      assert.equal(result.status, fail ? 19 : 0,
        `shortcut switch ${fail ? 'failure' : 'success'}: ${result.stderr || result.stdout || result.error}`);
      assert.equal(fs.existsSync(releaseDir), !fail);
      assert.equal(fs.existsSync(stageDir), fail);
      assert.equal(fs.existsSync(path.join(fail ? stageDir : releaseDir, 'app.exe')), true);
      if (fail) assert.equal(fs.statSync(shortcut).isDirectory(), true);
      else {
        assert.equal(fs.readFileSync(shortcut, 'utf8'), 'new-link');
        assert.equal(fs.readFileSync(path.join(rootDir, 'shortcut-before-test.lnk'), 'utf8'), 'old-link');
      }
    }
  } finally {
    fs.rmSync(temp, { recursive: true, force: true });
  }
}

assert.match(goal, /## Phase I — Windows PC 测试版 \+ 产品设计者持续反馈/);
for (const milestone of ['I-00', 'I-01', 'I-02', 'I-03', 'I-04', 'I-05', 'I-GATE']) {
  assert.match(goal, new RegExp(`\\| ${milestone} \\|`));
}
assert.match(plan, /dedicated local Docker runtime/);
assert.match(plan, /No production authentication bypass route is added/);
assert.match(plan, /windows-pc-test-feedback-log\.md/);
assert.match(feedback, /FIXED_AWAITING_RETEST/);
assert.match(feedback, /CLOSED/);

console.log('Windows PC test build guard passed.');
