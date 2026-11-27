param([switch]$StageOnly, [switch]$ActivateStaged)

$ErrorActionPreference = "Stop"
if ($StageOnly -and $ActivateStaged) { throw "StageOnly and ActivateStaged are mutually exclusive." }

function Get-ReleaseFileManifest([string]$root) {
    $fullRoot = (Get-Item -LiteralPath $root).FullName.TrimEnd('\')
    return @(
        Get-ChildItem -LiteralPath $fullRoot -Recurse -File -Force |
            Where-Object { $_.FullName -ne (Join-Path $fullRoot "release-manifest.json") } |
            Sort-Object FullName |
            ForEach-Object {
                if ($_.Attributes -band [System.IO.FileAttributes]::ReparsePoint) {
                    throw "PC release contains a file link; refusing to validate external content."
                }
                [pscustomobject]@{
                    Path = $_.FullName.Substring($fullRoot.Length + 1).Replace('\', '/')
                    SHA256 = (Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash
                }
            }
    )
}

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$projectDir = (Resolve-Path (Join-Path $scriptDir "..\..")).Path
Push-Location $projectDir
$stageDir = $null
$sourceDir = $null
$tempShortcut = $null
$verificationArchive = $null
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
    $installRoot = "D:\AI-Tools\LinguaCafe\PC-Test-V5"
    if (-not (Test-Path -LiteralPath $installRoot -PathType Container)) {
        throw "PC Test D-drive root is missing; initialize and verify the private directory before installing."
    }
    $rootAcl = Get-Acl -LiteralPath $installRoot
    if (-not $rootAcl.AreAccessRulesProtected) {
        throw "PC Test D-drive root has inherited permissions; refusing to place local state here."
    }
    $legacyState = Join-Path ([Environment]::GetFolderPath("LocalApplicationData")) "LinguaCafePCTestStudyV5\state.json"
    if (-not (Test-Path -LiteralPath (Join-Path $installRoot "state.json")) -and (Test-Path -LiteralPath $legacyState)) {
        throw "PC Test state remains on C drive; verify and copy its exact contents to the private D-drive root before installing."
    }
    $releaseDir = Join-Path $installRoot ("app-" + $commit.Substring(0, 12))
    if ($ActivateStaged) {
        if (-not (Test-Path -LiteralPath $releaseDir -PathType Container)) {
            throw "Exact staged PC Test revision is missing; refusing to switch the shortcut."
        }
        $marker = Join-Path $releaseDir "runtime-version.txt"
        $publishedZip = Join-Path $releaseDir "runtime-source.zip"
        $publishedExe = Join-Path $releaseDir "LinguaCafe PC Test.exe"
        $publishedManifest = Join-Path $releaseDir "release-manifest.json"
        if (-not (Test-Path -LiteralPath $publishedManifest -PathType Leaf)) {
            throw "Staged PC Test release file manifest is missing."
        }
        if (-not (Test-Path -LiteralPath $marker -PathType Leaf) -or
            (Get-Content -LiteralPath $marker -Raw).Trim() -ne $commit -or
            -not (Test-Path -LiteralPath $publishedZip -PathType Leaf) -or
            -not (Test-Path -LiteralPath $publishedExe -PathType Leaf)) {
            throw "Staged PC Test revision is incomplete or does not match HEAD."
        }
        $expectedFiles = Get-Content -LiteralPath $publishedManifest -Raw | ConvertFrom-Json
        $actualFiles = @(Get-ReleaseFileManifest $releaseDir)
        if ($expectedFiles.Count -eq 0 -or $expectedFiles.Count -ne $actualFiles.Count) {
            throw "Staged PC Test release file manifest does not match the package."
        }
        for ($i = 0; $i -lt $expectedFiles.Count; $i++) {
            if ($expectedFiles[$i].Path -cne $actualFiles[$i].Path -or
                $expectedFiles[$i].SHA256 -cne $actualFiles[$i].SHA256) {
                throw "Staged PC Test release file checksum mismatch."
            }
        }
    }
    elseif (Test-Path -LiteralPath $releaseDir) {
        throw "This exact version is already installed; use -ActivateStaged after validation."
    }

    $nonce = [guid]::NewGuid().ToString("N")
    if ($ActivateStaged) {
        # Recheck the staged ZIP against the exact committed Git source.
        $verificationArchive = Join-Path $installRoot ("verify-" + $nonce + ".zip")
        git archive --format=zip --output=$verificationArchive HEAD
        if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $verificationArchive) -or
            (Get-FileHash -LiteralPath $verificationArchive -Algorithm SHA256).Hash -ne
            (Get-FileHash -LiteralPath $publishedZip -Algorithm SHA256).Hash) {
            throw "Staged PC Test archive differs from the exact committed source."
        }
    }
    else {
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
            $forbiddenRoot = '(?i)((^|/)(output|screenshots|audit_screenshots)(/|$)|^(data|cookies\.txt|login\.html|routes\.txt|body\.md)(/|$))'
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
            docker compose -p linguacafe-pc-test-study-v5 -f $composeFile build web python
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

            # Dependencies can add files after the archived source has passed its filename scan.
            $imageRoot = if ($image -like "linguacafe-pc-test-web:*") { "/var/www/html" } else { "/app" }
            $imagePaths = @(docker run --rm --network none --read-only --entrypoint find $image $imageRoot -print)
            if ($LASTEXITCODE -ne 0) {
                throw "Could not inspect final Docker image paths."
            }
            # These two public framework classes contain "Secret" in their names.
            $publicVendorClasses = @(
                "vendor/laravel/framework/src/Illuminate/Console/View/Components/Secret.php",
                "vendor/laravel/serializable-closure/src/Exceptions/MissingSecretKeyException.php"
            )
            $unsafeImageCount = @($imagePaths | Where-Object {
                $relativePath = $_.Substring($imageRoot.Length).TrimStart("/") -replace '\\', '/'
                (($relativePath -match $forbiddenName) -or ($relativePath -match $forbiddenRoot)) -and
                    ($relativePath -notin $publicVendorClasses)
            }).Count
            if ($unsafeImageCount -ne 0) {
                throw "PC image rejected: $unsafeImageCount disallowed file or directory names."
            }
        }

        Set-Content -LiteralPath (Join-Path $stageDir "runtime-version.txt") -Value $commit -Encoding ascii -NoNewline
        $releaseFiles = @(Get-ReleaseFileManifest $stageDir)
        if ($releaseFiles.Count -eq 0) { throw "PC release has no files to verify." }
        $releaseFiles | ConvertTo-Json -Depth 3 |
            Set-Content -LiteralPath (Join-Path $stageDir "release-manifest.json") -Encoding UTF8
    }
    $desktop = [Environment]::GetFolderPath("Desktop")
    $shortcutPath = Join-Path $desktop "LinguaCafe PC Test Study V5.lnk"
    $shortcutExisted = Test-Path -LiteralPath $shortcutPath -PathType Leaf
    $shortcutSwitchAttempted = $false
    $shortcutBackup = $null
    $tempShortcut = Join-Path $desktop ("LinguaCafe PC Test." + $nonce + ".lnk")
    $shell = New-Object -ComObject WScript.Shell
    $shortcut = $shell.CreateShortcut($tempShortcut)
    $shortcut.TargetPath = Join-Path $releaseDir "LinguaCafe PC Test.exe"
    $shortcut.WorkingDirectory = $releaseDir
    $shortcut.Description = "LinguaCafe Windows PC test build"
    $shortcut.IconLocation = "$(Join-Path $releaseDir 'LinguaCafe PC Test.exe'),0"
    $shortcut.Save()

    # Previous app directories remain immutable. Switch only the shortcut.
    if (-not $ActivateStaged) {
        Move-Item -LiteralPath $stageDir -Destination $releaseDir
        $stageDir = $null
        if ($StageOnly) {
            Write-Host "Staged PC Test revision $commit at $releaseDir. Desktop shortcut remains unchanged pending real GUI validation."
            return
        }
    }
    try {
        if ($shortcutExisted) {
            $shortcutBackup = Join-Path $installRoot ("shortcut-before-" + $nonce + ".lnk")
            # Keep a verified D-drive rollback copy; File.Replace's temporary backup must stay on C:.
            [System.IO.File]::Copy($shortcutPath, $shortcutBackup)
            $localBackup = $shortcutPath + ".before-" + $nonce + ".bak"
            $shortcutSwitchAttempted = $true
            [System.IO.File]::Replace($tempShortcut, $shortcutPath, $localBackup)
            try { [System.IO.File]::Delete($localBackup) }
            catch { Write-Warning "Could not remove the temporary shortcut backup; D-drive rollback remains intact." }
        }
        else {
            $shortcutSwitchAttempted = $true
            [System.IO.File]::Move($tempShortcut, $shortcutPath)
        }
        $tempShortcut = $null
    }
    catch {
        if ($shortcutSwitchAttempted) {
            try {
                if ($shortcutExisted) {
                    if (-not (Test-Path -LiteralPath $shortcutBackup -PathType Leaf)) {
                        throw "The previous PC Test shortcut backup is unavailable."
                    }
                    [System.IO.File]::Copy($shortcutBackup, $shortcutPath, $true)
                }
                elseif (Test-Path -LiteralPath $shortcutPath -PathType Leaf) {
                    [System.IO.File]::Delete($shortcutPath)
                }
            }
            catch {
                # Keep the release directory intact if restoring the shortcut fails.
                throw "PC Test shortcut recovery failed; staged release preserved for manual recovery."
            }
        }
        # On activation failure, keep the staged revision intact for a retry.
        if (-not $ActivateStaged) {
            $stageDir = Join-Path $installRoot ("staging-" + $nonce)
            Move-Item -LiteralPath $releaseDir -Destination $stageDir
        }
        throw
    }

    Write-Host "Installed PC Test revision $commit at $releaseDir. Older versions retained."
}
finally {
    # Only this invocation's unique disposable staging folders are cleaned.
    if ($verificationArchive -and (Test-Path -LiteralPath $verificationArchive)) {
        Remove-Item -LiteralPath $verificationArchive -Force
    }
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
