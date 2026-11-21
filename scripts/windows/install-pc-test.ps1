param([switch]$StageOnly)

$ErrorActionPreference = "Stop"

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$projectDir = (Resolve-Path (Join-Path $scriptDir "..\..")).Path
Push-Location $projectDir
$stageDir = $null
$sourceDir = $null
$tempShortcut = $null
try {
    $gitStatus = @(git status --porcelain=v1 --untracked-files=all)
    if ($LASTEXITCODE -ne 0 -or $gitStatus.Count -ne 0) {
        throw "PC packaging requires a clean, committed source worktree."
    }

    $commit = (git rev-parse HEAD).Trim()
    if ($LASTEXITCODE -ne 0 -or $commit -notmatch '^[0-9a-f]{40}$') {
        throw "Cannot verify the exact source commit."
    }

    # Separate test lane: old installation and its Docker volumes are never touched.
    # Use the private D-drive application root prepared and checked during I06 migration.
    $installRoot = "D:\AI-Tools\LinguaCafe\PC-Test"
    if (-not (Test-Path -LiteralPath $installRoot -PathType Container)) {
        throw "PC Test D-drive root is missing; initialize and verify the private directory before installing."
    }
    $rootAcl = Get-Acl -LiteralPath $installRoot
    if (-not $rootAcl.AreAccessRulesProtected) {
        throw "PC Test D-drive root has inherited permissions; refusing to place local state here."
    }
    $legacyState = Join-Path ([Environment]::GetFolderPath("LocalApplicationData")) "LinguaCafePCTestStudyV4\state.json"
    if (-not (Test-Path -LiteralPath (Join-Path $installRoot "state.json")) -and (Test-Path -LiteralPath $legacyState)) {
        throw "PC Test state remains on C drive; verify and copy its exact contents to the private D-drive root before installing."
    }
    $releaseDir = Join-Path $installRoot ("app-" + $commit.Substring(0, 12))
    if (Test-Path -LiteralPath $releaseDir) {
        throw "This exact version is already installed; older releases are never overwritten."
    }

    $nonce = [guid]::NewGuid().ToString("N")
    $stageDir = Join-Path $installRoot ("staging-" + $nonce)
    $sourceDir = Join-Path $installRoot ("source-" + $nonce)
    New-Item -ItemType Directory -Path $stageDir, $sourceDir | Out-Null

    $runtimeZip = Join-Path $stageDir "runtime-source.zip"
    git archive --format=zip --output=$runtimeZip HEAD
    if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $runtimeZip)) {
        throw "Could not archive the exact committed runtime source."
    }

    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $archive = [System.IO.Compression.ZipFile]::OpenRead($runtimeZip)
    try {
        # Inspect names only: no secret file contents are opened, logged or uploaded.
        $forbiddenName = '(?i)(^|/)(\.env[^/]*|[^/]*\.(pem|key|p12|pfx|jks|keystore)|[^/]*secret[^/]*|credentials[^/]*|\.secrets|\.playwright-cli|\.codex|\.opencode|\.codebuddy|\.trae|\.claude|\.omo|\.reasonix|\.npmrc|\.pypirc|\.netrc|\.ssh|\.aws)(/|$)'
        $forbiddenRoot = '(?i)^(data|output|screenshots|audit_screenshots|cookies\.txt|login\.html|routes\.txt|body\.md)(/|$)'
        $unsafeCount = @($archive.Entries | Where-Object {
            ((($_.FullName -replace '\\', '/') -match $forbiddenName) -or (($_.FullName -replace '\\', '/') -match $forbiddenRoot))
        }).Count
        if ($unsafeCount -ne 0) {
            throw "PC archive rejected: $unsafeCount disallowed file or directory names."
        }
    }
    finally {
        $archive.Dispose()
    }

    # Docker and .NET compile the SAME verified source snapshot as the packaged ZIP.
    [System.IO.Compression.ZipFile]::ExtractToDirectory($runtimeZip, $sourceDir)
    # Framework-dependent Windows publish avoids an unnecessary RID runtime download.
    dotnet publish (Join-Path $sourceDir "desktop\windows-pc-test\LinguaCafe.PcTest.csproj") -c Release --self-contained false -o $stageDir
    if ($LASTEXITCODE -ne 0) { throw "dotnet publish failed." }

    $env:PC_TEST_RUNTIME_VERSION = $commit
    $env:COMPOSE_DISABLE_ENV_FILE = "1"
    $composeFile = Join-Path $sourceDir "desktop\windows-pc-test\docker-compose.pc-test.yml"
    Push-Location $sourceDir
    try {
        docker compose -p linguacafe-pc-test-study-v4 -f $composeFile build web python
        if ($LASTEXITCODE -ne 0) { throw "Docker Web/Python build failed." }
    }
    finally { Pop-Location }

    foreach ($image in @("linguacafe-pc-test-web:$commit", "linguacafe-pc-test-python:$commit")) {
        # Windows PowerShell strips the nested quotes in Docker's Go template.
        # Fetch JSON labels instead, then select the exact revision in PowerShell.
        $labelsJson = docker image inspect --format '{{json .Config.Labels}}' $image
        if ($LASTEXITCODE -ne 0) {
            throw "Could not inspect Docker image labels."
        }
        $revision = ($labelsJson | ConvertFrom-Json).'org.opencontainers.image.revision'
        if ($revision -ne $commit) {
            throw "Docker image source revision does not match archived commit."
        }
    }

    Set-Content -LiteralPath (Join-Path $stageDir "runtime-version.txt") -Value $commit -Encoding ascii -NoNewline
    $desktop = [Environment]::GetFolderPath("Desktop")
    $shortcutPath = Join-Path $desktop "LinguaCafe PC Test Study V4.lnk"
    $tempShortcut = Join-Path $desktop ("LinguaCafe PC Test." + $nonce + ".lnk")
    $shell = New-Object -ComObject WScript.Shell
    $shortcut = $shell.CreateShortcut($tempShortcut)
    $shortcut.TargetPath = Join-Path $releaseDir "LinguaCafe PC Test.exe"
    $shortcut.WorkingDirectory = $releaseDir
    $shortcut.Description = "LinguaCafe Windows PC test build"
    $shortcut.IconLocation = "$(Join-Path $releaseDir 'LinguaCafe PC Test.exe'),0"
    $shortcut.Save()

    # Previous app directories remain immutable. Switch only the shortcut.
    Move-Item -LiteralPath $stageDir -Destination $releaseDir
    $stageDir = $null
    if ($StageOnly) {
        Write-Host "Staged PC Test revision $commit at $releaseDir. Desktop shortcut remains unchanged pending real GUI validation."
        return
    }
    try {
        if (Test-Path -LiteralPath $shortcutPath) {
            $shortcutBackup = Join-Path $installRoot ("shortcut-before-" + $nonce + ".lnk")
            # Keep a verified D-drive rollback copy; File.Replace's temporary backup must stay on C:.
            [System.IO.File]::Copy($shortcutPath, $shortcutBackup)
            $localBackup = $shortcutPath + ".before-" + $nonce + ".bak"
            [System.IO.File]::Replace($tempShortcut, $shortcutPath, $localBackup)
            try { [System.IO.File]::Delete($localBackup) }
            catch { Write-Warning "Could not remove the temporary shortcut backup; D-drive rollback remains intact." }
        }
        else {
            [System.IO.File]::Move($tempShortcut, $shortcutPath)
        }
        $tempShortcut = $null
    }
    catch {
        # A failed shortcut switch must not strand this version in a non-retryable directory.
        $stageDir = Join-Path $installRoot ("staging-" + $nonce)
        Move-Item -LiteralPath $releaseDir -Destination $stageDir
        throw
    }

    Write-Host "Installed PC Test revision $commit at $releaseDir. Older versions retained."
}
finally {
    # Only this invocation's unique disposable staging folders are cleaned.
    if ($tempShortcut -and (Test-Path -LiteralPath $tempShortcut)) {
        Remove-Item -LiteralPath $tempShortcut -Force
    }
    if ($sourceDir -and (Test-Path -LiteralPath $sourceDir)) {
        Remove-Item -LiteralPath $sourceDir -Recurse -Force
    }
    if ($stageDir -and (Test-Path -LiteralPath $stageDir)) {
        Remove-Item -LiteralPath $stageDir -Recurse -Force
    }
    Pop-Location
}
