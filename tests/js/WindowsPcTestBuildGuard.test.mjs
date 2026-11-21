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

assert.match(compose, /name: linguacafe-pc-test-study-v4/);
assert.match(compose, /linguacafe-pc-test-web:\$\{PC_TEST_RUNTIME_VERSION:-dev\}/);
assert.match(compose, /linguacafe-pc-test-python:\$\{PC_TEST_RUNTIME_VERSION:-dev\}/);
assert.match(compose, /MYSQL_DATABASE: linguacafe_pc_test_study_v4/);
assert.match(compose, /127\.0\.0\.1:9396:80/);
assert.match(compose, /127\.0\.0\.1:6006:6001/);
assert.match(compose, /mysql-data:\/var\/lib\/mysql/);
assert.match(compose, /storage-data:\/var\/www\/html\/storage/);
assert.match(compose, /\$\{PC_TEST_ECDICT_CSV:-\.\/empty-ecdict\.csv\}:\/pc-test\/ecdict\.csv:ro/);
assert.doesNotMatch(compose, /C:\/Users\/Administrator|C:\\\\Users\\\\Administrator/);
assert.match(dockerignore, /^\/desktop$/m);
assert.match(dockerignore, /^\.env\*$/m);
assert.match(dockerignore, /^\*\*\/\.env\*$/m);
const dockerIgnoredPaths = new Set(dockerignore.split(/\r?\n/));
for (const directory of [
  '.secrets', '.playwright-cli', '.codex', '.opencode', '.codebuddy',
  '.trae', '.claude', '.omo', '.reasonix', '.ssh', '.aws',
]) {
  assert.ok(dockerIgnoredPaths.has(`**/${directory}`), `Docker must exclude directory root: ${directory}`);
  assert.ok(dockerIgnoredPaths.has(`**/${directory}/**`), `Docker must exclude directory contents: ${directory}`);
}
const attrs = read('.gitattributes');
assert.match(attrs, /^\.env\* -diff export-ignore$/m);
assert.match(attrs, /^\*\*\/\.env\* -diff export-ignore$/m);
assert.match(attrs, /^\.playwright-cli\/\*\* export-ignore$/m);
assert.match(attrs, /^\.opencode\/\*\* export-ignore$/m);
for (const file of ['.envrc', '.env_backup', '.env-prod', 'nested/.envrc']) {
  assert.match(file, /(^|\/)\.env[^/]*/i);
}
for (const file of ['docker/PhpDockerfile', 'docker/PythonDockerfile']) {
  assert.match(read(file), /LABEL org\.opencontainers\.image\.revision=\$PC_TEST_SOURCE_COMMIT/);
}
const phpDockerfile = read('docker/PhpDockerfile');
assert.match(phpDockerfile, /CARGO_NET_GIT_FETCH_WITH_CLI=true cargo build --release --locked/);
assert.match(phpDockerfile, /--mount=type=cache,id=linguacafe-pc-test-cargo-git/);
assert.match(phpDockerfile, /cp target\/release\/libfsrs_rs_php\.so libfsrs_rs_php\.so/);
assert.match(phpDockerfile, /RUN composer install --optimize-autoloader --no-dev \\\s*&& rm -f vendor\/laravel\/horizon\/\.npmrc/);
assert.match(compose, /PC_TEST_SOURCE_COMMIT: \$\{PC_TEST_RUNTIME_VERSION:-dev\}/);
assert.match(gitignore, /^\/desktop\/windows-pc-test\/bin\/$/m);
assert.match(gitignore, /^\/desktop\/windows-pc-test\/obj\/$/m);
assert.doesNotMatch(compose, /env_file:|\.env/);

assert.match(runtime, /APPDATA|LocalApplicationData/);
assert.match(runtime, /LinguaCafePCTestStudyV4/);
assert.match(runtime, /127\.0\.0\.1:9396/);
assert.match(runtime, /linguacafe-pc-test-study-v4/);
assert.match(runtime, /runtime-version\.txt/);
assert.match(runtime, /DockerImageMatchesRevisionAsync/);
assert.match(runtime, /org.opencontainers.image.revision/);
assert.match(runtime, /COMPOSE_DISABLE_ENV_FILE/);
assert.match(runtime, /PC_TEST_RUNTIME_VERSION/);
assert.match(runtime, /runtimeImageTag != "dev"/);
assert.match(runtime, /"up", "-d", "--wait", "--wait-timeout", "600", "mysql", "redis", "python"/);
assert.match(runtime, /"migrate", "--force"/);
assert.match(runtime, /"db:seed", "--force"/);
assert.match(runtime, /dictionary:import-ecdict/);
assert.match(runtime, /PC_TEST_ECDICT_CSV/);
assert.doesNotMatch(runtime, /migrate:fresh|migrate:refresh|migrate:reset|db:wipe/);

assert.match(runtime, /DataRoot = Path\.Combine\(@"D:\\AI-Tools", "LinguaCafe", "PC-Test"\)/);
assert.match(windowCode, /Path\.Combine\(RuntimeManager\.DataRoot, "startup\.log"\)/);
assert.match(windowCode, /Path\.Combine\(RuntimeManager\.DataRoot, "WebView2"\)/);
assert.doesNotMatch(windowCode, /"LinguaCafePCTest"/);
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
assert.match(installer, /app-.*commit\.Substring/);
assert.doesNotMatch(installer, /Remove-Item -Recurse -Force \$installDir/);
assert.match(installer, /dotnet publish[\s\S]*-o \$stageDir/);
assert.match(installer, /git archive --format=zip --output=\$runtimeZip HEAD/);
assert.match(installer, /\$env:PC_TEST_RUNTIME_VERSION = \$commit/);
assert.match(installer, /docker compose[\s\S]*build web python/);
assert.match(installer, /Move-Item -LiteralPath \$stageDir -Destination \$releaseDir/);
assert.match(installer, /\[System\.IO\.File\]::Copy\(\$shortcutPath, \$shortcutBackup\)/);
assert.match(installer, /\[System\.IO\.File\]::Replace\(\$tempShortcut, \$shortcutPath, \$localBackup\)/);
assert.match(installer, /ZipFile\]::OpenRead\(\$runtimeZip\)/);
assert.match(installer, /unsafeCount -ne 0/);
assert.match(installer, /ExtractToDirectory\(\$runtimeZip, \$sourceDir\)/);
assert.match(installer, /org\.opencontainers\.image\.revision/);
assert.match(installer, /docker run --rm --network none --read-only --entrypoint find/);
assert.match(installer, /unsafeImageCount -ne 0/);
assert.match(installer, /\$relativePath -notin \$publicVendorClasses/);
assert.match(installer, /vendor\/laravel\/framework\/src\/Illuminate\/Console\/View\/Components\/Secret\.php/);
assert.match(installer, /vendor\/laravel\/serializable-closure\/src\/Exceptions\/MissingSecretKeyException\.php/);
assert.match(installer, /COMPOSE_DISABLE_ENV_FILE/);
assert.match(installer, /runtime-version\.txt/);
assert.match(installer, /LinguaCafe PC Test Study V4\.lnk/);
assert.match(installer, /LinguaCafePCTestStudyV4/);
assert.match(installer, /docker compose -p linguacafe-pc-test-study-v4/);
assert.match(installer, /LinguaCafe PC Test\.exe/);

// Run the actual release/shortcut switch in temporary paths. A failed shortcut
// replacement must leave the old shortcut intact and permit a retry of the SHA.
if (process.platform === 'win32') {
  const begin = installer.indexOf('    Move-Item -LiteralPath $stageDir -Destination $releaseDir');
  const end = installer.indexOf('    Write-Host "Installed', begin);
  assert.ok(begin > 0 && end > begin, 'release switch block missing');
  const actualSwitch = installer.slice(begin, end);
  const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'lc-i05-switch-'));
  const backupTemp = fs.mkdtempSync(path.join(root, '.i05-backup-'));
  const crossVolume = path.parse(temp).root.toLowerCase() !== path.parse(backupTemp).root.toLowerCase();
  const quote = (s) => "'" + s.replaceAll("'", "''") + "'";
  try {
    // Exercise the stricter installer scanner against a real ZIP: even empty
    // excluded browser directories are unsafe; nested secrets must also fail closed.
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
  if ($unsafe) {
    [void]$zip.CreateEntry('nested/.envrc')
    [void]$zip.CreateEntry('.opencode/')
    [void]$zip.CreateEntry('.codebuddy/')
    [void]$zip.CreateEntry('.trae/')
  }
  $zip.Dispose()
  $archive = [System.IO.Compression.ZipFile]::OpenRead($zipPath)
${scanner}
  $archive.Dispose()
  if ($unsafeCount -ne (1 + 4 * [int]$unsafe)) { throw 'Archive scan did not reject browser directory and secret file' }
}
`, 'utf8');
    const scanResult = spawnSync('powershell.exe', ['-NoProfile', '-NonInteractive', '-File', scanFile],
      { encoding: 'utf8', timeout: 20000, windowsHide: true });
    assert.equal(scanResult.status, 0, `archive scan: ${scanResult.stderr || scanResult.stdout || scanResult.error}`);

    // Exercise the production final-image filename filter without requiring Docker in CI.
    const imageScanStart = installer.indexOf('        $publicVendorClasses = @(');
    const imageScanEnd = installer.indexOf('        if ($unsafeImageCount -ne 0)', imageScanStart);
    assert.ok(imageScanStart >= 0 && imageScanEnd > imageScanStart, 'final image filename scanner missing');
    const imageScanner = installer.slice(imageScanStart, imageScanEnd);
    const forbiddenAssignments = installer.split(/\r?\n/).filter(line =>
      line.includes('$forbiddenName = ') || line.includes('$forbiddenRoot = ')).join('\n');
    const imageProbe = path.join(temp, 'image-scan.ps1');
    fs.writeFileSync(imageProbe, `$ErrorActionPreference='Stop'
${forbiddenAssignments}
$imageRoot='/var/www/html'
$imagePaths=@('/var/www/html/README.md',
 '/var/www/html/vendor/laravel/framework/src/Illuminate/Console/View/Components/Secret.php',
 '/var/www/html/private/.envrc', '/var/www/html/private/.playwright-cli/page.yml',
 '/var/www/html/vendor/laravel/horizon/.npmrc')
${imageScanner}
if ($unsafeImageCount -ne 3) { throw 'Final-image checker failed to reject private files or vendor npm config' }
$imagePaths=@('/var/www/html/README.md',
 '/var/www/html/vendor/laravel/framework/src/Illuminate/Console/View/Components/Secret.php',
 '/var/www/html/vendor/laravel/serializable-closure/src/Exceptions/MissingSecretKeyException.php')
${imageScanner}
if ($unsafeImageCount -ne 0) { throw 'Final-image checker rejected public framework files' }
$imageRoot='/app'
$imagePaths=@('/app/main.py', '/app/private/.aws/credentials', '/app/private/key.pem')
${imageScanner}
if ($unsafeImageCount -ne 2) { throw 'Python image checker missed private files' }
`, 'utf8');
    const imageProbeResult = spawnSync('powershell.exe', ['-NoProfile', '-NonInteractive', '-File', imageProbe],
      { encoding: 'utf8', timeout: 20000, windowsHide: true });
    assert.equal(imageProbeResult.status, 0,
      `final-image filename filter: ${imageProbeResult.stderr || imageProbeResult.stdout || imageProbeResult.error}`);

    for (const fail of [false, true]) {
      const rootDir = path.join(temp, fail ? 'fail' : 'success');
      const backupDir = path.join(backupTemp, fail ? 'fail' : 'success');
      const stageDir = path.join(backupDir, 'staging-test');
      const releaseDir = path.join(backupDir, 'app-a1b2c3');
      const shortcut = path.join(rootDir, 'old.lnk');
      const tempShortcut = path.join(rootDir, 'new.lnk');
      fs.mkdirSync(rootDir, { recursive: true });
      fs.mkdirSync(stageDir, { recursive: true });
      fs.writeFileSync(path.join(stageDir, 'app.exe'), 'new');
      const createLinks = `$ErrorActionPreference='Stop'
$shell=New-Object -ComObject WScript.Shell
$old=$shell.CreateShortcut(${quote(shortcut)})
$old.TargetPath=Join-Path $env:SystemRoot 'System32\\notepad.exe'
$old.Save()
$new=$shell.CreateShortcut(${quote(tempShortcut)})
$new.TargetPath=Join-Path $env:SystemRoot 'System32\\cmd.exe'
$new.Save()`;
      const createResult = spawnSync('powershell.exe', ['-NoProfile', '-NonInteractive', '-Command', createLinks],
        { encoding: 'utf8', timeout: 20000, windowsHide: true });
      assert.equal(createResult.status, 0, 'Could not create real Windows shortcut fixtures');
      const oldBytes = fs.readFileSync(shortcut);
      const newBytes = fs.readFileSync(tempShortcut);
      assert.notDeepEqual(oldBytes, newBytes);
      if (fail) fs.mkdirSync(shortcut + '.before-test.bak');
      const probeFile = path.join(rootDir, 'switch.ps1');
      fs.writeFileSync(probeFile, `$ErrorActionPreference='Stop'
$stageDir=${quote(stageDir)}
$releaseDir=${quote(releaseDir)}
$shortcutPath=${quote(shortcut)}
$tempShortcut=${quote(tempShortcut)}
$installRoot=${quote(backupDir)}
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
      assert.deepEqual(fs.readFileSync(shortcut), fail ? oldBytes : newBytes);
      assert.deepEqual(fs.readFileSync(path.join(backupDir, 'shortcut-before-test.lnk')), oldBytes);
      assert.equal(fs.existsSync(shortcut + '.before-test.bak'), fail);
    }
  } finally {
    fs.rmSync(temp, { recursive: true, force: true });
    fs.rmSync(backupTemp, { recursive: true, force: true });
  }
  if (crossVolume) console.log('PC shortcut switch C/D cross-volume verified.');
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
