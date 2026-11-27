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
const pcState = read('desktop', 'windows-pc-test', 'PcTestState.cs');
const pcStatePersistence = read('desktop', 'windows-pc-test', 'PcTestStatePersistence.cs');
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

assert.match(compose, /name: linguacafe-pc-test-study-v5/);
assert.match(compose, /linguacafe-pc-test-web:\$\{PC_TEST_RUNTIME_VERSION:-dev\}/);
assert.match(compose, /linguacafe-pc-test-python:\$\{PC_TEST_RUNTIME_VERSION:-dev\}/);
assert.match(compose, /MYSQL_DATABASE: linguacafe_pc_test_study_v5/);
assert.match(compose, /127\.0\.0\.1:9397:80/);
assert.match(compose, /127\.0\.0\.1:6007:6001/);
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
for (const directory of ['output', 'screenshots', 'audit_screenshots']) {
  assert.ok(dockerIgnoredPaths.has(`**/${directory}`), `Docker nested ${directory} root must be excluded`);
  assert.ok(dockerIgnoredPaths.has(`**/${directory}/**`), `Docker nested ${directory} files must be excluded`);
  assert.match(attrs, new RegExp(`^\\*\\*/${directory} export-ignore$`, 'm'));
  assert.match(attrs, new RegExp(`^\\*\\*/${directory}/\\*\\* export-ignore$`, 'm'));
}
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
assert.match(runtime, /LinguaCafePCTestStudyV5/);
assert.match(runtime, /127\.0\.0\.1:9397/);
assert.match(runtime, /linguacafe-pc-test-study-v5/);
assert.match(runtime, /runtime-version\.txt/);
// I05: desktop auto-login secret is encrypted for the Windows current user.
assert.match(pcState, /\[JsonIgnore\]\s*public string AdminPassword/);
assert.match(pcState, /public int SchemaVersion/);
assert.match(pcStatePersistence, /ProtectedData\.Protect\(/);
assert.match(pcStatePersistence, /ProtectedData\.Unprotect\(/);
assert.match(pcStatePersistence, /DataProtectionScope\.CurrentUser/);
assert.match(pcStatePersistence, /StudyV5\.AdminPassword/);
assert.match(pcStatePersistence, /File\.Replace\(staging, path, null\)/);
assert.match(runtime, /PcTestStatePersistence\.Load\(StatePath\)/);
assert.match(runtime, /PcTestStatePersistence\.Save\(StatePath, state\)/);
assert.doesNotMatch(runtime, /File\.WriteAllText\(StatePath/);
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

assert.match(runtime, /DataRoot = Path\.Combine\(@"D:\\AI-Tools", "LinguaCafe", "PC-Test-V5"\)/);
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
assert.match(installer, /param\(\[switch\]\$StageOnly, \[switch\]\$ActivateStaged\)/);
assert.match(installer, /git archive --format=zip --output=\$verificationArchive HEAD/);
assert.match(installer, /Staged PC Test archive differs from the exact committed source/);
assert.match(installer, /if \(-not \$ActivateStaged\)/);
assert.match(installer, /On activation failure, keep the staged revision intact/);
assert.match(installer, /D:\\AI-Tools\\LinguaCafe\\PC-Test-V5/);
assert.doesNotMatch(installer, /PC Test Study V4\.lnk|linguacafe-pc-test-study-v4/);
assert.match(installer, /LinguaCafe PC Test Study V5\.lnk/);
assert.match(installer, /LinguaCafePCTestStudyV5/);
assert.match(installer, /docker compose -p linguacafe-pc-test-study-v5/);
assert.match(installer, /LinguaCafe PC Test\.exe/);

// Run the actual release/shortcut switch in temporary paths. A failed shortcut
// replacement must leave the old shortcut intact and permit a retry of the SHA.
if (process.platform === 'win32') {
  const begin = installer.indexOf('    # Previous app directories remain immutable. Switch only the shortcut.');
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
    [void]$zip.CreateEntry('nested/output/capture.log')
    [void]$zip.CreateEntry('nested/screenshots/page.png')
    [void]$zip.CreateEntry('nested/audit_screenshots/audit.txt')
  }
  $zip.Dispose()
  $archive = [System.IO.Compression.ZipFile]::OpenRead($zipPath)
${scanner}
  $archive.Dispose()
  if ($unsafeCount -ne (1 + 7 * [int]$unsafe)) { throw 'Archive scan did not reject nested captures and secret files' }
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
 '/var/www/html/vendor/laravel/horizon/.npmrc',
 '/var/www/html/docs/audit_screenshots/report.json')
${imageScanner}
if ($unsafeImageCount -ne 4) { throw 'Final-image checker failed to reject private files, nested captures or vendor npm config' }
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

    // A real Git archive must omit tracked nested captures but retain ordinary source.
    const gitSource = path.join(temp, 'synthetic-git-source');
    const gitZip = path.join(temp, 'synthetic-git-source.zip');
    fs.mkdirSync(gitSource);
    fs.copyFileSync(path.join(root, '.gitattributes'), path.join(gitSource, '.gitattributes'));
    for (const name of ['nested/output/raw.txt', 'nested/screenshots/page.png',
      'nested/audit_screenshots/report.txt', 'nested/.envrc', 'nested/keep.txt']) {
      const file = path.join(gitSource, name);
      fs.mkdirSync(path.dirname(file), { recursive: true });
      fs.writeFileSync(file, 'synthetic only');
    }
    for (const args of [
      ['init', '--quiet'],
      ['add', '-f', '--', '.gitattributes', 'nested'],
      ['-c', 'user.name=Test', '-c', 'user.email=test@example.invalid', 'commit', '--quiet', '-m', 'synthetic'],
      ['archive', '--format=zip', '--output=' + gitZip, 'HEAD'],
    ]) {
      const proc = spawnSync('git', args, { cwd: gitSource, encoding: 'utf8', timeout: 15000 });
      assert.equal(proc.status, 0, 'isolated Git archive fixture: ' + (proc.stderr || proc.error));
    }
    const archiveCheck = path.join(temp, 'git-export-check.ps1');
    fs.writeFileSync(archiveCheck, [
      "$ErrorActionPreference='Stop'",
      'Add-Type -AssemblyName System.IO.Compression.FileSystem',
      '$zip=[System.IO.Compression.ZipFile]::OpenRead(' + quote(gitZip) + ')',
      'try {',
      '  $names=@($zip.Entries | ForEach-Object { $_.FullName })',
      "  if ($names -notcontains 'nested/keep.txt') { throw 'Allowed source file missing from ZIP' }",
      "  $unsafe=@($names | Where-Object { $_ -match '(?i)(^|/)(output|screenshots|audit_screenshots)(/|$)|(^|/)\\.env[^/]*$' })",
      "  if ($unsafe.Count -ne 0) { throw 'Nested private fixture reached ZIP' }",
      '} finally { $zip.Dispose() }',
    ].join('\n'), 'utf8');
    const archiveCheckResult = spawnSync('powershell.exe', ['-NoProfile', '-NonInteractive',
      '-File', archiveCheck], { encoding: 'utf8', timeout: 15000, windowsHide: true });
    assert.equal(archiveCheckResult.status, 0,
      'actual Git ZIP export: ' + (archiveCheckResult.stderr || archiveCheckResult.error));

    // Stage activation verifies a real Git-generated archive against the staged ZIP.
    const verifyBegin = installer.indexOf('    if ($ActivateStaged) {\r\n        # Recheck the staged ZIP');
    const verifyEnd = installer.indexOf('    else {\r\n        $stageDir', verifyBegin);
    assert.ok(verifyBegin > 0 && verifyEnd > verifyBegin, 'activation integrity gate missing');
    const verifier = installer.slice(verifyBegin, verifyEnd);
    for (const bad of [false, true]) {
      const stagedZip = path.join(temp, bad ? 'tampered-staged.zip' : 'valid-staged.zip');
      fs.copyFileSync(gitZip, stagedZip);
      if (bad) fs.appendFileSync(stagedZip, 'tampered');
      const verifyPs = path.join(temp, bad ? 'verify-bad.ps1' : 'verify-good.ps1');
      fs.writeFileSync(verifyPs, [
        "$ErrorActionPreference='Stop'",
        '$ActivateStaged=$true',
        '$installRoot=' + quote(temp),
        '$publishedZip=' + quote(stagedZip),
        "$nonce='synthetic-verify'",
        'try {',
        verifier,
        '} catch {',
        bad ? '  exit 19' : '  throw',
        '}',
      ].join('\n'), 'utf8');
      const checked = spawnSync('powershell.exe', ['-NoProfile', '-NonInteractive', '-File', verifyPs],
        { cwd: gitSource, encoding: 'utf8', timeout: 20000, windowsHide: true });
      assert.equal(checked.status, bad ? 19 : 0,
        'staged ZIP integrity: ' + (checked.stderr || checked.stdout || checked.error));
    }

    for (const [activateStaged, fail] of [[false, false], [false, true], [true, false], [true, true]]) {
      const caseName = (activateStaged ? 'activate-' : 'fresh-') + (fail ? 'fail' : 'success');
      const rootDir = path.join(temp, caseName);
      const backupDir = path.join(backupTemp, caseName);
      const stageDir = path.join(backupDir, 'staging-test');
      const releaseDir = path.join(backupDir, 'app-a1b2c3');
      const shortcut = path.join(rootDir, 'old.lnk');
      const tempShortcut = path.join(rootDir, 'new.lnk');
      fs.mkdirSync(rootDir, { recursive: true });
      fs.mkdirSync(activateStaged ? releaseDir : stageDir, { recursive: true });
      fs.writeFileSync(path.join(activateStaged ? releaseDir : stageDir, 'app.exe'), 'new');
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
$ActivateStaged=${activateStaged ? '$true' : '$false'}
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
      assert.equal(fs.existsSync(releaseDir), activateStaged || !fail);
      assert.equal(fs.existsSync(stageDir), !activateStaged && fail);
      assert.equal(fs.existsSync(path.join(!activateStaged && fail ? stageDir : releaseDir, 'app.exe')), true);
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
