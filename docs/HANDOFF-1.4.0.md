# Handoff — 1.4.0 work (state on 2026-10-01)

Branch `claude/festive-knuth-jsma3u`, draft PR #3 against `main` (1.3.0,
`b1076c8`). Not merged, not released. Nothing was imported, deployed or
submitted to a directory.

## Where things are

- `docs/FINDINGS-1.4.0.md`: the findings register (106 findings from the
  audit of 1.3.0: 2 × P0, 15 × P1, 41 × P2, 48 × P3), with package and
  status per finding.
- `docs/findings-1.4.0.json`: the same findings in full: evidence (file:line),
  reproduction steps, user impact, proposed fix and test plan. The open
  findings below are worked from this file.
- `tests/README.md`: every suite, how to run it, and what it covers.

## Done in this branch (each fix has a test that failed before it)

| Package | Findings | Main tests |
|---|---|---|
| B1 import engine | IMP-01, IMP-02, IMP-05, IMP-N1, IMP-N2, IMPB-N5, PERF-N2 | `integration/import.php`, `concurrency/`, `perf/run.sh`, `http/run.sh` |
| B2 media on a move | IMP-03, IMP-04, IMPB-N1..N4, FEED-N7 | `integration/media.php`, `media/run.sh` |
| C1 player | PLAY-01..03 (+ PLAY-N1..N11), WID-N5 | `e2e/player.mjs` |
| C2 design inheritance, widgets | DESIGN-01, DESIGN-N1..N4, WID-N1..N4, WID-N6..N9 | `integration/design.php`, `integration/widgets.php`, `e2e/design.mjs`, `e2e/widgets.mjs`, `e2e/style-audit.mjs` |
| F upgrade and feed (both P0) | LIFE-N1, PERF-N1, PERF-01, PERF-N4, FEED-N3, N4, N6, N8..N15 | `integration/feed.php`, `perf/run.sh`, `perf/production.sh`, `http/run.sh` |

## What was and was not verified at the final commit

Run here on a fresh SQLite test site (WordPress 7.1.2, Elementor 4.3.3,
PHP 8.4) after the three merges: `tests/bin/lint.sh` and all nine PHP
integration suites pass: run 179, admin 331, design 216, feed 147,
frontend 212, hosting 1126, import 92, media 165, widgets 131.

Not run on the merged state (each was run on its own branch by the
package that wrote it): `concurrency/run.sh`, `perf/run.sh`,
`media/run.sh`, `http/run.sh`, all browser suites (`e2e/*.mjs`), and the
MariaDB runs. C2 and F were stopped while their final browser and
production-like runs were in progress, so a full `tests/run-all.sh` on
the merged state is the first thing to do. CI on PR #3 runs part of it.

Merge notes (the conflicts were resolved by hand):
- `includes/Readiness.php`: B2's per-kind "still at the old host" checks
  now use F's paged `Episodes::each_public()` instead of a full episode
  list. The check during an unfinished move still loads every episode
  (`get_episodes( posts_per_page -1 )`).
- `tests/integration/feed.php` (PERF-N4) compares the readiness report
  with the frozen 1.3 report apart from B2's new `items`/`more` keys.
- WID-N4 (stylesheet only where something renders) is fixed in 9c376d4,
  but no test names the ID. UX-N7 and UX-N16 were assigned to C2. Its
  report never came, so check its commits before marking them.

## Open: not started (details in `docs/findings-1.4.0.json`)

- **B3 — sync and hosting switch:**
  - SYNC-N1..N8
  - FEED-N1: switching to an external host has no validation, and the 301 goes live at once
  - FEED-N2: redirect loop
  - FEED-N5
  - SEC-N2: quadratic entity expansion
  - SEC-N3: `plain()` cuts text at "<"
  - SEC-N7, N8, N9, PERF-N3, PERF-N5, LIFE-N4
  - UX-N2: the assistant says a stopped move is done
  - UX-N6, UX-N12
- **S — editor and REST security:**
  - SEC-N1: artwork attachment IDs are not validated
  - SEC-N4, N5, N6, N10, UX-N5
- **L — lifecycle, test safety, CI and packaging:**
  - LIFE-01: uninstall misses trash and auto-draft
  - LIFE-N2, N3, N5
  - QA-01: CI matrix (minimum WordPress, Elementor off, Firefox/WebKit); pinned versions; release ZIP with a checksum
  - QA-N1: the test runner must refuse a site that is not a marked test site
  - QA-N2..N4
- **U — user journeys:**
  - UX-N1: the embed bridge reads the first `#?secret=`
  - UX-N3, N4, N8..N11, N13..N15
  - German translation
- **Known leftovers named by the packages:**
  - the import lock TTL is still 20 minutes
  - `Importer::guid_map()` is loaded on every step
  - resuming a download does not use If-Range
  - mid-transfer limits need the cURL transport
  - the Readiness check during an unfinished move loads every episode

## Rules kept from the work order

- **Test sites only.** `tests/fixtures/seed.php` deletes episodes, so never run it against a real site. `EPM_ALLOW_TEST_SEED=1` is set only by the test runners.
- **Before fixing a finding,** write a test that reproduces it and fails on the old code.
- **Keep existing data:** GUIDs, the podcast GUID, URLs, settings, local edits of imported episodes, and manual widget settings.
- **Release steps need a separate order:** no merge to `main`, release, deployment, production import, hosting switch or directory submission without one.
