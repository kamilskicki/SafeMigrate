=== Safe Migrate ===
Contributors: kamilskicki
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Reliable WordPress migrations and restores with preflight diagnostics, checkpoints, push/pull transfer, and recovery-first workflows.

== Description ==

Safe Migrate focuses on predictable migrations, readable diagnostics, resumable execution, and snapshot-backed rollback.

Free Core includes preflight, export, validate, preview restore, destructive restore, rollback, resume, support bundle export, push/pull transfer, and WP-CLI parity.

Safe Migrate 1.0 ships as a direct-distribution plugin with builder-aware compatibility reporting for Elementor, Gutenberg-family plugins, and Oxygen, plus a local Core/Pro shell in a single codebase.

== Highlights ==

- Go / No-Go preflight before you move or restore a site
- Local export packages with checksums, segmented SQL, and ZIP file chunks
- Site-to-site push/pull migration using one-time transfer tokens
- Validate and preview restore before destructive execution
- Mandatory snapshot-backed rollback for destructive restores
- Support bundles with checkpoints, logs, and compatibility context
- WP-CLI, REST, and admin surfaces aligned on the same runtime

== Installation ==

1. Upload the plugin to `/wp-content/plugins/`.
2. Activate the plugin through the WordPress admin.
3. Open `Safe Migrate` in wp-admin.

== Changelog ==

= 1.0.0 =

- Added Push / Pull site-to-site transfer for the free core workflow
- Hardened large-site export performance and bounded CLI summaries
- Promoted the plugin from RC to the public 1.0.0 release
