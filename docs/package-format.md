# Package Format

Date: 2026-03-08

Safe Migrate package format is designed for:

- deterministic validation,
- resumable processing,
- readable diagnostics,
- partial recovery,
- future storage adapter support.

## Artifact Layout

```text
wp-content/uploads/safe-migrate/exports/job-<id>/
  manifest.json
  files.json
  chunks/
    chunk-001.zip
    chunk-002.zip
    ...
  database/
    <table-name>/
      schema.sql
      part-0001.sql
      part-0002.sql
      ...
```

## Manifest Responsibilities

`manifest.json` is the contract document for import.

It records:

- package kind and schema version,
- site metadata,
- detected builders,
- export scope,
- file chunk plan,
- database table inventory,
- artifact locations,
- integrity metadata.

## Integrity Model

The manifest checksum is computed canonically:

- serialize the manifest with `manifest_checksum_sha256` blank
- hash that canonical JSON payload with SHA-256
- store the resulting checksum in `manifest.json`

This avoids self-referential checksum paradoxes.

## Filesystem Strategy

- `files.json` is the exhaustive file index
- each file is assigned to a chunk number during planning
- chunks are written as ZIP files when `ZipArchive` is available
- fallback mode can use directory chunks if ZIP support is missing

## Database Strategy

- each table gets its own directory
- `schema.sql` contains the drop/create statement
- row data is exported into fixed-size SQL parts
- each SQL part gets its own checksum entry

## Import Strategy

Import must follow the manifest, not directory guessing.

Order:

1. validate manifest
2. validate required artifacts
3. checkpoint package acceptance
4. restore filesystem chunks
5. restore database schema and segments
6. run remap
7. run verification
8. checkpoint success

## Restore Preview Workspace

Before destructive restore, Safe Migrate can build a separate workspace:

- extract filesystem chunks into an isolated preview tree
- stage database SQL into a separate preview directory
- write stage checkpoints as JSON files
- compute remap rules without touching the live site

This is the bridge between validation and real switch-over.

## Retention

- exports and restore workspaces are cleanup-managed
- newest artifacts are retained by default
- older artifacts can be deleted through maintenance jobs

## Future Extensions

- remote chunk storage adapters
- encrypted payloads
- package signing
- differential chunking
- builder-specific remap annotations
