import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
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
assert.doesNotMatch(compose, /env_file:|\.env/);

assert.match(runtime, /APPDATA|LocalApplicationData/);
assert.match(runtime, /runtime-version\.txt/);
assert.match(runtime, /DockerImageExistsAsync/);
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
