# Podcast editorial workspace — 1.5.0-rc.2

## Base and scope

Verified remote on 2026-11-12: main is `e7c51ce` (1.4.0); the newest development version is `4558d90` (1.5.0-rc.1), on `codex/studio-analytics`, open draft PR #6. This candidate builds on rc.1, retaining its opt-in analytics. No production installation, feed mutation, import or deployment was performed.

The configured local project folder did not exist. A fresh clone was created under `plugin/`, branch `codex/podcast-product-experience`.

## Product decisions

Primary task: return to a draft, find an episode, or check the next scheduled release. The show remains recognizable; real work appears before hosting/distribution. Recent drafts (at most five) and upcoming releases (at most three, earliest first) have direct edit links. Full lists and native WordPress search remain available. Draft/pending/scheduled states stay distinct. The next-action area prioritizes setup, feed errors, editable drafts, warnings, then distribution. Setup remains dismissible, without two competing setup pitches.

Chapter and link removal now has persistent, keyboard-accessible **Undo last removal**. It restores only the removed row, retaining edits to other rows. One removal is retained per repeater until another removal, replacement paste, navigation or save. Undo is deliberately not described as reverting a saved publication. Replacing a chapter list clears any stale removal undo.

## References and limits

- [Mobbin / Kajabi podcast list](https://mobbin.com/screens/8f05c696-c47c-4754-bef7-7ac629fa02b2): reviewed screenshot. Episode identity and explicit status/actions guided the editorial list. Its custom navigation/branding was not copied.
- [21st / Table with Filters](https://21st.dev/@felipemenezes098/components/table-12): searched and retrieved source. Search next to the content and explicit filters informed native WordPress search/status links. React and TanStack were not added to a PHP/jQuery plugin. Local 21st review: no errors or warnings; informational suggestions mainly concern existing literal color fallbacks.
- [WordPress WP_Query](https://developer.wordpress.org/reference/classes/wp_query/): bounded queries with explicit status, author scope and stable WordPress edit routes. Every returned draft must also pass its per-post edit permission. No whole-catalog fetch.
- [W3C status messages](https://www.w3.org/WAI/WCAG22/Understanding/status-messages.html): removal/restoration is announced through the existing WordPress accessibility speaker; focus returns to the restored field.

These are design references and implementation guidance, not human usability research. Magic UI/React components do not fit this native WordPress stack.

## Safety and acceptance

No new data schema or episode migration was introduced. The existing version upgrade handler still runs its normal cache/rewrite maintenance and background upgrade tasks; the upgrade regression checks published posts, metadata, permalinks, feed/enclosure identities, settings and saved Elementor data. Public player/rendering, feed generation, import behavior and consent logic were not modified by this UX delta.

Tests use a marked disposable SQLite WordPress 7.1.2 / Elementor 4.3.3 site on localhost:8892. This Mac has PHP 8.5; upstream WP-CLI/Elementor emit deprecation notices. They are not represented as plugin failures or as a clean PHP 8.1/8.4 compatibility run. Browser checks use a locally cached Chromium after the repository-pinned browser download failed.

Before installing on the active site: take a files/database backup, test this ZIP on a staging copy with the actual active plugin version/theme/Elementor/Borlabs configuration, compare feed GUIDs and enclosures, play an existing episode, and verify a draft edit/save. Keep the previous plugin ZIP for rollback. No production safety claim can substitute for that site-specific acceptance.

## Executed evidence

- Core integration: 192 assertions; admin integration: 361; Studio Analytics: 8; new editorial/upgrade preservation: 13. All passed (574 total).
- Existing admin browser: 68 checks; general end-to-end browser: 40; player/Elementor lifecycle: 121. All passed.
- New editorial workflow: 17 checks, including scoped axe; German/empty/long-title checks passed after repairing the reproduced 320px feed overflow.
- Clipboard fallback checks passed on dashboard, setup, hosting and distribution, including denied/unavailable clipboard paths.
- Consent JavaScript tests passed: no-consent, Borlabs service gate, GPC, seek/paused exclusion and qualified playback.
- PHP/JavaScript/shell lint, German catalog checks, deterministic packaging and whitespace checks passed.
- Baseline removal reproduced against rc.1 JavaScript in the browser: the chapter disappeared and no Undo control existed; the new restoration tests pass.
- UI Gate: PASS, with inspected desktop, tablet, phone and short-landscape captures and a recorded false positive for the WordPress core update notice's wrapped inline link bounding box. See sibling `review/ui/ui-gate-review.json` and `review/logs/` for local evidence.

Not exercised: production WordPress/MySQL, real podcast hosting/CDN, licensed Borlabs acceptance, Safari/iOS and assistive-technology testing. No claim of human usability acceptance or production readiness.

## rc.3: studio dashboard and local public-show verification

The user rejected the sage/serif direction and asked for a clean, Apple-like product. Retrieved and visually inspected 21st components 14941 (Dashboard Sidebar) and 31495 (Card); adapted neutral surfaces, spacing and content hierarchy into PHP/CSS, without React dependencies. Apple typography/layout guidance supports system fonts, legibility, consistent hierarchy and content-responsive columns. This is an adaptation for a web WordPress plugin, not native Apple UI.

Changes: compact show identity, contextual primary action, bounded published-episode shelf excluding the featured latest episode, on-demand audio preview with loading/error/retry and `preload=none`, quiet secondary settings links, and container-based reflow. Distribution remains accessible but no longer displaces publishing as the next action on a healthy show. Setup and feed problems still take priority.

Local testing used WordPress 7.1.2, Elementor 4.3.3 and PHP 8.5.11. Imported the seven public BUSINESS TUNING feed entries into the marked disposable test site with remote media preserved. All seven GUIDs and enclosure URL/length/type attributes match the source feed. Real imported episode playback, keyboard seeking and pause passed; dashboard audio preview also plays the real audio. Private live settings and production Elementor page data were not copied or verified. No live writes or deployments.

Regression coverage: 192 core, 361 admin, 8 analytics and 13 preservation assertions passed. Existing frontend/Elementor and player browser suites passed; final editorial, German/empty states and scoped accessibility checks run separately. Upgrade assertions preserve posts, meta, settings, feed identities and Elementor data in the local fixture environment. Upstream Elementor/WP-CLI PHP 8.5 deprecations remain; these are not claimed resolved.

The design review caught a 200% zoom layout that technically had no overflow but squeezed text into narrow columns. Container queries now collapse those columns. The WordPress core update notice produces one known inline-link bounding-box overlap false positive at 320px; its visible text fragments do not overlap.

Second critique repairs and review limits: see `STUDIO-DESIGN-CRITIQUE.md`. The published shelf shows up to six other episodes, using show artwork when an episode has none.
