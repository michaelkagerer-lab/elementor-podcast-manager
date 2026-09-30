# Tests

Everything runs against a real, disposable WordPress + Elementor site —
no mocks. The site uses SQLite (no database server) and PHP's built-in web
server, so the only requirements are PHP 8.1+ (with `pdo_sqlite`, `gd`,
`zip`), Node 22+ and internet access for the first download.

```bash
tests/run-all.sh              # lint + integration + HTTP + browser
SKIP_E2E=1 tests/run-all.sh   # without the browser suite
```

`run-all.sh` provisions the site (idempotent), seeds fixtures and runs:

| Suite | File | What it covers |
|---|---|---|
| Lint | `bin/lint.sh` | `php -l` on every PHP file, `node --check` on every script |
| Integration | `integration/run.php` (WP-CLI) | rewrite-rule order, capabilities per role and with a filtered capability, meta sanitizers (HTML/line breaks kept), visibility of draft/private/scheduled/password episodes, GUID immutability, duration detection, feed contents (items, channel tags, categories, explicit, chapters/transcript tags, episode artwork rules, feed window, serial order, distribution options), feed cache invalidation, shortcodes, automatic episode pages, readiness report, Elementor widget registration (no duplicate control IDs, dynamic content), no `_doing_it_wrong` |
| HTTP | `http/run.sh` | `/podcast/feed/` and every archive feed URL serve the podcast feed, ETag/Last-Modified with 304s, chapters JSON and transcript endpoints (404 for restricted episodes), episode page output, feed discovery link, design tokens printed once, REST meta exposure/protection, byte-range media |
| Browser | `e2e/run.mjs` (Playwright/Chromium) | player playback, chapter seek + highlight, theme-proof buttons, resume position, remembered speed, shared state between card and player, pause-others, AJAX-inserted players, mobile layout; Elementor editor rendering, re-render on control change, playback in the preview, episode picker; episode admin: audio box placement, drag-and-drop upload, chapters, show notes, validation notices, feed update |

Finally it fails if the plugin's own files raised any PHP warning, notice
or deprecation.

## Environment variables

| Variable | Default | Purpose |
|---|---|---|
| `WP_DIR` | `/tmp/epm-wp` | where the test site lives |
| `WP_PORT` | `8889` | local port |
| `WP_VERSION` | `latest` | WordPress version |
| `ELEMENTOR_VERSION` | `latest-stable` | Elementor version |
| `WP_THEME` | `hello-elementor` | active theme (`twentytwentyfive` also installed) |
| `CHROMIUM_PATH` | — | use a specific Chromium binary |

## Running suites individually

```bash
tests/bin/setup-wp.sh                                   # site at http://localhost:8889
EPM_ALLOW_TEST_SEED=1 /tmp/epm-wp/wp eval-file tests/fixtures/seed.php
/tmp/epm-wp/wp eval-file tests/integration/run.php
WP_CLI=/tmp/epm-wp/wp tests/http/run.sh
cd tests/e2e && npm install && npx playwright install chromium && WP_CLI=/tmp/epm-wp/wp node run.mjs
```

`fixtures/seed.php` **deletes every episode** before seeding and refuses to
run on a site whose environment type is `production` unless
`EPM_ALLOW_TEST_SEED=1` is set. Never point it at a real site.

The fixtures generate their own media in PHP: silent MPEG-1 Layer III
MP3s (WordPress reads their exact durations), a WAV file and PNG artwork.
