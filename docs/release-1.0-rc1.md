# Safe Migrate 1.0.0

## Scope

- Local-first export, validate, preview, destructive restore, rollback, resume, and Push / Pull transfer.
- Mandatory snapshot before destructive restore.
- Checkpointed restore state machine with support bundle output.
- Builder-aware compatibility reporting for Elementor, Gutenberg-family, and Oxygen.
- Single-codebase Core/Pro boundary with local license state and feature policy.
- WP-CLI, REST, and admin surfaces aligned on the same runtime.

## Core

- Free Core includes:
  - preflight
  - export
  - validate
  - preview restore
  - destructive restore
  - rollback
  - resume
  - support bundle export
  - push / pull transfer
  - WP-CLI parity

## Pro Boundary

- Pro-gated in `1.0.0`:
  - advanced retention
  - include/exclude migration policies
  - support bundle redaction
  - saved profile readiness in settings schema
  - advanced compatibility reporting surface

## Reliability Hooks

- Failure injection stages:
  - `after_snapshot`
  - `after_filesystem`
  - `after_database`
  - `verification`

- Testing guard:
  - available outside production or when `SAFE_MIGRATE_ENABLE_TESTING` is enabled

## Verified On March 16, 2026

- `wp safe-migrate preflight --user=1`
- `wp safe-migrate e2e --user=1 --destructive=1 --rollback-after=1`
- `wp safe-migrate push_pull --source-url=<source> --transfer-token=<token>`
- `wp safe-migrate support_bundle --job-id=95`
- REST `GET /safe-migrate/v1/settings`
- REST `POST /safe-migrate/v1/license`
- REST `POST /safe-migrate/v1/transfer-token`
- REST `POST /safe-migrate/v1/push-pull`
- REST `POST /safe-migrate/v1/restore-execute` without confirmation returns `422` and `safe_migrate_code = destructive_confirmation_required`
- Imported-site destructive restore and rollback complete successfully on the local fixture
- Push / Pull transport is additive on top of the existing package format and local validate / preview / restore flow

## Known Gaps

- No multisite support.
- No cloud or remote storage adapters.
- Push / Pull currently uses target-pull transport first and does not yet implement a separate source-push mode.
