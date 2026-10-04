# Elementor follow-up audit — 2026-10-04

Base: `58ce896`, branch `codex/elementor-reliability-ux`. Focused second pass
using the project design rules and Impeccable hardening guidance. Disposable,
marked sites only. No import, deployment, merge or release.

## Sharp critique and fixes

1. **P2 — Saved topics could remain illegible after an interrupted lookup.**
   Reapplying saved values aborted the pending label request; the existing
   placeholder option then looked resolved. Builders saw internal slugs instead
   of names. The browser reproducer failed the hydration and readable-label
   checks before the fix. Unresolved options now remain explicitly pending;
   reapplication restarts bounded lookup and stale callbacks cannot update it.
2. **P2 — Label-load errors were silent and malformed responses crashed.**
   A JSON `null` response raised `Cannot read properties of null`; no recovery
   action appeared. Both checks failed before the fix. Search and hydration now
   share response validation; failed hydration shows native editor guidance and
   Retry. Keyboard Retry restores names, keeps the selection, clears the error
   and returns focus to the topic field. Destruction aborts requests and timers.
3. **P2 — The native topic search was inaccessible despite readable help.**
   The broader axe check found an unnamed `type=search` input with an invalid
   `role=textbox` combination. This inherited native Select2 markup was exposed
   by our control. The owned field now uses text-input semantics matching its
   role and the visible Topic label as its accessible name. Other controls and
   native localization stay intact. Scoped axe passes on both editor versions.
4. **P2 — Malformed video URLs produced warnings or unusable players.**
   An array-valued YouTube `v` parameter emitted an array-to-string warning;
   unsupported schemes could create a file facade with an empty or unusable
   source. PHP assertions reproduced these failures before the fix. The parser
   rejects non-string identifiers and schemes other than HTTP/HTTPS. Existing
   valid protocol-relative web videos and ordinary YouTube URLs remain valid;
   unlisted Vimeo access hashes remain covered by the existing regression.

## Extreme-content review

All twelve widgets were rendered with long unbroken German episode titles,
Arabic, CJK and emoji, plus long rich prose, wide tables and code. Desktop,
390px and 320px viewports passed. At 390px, computed text sizes were doubled,
including controls; the page and player/header/Hero/list roots remained within
bounds. The resulting player screenshot was inspected: the entire title stays
available and the controls remain distinct. This is text enlargement, not a
claim of testing every browser's zoom implementation or complete RTL behavior.
No new layout defect was reproduced in those cases. User presets, title-size
choices and saved widget settings were preserved.

## Verification on final code

| Suite | WP 7.1.2 / Elementor 4.3.3 / SQLite | WP 6.2 Multisite / Elementor 3.12.2 / MariaDB 11.4 |
|---|---:|---:|
| PHP `elementor-deep` | 28 | 28 |
| PHP `frontend` | 223 | 223 |
| PHP `widgets` | 131 | 131 |
| Chromium `elementor-topics` | 19 | 19 |
| Chromium `elementor-deep` | 71 | 71 |

944 assertions passed. Both environments use PHP 8.3. Lint, both German
catalog tests, reproducible packaging and `git diff --check` passed. Packaging
produced identical ZIPs with SHA-256
`5bd458794d9712aa6014b92688b198ef9f34d6d3958b95453cb82cfbc1924351`.
This is artifact validation, not a published release.

Browser suites were bounded to 100–110 seconds; servers to 900 seconds.
No hosted CI run was started. The three owned test containers were stopped
when verification finished.

## Limits

This round does not reverify the original 106-finding register, backend import,
feed, move or performance suites, every preset, Firefox/WebKit, real assistive
technology, full RTL navigation or live provider accounts. Third-party requests
remain blocked during browser tests. The previous audit remains at
`docs/ELEMENTOR-DEEP-AUDIT-2026-10-04.md`. Passing this focused round establishes
these fixes and regressions; it does not establish that the project is perfect
or independently clear a merge of the entire branch.
