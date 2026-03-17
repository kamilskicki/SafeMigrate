param(
    [string]$Target = '',
    [string]$ComposeDir = ''
)

$ErrorActionPreference = 'Stop'

$source = Split-Path -Parent $PSScriptRoot

if ([string]::IsNullOrWhiteSpace($Target)) {
    if ([string]::IsNullOrWhiteSpace($ComposeDir)) {
        $ComposeDir = $env:SAFE_MIGRATE_COMPOSE_DIR
    }

    if ([string]::IsNullOrWhiteSpace($ComposeDir)) {
        throw 'Missing sync target. Pass -Target, pass -ComposeDir, or set SAFE_MIGRATE_COMPOSE_DIR.'
    }

    $Target = Join-Path $ComposeDir 'wp-content\plugins\safe-migrate'
}

if (-not (Test-Path $Target)) {
    New-Item -ItemType Directory -Path $Target -Force | Out-Null
}

$null = robocopy $source $Target /MIR /XD .git tests /XF .gitignore .phpunit.result.cache

if ($LASTEXITCODE -gt 7) {
    throw "robocopy failed with exit code $LASTEXITCODE"
}

Write-Host "Synced Safe Migrate to $Target"
