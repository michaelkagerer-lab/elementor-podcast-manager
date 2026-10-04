# Merge preparation — 2026-10-04

PR: https://github.com/michaelkagerer-lab/elementor-podcast-manager/pull/5
Base: main `f649dfb`. The three Elementor follow-up commits preserve stored
settings, episode IDs/GUIDs and content; no migration or production operation.

## Final local confirmation

All 15 PHP integration suites passed on a marked disposable WordPress 7.1.2 /
Elementor 4.3.3 / PHP 8.3 / SQLite site: 3,007 assertions. This includes import,
media, feed, hosting, frontend, widgets, design and the independent audit.
Six Python test-safety checks, both German catalog checks and reproducible
packaging passed. The two-version targeted browser evidence is recorded in the
three Elementor reports dated 2026-10-04.

## Full CI review and corrected harness defect

Run `37203557508` on `5935ad4` passed all ten non-browser jobs: PHP 8.1–8.4
lint, integration/HTTP/performance on PHP 8.1 and 8.4, MariaDB races/import/media,
and the three compatibility profiles. Twenty-five browser suites passed.
`elementor-deep.mjs` failed when its PHP helper was resolved through `getcwd()`:
CI invokes WP-CLI from `tests/e2e`, while the earlier local wrapper used the
repository root. The missing include also correctly failed the notice check.

The same working-directory failure was reproduced locally before the fix.
The helper now resolves through `EPM_PATH`. Running the whole suite with
WP-CLI itself in `tests/e2e` passes all 71 checks. No assertion was weakened.
This harness correction changes no shipped runtime file; the plugin ZIP
remains byte-identical with SHA-256
`5bd458794d9712aa6014b92688b198ef9f34d6d3958b95453cb82cfbc1924351`.

The final-head checks on PR #5 are the authoritative merge gate. The first
run's successes alone do not establish that the final head passed the matrix.
The PR remains a draft until all eleven final-head jobs pass and GitHub reports
no merge conflict. CI jobs have hard limits; the browser job is bounded to
20 minutes. Local test containers are stopped after checks.

This is merge preparation, not a release or a certification of perfection.
Human assistive technology, Elementor Pro and live provider-account behavior
remain outside this automation's coverage.
