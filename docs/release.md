# Release Process

## Pre-release Checks

Run before cutting a public ZIP:

```powershell
composer ci
.\scripts\sync-local-env.ps1
.\scripts\run-e2e.ps1
.\scripts\run-e2e.ps1 -Destructive -RollbackAfter
.\scripts\build-release.ps1
```

## Release Notes Checklist

- summarize user-visible changes in `readme.txt`
- record any migration, restore, rollback, or compatibility fixes
- note new verification coverage or operator workflow changes
- confirm the release ZIP was built from a clean `dist/` directory

## Publish Checklist

- confirm version headers in `safe-migrate.php` and `readme.txt`
- ensure CI is green
- ensure the built ZIP contains `safe-migrate.php`, `uninstall.php`, `assets/`, `src/`, `readme.txt`, and `LICENSE`
- sanity-check activation, export, preview, destructive restore, rollback, and cleanup on the local fixture environment
