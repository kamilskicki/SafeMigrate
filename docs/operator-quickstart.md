# Safe Migrate Operator Quickstart

## Local Package Workflow

1. Run Go / No-Go Preflight.
2. Build an export package.
3. Validate the package.
4. Build a restore preview.
5. Execute restore only after preview is ready.
6. Use rollback if the destructive restore needs to be reversed.

## Push / Pull Workflow

1. On the source site, generate a Source Transfer Token.
2. On the destination site, open Push / Pull Migration.
3. Paste the source site URL and transfer token.
4. Pull the package from the source site.
5. Validate the downloaded artifact locally.
6. Preview the restore locally.
7. Execute restore with destructive confirmation.
8. Roll back from the generated snapshot if needed.

## Support Scope

- Single-site only
- Local filesystem artifacts
- Builder-aware reporting for Elementor, Gutenberg-family plugins, and Oxygen
- No multisite or cloud adapters in 1.0
