param(
    [switch]$Destructive,
    [switch]$RollbackAfter,
    [int]$User = 1,
    [string]$ComposeDir = 'D:\docker\WordPress\SafeMigrate'
)

$ErrorActionPreference = 'Stop'

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
