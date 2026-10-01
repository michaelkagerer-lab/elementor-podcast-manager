# Tests

Everything runs against a real, disposable WordPress + Elementor site. The
site uses SQLite (no database server) and PHP's built-in web server, so the
only requirements are PHP 8.1+ (with `pdo_sqlite`, `gd`, `zip`), Node 22+
and internet access for the first download. Podcast feeds and hosts are
the one thing that is simulated: a test-only must-use plugin answers
requests to `https://feeds.example.test/…` (and Apple's podcast lookup)
from `fixtures/feeds/`, so imports and syncs never leave localhost.

```bash
tests/run-all.sh              # lint + integration + HTTP + browser
SKIP_E2E=1 tests/run-all.sh   # without the browser suites
WP_DIR=/tmp/epm-wp-2 WP_PORT=8890 tests/run-all.sh   # a second site side by side
```

`run-all.sh` provisions the site (idempotent), then runs every suite. The
fixtures are seeded again before each suite, so every suite starts from
the same site, and every suite runs even when an earlier one failed. The
script ends with a summary and a non-zero exit code when a suite failed.
It leaves the site running and seeded at `http://localhost:$WP_PORT`
(admin/admin) for manual checks.

## How suites are found

Suites are discovered by file name, so a new suite needs no change to
`run-all.sh`:

- **Integration:** every `integration/*.php`, run with `wp eval-file`;
  `run.php` first, the others in alphabetical order. `integration/lib.php`
  is shared code (assertion runner, feed helpers) and files starting with
  `_` are helpers (`_media-child.php`, a process `media.php` starts), not
  suites.
- **Browser:** every `e2e/*.mjs`, run with Node; `run.mjs` first, the
  others in alphabetical order. `e2e/lib.mjs`, `e2e/helpers.mjs` and files
  starting with `_` are shared code, not suites.

Current order: `integration/run.php`, `admin.php`, `frontend.php`,
`hosting.php`, `import.php`, `media.php`; then `concurrency/run.sh`; then
`perf/run.sh`; then `media/run.sh`; then `http/run.sh`; then
`e2e/run.mjs`, `admin.mjs`, `frontend.mjs`, `setup.mjs`.

## Suites

| Suite | File | What it covers |
|---|---|---|
| Lint | `bin/lint.sh` | `php -l` on every PHP file, `node --check` on every script and browser suite |
| Integration | `integration/run.php` | rewrite-rule order, capabilities per role and with a filtered capability, meta sanitizers (HTML/line breaks kept), visibility of draft/private/scheduled/password episodes, GUID immutability, duration detection, pure helpers (durations, timestamps, languages, categories, UUIDv5), feed contents (items, channel tags, categories, explicit, chapters/transcript tags, episode artwork rules, feed window and serial order, distribution options), feed cache invalidation, shortcodes, automatic episode pages, readiness report and its links, the CTA's assets, Elementor widget registration (no duplicate control IDs, dynamic content), no `_doing_it_wrong` |
| | `integration/admin.php` | Topics taxonomy and its capabilities (contributors assign, editors manage, filtered capabilities), the Podcast menu and sentence-case labels, default hidden list columns; episode editor: next episode number, paste-chapters disclosure, video field help, transcript files (fill the text, SRT accepted, other files rejected, hosted file shown), episode search and media AJAX for contributors (no other authors' private episodes or media); Quick Edit and Bulk Edit (*Number from*); design export allowlist and import validation, the Design screen's token table against `DesignSettings::output_tokens()` for every preset, the script data, the WCAG contrast formula and pairs (every preset passes), the Design screen render |
| | `integration/frontend.php` | timestamp links (parsing, building, only the page's episode), share menu (accessible markup, none for restricted episodes), embeds (iframe code, oEmbed height and HTML, the card document), video (sources, no third-party request before play, place on the episode page), topic filters and chips, `preload="none"` for audio on another host, the sticky player for lists and chapters, list play button labels, the number column, dark-design section surfaces, hero/latest background padding, no strings in the player engine |
| | `integration/hosting.php` | the feed parser against every real feed in `fixtures/feeds/` and its host quirks, bot pages and Atom feeds rejected; host detection (address and `<generator>`), listing links, feed-address normalization and media types; finding a feed from an Apple Podcasts link, a web page (podcast feed before the blog feed) or a redirect; import (preview counts, lock and consent, GUIDs byte-for-byte including `%`-escapes, dates, external audio, chapters, transcripts to HTML, transcript files kept or copied, drafts for blocked/undated items, duplicates, re-import, show details, `podcast:guid`, audio that could not be copied, lock ownership, cancelling); host sync (conditional requests, local edits kept and editor saves ignored, deleted episodes stay deleted, truncated/empty feed guards, removed episodes drafted after a day, new feed addresses, never https to http, a redirect to this site, failures and back-off, schedule); feed output (external audio, remote artwork, moved-in `new-feed-url`, download-statistics prefix, `podcast:trailer`, `podcast:person`, `podcast:transcript` with captions, build time); transcript files (SRT type on every server, type aliases, upload before hosted file, readable text); setup steps and distribution progress |
| | `integration/import.php` | the import's data integrity in one process: a sync whose lock another request took over stops before the next episode (no validators stored, the other lock stays), the GUID is checked in the database right before an episode is created; paged feeds: page 2 answering 500, a transport timeout, invalid XML and an empty page that links on each end the catalog as incomplete with the reason, the page address and the error; relative and root-relative `rel="next"` resolved against the page; the page limit (`epm_import_max_pages`), a cycle and duplicate GUIDs across pages; "try again" continues from the failed page without reading a page or importing an episode twice; a move with an incomplete catalog is refused (no `moved_in`, no lock, no hosting switch) unless the missing part is accepted, a mirror is allowed and says it is partial; `wp podcast import` exits 1 on a partial catalog with `--resume`/`--accept-partial` as the way forward and never prints a plain success for part of a catalog; the parsed feed is stored in the database (never in uploads) and read back intact (1.5 MB of notes, Unicode, HTML), removed on done, cancel, a new preview and a failed import; two overlapping previews leave one job; a checked feed nobody imports expires after a day with its data (a running import does not); a running 1.3.0 import continues from the database and its folder is removed; uninstalling removes the stored data, the job, the 1.3.0 folder and the cleanup event |
| | `integration/media.php` | moving media (IMP-03, FEED-N7, IMP-04, IMPB-N1..N4), with answers from `EPM_Test_HTTP::$routes`: mirror a show (WebVTT, SRT, JSON transcripts, images), then move it with an image and a WebVTT file failing, a transcript address chosen on the site and an edited transcript text, both with the items unchanged and changed at the host: what is copied, kept and listed per kind with the reason, in the job, in Readiness and in the feed, and that the next run requests exactly what failed; transcript addresses imported by 1.3.0 (the feed item's own are copied, others kept); the editor's wording; a move whose audio stays at the host ends as `done_with_problems` with mode, `moved_in` and `locked` unchanged, and a retry or the confirmation finishes it; size limits with and without Content-Length, a truncated download, a web page/JSON/random bytes instead of audio (and an image or WebVTT answered with a web page), HTTP 404/403/500 retried by the next run, 429 and 503 with Retry-After pause the job; a copy that dies keeps chapters and transcript; an identical orphan and an existing attachment are reused (no "-1"); a separate process (`_media-child.php`) runs out of memory right after the file is moved into uploads: no orphan, no temp file, lock released, reason recorded, continuation scheduled, the next step copies the file; stale download files; `wp podcast status` and `wp podcast cancel` |
| Races | `concurrency/run.sh` | two (or three) real PHP processes per scenario, synchronized by barrier files on observable points (a statement on the lock row, an episode insert, a feed request): a free lock, an abandoned lock, renew vs. takeover, release vs. takeover and a loop re-taking the lock all leave exactly one holder; cron's loop plus a step from the import screen import no GUID twice and count what happened; a step that read the job before the lock never marks a finished job failed, never overwrites the next preview and never saves an older position; a preview never replaces an import started meanwhile; Cancel lets the episode in flight finish and nothing after it. `STRESS=1` adds a barrier-free cron loop plus polling run (200 items) |
| Budget | `perf/run.sh` | the import's cost per request, each request a PHP process of its own with a 128M memory limit (a stock php-fpm): a paged feed (10 pages × 100 items from a slow host) is read over several requests, each below 48 MB above the booted site and 15 s; an import step needs the same memory on 1,000 and 4,000 items (at most 2 MB more). `PERF_HEAVY=1`: 50 pages × 500 items (25,000 episodes) and steps on 1,000 vs 10,000 items |
| Media downloads | `media/run.sh` | downloads over real sockets from a local media host (`fixtures/mediaserver.py`, on a free port; the test-only mu-plugin `epm-test-loopback.php` lets the site reach exactly that port): the size limit stops a 50 MB file right after the headers (Content-Length) or at the limit (chunked) and the host sends only a few MB; a stalled host is cut off by the low-speed limit, tried three times and reported, also with steps shorter than the low-speed window; a slow file larger than one request continues with Range requests over several steps and is byte-identical; a host without Range support is reported; a login page, random bytes, HTTP 404, a redirect to a private address; 429 makes the job wait; a move finishes only once a slow file is here; a 60 MB audio file is copied in a process with a 128M limit using less than 32 MB above the booted site (`MEDIA_HEAVY=1`: also 300 MB); as root with tmpfs: a 20 MB temp folder (refused before the download with Content-Length, stopped when the disk fills without) and a full uploads folder (no partial file stays). Needs `python3` |
| HTTP | `http/run.sh` | `/podcast/feed/` and every archive feed URL serve the podcast feed, ETag/Last-Modified with 304s, a channel change answers `If-Modified-Since` with the new feed and a new `Last-Modified`, chapters JSON and transcript endpoints (404 for restricted episodes), episode page output, feed discovery link, design tokens printed once, shortcode and Elementor pages, REST meta exposure/protection, byte-range media; import data: a feed check through admin-ajax stores the parsed feed in the database, in no file under uploads, no `epm-import` folder is served, the job token is in no URL or log, Cancel removes the data; with another host: 301 from every feed address to the host's feed (discovery link too), the blog feed not redirected, 200 again without the redirect and when self-hosted |
| Browser | `e2e/run.mjs` (Playwright/Chromium) | player playback, chapter seek + highlight, theme-proof buttons, resume position, remembered speed, shared state between card and player, pause-others, AJAX-inserted players, mobile layout; Elementor editor rendering, re-render on control change, playback in the preview, episode picker; episode admin: audio box placement, drag-and-drop upload, chapters, show notes, validation notices, feed update; Elementor page with the sticky player; design presets, export and import |
| | `e2e/admin.mjs` | Design screen (live preview, preset tiles and the confirm dialog, contrast badges, save, the unsaved-changes warning, the save bar clear of focused fields, keyboard and 390 px); episode editor (next number, paste chapters: add or replace, half-filled rows, the save buttons after autosave); episode list (column widths, Quick Edit) |
| | `e2e/frontend.mjs` | share menu (keyboard, copy, position link, embed code, manual copy), timestamp links (`?t=` forms, the cue label), the embed card (320 and 600 px, height message, links), card buttons and the sticky bar, the video facade (no third-party request before play, focus), the sticky bar for lists and chapters, remote audio loading only on play, the design system on real pages (dark designs, Elementor Kit rules, row alignment) |
| | `e2e/setup.mjs` | activation opens the setup assistant once; the assistant's three paths (keep a host: keyboard-only step 1, feed check, import with progress, show details taken from the feed, style and podcast page; host here at 390 px with inline errors; move a locked show with owner consent), focus on each new step, no horizontal overflow; Hosting & import (another host needs its feed address, switch host, Sync now, import with progress, the confirmation before copying a mirrored show's audio, a move that leaves audio at the old host: listed per kind with the reason, not finished, the informed confirmation refused without the checkbox and then finishing the move, a host that asks the import to wait, the move back to *This website*); the setup assistant's move that leaves audio behind (Continue only after the confirmation); Distribution (mark submitted, the live count and primary button, listing link becomes a subscribe button) |

Finally `run-all.sh` fails if the plugin's own files raised a PHP warning,
notice or deprecation during this run (the debug log is emptied first).
Notices from WordPress, Elementor or WP-CLI are ignored.

Browser screenshots land in `e2e/screenshots/` (not tracked).

## Environment variables

| Variable | Default | Purpose |
|---|---|---|
| `WP_DIR` | `/tmp/epm-wp` | where the test site lives |
| `WP_PORT` | `8889` | local port |
| `WP_VERSION` | `latest` | WordPress version |
| `ELEMENTOR_VERSION` | `latest-stable` | Elementor version |
| `WP_THEME` | `hello-elementor` | active theme (`twentytwentyfive` also installed) |
| `WP_DB` | `sqlite` | `mysql` installs the site on MySQL/MariaDB instead (needs `DB_NAME`; `DB_USER`, `DB_PASSWORD`, `DB_HOST` default to `root`, empty, `localhost`); the database is created when missing and must hold nothing else |
| `CHROMIUM_PATH` | — | use a specific Chromium binary |
| `SKIP_E2E` | — | skip the browser suites |

Several sites can run side by side with their own `WP_DIR` and `WP_PORT`.
A site keeps the database type it was installed with; use another
`WP_DIR` to switch.

```bash
WP_DB=mysql DB_NAME=epm_test DB_USER=epm DB_PASSWORD=epm \
  WP_DIR=/tmp/epm-wp-mysql WP_PORT=8891 tests/run-all.sh
```

## Race tests

`concurrency/run.sh` runs each scenario of `concurrency/race.php` as
separate `wp eval-file` processes (roles A and B, plus a monitor M that
records every saved job position). The roles meet at barrier files in a
temporary directory; a role waiting for a barrier is also released when
the other role has finished, so a barrier the code under test never
reaches cannot hang the run (a barrier that times out fails the
scenario). Barriers sit on behavior both an old and a new implementation
show (a statement on the `epm_import_lock` row seen through the `query`
filter, `wp_insert_post`, a fixture feed request), so the same scenarios
reproduce the races on the 1.3.0 code and pass on the fixed code.

```bash
WP_DIR=/tmp/epm-wp tests/concurrency/run.sh                 # all scenarios
WP_DIR=/tmp/epm-wp tests/concurrency/run.sh lock-stale       # one scenario
STRESS=1 WP_DIR=/tmp/epm-wp tests/concurrency/run.sh         # plus the stress run
RACE_KEEP=1 …                                                # keep events.log and role output
```

Run them on MySQL/MariaDB too (`WP_DB=mysql`, see below): the SQLite
drop-in and MariaDB answer the same statements differently (for example,
MySQL reports 0 affected rows for an UPDATE that writes the same value).
They do not cover a persistent object cache.

## Import budget

`perf/run.sh` measures what the import costs one request. The light
variant runs with every `run-all.sh`; the heavy one takes about half a
minute more:

```bash
WP_DIR=/tmp/epm-wp tests/perf/run.sh                  # 10 x 100 items, steps on 1,000 vs 4,000
PERF_HEAVY=1 WP_DIR=/tmp/epm-wp tests/perf/run.sh     # 50 x 500 items, steps on 1,000 vs 10,000
MEMORY_LIMIT=96M BUDGET_MB=32 …                       # other limits
```

The per-request peak needs PHP 8.2+ (`memory_reset_peak_usage()`); on
PHP 8.1 the memory budget is not checked, the rest is.

## Media downloads

`media/run.sh` starts `fixtures/mediaserver.py` on a free port and runs
`media/downloads.php` against it; the memory case runs in a PHP process
of its own with a 128M limit, and the full-disk cases mount small tmpfs
folders when run as root (otherwise they are skipped, and the output says
so).

```bash
WP_DIR=/tmp/epm-wp tests/media/run.sh                  # about a minute
MEDIA_HEAVY=1 WP_DIR=/tmp/epm-wp tests/media/run.sh    # plus a 300 MB file
```

## Running suites individually

```bash
tests/bin/setup-wp.sh                                   # site at http://localhost:8889
EPM_ALLOW_TEST_SEED=1 /tmp/epm-wp/wp eval-file tests/fixtures/seed.php
/tmp/epm-wp/wp eval-file tests/integration/run.php      # or admin.php, frontend.php, hosting.php
WP_URL=http://localhost:8889 WP_CLI=/tmp/epm-wp/wp tests/http/run.sh
cd tests/e2e && npm install && npx playwright install chromium
WP_URL=http://localhost:8889 WP_CLI=/tmp/epm-wp/wp node run.mjs   # or admin.mjs, frontend.mjs, setup.mjs
```

Seed the fixtures before each suite when running them by hand: suites
clean up after themselves (posts, terms, options and media they create
are removed or restored), but start from the seeded site.

## Fixtures

- `fixtures/seed.php` **deletes every episode** before seeding and refuses
  to run on a site whose environment type is `production` unless
  `EPM_ALLOW_TEST_SEED=1` is set. Never point it at a real site. It
  generates its own media in PHP: silent MPEG-1 Layer III MP3s (WordPress
  reads their exact durations), a WAV file and PNG artwork, and seeds
  published, draft, private, scheduled, password-protected, audio-less and
  WAV-only episodes plus an Elementor page and a shortcode page. The IDs
  are stored in the option `epm_test_fixtures`.
- `fixtures/feeds/` holds trimmed copies of 26 real feeds from 24 hosts
  and publishing tools, bot-protection pages (`negative/`) and synthetic feeds
  (`synthetic/`: a locked show with every edge case, a paged feed, broken
  markup, an Atom feed, a feed whose first audio file answers 404); see
  its README. `hosting.php` checks that the README lists every file.

## The HTTP fixture server

`fixtures/mu-plugins/epm-test-http.php` answers outgoing requests through
WordPress's `pre_http_request` filter. `setup-wp.sh` links it into the
test site's `mu-plugins`; it does nothing on a site whose environment type
is `production`. It serves:

| Address | Answer |
|---|---|
| `https://feeds.example.test/<name>.xml` | a real feed from `fixtures/feeds/` |
| `https://feeds.example.test/synthetic/<name>` | a synthetic feed (and its `extras/`: chapters, transcripts) |
| `https://feeds.example.test/negative/<name>.html` | a bot-protection page with the HTTP status in the file name (`…-http403.html` answers 403) |
| `https://feeds.example.test/media/<name>.mp3` / `.m4a` | a generated 5-second silent MP3 |
| `https://feeds.example.test/media/<name>.png`, `<name>-<w>x<h>.png` | a generated square PNG (1400 px) or the given size |
| `https://show.example.test/…` | web pages that link to a feed |
| `https://itunes.apple.com/lookup?id=…` | Apple's lookup API for the test IDs (`1000000001` → the locked show) |
| `https://op3.dev/e/feeds.example.test/…` | the URL after a measurement prefix |
| `https://api.podcastindex.org/…` | a fixed success answer to the "feed updated" notification, so test sites never notify Podcast Index |

Feeds answer `If-None-Match` with 304. Every other request passes through
untouched. `integration/hosting.php` loads the class directly and uses
`EPM_Test_HTTP::$routes` (dynamic answers, for example a feed that changes
between syncs), `EPM_Test_HTTP::$log` (requests made) and
`EPM_Test_HTTP::$offline` (refuse every other request, so a test can prove
that nothing left the site).
