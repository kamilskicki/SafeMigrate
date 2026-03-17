param(
    [string]$Version = ""
)

$ErrorActionPreference = "Stop"

$root = Split-Path -Parent $PSScriptRoot
$pluginFile = Join-Path $root "safe-migrate.php"

if (-not (Test-Path $pluginFile)) {
    throw "Could not find safe-migrate.php at $pluginFile"
}

if ([string]::IsNullOrWhiteSpace($Version)) {
    $header = Get-Content -Path $pluginFile -Raw
    $match = [regex]::Match($header, 'Version:\s*([0-9][0-9A-Za-z\.\-\+]*)')

    if (-not $match.Success) {
        throw "Could not determine plugin version from safe-migrate.php"
    }

    $Version = $match.Groups[1].Value
}

$dist = Join-Path $root "dist"
$stageRoot = Join-Path $dist "stage"
$packageRoot = Join-Path $stageRoot "safe-migrate"
$zipPath = Join-Path $dist ("safe-migrate-" + $Version + ".zip")

if (Test-Path $stageRoot) {
    Remove-Item -Path $stageRoot -Recurse -Force
}

New-Item -Path $packageRoot -ItemType Directory -Force | Out-Null

$includeItems = @(
    "safe-migrate.php",
    "uninstall.php",
    "readme.txt",
    "LICENSE",
    "assets",
    "src"
)

foreach ($item in $includeItems) {
    $source = Join-Path $root $item

    if (-not (Test-Path $source)) {
        continue
    }

    Copy-Item -Path $source -Destination $packageRoot -Recurse -Force
}

if (Test-Path $zipPath) {
    Remove-Item -Path $zipPath -Force
}

Compress-Archive -Path (Join-Path $stageRoot "safe-migrate") -DestinationPath $zipPath -CompressionLevel Optimal

Write-Output $zipPath
