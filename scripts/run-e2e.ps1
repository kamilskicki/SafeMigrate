param(
    [switch]$Destructive,
    [switch]$RollbackAfter,
    [int]$User = 1,
    [string]$ComposeDir = ''
)

$ErrorActionPreference = 'Stop'

if ([string]::IsNullOrWhiteSpace($ComposeDir)) {
    $ComposeDir = $env:SAFE_MIGRATE_COMPOSE_DIR
}

if ([string]::IsNullOrWhiteSpace($ComposeDir)) {
    throw 'Missing Compose directory. Pass -ComposeDir or set SAFE_MIGRATE_COMPOSE_DIR.'
}

if (-not (Test-Path (Join-Path $ComposeDir 'docker-compose.yml'))) {
    throw "Could not find docker-compose.yml in $ComposeDir"
}

$args = @(
    '--profile', 'ops',
    'run', '--rm',
    'wp-cli',
    'safe-migrate', 'e2e',
    "--user=$User"
)

if ($Destructive) {
    $args += '--destructive=1'
}

if ($RollbackAfter) {
    $args += '--rollback-after=1'
}

Push-Location $ComposeDir
try {
    docker compose @args
} finally {
    Pop-Location
}
