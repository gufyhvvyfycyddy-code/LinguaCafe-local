<#
.SYNOPSIS
    Setup script for OpenCode Go CI on LinguaCafe-local repository.
    
    One-time setup that:
    1. Checks Git repo and GitHub CLI auth
    2. Creates required GitHub labels
    3. Prompts for OpenCode Go API Key and stores it as GitHub Secret
    4. Checks workflow files exist
    5. Attempts to set Actions permissions via API
    6. Provides manual steps if automation fails
#>

$ErrorActionPreference = "Stop"
$script:hasError = $false

function Write-Step {
    param([string]$Label, [string]$Status, [string]$Detail = "")
    $icon = switch ($Status) {
        "PASS" { "[PASS]" }
        "FAIL" { "[FAIL]" }
        "SKIP" { "[SKIP]" }
        "INFO" { "[INFO]" }
        default { "[....]" }
    }
    $color = switch ($Status) {
        "PASS" { "Green" }
        "FAIL" { "Red" }
        "SKIP" { "Yellow" }
        "INFO" { "Cyan" }
        default { "Gray" }
    }
    if ($Detail) {
        Write-Host ("{0,-8} {1,-50} {2}" -f $icon, $Label, $Detail) -ForegroundColor $color
    } else {
        Write-Host ("{0,-8} {1,-50}" -f $icon, $Label) -ForegroundColor $color
    }
}

Write-Host ""
Write-Host "============================================" -ForegroundColor Cyan
Write-Host "  OpenCode Go CI Setup Script" -ForegroundColor Cyan
Write-Host "============================================" -ForegroundColor Cyan
Write-Host ""

# Step 1: Check Git repository
Write-Host "--- Step 1: Git Repository ---" -ForegroundColor Yellow
try {
    $gitRoot = git rev-parse --show-toplevel 2>$null
    $gitRemote = git remote get-url origin 2>$null
    if (-not $gitRoot) { throw "Not a git repository" }
    if (-not $gitRemote) { throw "No remote 'origin' configured" }
    Write-Step "Git repository" "PASS" $gitRoot
    Write-Step "Remote origin" "PASS" $gitRemote
} catch {
    Write-Step "Git repository" "FAIL" $_.Exception.Message
    $script:hasError = $true
}

# Step 2: Check GitHub CLI
Write-Host "`n--- Step 2: GitHub CLI (gh) ---" -ForegroundColor Yellow
try {
    $ghVersion = gh --version 2>$null
    if (-not $ghVersion) { throw "gh not found" }
    Write-Step "gh installed" "PASS" ($ghVersion -split "`n")[0]
    
    $ghAuth = gh auth status 2>&1
    if ($LASTEXITCODE -eq 0) {
        Write-Step "gh authenticated" "PASS"
    } else {
        Write-Step "gh authenticated" "FAIL" "Run: gh auth login"
        $script:hasError = $true
    }
} catch {
    Write-Step "gh installed" "FAIL" $_.Exception.Message
    Write-Step "Install gh" "INFO" "winget install GitHub.cli"
    $script:hasError = $true
}

if ($script:hasError) {
    Write-Host "`nPlease fix the errors above and re-run this script." -ForegroundColor Red
    exit 1
}

# Extract owner/repo from remote URL
$repoFull = ""
if ($gitRemote -match 'github\.com[:\/](.+?)(\.git)?$') {
    $repoFull = $matches[1] -replace '\.git$', ''
}
if (-not $repoFull) {
    Write-Step "Parse repo name" "FAIL" "Cannot extract owner/repo from remote"
    exit 1
}
Write-Step "Repository" "INFO" $repoFull

# Step 3: Create labels
Write-Host "`n--- Step 3: Create GitHub Labels ---" -ForegroundColor Yellow
$labels = @(
    @{name="auto-fix/attempt-1"; color="0E8A16"; description="Auto-fix attempt 1"}
    @{name="auto-fix/attempt-2"; color="FB9400"; description="Auto-fix attempt 2"}
    @{name="auto-fix/attempt-3"; color="D93F0B"; description="Auto-fix attempt 3"}
    @{name="auto-fix/passed"; color="2CBE4E"; description="Auto-fix tests passed"}
    @{name="needs-human-review"; color="E99695"; description="Needs manual review"}
    @{name="auto-fix"; color="BFDADC"; description="Auto-fix related"}
)

foreach ($label in $labels) {
    $exists = gh api "/repos/$repoFull/labels" --jq ".[] | select(.name == `"$($label.name)`") | .name" 2>$null
    if ($exists) {
        Write-Step "Label: $($label.name)" "SKIP" "Already exists"
    } else {
        $result = gh api "/repos/$repoFull/labels" --method POST `
            --field "name=$($label.name)" `
            --field "color=$($label.color)" `
            --field "description=$($label.description)" 2>&1
        if ($LASTEXITCODE -eq 0) {
            Write-Step "Label: $($label.name)" "PASS" "Created"
        } else {
            Write-Step "Label: $($label.name)" "FAIL" $result
        }
    }
}

# Step 4: Setup OpenCode Go API Key Secret
Write-Host "`n--- Step 4: OpenCode Go API Key Secret ---" -ForegroundColor Yellow
$existingSecret = gh api "/repos/$repoFull/actions/secrets/OPENCODE_GO_API_KEY" --jq '.name' 2>$null
if ($existingSecret) {
    Write-Step "OPENCODE_GO_API_KEY" "SKIP" "Secret already exists"
    $overwrite = Read-Host "Overwrite existing Secret? (y/N)"
    if ($overwrite -ne "y" -and $overwrite -ne "Y") {
        Write-Step "User skipped" "SKIP"
    } else {
        Write-Host "Enter your OpenCode Go API Key (typing will not be shown):" -ForegroundColor Yellow
        $apiKey = Read-Host -AsSecureString
        $BSTR = [System.Runtime.InteropServices.Marshal]::SecureStringToBSTR($apiKey)
        $plainKey = [System.Runtime.InteropServices.Marshal]::PtrToStringAuto($BSTR)
        
        if ([string]::IsNullOrEmpty($plainKey)) {
            Write-Step "API Key" "FAIL" "Empty key, skipping"
        } else {
            $result = gh secret set OPENCODE_GO_API_KEY --repo $repoFull --body $plainKey 2>&1
            if ($LASTEXITCODE -eq 0) {
                Write-Step "OPENCODE_GO_API_KEY" "PASS" "Updated"
            } else {
                Write-Step "OPENCODE_GO_API_KEY" "FAIL" $result
            }
        }
        # Clear the key from memory
        $plainKey = $null
        [System.Runtime.InteropServices.Marshal]::ZeroFreeBSTR($BSTR)
    }
} else {
    Write-Host "Enter your OpenCode Go API Key (typing will not be shown):" -ForegroundColor Yellow
    Write-Host "  (Get this from opencode.ai -> Account -> API Keys)" -ForegroundColor Gray
    $apiKey = Read-Host -AsSecureString
    $BSTR = [System.Runtime.InteropServices.Marshal]::SecureStringToBSTR($apiKey)
    $plainKey = [System.Runtime.InteropServices.Marshal]::PtrToStringAuto($BSTR)
    
    if ([string]::IsNullOrEmpty($plainKey)) {
        Write-Step "API Key" "FAIL" "No key provided, skipping"
    } else {
        $result = gh secret set OPENCODE_GO_API_KEY --repo $repoFull --body $plainKey 2>&1
        if ($LASTEXITCODE -eq 0) {
            Write-Step "OPENCODE_GO_API_KEY" "PASS" "Created"
        } else {
            Write-Step "OPENCODE_GO_API_KEY" "FAIL" $result
        }
    }
    # Clear the key from memory
    $plainKey = $null
    [System.Runtime.InteropServices.Marshal]::ZeroFreeBSTR($BSTR)
    [System.Runtime.InteropServices.Marshal]::ZeroFreeCoTaskMemUnicode($BSTR)
}

# Step 5: Check workflow files exist
Write-Host "`n--- Step 5: Verify Workflow Files ---" -ForegroundColor Yellow
$workflows = @(
    ".github/workflows/opencode-executor.yml",
    ".github/workflows/auto-fix-scheduler.yml"
)
foreach ($wf in $workflows) {
    $fullPath = Join-Path (git rev-parse --show-toplevel 2>$null) $wf
    if (Test-Path $fullPath) {
        Write-Step $wf "PASS" "Exists"
    } else {
        Write-Step $wf "FAIL" "Missing - commit workflow files first"
        $script:hasError = $true
    }
}

# Step 6: Check/Set Actions permissions
Write-Host "`n--- Step 6: Actions Permissions ---" -ForegroundColor Yellow
try {
    # Try to get current permissions via API
    $actionsPerms = gh api "/repos/$repoFull/actions/permissions" --jq '.' 2>$null
    if ($actionsPerms) {
        $currentEnabled = ($actionsPerms | ConvertFrom-Json).enabled
        Write-Step "Actions enabled" "PASS" "Enabled: $currentEnabled"
    }
    
    # Try to set workflow permissions via API
    $permResult = gh api "/repos/$repoFull/actions/permissions/workflow" --method PUT `
        --field "default_workflow_permissions=write" `
        --field "can_approve_pull_request_reviews=true" 2>&1
    
    if ($LASTEXITCODE -eq 0) {
        Write-Step "Workflow permissions" "PASS" "Set to read+write"
    } else {
        Write-Step "Workflow permissions" "SKIP" "Cannot auto-set via API"
        Write-Step "Manual step required" "INFO" "Set Actions permissions manually:"
        Write-Host "       1. Go to: https://github.com/$repoFull/settings/actions" -ForegroundColor Gray
        Write-Host "       2. General -> Workflow permissions" -ForegroundColor Gray
        Write-Host "       3. Select: Read and write permissions" -ForegroundColor Gray
        Write-Host "       4. Check: Allow GitHub Actions to create and approve pull requests" -ForegroundColor Gray
        Write-Host "       5. Click Save" -ForegroundColor Gray
        Write-Host "       (This is required for OpenCode to create PRs via Actions)" -ForegroundColor Gray
    }
} catch {
    Write-Step "Actions permissions" "SKIP" "Cannot check automatically"
    Write-Step "Manual step" "INFO" "See instructions above"
}

# Step 7: Check opencode.json
Write-Host "`n--- Step 7: OpenCode Config ---" -ForegroundColor Yellow
$configPath = Join-Path (git rev-parse --show-toplevel 2>$null) "opencode.json"
if (Test-Path $configPath) {
    Write-Step "opencode.json" "PASS" "Exists"
    $hasGo = Select-String -Path $configPath -Pattern "opencode-go" -SimpleMatch -Quiet
    if ($hasGo) {
        Write-Step "Model: opencode-go" "PASS"
    } else {
        Write-Step "Model: opencode-go" "FAIL" "Not configured!"
    }
    
    $hasDeepSeek = Select-String -Path $configPath -Pattern "api\.deepseek\.com" -SimpleMatch -Quiet
    if (-not $hasDeepSeek) {
        Write-Step "No DeepSeek API" "PASS"
    } else {
        Write-Step "No DeepSeek API" "FAIL" "Config still has DeepSeek API reference!"
    }
} else {
    Write-Step "opencode.json" "FAIL" "Missing. Commit opencode.json first."
    $script:hasError = $true
}

# Summary
Write-Host ""
Write-Host "============================================" -ForegroundColor Cyan
Write-Host "  Setup Complete" -ForegroundColor Cyan
Write-Host "============================================" -ForegroundColor Cyan
Write-Host ""

if ($script:hasError) {
    Write-Host "Some checks failed. Please fix the issues above." -ForegroundColor Yellow
} else {
    Write-Host "All checks passed!" -ForegroundColor Green
}

Write-Host ""
Write-Host "Next steps:" -ForegroundColor Yellow
Write-Host "  1. Push your committed workflow files to GitHub:" -ForegroundColor Gray
Write-Host "     git push origin master" -ForegroundColor White
Write-Host "  2. Create a smoke test Issue on GitHub to verify the pipeline." -ForegroundColor Gray
Write-Host "  3. Open a simple Issue and run Codex CLI locally to dispatch your first task." -ForegroundColor Gray
Write-Host ""
Write-Host "For help, see: docs/codex-opencode-loop.md" -ForegroundColor Cyan
Write-Host ""

Read-Host "Press Enter to exit"
