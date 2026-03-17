param(
    [string]$Target = 'D:\docker\WordPress\SafeMigrate\wp-content\plugins\safe-migrate'
)

$ErrorActionPreference = 'Stop'

$source = Split-Path -Parent $PSScriptRoot

if (-not (Test-Path $Target)) {
    New-Item -ItemType Directory -Path $Target -Force | Out-Null
}

$null = robocopy $source $Target /MIR /XD .git tests /XF .gitignore .phpunit.result.cache

if ($LASTEXITCODE -gt 7) {
    throw "robocopy failed with exit code $LASTEXITCODE"
}

Write-Host "Synced Safe Migrate to $Target"
