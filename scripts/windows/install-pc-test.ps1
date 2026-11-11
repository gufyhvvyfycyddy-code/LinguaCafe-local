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
    $installRoot = Join-Path ([Environment]::GetFolderPath("LocalApplicationData")) "LinguaCafePCTestNext"
    New-Item -ItemType Directory -Force -Path $installRoot | Out-Null
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
        $forbiddenName = '(?i)(^|/)(\.env[^/]*|[^/]*\.pem|[^/]*\.key|[^/]*secret[^/]*|credentials[^/]*|\.secrets|\.playwright-cli|\.codex|\.claude|\.omo|\.reasonix)(/|$)'
        $unsafeCount = @($archive.Entries | Where-Object {
            ($_.FullName -replace '\\', '/') -match $forbiddenName
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
    dotnet publish (Join-Path $sourceDir "desktop\windows-pc-test\LinguaCafe.PcTest.csproj") -c Release -r win-x64 --self-contained false -o $stageDir
    if ($LASTEXITCODE -ne 0) { throw "dotnet publish failed." }

    $env:PC_TEST_RUNTIME_VERSION = $commit
    $env:COMPOSE_DISABLE_ENV_FILE = "1"
    $composeFile = Join-Path $sourceDir "desktop\windows-pc-test\docker-compose.pc-test.yml"
    Push-Location $sourceDir
    try {
        docker compose -p linguacafe-pc-test-next -f $composeFile build web python
        if ($LASTEXITCODE -ne 0) { throw "Docker Web/Python build failed." }
    }
    finally { Pop-Location }

    foreach ($image in @("linguacafe-pc-test-web:$commit", "linguacafe-pc-test-python:$commit")) {
        $revision = (docker image inspect --format '{{ index .Config.Labels "org.opencontainers.image.revision" }}' $image)
        if ($LASTEXITCODE -ne 0 -or $revision.Trim() -ne $commit) {
            throw "Docker image source revision does not match archived commit."
        }
    }

    Set-Content -LiteralPath (Join-Path $stageDir "runtime-version.txt") -Value $commit -Encoding ascii -NoNewline
    $desktop = [Environment]::GetFolderPath("Desktop")
    $shortcutPath = Join-Path $desktop "LinguaCafe PC Test.lnk"
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
    if (Test-Path -LiteralPath $shortcutPath) {
        $shortcutBackup = Join-Path $installRoot ("shortcut-before-" + $nonce + ".lnk")
        [System.IO.File]::Replace($tempShortcut, $shortcutPath, $shortcutBackup)
    }
    else {
        [System.IO.File]::Move($tempShortcut, $shortcutPath)
    }
    $tempShortcut = $null

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
