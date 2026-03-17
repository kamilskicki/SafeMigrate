# Safe Migrate E2E Runbook

## WP-CLI commands

Run the full non-destructive flow:

```bash
wp safe-migrate e2e --user=1
```

Run the destructive flow:

```bash
wp safe-migrate e2e --user=1 --destructive=1
```

Run the destructive flow and roll back from the generated snapshot:

```bash
wp safe-migrate e2e --user=1 --destructive=1 --rollback-after=1
```

## Local Docker helper

From the plugin workspace:

```powershell
$env:SAFE_MIGRATE_COMPOSE_DIR='D:\path\to\your\wordpress-environment'
.\scripts\run-e2e.ps1
.\scripts\run-e2e.ps1 -Destructive
.\scripts\run-e2e.ps1 -Destructive -RollbackAfter
```

## Notes

- `e2e` defaults to the newest export artifact when a restore artifact is not passed explicitly.
- `restore-execute` still requires explicit confirmation outside the `e2e` helper.
- Rollback requires a restore job that already has a completed snapshot.
