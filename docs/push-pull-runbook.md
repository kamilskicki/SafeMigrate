# Push / Pull Runbook

## Goal

Move a site from a source WordPress installation to a destination WordPress installation using a one-time transfer token and the existing Safe Migrate package format.

## Source Site

1. Open Safe Migrate.
2. Generate a Source Transfer Token.
3. Copy the token to the destination operator.

## Destination Site

1. Open Push / Pull Migration.
2. Enter the source site URL.
3. Paste the transfer token.
4. Pull the package from the source.
5. Validate the artifact.
6. Preview the restore.
7. Execute restore only after preview succeeds.

## Failure Handling

- If remote preflight fails, stop before transfer.
- If remote export fails, rerun Push / Pull after generating a new token.
- If local validation fails, do not restore.
- If destructive restore fails after snapshot creation, use rollback.
