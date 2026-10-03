# Independent audit of the merged 1.4.0 work

Baseline: `main` at `bd52dcdca46c2253fbae833fee442b664f58cd31`.
Work branch: `codex/independent-1.4-audit`.

This is a new review of actual user journeys. The earlier 106-finding register
and green CI establish previous evidence; they do not prove that untested
journeys work or that the product is perfect. New failures are reproduced
before changes. Fixes preserve identifiers, URLs, local episode edits and
explicit user settings. Only marked disposable sites are used.

## Review scope

| Area | Independent verification |
|---|---|
| Fresh installation and versioning | Installable runtime ZIP; consistent version metadata; upgrade and data preservation |
| Elementor discovery | Real sidebar category, all twelve widgets, search, insertion into a document |
| Editor empty states | No episodes, missing audio, chapters, transcript, guest, notes, video and platform links |
| Setup and starting a site | First-run choices, back/cancel/reload, preset selection, optional page creation and next action |
| Player and sticky controls | Every layout, new and legacy widget settings, play/seek/speed/share, sticky opt-in/opt-out and mobile |
| Design inheritance | Global settings versus explicitly chosen widget values, preset changes, theme interference and contrast |
| Accessibility | Semantic/accessibility-tree checks, keyboard, focus, announcements, zoom, contrast and touch targets |
| Import and sync | Paged feeds, GUID preservation, local edits, retry/cancel/resume, removed or broken feeds and signed media URLs |
| Hosting changes | Validation, redirects, loops, completion state and original media preservation |
| Other publishing features | Podcast Index ping, WP-CLI, transcripts, video privacy and publishing readiness |
| Scale and lifecycle | Long titles and transcripts, large catalogs, request budgets, concurrency, multisite and uninstall guards |

UI review preserves native WordPress/Elementor conventions and the owner's
project design guidance. It covers novice publishers, site builders,
keyboard users and listeners on narrow screens. Visual inspection is bounded:
one desktop/mobile evidence batch, a grouped repair pass and a confirmation
batch. New functional failures receive targeted reproductions rather than an
unbounded visual polishing loop.

## Confirmed findings

### IA-01 — P0: Podcast widgets have no registered sidebar category

`includes/Elementor/Integration.php` subscribes to
`elementor/elements/categories`, but Elementor 4.3.3 fires
`elementor/elements/categories_registered` when constructing its category list.
All twelve widget types exist; their `epm-podcast` category does not. Tests that
open widgets already saved in a document do not exercise discovery in the
sidebar. This prevented the normal site-building journey from being verified.

Before-fix reproduction: `tests/integration/independent-audit.php` reports one
passing assertion and thirteen failures against the real Elementor manager.
`tests/e2e/independent-elementor.mjs` exercises the actual sidebar, including
visual evidence. Fixed: correct `categories_registered` hook. All fourteen
integration assertions and five real sidebar/search/drag-and-drop checks pass.

### IA-02 — P2: Settings checkboxes are too tightly spaced

The independent WCAG 2.2 scan flagged three desktop checkbox targets on the
Podcast settings screen. Their 16px boxes had only 20.4px of safe space.
Their associated, fully clickable labels now have a 44px minimum height.
Native checkbox appearance and existing values are preserved. Before: three
target-size failures. After: both 1280px and 390px settings scans pass.

### IA-03 — P2: Scrollable design preview is inaccessible by keyboard

The desktop preview has a scrollable viewport around inert demonstration
content, but no keyboard focus target. The WCAG scan reproduced
`scrollable-region-focusable`. The viewport is now a named region with
`tabindex="0"` and a visible focus outline. Its demonstration controls remain
inert. Before: one failure. After: desktop and mobile scans pass.

### IA-04 — P1: Deferred directory notification ignores a later opt-out

The publisher checked visibility and the notification filter when scheduling,
but checked only hosting mode when cron actually ran. Making the site private
or installing the opt-out filter before dispatch still sent a notification.
The event now rechecks all eligibility conditions. Before: both negative cases
sent one request. After: both send none; an eligible public site still sends
one. Tests intercept requests and never notify the real service.

### IA-05 — P1: Import still removes text between literal comparison signs

The old parser removed every `<…>` sequence, including encoded literal text.
A valid show title `A < B and C > D` became `A D`; episode comparisons and
mixed HTML descriptions also lost content. Actual tag/comment syntax is now
removed while literal comparisons are retained. Before: three failed data
preservation assertions. After: all four parser assertions pass.

### IA-06 — P2: Installable artifact still identifies itself as 1.3.0

The package test reproduced missing 1.4.0 header/constant/stable-tag metadata.
Plugin, readme, changelog and PO/POT/compiled MO headers now identify 1.4.0.
This prepares the requested unpublished local artifact; no public release or
tag is created. Deterministic packaging and German catalog checks pass.
The real ZIP installation and data-preservation smoke check pass.

### IA-07 — P2: Race runner interprets dependency warnings as scenarios

On minimum WordPress with PHP 8.3, a core deprecation printed before the
scenario list became a bogus `Deprecated:` scenario. The actual thirteen
race scenarios passed but the runner reported failure. List output now uses
an explicit `EPM_RACE_SCENARIO` marker; unrelated dependency diagnostics stay
outside that protocol. The complete runner passes all thirteen scenarios.

### IA-08 — P1: Minimum WordPress permits cloud-metadata redirects

The real-socket media suite failed on WordPress 6.2: a redirect to
`169.254.169.254` timed out as a stall instead of being refused. Core 6.2
omits link-local, CGNAT and other special-purpose ranges added in modern core.
A separate mocked reproducer confirmed that the plugin allowed the redirect
hook to proceed; it performs no request to a metadata service.

SafeHttp now supplements core validation at the initial request and every
redirect hop, using the modern restrictions and preserving WordPress's
explicit allowlist filter. Audio-URL and delivery HEAD/GET checks share the
same wrapper. Refused downloads are reported as unsafe rather than retried
as transport failures. The mocked negative tests and full real-socket media
suite pass on minimum WordPress; a 60 MB MP3 copies with 4.2 MB request peak
above the booted site under a 128 MB memory limit.

### IA-09 — P2: Minimum-version HTTP fixture documents cannot render

The fixture documents use Flexbox Containers. Minimum Elementor 3.12.2 keeps
that experiment off by default, so its HTTP run initially missed widget
markup. Disposable-site provisioning now enables that feature explicitly.
Before: two HTTP fixture assertions failed. After: all 83 HTTP checks pass,
including actual feed redirects, private REST/endpoints and empty-widget
asset behavior. This changes test configuration, not live-site settings.

## Review of reported suspicions

- Sticky is intentionally opt-in for standalone player widgets; list/chapters
  can request the bar. Saved explicit settings are preserved. Current player
  and widget suites verify live opt-in/opt-out and shared playback state.
- Minimal/Compact layouts intentionally omit secondary speed/share controls;
  widget help describes this. Editorial/Artwork/Full expose the controls.
- The setup assistant already offers an optional starter podcast page with
  latest-player, subscribe and episode-list shortcodes. It is tested through
  the real setup journey; it is not an Elementor template-library preset.
- Empty editor widgets already have explanatory placeholders. A selected
  episode with no audio has a visible missing-audio message from the renderer.
  A public empty widget does not invent content or leak editor instructions.
- The original handoff's lock TTL and missing If-Range notes are outdated:
  current code has a five-minute lock TTL and validated If-Range resumption.

## Final local verification

All nine findings above are corrected with reproduced before-fix failures and
passing confirmation evidence. Logs were kept under `/tmp/epm-audit`; no
production site, import, hosting setting or public directory was touched.

| Check | Final result |
|---|---|
| Current WordPress / Elementor / SQLite | All 13 PHP suites: 2,832 assertions, zero failures |
| Minimum WordPress / Elementor / MariaDB | All 13 PHP suites pass on the final code |
| Elementor disabled | Current and minimum backend/safety checks pass |
| Browser journeys | All 20 discovered suites pass after resolving the two initial diagnostic/transition races; Setup and Admin rerun after the final HTTP change also pass |
| Browser engines | Chromium, Firefox and WebKit real playback, chapters, share keyboard and mobile widget checks pass |
| Independent accessibility | 22 WCAG scans, viewport checks and keyboard-focus confirmation: 48 assertions pass |
| Real Elementor discovery | Sidebar, twelve tiles, search and drag-and-drop pass on current and minimum Elementor |
| Real HTTP | 83 checks pass: feed routing/caching/redirects, protected REST/endpoints, assets and import-state privacy |
| Concurrency | All 13 separate-process race scenarios pass |
| Request budgets | Import, feed, readiness and background-upgrade suites pass at 128 MB PHP memory limit |
| Real media sockets | No-cURL safety, 65 download assertions and six feed-loop assertions pass; 60 MB audio copies with 4.2 MB added peak memory |
| Network lifecycle | 42 assertions pass: network activation/deactivation, new sites, keep/delete uninstall policy |
| Explicit deletion | 5,000 episodes deleted in 0.436 s / 288 queries; all 14 cleanup/preservation/retry assertions pass |
| Static/catalog/package safety | PHP/JS lint, six safety tests, complete German catalogs and deterministic runtime package checks pass |
| Installed ZIP | 1.4.0 installs/activates as actual files; GUIDs, settings, local title/transcript edits and serialized widget data remain intact; all 33 new independent backend assertions pass against it |

The initial Admin browser failure followed a Docker-induced
`ERR_NETWORK_CHANGED` and missing core jQuery requests. Its targeted repeat
and final repeat pass. The initial share-label contrast failures occurred
while an inactive “Copied” label faded to zero opacity; the scan now waits
for that initial transition to settle and passes. Neither is recorded as an
unfixed product flaw or silently suppressed.

The minimum HTTP environment additionally required enabling Elementor's
then-experimental Flexbox Containers for its fixture documents. WebKit was
run with locally extracted system libraries and a launcher preserving their
paths because this workspace has no administrative package-install rights.
The actual browser checks pass; no browser result is inferred from CI.

Artifact: `elementor-podcast-manager-1.4.0.zip`, 96 runtime files, archive CRC
verified, deterministic SHA-256
`7662c2571f26438715db03e212bcfc79205a9faa50f71ee23a1b0d6025559235`.

## Evidence and limitations

Fresh local environments: WordPress 7.1.2, Elementor 4.3.3, PHP 8.3, SQLite;
and WordPress 6.2, Elementor 3.12.2, PHP 8.3, MariaDB 11.4.
Exact archives are checksum-pinned by `tests/versions.json`.
Existing CI on the merged baseline passed, including minimum versions,
MariaDB, Firefox and WebKit. This review does not relabel that evidence as
newly independently executed evidence.

The tmpfs/full-disk media scenarios were explicitly skipped because the
container cannot mount tmpfs. The nginx/php-fpm production-like runner and
optional heavy performance profiles were not rerun locally; request-budget
checks used separate real PHP processes instead. Physical-device/desktop-zoom
and comprehensive screen-reader testing remain outside this environment.

Automated accessibility checks do not establish comprehensive screen-reader
or physical-device certification. Production imports, hosting switches,
deployments, directory submissions and public releases are outside this run.
The requested local plugin ZIP is part of the deliverable.
