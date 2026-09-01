$ErrorActionPreference = "Stop"

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$projectDir = Resolve-Path (Join-Path $scriptDir "..\..")
Push-Location $projectDir
try {
    $gitStatus = @(git status --porcelain=v1 --untracked-files=all)
    if ($LASTEXITCODE -ne 0) {
        throw "Could not inspect Git status before PC test packaging."
    }
    if ($gitStatus.Count -ne 0) {
        throw "PC test packaging requires a clean Git worktree with no tracked or untracked source changes. Commit or isolate the current changes first."
    }

    $commit = (git rev-parse HEAD).Trim()
    if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($commit)) {
        throw "Could not resolve the current Git commit."
    }

    $installRoot = Join-Path ([Environment]::GetFolderPath("LocalApplicationData")) "LinguaCafePCTest"
    $installDir = Join-Path $installRoot "app"
    $stagingDir = Join-Path $installRoot "app-staging"
    if (Test-Path $stagingDir) {
        Remove-Item -Recurse -Force $stagingDir
    }
    New-Item -ItemType Directory -Force -Path $stagingDir | Out-Null

    Write-Host "Publishing LinguaCafe PC Test shell..."
    dotnet publish "desktop\windows-pc-test\LinguaCafe.PcTest.csproj" `
        -c Release `
        -r win-x64 `
        --self-contained false `
        -o $stagingDir
    if ($LASTEXITCODE -ne 0) {
        throw "dotnet publish failed."
    }

    Write-Host "Packing exact LinguaCafe runtime source from $commit..."
    $runtimeZip = Join-Path $stagingDir "runtime-source.zip"
    git archive --format=zip --output=$runtimeZip HEAD
    if ($LASTEXITCODE -ne 0 -or -not (Test-Path $runtimeZip)) {
        throw "git archive failed."
    }
    Set-Content -LiteralPath (Join-Path $stagingDir "runtime-version.txt") -Value $commit -Encoding ascii -NoNewline

    if (Test-Path $installDir) {
        Remove-Item -Recurse -Force $installDir
    }
    Move-Item -LiteralPath $stagingDir -Destination $installDir

    $desktop = [Environment]::GetFolderPath("Desktop")
    $shortcutPath = Join-Path $desktop "LinguaCafe PC Test.lnk"
    $shell = New-Object -ComObject WScript.Shell
    $shortcut = $shell.CreateShortcut($shortcutPath)
    $shortcut.TargetPath = Join-Path $installDir "LinguaCafe PC Test.exe"
    $shortcut.WorkingDirectory = $installDir
    $shortcut.Description = "LinguaCafe Windows PC test build"
    $shortcut.IconLocation = "$(Join-Path $installDir 'LinguaCafe PC Test.exe'),0"
    $shortcut.Save()

    Write-Host ""
    Write-Host "Installed LinguaCafe PC Test"
    Write-Host "Commit:   $commit"
    Write-Host "App:      $installDir"
    Write-Host "Shortcut: $shortcutPath"
}
finally {
    Pop-Location
}
