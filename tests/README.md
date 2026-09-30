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
```

`run-all.sh` provisions the site (idempotent), then runs every suite. The
fixtures are seeded again before each suite, so every suite starts from
the same site, and every suite runs even when an earlier one failed. The
script ends with a summary and a non-zero exit code when a suite failed.
It leaves the site running and seeded at `http://localhost:$WP_PORT`
(admin/admin) for manual checks.

## Suites

Suites are discovered by file name, so a new suite needs no change to
`run-all.sh`:

- **Integration:** every `integration/*.php`, run with `wp eval-file`;
  `run.php` first, the others in alphabetical order. `integration/lib.php`
  is shared code (assertion runner, feed helpers), not a suite.
- **Browser:** every `e2e/*.mjs`, run with Node; `run.mjs` first, the
  others in alphabetical order. `e2e/lib.mjs`, `e2e/helpers.mjs` and files
  starting with `_` are shared code, not suites.

| Suite | File | What it covers |
|---|---|---|
| Lint | `bin/lint.sh` | `php -l` on every PHP file, `node --check` on every script and browser suite |
| Integration | `integration/run.php` | rewrite-rule order, capabilities per role and with a filtered capability, meta sanitizers (HTML/line breaks kept), visibility of draft/private/scheduled/password episodes, GUID immutability, duration detection, feed contents (items, channel tags, categories, explicit, chapters/transcript tags, episode artwork rules, feed window, serial order, distribution options), feed cache invalidation, shortcodes, automatic episode pages, readiness report, Elementor widget registration (no duplicate control IDs, dynamic content), no `_doing_it_wrong` |
| | `integration/hosting.php` | the feed parser against every real feed in `fixtures/feeds/` and its host quirks, bot pages and Atom feeds rejected; host detection (address and `<generator>`), listing links, feed-address normalization; finding a feed from an Apple Podcasts link, a web page or a redirect; import (preview counts, lock and consent, GUIDs byte-for-byte, dates, external audio, chapters, transcripts to HTML, transcript files kept or copied, drafts for blocked/undated items, duplicates, re-import, show details, `podcast:guid`); host sync (conditional requests, local edits kept, deleted episodes stay deleted, truncated/empty feed guards, removed episodes drafted after a day, new feed addresses, failures and back-off, locking, schedule); feed output (external audio, remote artwork, moved-in `new-feed-url`, download-statistics prefix, `podcast:trailer`, `podcast:person`, `podcast:transcript` with captions); transcript files (SRT upload permission, type aliases, upload before hosted file, readable text); setup steps and distribution progress |
| | `integration/admin.php`, `integration/frontend.php` | admin screens and frontend components (see each file's header) |
| HTTP | `http/run.sh` | `/podcast/feed/` and every archive feed URL serve the podcast feed, ETag/Last-Modified with 304s, chapters JSON and transcript endpoints (404 for restricted episodes), episode page output, feed discovery link, design tokens printed once, REST meta exposure/protection, byte-range media; with another host: 301 from every feed address to the host's feed (discovery link too), 200 again without the redirect and when self-hosted |
| Browser | `e2e/run.mjs` (Playwright/Chromium) | player playback, chapter seek + highlight, theme-proof buttons, resume position, remembered speed, shared state between card and player, pause-others, AJAX-inserted players, mobile layout; Elementor editor rendering, re-render on control change, playback in the preview, episode picker; episode admin: audio box placement, drag-and-drop upload, chapters, show notes, validation notices, feed update; design presets, export and import |
| | `e2e/setup.mjs` | activation opens the setup assistant once; the assistant's three paths (keep a host: keyboard-only step 1, feed check, import with progress, show details taken from the feed, style and podcast page; host here at 390 px with inline errors; move a locked show with owner consent), focus on each new step, no horizontal overflow; Hosting & import (switch host, Sync now, import with progress); Distribution (mark submitted, listing link becomes a subscribe button) |
| | `e2e/admin.mjs`, `e2e/frontend.mjs` | admin screens and frontend components (see each file's header) |

Finally `run-all.sh` fails if the plugin's own files raised a PHP warning,
notice or deprecation during this run (the debug log is emptied first).

Browser screenshots land in `e2e/screenshots/`.

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
/tmp/epm-wp/wp eval-file tests/integration/run.php      # or hosting.php, admin.php, …
WP_URL=http://localhost:8889 WP_CLI=/tmp/epm-wp/wp tests/http/run.sh
cd tests/e2e && npm install && npx playwright install chromium
WP_URL=http://localhost:8889 WP_CLI=/tmp/epm-wp/wp node run.mjs   # or setup.mjs, …
```

Seed the fixtures before each suite when running them by hand: suites
clean up after themselves, but start from the seeded site.

## Fixtures

- `fixtures/seed.php` **deletes every episode** before seeding and refuses
  to run on a site whose environment type is `production` unless
  `EPM_ALLOW_TEST_SEED=1` is set. Never point it at a real site. It
  generates its own media in PHP: silent MPEG-1 Layer III MP3s (WordPress
  reads their exact durations), a WAV file and PNG artwork.
- `fixtures/feeds/` holds trimmed real feeds from 20+ hosts, bot-protection
  pages and synthetic feeds; see its README.
- `fixtures/mu-plugins/epm-test-http.php` is the HTTP fixture server.
  `setup-wp.sh` links it into the test site's `mu-plugins`; it does
  nothing on a site whose environment type is `production`.
  `integration/hosting.php` uses its `$routes` (dynamic answers), `$log`
  (requests made) and `$offline` (refuse every other request).
