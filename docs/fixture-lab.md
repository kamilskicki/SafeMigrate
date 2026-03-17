# Safe Migrate Fixture Lab

## Primary Fixture

- Imported builder-heavy WooCommerce site in the local Docker environment
- Used as the large-site regression fixture for preflight, export, validate, preview, destructive restore, rollback, support bundle, and cleanup

## Push / Pull Fixture

- One clean source site
- One clean destination site
- Both running Safe Migrate 1.0.0
- Used to validate transfer token creation, remote preflight, remote export, artifact download, local validation, preview, restore, and rollback

## Failure Fixture

- Dedicated destructive restore run with Failure Injection enabled in a testing-only context
- Used to verify automatic rollback and support bundle generation
