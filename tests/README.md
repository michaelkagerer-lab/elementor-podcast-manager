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

Current order: `integration/run.php`, `admin.php`, `design.php`,
`feed.php`, `frontend.php`, `hosting.php`, `import.php`, `media.php`,
`widgets.php`; then `concurrency/run.sh`; then `perf/run.sh`; then
`media/run.sh`; then `http/run.sh`; then `e2e/run.mjs`, `admin.mjs`,
`design.mjs`, `frontend.mjs`, `player.mjs`, `setup.mjs`,
`style-audit.mjs`, `widgets.mjs`. (`integration/reference/` holds frozen
copies of earlier code for comparisons, not suites.)

## Suites

| Suite | File | What it covers |
|---|---|---|
| Lint | `bin/lint.sh` | `php -l` on every PHP file, `node --check` on every script and browser suite |
| Integration | `integration/run.php` | rewrite-rule order, capabilities per role and with a filtered capability, meta sanitizers (HTML/line breaks kept), visibility of draft/private/scheduled/password episodes, GUID immutability, duration detection, pure helpers (durations, timestamps, languages, categories, UUIDv5), feed contents (items, channel tags, categories, explicit, chapters/transcript tags, episode artwork rules, feed window and serial order, distribution options), feed cache invalidation, shortcodes, automatic episode pages, readiness report and its links, the CTA's assets, Elementor widget registration (no duplicate control IDs, dynamic content), no `_doing_it_wrong` |
| | `integration/admin.php` | Topics taxonomy and its capabilities (contributors assign, editors manage, filtered capabilities), the Podcast menu and sentence-case labels, default hidden list columns; episode editor: next episode number, paste-chapters disclosure, video field help, transcript files (fill the text, SRT accepted, other files rejected, hosted file shown), episode search and media AJAX for contributors (no other authors' private episodes or media); Quick Edit and Bulk Edit (*Number from*); design export allowlist and import validation, the Design screen's token table against `DesignSettings::output_tokens()` for every preset, the script data, the WCAG contrast formula and pairs (every preset passes), the Design screen render |
| | `integration/design.php` | *Details shown by default*: every consumer (widgets, shortcodes, episode page) shows the same details as 1.3.0 on an untouched site (`fixtures/details-1.3.0.json`, regenerate with `details-snapshot.php`); presets change new widgets, explicit Show/Hide beats them and *Default* follows; saving colors never changes details; the episode page keeps Full for Minimal/Compact; widgets saved by 1.3.0 (`fixtures/elementor-1.3.0.json`) render the same across preset and details changes and open in the editor with their 1.3.0 values made explicit; the 1.1–1.3 maps become suggestions (rendering unchanged, dismiss changes nothing); export format 2 round trip, format-1 import into suggestions, format-2 validation; previews equal the frontend for three presets; an explicit layout survives preset changes; the preset `layout` key; no unused `Presets::import/export` |
| | `integration/frontend.php` | timestamp links (parsing, building, only the page's episode), share menu (accessible markup, none for restricted episodes), embeds (iframe code, oEmbed height and HTML, the card document), video (sources, no third-party request before play, place on the episode page), topic filters and chips, `preload="none"` for audio on another host, the sticky player for lists and chapters (and which views open it: `data-epm-sticky-player`), list play button labels, the number column, dark-design section surfaces, hero/latest background padding, no strings in the player engine |
| | `integration/hosting.php` | the feed parser against every real feed in `fixtures/feeds/` and its host quirks, bot pages and Atom feeds rejected; host detection (address and `<generator>`), listing links, feed-address normalization and media types; finding a feed from an Apple Podcasts link, a web page (podcast feed before the blog feed) or a redirect; import (preview counts, lock and consent, GUIDs byte-for-byte including `%`-escapes, dates, external audio, chapters, transcripts to HTML, transcript files kept or copied, drafts for blocked/undated items, duplicates, re-import, show details, `podcast:guid`, audio that could not be copied, lock ownership, cancelling); host sync (conditional requests, local edits kept and editor saves ignored, deleted episodes stay deleted, truncated/empty feed guards, removed episodes drafted after a day, new feed addresses, never https to http, a redirect to this site, failures and back-off, schedule); feed output (external audio, remote artwork, moved-in `new-feed-url`, download-statistics prefix, `podcast:trailer`, `podcast:person`, `podcast:transcript` with captions, build time); transcript files (SRT type on every server, type aliases, upload before hosted file, readable text); setup steps and distribution progress |
| | `integration/feed.php` | the feed built page by page against the 1.3 builder (`reference/Feed-1.3.php`), byte for byte without `<lastBuildDate>`, episodic and serial, limits 0/1/2/3/500, a trailer, special characters, a measurement prefix and the per-episode filter; a fixed order for episodes with the same time; the feed of 600 episodes within 8 MB for every limit; the cache (pieces of at most 256 KB, no transient row, streamed byte for byte, a 304 that reads no piece, missing pieces rebuilt, one builder at a time, an overtaken build not stored, old pieces swept); Last-Modified never in the future and a stored future time repaired; If-None-Match lists, weak tags, `*` and substrings; U+FFFE/U+FFFF, control characters and invalid UTF-8; cache invalidation for a replaced media file, its metadata and the site title; archive-feed and previous-address routing, the setup assistant's and settings' offer; a changed feed address reported until confirmed, `/podcast/feed/` under plain permalinks; GUIDs at creation without the editor, derived values, duplicate rows collapsed to the served one (and recorded); the upgrade under a 24 MB memory headroom with 40 MB of transcripts (version first, nothing per episode in the request, batches finish it), a request that lost the version claim, a held upgrade lock; the readiness report against the 1.3 report (`reference/Readiness-1.3.php`) and folding beyond 50 episode problems; the delivery test on the feed's first enclosure (prefix, 404, a followed redirect, wrong sizes and ranges); listing links per platform; YouTube's requirements and the `<`/`>` warning |
| | `integration/import.php` | the import's data integrity in one process: a sync whose lock another request took over stops before the next episode (no validators stored, the other lock stays), the GUID is checked in the database right before an episode is created; paged feeds: page 2 answering 500, a transport timeout, invalid XML and an empty page that links on each end the catalog as incomplete with the reason, the page address and the error; relative and root-relative `rel="next"` resolved against the page; the page limit (`epm_import_max_pages`), a cycle and duplicate GUIDs across pages; "try again" continues from the failed page without reading a page or importing an episode twice; a move with an incomplete catalog is refused (no `moved_in`, no lock, no hosting switch) unless the missing part is accepted, a mirror is allowed and says it is partial; `wp podcast import` exits 1 on a partial catalog with `--resume`/`--accept-partial` as the way forward and never prints a plain success for part of a catalog; the parsed feed is stored in the database (never in uploads) and read back intact (1.5 MB of notes, Unicode, HTML), removed on done, cancel, a new preview and a failed import; two overlapping previews leave one job; a checked feed nobody imports expires after a day with its data (a running import does not); a running 1.3.0 import continues from the database and its folder is removed; uninstalling removes the stored data, the job, the 1.3.0 folder and the cleanup event |
| | `integration/media.php` | moving media (IMP-03, FEED-N7, IMP-04, IMPB-N1..N4), with answers from `EPM_Test_HTTP::$routes`: mirror a show (WebVTT, SRT, JSON transcripts, images), then move it with an image and a WebVTT file failing, a transcript address chosen on the site and an edited transcript text, both with the items unchanged and changed at the host: what is copied, kept and listed per kind with the reason, in the job, in Readiness and in the feed, and that the next run requests exactly what failed; transcript addresses imported by 1.3.0 (the feed item's own are copied, others kept); the editor's wording; a move whose audio stays at the host ends as `done_with_problems` with mode, `moved_in` and `locked` unchanged, and a retry or the confirmation finishes it; size limits with and without Content-Length, a truncated download, a web page/JSON/random bytes instead of audio (and an image or WebVTT answered with a web page), HTTP 404/403/500 retried by the next run, 429 and 503 with Retry-After pause the job; a copy that dies keeps chapters and transcript; an identical orphan and an existing attachment are reused (no "-1"); a separate process (`_media-child.php`) runs out of memory right after the file is moved into uploads: no orphan, no temp file, lock released, reason recorded, continuation scheduled, the next step copies the file; stale download files; `wp podcast status` and `wp podcast cancel` |
| | `integration/widgets.php` | order by episode number keeps unnumbered episodes (WID-N3); a call to action without a link explains itself in the editor and renders nothing on the site (WID-N7); the metadata separator (WID-N8); a widget or shortcode that renders nothing enqueues nothing, tokens printed only where podcast styles are used (WID-N9); the Latest Episode sticky option (widget and `[podcast_latest]`); the volume slider named once; every detail control is Default / Show / Hide with Default as the default |
| Races | `concurrency/run.sh` | two (or three) real PHP processes per scenario, synchronized by barrier files on observable points (a statement on the lock row, an episode insert, a feed request): a free lock, an abandoned lock, renew vs. takeover, release vs. takeover and a loop re-taking the lock all leave exactly one holder; cron's loop plus a step from the import screen import no GUID twice and count what happened; a step that read the job before the lock never marks a finished job failed, never overwrites the next preview and never saves an older position; a preview never replaces an import started meanwhile; Cancel lets the episode in flight finish and nothing after it; two first requests after a plugin update do no per-episode work and the queued batches write every episode once (`upgrade-once`); two requests that store the GUID of an episode created without hooks serve the same one (`guid-first-read`). `STRESS=1` adds a barrier-free cron loop plus polling run (200 items) |
| Budget | `perf/run.sh` | cost per request, each request a PHP process of its own with a 128M memory limit (a stock php-fpm): a paged feed (10 pages × 100 items from a slow host) is read over several requests, each below 48 MB above the booted site and 15 s; an import step needs the same memory on 1,000 and 4,000 items (at most 2 MB more); the feed (limits 20, 500 and 0) on synthetic catalogs of 300 and 1,500 episodes: 200, well-formed, every expected item, within 24 MB, the same memory on both sizes for 20 and 500, a 304 under 2 MB; the readiness report the same on both; the first request after an update with 300 episodes of 200 KB transcripts (60 MB) stores the version and touches no episode, the queued batches finish it. `PERF_HEAVY=1`: 50 pages × 500 items (25,000 episodes), steps on 1,000 vs 10,000 items, feed and readiness on 1,000 vs 10,000 episodes and 1,000 episodes with 40 KB transcripts, upgrade with 1,000 × 200 KB |
| Media downloads | `media/run.sh` | downloads over real sockets from a local media host (`fixtures/mediaserver.py`, on a free port; the test-only mu-plugin `epm-test-loopback.php` lets the site reach exactly that port): the size limit stops a 50 MB file right after the headers (Content-Length) or at the limit (chunked) and the host sends only a few MB; a stalled host is cut off by the low-speed limit, tried three times and reported, also with steps shorter than the low-speed window; a slow file larger than one request continues with Range requests over several steps and is byte-identical; a host without Range support is reported; a login page, random bytes, HTTP 404, a redirect to a private address; 429 makes the job wait; a move finishes only once a slow file is here; a 60 MB MP3 without a newline byte (the file `getimagesize()` read whole: a fatal error at 128M before) is copied in a process with a 128M limit using less than 32 MB above the booted site (`MEDIA_HEAVY=1`: also 300 MB); as root with tmpfs: a 20 MB temp folder (refused before the download with Content-Length, stopped when the disk fills without) and a full uploads folder (no partial file stays). Needs `python3` |
| HTTP | `http/run.sh` | `/podcast/feed/` and every archive feed URL serve the podcast feed, ETag/Last-Modified with 304s, a channel change answers `If-Modified-Since` with the new feed and a new `Last-Modified` (also on `/podcast/rss2/`, `/podcast/feed/atom/` and `?post_type=podcast_episode&feed=rss2`, which carry the feed's ETag), `If-None-Match: *` and tag lists answer 304, a tag merely containing the ETag 200, HEAD without a body, an episode dated next year leaves `Last-Modified` at or before now, the previous address `/feed/podcast/` and `?feed=podcast` (404 when off, 301 when on, also to `If-Modified-Since`), `/podcast/feed/` under plain permalinks, chapters JSON and transcript endpoints (404 for restricted episodes), episode page output, feed discovery link, design tokens printed once, shortcode and Elementor pages, REST meta exposure/protection, byte-range media; import data: a feed check through admin-ajax stores the parsed feed in the database, in no file under uploads, no `epm-import` folder is served, the job token is in no URL or log, Cancel removes the data; with another host: 301 from every feed address to the host's feed (discovery link too), the blog feed not redirected, 200 again without the redirect and when self-hosted |
| Browser | `e2e/run.mjs` (Playwright/Chromium) | player playback, chapter seek + highlight, theme-proof buttons, resume position, remembered speed, shared state between card and player, pause-others, AJAX-inserted players, mobile layout; Elementor editor rendering, re-render on control change, playback in the preview, episode picker; episode admin: audio box placement, drag-and-drop upload, chapters, show notes, validation notices, feed update; Elementor page with the sticky player; design presets, export and import |
| | `e2e/admin.mjs` | Design screen (live preview, preset tiles and the confirm dialog, contrast badges, save, the unsaved-changes warning, the save bar clear of focused fields, keyboard and 390 px); episode editor (next number, paste chapters: add or replace, half-filled rows, the save buttons after autosave); episode list (column widths, Quick Edit) |
| | `e2e/design.mjs` | the *Details shown by default* form (keyboard, phones, save); 1.1–1.3 maps as suggestions, never active; an explicit layout chosen in the real Elementor editor survives preset changes (DESIGN-N1); widgets saved by 1.3.0 in the editor and *Use Podcast → Design defaults*; artwork radius and the round guest photo inside Elementor (DESIGN-N2) |
| | `e2e/frontend.mjs` | share menu (keyboard, copy, position link, embed code, manual copy), timestamp links (`?t=` forms, the cue label), the embed card (320 and 600 px, height message, links), card buttons and the sticky bar, the video facade (no third-party request before play, focus), the sticky bar for lists and chapters, remote audio loading only on play, the design system on real pages (dark designs, Elementor Kit rules, row alignment) |
| | `e2e/player.mjs` | the player engine and its Elementor integration: initialization through Elementor's `element_ready` hooks alone (the engine is served with its MutationObserver fallback switched off) and together with the observer, in the real editor (insert, switch episode, duplicate, delete, undo/redo, ten re-renders; one click listener per control, one click = one playback); init three times plus hooks plus observer; Swiper 8 loop copies (Elementor's bundled `swiper.js`); a replaced or fixed audio file taking over (duration, title, sticky bar, Media Session), re-rendering a playing player (frontend and editor), views and controllers released, a player put back into the page, chapter-first artwork, remote audio unrequested after a re-render; one volume across players, native mute, unmute on raise, an emulated read-only volume (iOS), touch hit areas; speed on another episode, resume vs `?t=`, out-of-range timestamps, Media Session position after seeks, ArrowUp/ArrowDown on sliders; sticky bar safe areas (CDP insets), a shell inserted later, the sticky option next to a list. Creates its own pages and remote-audio episodes (audio on `127.0.0.1`, "another host") and deletes them at the end |
| | `e2e/widgets.mjs` | row lists and the Latest Episode card in narrow columns and row containers at 320/390 px (WID-N1); two paginated lists on one page (WID-N2); copied markup gets its own share-menu ids and the volume is named once; the Latest Episode sticky option |
| | `e2e/style-audit.mjs` | every style control of every widget, set in a real Elementor page: a measured computed-style change on the element it names; fails when `CONTROL-AUDIT.md` differs from the measurement (regenerate with `EPM_WRITE_AUDIT=1`) |
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
The runner refuses an existing `WP_DIR/site` unless setup created the
`.epm-test-site` marker there. This protects an unrelated WordPress site
from fixture seeding, which deletes episodes and changes site settings.
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

## Feed, readiness and upgrade budgets

`perf/run.sh` also measures the feed, the readiness report and the
upgrade on synthetic catalogs (`perf/catalog.php` writes them with bulk
SQL: 10,000 episodes in seconds; every 13th one WAV, every 17th one
without audio; all named `perf-cat-*` and removed afterwards):

```bash
PERF_ONLY=feed WP_DIR=/tmp/epm-wp tests/perf/run.sh        # one section: import, feed, readiness, upgrade
PERF_HEAVY=1 PERF_ONLY=feed WP_DIR=/tmp/epm-wp tests/perf/run.sh
WP_DIR=/tmp/epm-wp /tmp/epm-wp/wp eval-file tests/perf/catalog-budget.php catalog 5000 40   # by hand
WP_DIR=/tmp/epm-wp /tmp/epm-wp/wp eval-file tests/perf/catalog-budget.php feed 0 cold
```

## Production-like checks (nginx + php-fpm, MariaDB)

`perf/production.sh` drives a separate MariaDB test site through nginx
and php-fpm with the distribution's php.ini (128M), started and stopped
by `perf/fpm.sh` (which links `perf/probe.php` as a must-use plugin that
logs each labelled request's time and peak memory). It needs nginx and
php-fpm, deletes episodes and must run on a site that holds no other
episodes (do not seed it). Not part of `run-all.sh`; it takes several
minutes:

```bash
WP_DB=mysql DB_NAME=epm_perf DB_USER=epm DB_PASSWORD=epm DB_HOST=127.0.0.1 \
  WP_DIR=/tmp/epm-wp-fpm WP_PORT=8963 tests/bin/setup-wp.sh
WP_DIR=/tmp/epm-wp-fpm WP_PORT=8963 tests/perf/production.sh          # upgrade, feed, move
WP_DIR=/tmp/epm-wp-fpm WP_PORT=8963 tests/perf/production.sh feed     # one section
```

- **upgrade:** 1,000 episodes with 40 KB transcripts, an older
  `epm_version` written with SQL (an update without WordPress loading):
  three rounds of `/`, `/podcast/feed/`, `/wp-login.php` and `/wp-admin/`
  answer 200, the first request stores the new version, the queued
  batches finish.
- **feed:** 5,000 and 10,000 episodes and 1,000 with 40 KB transcripts:
  limits 20, 500 and 0 cold, warm and conditional: 200, well-formed,
  every item, at most `FEED_BUDGET_MB` (64) for the whole request, 304 in
  under `NOT_MODIFIED_MS` (300).
- **move:** "Move my podcast here" of a generated 5,000-episode feed
  (`https://feeds.example.test/generated/5000.xml`) through the import
  screen's AJAX requests; afterwards the feed (now unlimited) answers 200
  with every episode, again from the cache, and 304.

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
| `https://feeds.example.test/generated/<n>.xml` | a generated show of `n` episodes (at most 20,000; GUIDs `gen-1` …) for catalog-size tests |
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
