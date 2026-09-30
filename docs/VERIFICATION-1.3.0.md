# Verification report — 1.3.0

Date: 2026-09-30. This report records what was run for 1.3.0, on which
code, with which result, and what still needs a person, a real device or
a real service.

## Code under test

Branch `claude/festive-knuth-jsma3u` with the fixes of the final 1.3.0
review round (commits `0eca591`, `ab683ab`, `a2dee7e`; see the "Fixed"
and "Security" entries of the 1.3.0 changelog). The code was not changed
during the run.

## Environment

- **Software:**
  - WordPress 7.1.2 with the SQLite Database Integration drop-in 3.0.2
  - Elementor 4.3.3 (free)
  - PHP 8.4.19 (CLI and built-in server)
  - Node 22.22.2, Playwright 1.56.1, Chromium 141.0.7390.37
- **Theme:** Hello Elementor 3.5.1 (Twenty Twenty-Five 1.5 installed).
- **Media:** generated MP3 (MPEG-1 Layer III), WAV and PNG files.
- **Seeded episodes:** published, draft, private, scheduled,
  password-protected, without audio and WAV-only, plus an Elementor page
  and a shortcode page (`tests/fixtures/seed.php`).
- **Hosts:** simulated by the test HTTP fixture server
  (`tests/fixtures/mu-plugins/epm-test-http.php`) with 26 real feeds from
  24 hosts and publishing tools, bot-protection pages and synthetic edge
  cases. No request left the machine.

Command, run on a freshly provisioned site after the review fixes were
done:

```bash
WP_DIR=/tmp/epm-wp-lead WP_PORT=8910 tests/run-all.sh
```

## Results

| Suite | Result |
|---|---|
| Lint | passed (`php -l` on every PHP file, `node --check` on every script) |
| `integration/run.php` | 167 assertions passed, 0 failed (23 tests) |
| `integration/admin.php` | 331 assertions passed, 0 failed (29 tests) |
| `integration/frontend.php` | 204 assertions passed, 0 failed (21 tests) |
| `integration/hosting.php` | 1123 assertions passed, 0 failed (75 tests) |
| `http/run.sh` | 42 checks passed, 0 failed |
| `e2e/run.mjs` | 40 checks passed (player 13, Elementor editor 6, episode admin 9, Elementor page and sticky player 7, design presets/export/import 5) |
| `e2e/admin.mjs` | 68 checks passed (Design screen 36, episode editor 27, episode list 5) |
| `e2e/frontend.mjs` | 87 checks passed (share menu 19, timestamp links 14, embed 11, card buttons and sticky bar 7, video facade 9, sticky bar for lists and chapters 6, remote audio 4, design system on the page 17) |
| `e2e/setup.mjs` | 120 checks passed (activation 6, keep the current host by keyboard 40, host here at 390 px 19, move a locked show 22, Hosting & import 21, Distribution 12) |
| PHP notices from the plugin | none |
| **Overall** | **passed** (exit code 0) |

Totals: 1825 integration assertions, 42 HTTP checks, 315 browser checks,
all passed. The only PHP notices in the log come from Elementor itself
(a PHP 8.4 deprecation in `atomic-global-styles.php`).

### Fixed during verification

A first run found that the Design screen's preview of the three dark
presets (`business-tuning`, `night-studio`, `midnight`) lacked the section
surface the site prints: `Admin::design_dark_vars()` kept its own list.
It now returns `DesignSettings::dark_vars()`, and `integration/admin.php`
checks every preset against the site's output.

### Continuous integration

GitHub Actions (`.github/workflows/tests.yml`: lint on PHP 8.1–8.4,
integration and HTTP on PHP 8.1 and 8.4, browser suites) passed on
`89c0474` (run 12). The run before (`07cdfc0`, run 11) failed on PHP 8.1
because whether an SRT upload was accepted depended on the server's file
type detection; `89c0474` fixed that. The review-round commits run in CI
on the pull request.

## What was verified, and how

| Area | Verified by |
|---|---|
| Feed routing, capabilities, meta sanitizers, visibility, GUIDs, feed contents and cache | `integration/run.php`, HTTP |
| Feed `Last-Modified` / `lastBuildDate` following content changes | HTTP (a channel change answers `If-Modified-Since` with the new feed and a new date), `integration/hosting.php` |
| Control characters, serial feed window, M4A type | `integration/run.php`, `integration/hosting.php` |
| Feed parser against real host feeds and their quirks | `integration/hosting.php` (every fixture feed, bot pages, Atom) |
| Finding a feed (Apple link, web page with blog and podcast feeds, redirects) | `integration/hosting.php` |
| Import: GUIDs byte-for-byte (`%`-escapes), dates with wrong weekdays, chapters, transcripts, transcript files kept or copied, drafts, duplicates, re-import, show details, `podcast:guid` on a move, lock ownership and renewal, cancelling, audio that could not be copied | `integration/hosting.php`, `e2e/setup.mjs` (setup assistant and Hosting & import with progress and the not-copied list) |
| Sync: 304s, local edits kept and editor saves ignored, deleted episodes, truncated/empty feeds, removed episodes, feed moves, no https → http, a redirect to this site switching to *This website*, back-off, schedule | `integration/hosting.php` |
| External mode: 301 from every feed address, blog feed untouched, discovery link, 200 again when off or self-hosted; the mode needs a feed address | HTTP, `integration/hosting.php`, `e2e/setup.mjs` |
| Moving a mirrored show here (confirmation, switch to *This website*); readiness warning while imported audio still loads from the old host | `e2e/setup.mjs`, `integration/hosting.php` |
| Setup assistant: three paths, keyboard only, 390 px, inline errors tied to fields, focus on each step, locked-feed consent | `e2e/setup.mjs` |
| Distribution: progress, live count, primary button, listing link → subscribe button, field errors | `e2e/setup.mjs`, `integration/hosting.php` |
| Transcript files: SRT type on every server, aliases, editor picker filling the text, feed tags with `rel="captions"` | `integration/admin.php`, `integration/hosting.php` |
| Topics: taxonomy, capabilities (contributors assign, editors manage), menu, filters, chips | `integration/admin.php`, `integration/frontend.php` |
| Editor: next number, paste chapters (add, replace, half-filled rows), video help, audio and transcript files the user may read (editor save, AJAX and REST `meta`), episode search | `integration/admin.php`, `e2e/admin.mjs` |
| Quick Edit and Bulk Edit (*Number from*), default hidden columns | `integration/admin.php`, `e2e/admin.mjs` |
| Design screen: presets, dialog, live preview, contrast check (eight pairs, every preset passes), unsaved-changes warning, save bar, export/import validation, 390 px | `integration/admin.php`, `e2e/admin.mjs` |
| Share menu (keyboard, clipboard, position link, embed code, manual copy) | `integration/frontend.php`, `e2e/frontend.mjs` (clipboard permission granted in Chromium) |
| Timestamp links (`?t=` forms, cue without autoplay, the play button's name) | `integration/frontend.php`, `e2e/frontend.mjs` |
| Embed card (iframe code, oEmbed height and HTML, only podcast assets, fits a 200px frame down to 320 px, the focus ring of the linked title, height message) | `integration/frontend.php`, `e2e/frontend.mjs` (the embedding site's message was simulated) |
| Video facade (no third-party request before play, only youtube-nocookie.com after play, focus moves into the video) | `integration/frontend.php`, `e2e/frontend.mjs` (third-party requests refused and recorded) |
| Sticky player for lists, chapters and the episode page; remote audio only on play | `integration/frontend.php`, `e2e/frontend.mjs` |
| Dark designs on a light theme page (sections, the Episode Metadata line, the note under a video), Elementor Kit rules, row alignment, list button widths | `integration/frontend.php`, `e2e/frontend.mjs` |
| Preset contrast ratios | computed with the WCAG formula for every preset (table in `DESIGN.md`) and by `integration/admin.php` |
| Translations | `wp i18n make-pot` (below) |

## Translation template

`languages/elementor-podcast-manager.pot` regenerated with

```bash
wp i18n make-pot . languages/elementor-podcast-manager.pot --exclude=tests,docs,node_modules --domain=elementor-podcast-manager
```

1130 strings, generated without warnings: every string with placeholders
has a translator comment, strings used in several files share one
comment, and the player's "%1$s of %2$s" has its own context
(`player position`).

## Not verified (needs real devices, services, licenses or people)

- **Real devices and browsers:** iOS Safari and Android Chrome
  (lock-screen and Media Session controls, the share sheet, clipboard
  prompts, the sticky bar with on-screen keyboards and safe areas),
  Firefox and desktop Safari. The suites ran in Chromium only.
- **Screen readers:** VoiceOver, NVDA, JAWS and TalkBack were not used.
  The suites check names, roles, states, focus order and live-region
  text, not how a screen reader speaks them.
- **Elementor Pro Theme Builder:** the automatic episode page is skipped
  when a Theme Builder single template applies (two guarded checks).
  Elementor Pro was not available.
- **Directory submission:** the feed was validated structurally, not
  submitted to Apple Podcasts Connect, Spotify, YouTube, Amazon or the
  other platforms, and no hosted validator was run. Caption files
  (`podcast:transcript rel="captions"`) were not checked in Apple
  Podcasts.
- **Real hosts:** imports ran against trimmed copies of real feeds
  served locally. Live imports from the hosts, their redirect settings
  (including Spotify for Creators' "Redirect your podcast"), the dashboard
  menus listed as "not verified" in `docs/HOSTING.md`, and bot protection
  on real sites were not exercised.
- **Video platforms:** YouTube (youtube-nocookie.com) and Vimeo playback
  after the facade was not loaded; third-party requests were blocked on
  purpose.
- **Embeds on other sites:** the WordPress embed handshake was simulated
  inside the test site; a second WordPress site and non-WordPress pages
  were not used.
- **Large catalogs:** shows with 1000+ episodes (import time, the media
  copy of large files, feed build time, the episode list, MySQL query
  plans) were not profiled. The suites run on SQLite.
- **Production hosting:** HEAD and byte-range support, CDNs and page
  caches in front of `/podcast/feed/`, WP-Cron on low-traffic sites and
  `DISABLE_WP_CRON` setups.
- **Other environments:** PHP 8.1–8.3 locally (CI covers 8.1 and 8.4 for
  integration and HTTP), multisite, other themes than Hello Elementor,
  right-to-left languages, and real translations (there are no `.po`
  files yet).
