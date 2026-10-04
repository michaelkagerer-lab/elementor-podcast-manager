# Elementor reliability and UI follow-up — 2026-10-04

Scope: the shared episode picker used by episode widgets, inherited style
controls across the twelve widgets, and long-content rendering. The visual
direction stays native Elementor in the editor and theme-neutral podcast
tokens in the frontend. Explicit widget values, episode IDs, GUIDs and existing
content are preserved. No migration, hosting change or publication is involved.

## Findings and changes

| ID | Severity | Problem | Result and evidence |
|---|---|---|---|
| EL-01 | P1 | The picker delegated clicks to every list item, including Loading and No results. Those rows supplied an undefined episode ID. | Only validated episode options can select. Loading/empty clicks leave the ID intact; an explicit Clear selection button removes it. Both safety assertions failed against the unchanged main code. |
| EL-02 | P2 | Search had no keyboard selection or programmatic association with its label/listbox. | Arrow keys, Enter, Escape, connected label/listbox IDs and active-descendant announcements; Tab reaches pagination. Actual editor regression, including a scoped axe scan. |
| EL-03 | P2 | Failed requests looked like an empty catalog and offered no recovery. | Separate loading, empty and error states; retry keeps the search and selected ID. Error text also explains reloading the editor for session/permission problems. Unsuccessful or malformed JSON items are treated as recoverable errors, rather than crashing the control. The malformed-item probe reproduced two TypeErrors before the final guard. |
| EL-04 | P2 | Selected-title requests could return out of order; inaccessible IDs and failed label lookups became silent blank text. | Abort superseded requests, validate the current request and ID, explain unavailability, and offer retry for label errors. IDs remain stored until explicitly changed. Delayed-label and recovery assertions pass in the real editor. |
| EL-05 | P2 | A debounce timer could start a search after its view was destroyed. Global listener cleanup also shared one namespace across all views. | Per-view listener namespace, timer/request cleanup, immediate invalidation while typing and bounded 15-second AJAX requests. Leaving the widget sends no pending debounced search. |
| EL-06 | P1 | A responsive reset in mobile mode erased the desktop value and left the mobile override. Older control stacks exposed duplicate reset buttons. | Resolve the active device at click time, match server-generated device visibility, and preserve other device overrides. Undo restores the mobile value. Exactly one gap reset button is visible in each mode on both tested Elementor versions. Responsive copy says “inherited value”, because a device can inherit a desktop override. |
| EL-07 | P2 | Long imported notes, transcripts and guest role/company/bio text widened narrow Elementor columns. | Scoped text wrapping keeps the content readable inside its column. Twelve overflow assertions failed before the CSS fix; all pass afterward at desktop, 390 and 320px. |
| EL-08 | P2 | Native muted hint styling made the picker instructions too faint in the current editor (axe measured 3.49:1). | Scoped use of the editor's normal text color, 12px upright hints and visible option/action focus. The final scoped axe scan reports no violations in both editor versions. |

The compact baseline against main `f649dfb` produced nine failing selection,
keyboard and device-reset assertions. The expanded final suite additionally
checks label races, failed/unavailable selected episodes, pagination, focus,
cleanup and accessibility. Those additional checks are final-state evidence;
the original 106-finding register is not relabeled as independently reverified.

## Validation

Only marked disposable sites were used:

- WordPress 7.1.2 / Elementor 4.3.3 / SQLite / PHP 8.3.
- WordPress 6.2 / Elementor 3.12.2 / MariaDB / PHP 8.3; the final picker
  confirmation uses real German translations, restoring the test locale afterward.
- `e2e/elementor-reliability.mjs`: 30 passing assertions per site; actual
  editor panel, native settings/history, mocked error/latency responses and axe.
- `e2e/elementor-content.mjs`: 22 passing assertions per site; actual
  published temporary Elementor page and episode, deleted afterward.
- `integration/widgets.php`, `design.php`, `ui-phase4.php`: 131, 228 and 75
  passing assertions on each site.
- `e2e/design.mjs`: 53 passing checks, including saved 1.3 widget settings,
  explicit layouts across preset changes and inherited styles.
- `e2e/style-audit.mjs`: all 88 style controls work; the existing
  `CONTROL-AUDIT.md` matches without regeneration or weaker assertions.
- PHP/JS syntax, complete German catalogs and deterministic ZIP packaging pass.

Visual inspection covered the real picker panel, device reset controls and
long-content rendering at 320px. Search results stay in panel flow, scroll at
240px height, wrap long titles and use Elementor colors. Touch action buttons
have a 44px minimum; desktop buttons follow the native editor size.

All browser runs had 150-second limits, except the existing design/style
suites (300 seconds). Local server lifetimes were bounded. Test containers are
stopped after validation. No hosted CI, release, merge or deployment was
started for this follow-up.

Limits: targeted Chromium verification, not a new full matrix run; no Elementor
Pro, physical touch device or manual screen-reader certification. Existing
backend search authorization remains unchanged. A full CI run remains necessary
before merging this new branch.
