# Failure and Rollback Playbook

## Operator Rule

Never treat restore as complete until verification passes.

## Failure Stages

- preflight: stop and fix blockers
- package validation: do not restore
- preview: inspect the artifact and remap scope
- destructive restore: rely on the mandatory snapshot and rollback path
- verification: roll back immediately if verification fails

## Support Bundle

Generate a support bundle after failed restore, rollback failure, or package validation failure.

## Testing Hooks

Failure Injection stays available only in explicit testing contexts. It is not part of the normal public admin workflow.
