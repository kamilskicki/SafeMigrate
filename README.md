# Safe Migrate

Reliable WordPress migrations and restores with preflight diagnostics, checkpointed execution, snapshot-backed rollback, and aligned admin, REST, and WP-CLI workflows.

## What It Does

- Runs preflight checks before export or restore
- Builds export artifacts with manifests, checksums, SQL segments, and file chunks
- Validates and previews restores before destructive execution
- Creates a mandatory snapshot before destructive restore and supports rollback/resume
- Pulls packages from a remote Safe Migrate source via one-time transfer tokens
- Exports support bundles with checkpoints, logs, and compatibility context

## Project Layout

- `safe-migrate.php`: WordPress plugin bootstrap
- `src/`: plugin runtime code
- `assets/`: admin UI assets
- `tests/`: PHPUnit coverage
- `scripts/build-release.ps1`: builds the distributable plugin ZIP
- `scripts/run-e2e.ps1`: runs the Docker e2e helper against the local dev environment

## Local Development

Requirements:

- PHP 8.2+
- Composer
- A local WordPress environment

Install dependencies:

```powershell
composer install
```

Run automated checks:

```powershell
composer lint
composer test
composer ci
```

## Docker Validation Flow

The repository includes helper scripts for a paired local Docker environment.

Typical workflow:

```powershell
$env:SAFE_MIGRATE_COMPOSE_DIR='D:\path\to\your\wordpress-environment'
.\scripts\sync-local-env.ps1
.\scripts\run-e2e.ps1
.\scripts\run-e2e.ps1 -Destructive
.\scripts\run-e2e.ps1 -Destructive -RollbackAfter
```

If the target WordPress stack contains unrelated plugins that break WP-CLI, run Safe Migrate smoke checks with those plugins skipped so failures can be attributed correctly.

## Release Packaging

Build the plugin ZIP:

```powershell
.\scripts\build-release.ps1
```

The release archive is written into `dist/` and includes only plugin runtime files needed for distribution.

## Documentation

- [E2E runbook](./docs/e2e-runbook.md)
- [Push / Pull runbook](./docs/push-pull-runbook.md)
- [Builder compatibility notes](./docs/builder-compatibility.md)
- [Release process](./docs/release.md)

## License

GPL-2.0-or-later. See [LICENSE](./LICENSE).
