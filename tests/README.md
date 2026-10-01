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
  is shared code (assertion runner, feed helpers), not a suite.
- **Browser:** every `e2e/*.mjs`, run with Node; `run.mjs` first, the
  others in alphabetical order. `e2e/lib.mjs`, `e2e/helpers.mjs` and files
  starting with `_` are shared code, not suites.

Current order: `integration/run.php`, `admin.php`, `design.php`,
`frontend.php`, `hosting.php`, `widgets.php`; then `http/run.sh`; then
`e2e/run.mjs`, `admin.mjs`, `design.mjs`, `frontend.mjs`, `player.mjs`,
`setup.mjs`, `style-audit.mjs`, `widgets.mjs`.

## Suites

| Suite | File | What it covers |
|---|---|---|
| Lint | `bin/lint.sh` | `php -l` on every PHP file, `node --check` on every script and browser suite |
| Integration | `integration/run.php` | rewrite-rule order, capabilities per role and with a filtered capability, meta sanitizers (HTML/line breaks kept), visibility of draft/private/scheduled/password episodes, GUID immutability, duration detection, pure helpers (durations, timestamps, languages, categories, UUIDv5), feed contents (items, channel tags, categories, explicit, chapters/transcript tags, episode artwork rules, feed window and serial order, distribution options), feed cache invalidation, shortcodes, automatic episode pages, readiness report and its links, the CTA's assets, Elementor widget registration (no duplicate control IDs, dynamic content), no `_doing_it_wrong` |
| | `integration/admin.php` | Topics taxonomy and its capabilities (contributors assign, editors manage, filtered capabilities), the Podcast menu and sentence-case labels, default hidden list columns; episode editor: next episode number, paste-chapters disclosure, video field help, transcript files (fill the text, SRT accepted, other files rejected, hosted file shown), episode search and media AJAX for contributors (no other authors' private episodes or media); Quick Edit and Bulk Edit (*Number from*); design export allowlist and import validation, the Design screen's token table against `DesignSettings::output_tokens()` for every preset, the script data, the WCAG contrast formula and pairs (every preset passes), the Design screen render |
| | `integration/design.php` | *Details shown by default*: every consumer (widgets, shortcodes, episode page) shows the same details as 1.3.0 on an untouched site (`fixtures/details-1.3.0.json`, regenerate with `details-snapshot.php`); presets change new widgets, explicit Show/Hide beats them and *Default* follows; saving colors never changes details; the episode page keeps Full for Minimal/Compact; widgets saved by 1.3.0 (`fixtures/elementor-1.3.0.json`) render the same across preset and details changes and open in the editor with their 1.3.0 values made explicit; the 1.1–1.3 maps become suggestions (rendering unchanged, dismiss changes nothing); export format 2 round trip, format-1 import into suggestions, format-2 validation; previews equal the frontend for three presets; an explicit layout survives preset changes; the preset `layout` key; no unused `Presets::import/export` |
| | `integration/frontend.php` | timestamp links (parsing, building, only the page's episode), share menu (accessible markup, none for restricted episodes), embeds (iframe code, oEmbed height and HTML, the card document), video (sources, no third-party request before play, place on the episode page), topic filters and chips, `preload="none"` for audio on another host, the sticky player for lists and chapters (and which views open it: `data-epm-sticky-player`), list play button labels, the number column, dark-design section surfaces, hero/latest background padding, no strings in the player engine |
| | `integration/hosting.php` | the feed parser against every real feed in `fixtures/feeds/` and its host quirks, bot pages and Atom feeds rejected; host detection (address and `<generator>`), listing links, feed-address normalization and media types; finding a feed from an Apple Podcasts link, a web page (podcast feed before the blog feed) or a redirect; import (preview counts, lock and consent, GUIDs byte-for-byte including `%`-escapes, dates, external audio, chapters, transcripts to HTML, transcript files kept or copied, drafts for blocked/undated items, duplicates, re-import, show details, `podcast:guid`, audio that could not be copied, lock ownership, cancelling); host sync (conditional requests, local edits kept and editor saves ignored, deleted episodes stay deleted, truncated/empty feed guards, removed episodes drafted after a day, new feed addresses, never https to http, a redirect to this site, failures and back-off, schedule); feed output (external audio, remote artwork, moved-in `new-feed-url`, download-statistics prefix, `podcast:trailer`, `podcast:person`, `podcast:transcript` with captions, build time); transcript files (SRT type on every server, type aliases, upload before hosted file, readable text); setup steps and distribution progress |
| | `integration/widgets.php` | order by episode number keeps unnumbered episodes (WID-N3); a call to action without a link explains itself in the editor and renders nothing on the site (WID-N7); the metadata separator (WID-N8); a widget or shortcode that renders nothing enqueues nothing, tokens printed only where podcast styles are used (WID-N9); the Latest Episode sticky option (widget and `[podcast_latest]`); the volume slider named once; every detail control is Default / Show / Hide with Default as the default |
| HTTP | `http/run.sh` | `/podcast/feed/` and every archive feed URL serve the podcast feed, ETag/Last-Modified with 304s, a channel change answers `If-Modified-Since` with the new feed and a new `Last-Modified`, chapters JSON and transcript endpoints (404 for restricted episodes), episode page output, feed discovery link, design tokens printed once, shortcode and Elementor pages, REST meta exposure/protection, byte-range media; with another host: 301 from every feed address to the host's feed (discovery link too), the blog feed not redirected, 200 again without the redirect and when self-hosted |
| Browser | `e2e/run.mjs` (Playwright/Chromium) | player playback, chapter seek + highlight, theme-proof buttons, resume position, remembered speed, shared state between card and player, pause-others, AJAX-inserted players, mobile layout; Elementor editor rendering, re-render on control change, playback in the preview, episode picker; episode admin: audio box placement, drag-and-drop upload, chapters, show notes, validation notices, feed update; Elementor page with the sticky player; design presets, export and import |
| | `e2e/admin.mjs` | Design screen (live preview, preset tiles and the confirm dialog, contrast badges, save, the unsaved-changes warning, the save bar clear of focused fields, keyboard and 390 px); episode editor (next number, paste chapters: add or replace, half-filled rows, the save buttons after autosave); episode list (column widths, Quick Edit) |
| | `e2e/design.mjs` | the *Details shown by default* form (keyboard, phones, save); 1.1–1.3 maps as suggestions, never active; an explicit layout chosen in the real Elementor editor survives preset changes (DESIGN-N1); widgets saved by 1.3.0 in the editor and *Use Podcast → Design defaults*; artwork radius and the round guest photo inside Elementor (DESIGN-N2) |
| | `e2e/frontend.mjs` | share menu (keyboard, copy, position link, embed code, manual copy), timestamp links (`?t=` forms, the cue label), the embed card (320 and 600 px, height message, links), card buttons and the sticky bar, the video facade (no third-party request before play, focus), the sticky bar for lists and chapters, remote audio loading only on play, the design system on real pages (dark designs, Elementor Kit rules, row alignment) |
| | `e2e/player.mjs` | the player engine and its Elementor integration: initialization through Elementor's `element_ready` hooks alone (the engine is served with its MutationObserver fallback switched off) and together with the observer, in the real editor (insert, switch episode, duplicate, delete, undo/redo, ten re-renders; one click listener per control, one click = one playback); init three times plus hooks plus observer; Swiper 8 loop copies (Elementor's bundled `swiper.js`); a replaced or fixed audio file taking over (duration, title, sticky bar, Media Session), re-rendering a playing player (frontend and editor), views and controllers released, a player put back into the page, chapter-first artwork, remote audio unrequested after a re-render; one volume across players, native mute, unmute on raise, an emulated read-only volume (iOS), touch hit areas; speed on another episode, resume vs `?t=`, out-of-range timestamps, Media Session position after seeks, ArrowUp/ArrowDown on sliders; sticky bar safe areas (CDP insets), a shell inserted later, the sticky option next to a list. Creates its own pages and remote-audio episodes (audio on `127.0.0.1`, "another host") and deletes them at the end |
| | `e2e/widgets.mjs` | row lists and the Latest Episode card in narrow columns and row containers at 320/390 px (WID-N1); two paginated lists on one page (WID-N2); copied markup gets its own share-menu ids and the volume is named once; the Latest Episode sticky option |
| | `e2e/style-audit.mjs` | every style control of every widget, set in a real Elementor page: a measured computed-style change on the element it names; fails when `CONTROL-AUDIT.md` differs from the measurement (regenerate with `EPM_WRITE_AUDIT=1`) |
| | `e2e/setup.mjs` | activation opens the setup assistant once; the assistant's three paths (keep a host: keyboard-only step 1, feed check, import with progress, show details taken from the feed, style and podcast page; host here at 390 px with inline errors; move a locked show with owner consent), focus on each new step, no horizontal overflow; Hosting & import (another host needs its feed address, switch host, Sync now, import with progress, the confirmation before copying a mirrored show's audio, audio that could not be copied, the move back to *This website*); Distribution (mark submitted, the live count and primary button, listing link becomes a subscribe button) |

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
| `CHROMIUM_PATH` | — | use a specific Chromium binary |
| `SKIP_E2E` | — | skip the browser suites |

Several sites can run side by side with their own `WP_DIR` and `WP_PORT`.

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

Feeds answer `If-None-Match` with 304. Every other request passes through
untouched. `integration/hosting.php` loads the class directly and uses
`EPM_Test_HTTP::$routes` (dynamic answers, for example a feed that changes
between syncs), `EPM_Test_HTTP::$log` (requests made) and
`EPM_Test_HTTP::$offline` (refuse every other request, so a test can prove
that nothing left the site).
