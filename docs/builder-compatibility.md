# Builder Compatibility Map

Date: 2026-03-08

This file tracks the builder ecosystem that Safe Migrate should optimize for first.

The ranking below is based on publicly visible WordPress.org active installation counts where available.
Premium builders with large market impact but no public install counts are listed separately.

## Top 10 Countable Builder Targets

1. Elementor
   - Public signal: 10+ million active installs
   - Source: https://wordpress.org/plugins/elementor/
   - Usability profile: easiest broad-market entry, huge feature surface, high compatibility pressure
   - Migration implications: widget metadata, template library artifacts, generated CSS, popup/theme-builder assets

2. Spectra
   - Public signal: 1+ million active installs
   - Source: https://wordpress.org/plugins/ultimate-addons-for-gutenberg/
   - Usability profile: block-editor-native and approachable for Gutenberg users
   - Migration implications: block JSON, global styles, responsive settings inside block attributes

3. SeedProd
   - Public signal: 700,000+ active installs
   - Source: https://wordpress.org/plugins/coming-soon/
   - Usability profile: focused and funnel-friendly, narrower than full site builders
   - Migration implications: landing-page assets, theme-builder data, marketing templates, cache and image artifacts

4. Kadence Blocks
   - Public signal: 600,000+ active installs
   - Source: https://wordpress.org/plugins/kadence-blocks/
   - Usability profile: strong native-editor ergonomics for teams already committed to blocks
   - Migration implications: block attribute remap, patterns, dynamic content references

5. SiteOrigin Page Builder
   - Public signal: 500,000+ active installs
   - Source: https://wordpress.org/plugins/siteorigin-panels/
   - Usability profile: older but proven, lower polish than newer builders
   - Migration implications: legacy row/section data, older serialized payloads, backward-compat expectations

6. Pagelayer
   - Public signal: 400,000+ active installs
   - Source: https://wordpress.org/plugins/pagelayer/
   - Usability profile: broad feature set with mixed UX quality
   - Migration implications: custom builder content, generated assets, plugin/theme coupling

7. Gutenberg plugin
   - Public signal: 300,000+ active installs
   - Source: https://wordpress.org/plugins/gutenberg/
   - Usability profile: advanced block-editor cohort, close to core evolution
   - Migration implications: newest block serialization and experimental editor structures

8. Beaver Builder
   - Public signal: 100,000+ active installs
   - Source: https://wordpress.org/plugins/beaver-builder-lite-version/
   - Usability profile: conservative, predictable, agency-friendly
   - Migration implications: module data, saved rows/templates, theme-builder relationships

9. Brizy
   - Public signal: 70,000+ active installs
   - Source: https://wordpress.org/plugins/brizy/
   - Usability profile: visual-first and friendly, but less ubiquitous than Elementor
   - Migration implications: custom content storage, template/export artifacts, responsive metadata

10. Visual Composer
    - Public signal: 40,000+ active installs
    - Source: https://wordpress.org/plugins/visualcomposer/
    - Usability profile: broad site-builder model with legacy brand overlap confusion versus WPBakery
    - Migration implications: builder-specific shortcodes/data structures, template assets, editor state

## High-Impact Premium Builders To Support Early

- Divi
  - Source: https://www.elegantthemes.com/gallery/divi/
  - Why it matters: large installed base and strong agency presence

- WPBakery Page Builder
  - Source: https://wpbakery.com/
  - Why it matters: legacy theme-bundled footprint remains huge

- Oxygen
  - Source: https://oxygenbuilder.com/
  - Why it matters: technical audience, custom data structures, higher migration sensitivity

- Breakdance
  - Source: https://breakdance.com/
  - Why it matters: modern commercial builder with growing pro-user share

## Safe Migrate Strategy

- Do not chase one-off builder-specific hacks first.
- Build a generic remap pipeline with builder adapters behind it.
- Prioritize builders that combine high install count and non-trivial stored metadata.
- Treat block-based builders and shortcode/custom-post-data builders as separate compatibility families.

## Compatibility Order

1. Elementor
2. Gutenberg family: core blocks, Spectra, Kadence
3. SeedProd
4. SiteOrigin
5. Beaver Builder
6. Pagelayer
7. Brizy
8. Visual Composer
9. Divi
10. WPBakery
11. Oxygen
12. Breakdance

## Engineering Consequences

- Remap must understand serialized PHP payloads and block JSON.
- Export/import reports should call out detected builders explicitly.
- Preflight should warn when a site contains builders with known edge-case payload formats.
- Fixture lab should include at least one site for each builder family, not just each plugin.
