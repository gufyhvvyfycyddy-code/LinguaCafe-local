$ErrorActionPreference = "Stop"

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$projectDir = Resolve-Path (Join-Path $scriptDir "..\..")
$desktop = [Environment]::GetFolderPath("Desktop")
$shell = New-Object -ComObject WScript.Shell

function U([int[]] $Codes) {
    return -join ($Codes | ForEach-Object { [char] $_ })
}

$startup = U @(0x542F, 0x52A8)
$stop = U @(0x505C, 0x6B62)
$homeLabel = U @(0x9996, 0x9875)
$senseMapping = U @(0x8BCD, 0x4E49, 0x786E, 0x8BA4)
$generateGpt = U @(0x751F, 0x6210, 0x0020, 0x0047, 0x0050, 0x0054, 0x0020, 0x5305)
$validateGpt = U @(0x6821, 0x9A8C, 0x0020, 0x0047, 0x0050, 0x0054, 0x0020, 0x4E0B, 0x8F7D)
$dryRunImport = U @(0x5BFC, 0x5165, 0x0020, 0x0044, 0x0072, 0x0079, 0x0020, 0x0052, 0x0075, 0x006E)
$formalImport = U @(0x6B63, 0x5F0F, 0x5BFC, 0x5165)
$openChatGpt = U @(0x6253, 0x5F00, 0x0020, 0x0043, 0x0068, 0x0061, 0x0074, 0x0047, 0x0050, 0x0054)

Get-ChildItem -Path $desktop -Filter "LinguaCafe *.lnk" -ErrorAction SilentlyContinue | Remove-Item -Force

$shortcuts = @(
    @{ Name = "LinguaCafe $startup.lnk"; Script = "linguacafe-start.bat" },
    @{ Name = "LinguaCafe $stop.lnk"; Script = "linguacafe-stop.bat" },
    @{ Name = "LinguaCafe $homeLabel.lnk"; Script = "linguacafe-open-home.bat" },
    @{ Name = "LinguaCafe Word Review.lnk"; Script = "linguacafe-open-word-review.bat" },
    @{ Name = "LinguaCafe Sense Review.lnk"; Script = "linguacafe-open-sense-review.bat" },
    @{ Name = "LinguaCafe $senseMapping.lnk"; Script = "linguacafe-open-sense-mapping-review.bat" },
    @{ Name = "LinguaCafe Doctor.lnk"; Script = "linguacafe-doctor.bat" },
    @{ Name = "LinguaCafe $generateGpt.lnk"; Script = "gpt-workflow-prepare.bat" },
    @{ Name = "LinguaCafe $validateGpt.lnk"; Script = "gpt-workflow-validate-latest.bat" },
    @{ Name = "LinguaCafe $dryRunImport.lnk"; Script = "gpt-workflow-import-latest-dry-run.bat" },
    @{ Name = "LinguaCafe $formalImport.lnk"; Script = "gpt-workflow-import-latest.bat" },
    @{ Name = "LinguaCafe $openChatGpt.lnk"; Script = "open-chatgpt.bat" }
)

foreach ($item in $shortcuts) {
    $target = Join-Path $scriptDir $item.Script
    if (-not (Test-Path $target)) {
        throw "Missing script: $target"
    }

    $shortcutPath = Join-Path $desktop $item.Name
    $shortcut = $shell.CreateShortcut($shortcutPath)
    $shortcut.TargetPath = $target
    $shortcut.WorkingDirectory = $scriptDir
    $shortcut.Description = $item.Name.Replace(".lnk", "")
    $shortcut.Save()
    Write-Host "Created: $shortcutPath"
}

Write-Host ""
Write-Host "LinguaCafe desktop shortcuts created."
