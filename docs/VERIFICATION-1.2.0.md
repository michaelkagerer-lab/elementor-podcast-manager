# Verification report — 1.2.0

Date: 2026-09-30. Unlike 1.1.0 (static review only), this release was
verified by running the plugin.

## Environment

- **Software:**
  - WordPress 7.1.2 with the SQLite Database Integration drop-in
  - Elementor 4.3.2 (free)
  - PHP 8.4 (CLI and built-in server)
  - Chromium 141 via Playwright 1.56
- **Themes:** Hello Elementor (default for the suites), Twenty Twenty-Five (block theme) and Twenty Twenty-One (classic theme; used for the token-precedence and button-style checks).
- **Media:** generated MP3 (MPEG-1 Layer III) and WAV files, plus PNG/JPEG artwork.
- **Seeded episodes:** published, draft, private, scheduled, password-protected, without audio and WAV-only.

The same setup is reproducible with `tests/run-all.sh` (see
`tests/README.md`). CI runs lint on PHP 8.1–8.4, the integration and HTTP
suites on PHP 8.1 and 8.4, and all suites including the browser on PHP 8.3.

## How each 1.2.0 fix was found and verified

| Issue | Found by | Verified by |
|---|---|---|
| `/podcast/feed/` served WordPress's generic RSS | `curl /podcast/feed/` on the fresh install; `wp rewrite list` showed the plugin rule 14th, behind `podcast/(feed\|rdf\|rss\|rss2\|atom)/?$` | integration: rule order; HTTP: `/podcast/feed/`, `/podcast/rss2/`, `/podcast/feed/atom/`, `?epm_podcast_feed=1` all serve the podcast feed and never list restricted episodes |
| Admins lost `edit_posts` | activation in the test site: `map_meta_cap` notices, admin redirected to profile.php, `edit.php` 403 | integration: per-role capabilities (admin, editor, author, contributor, subscriber) and a filtered custom capability; debug log free of `map_meta_cap` notices |
| Show notes/transcripts lost HTML on save | stored meta was stripped after `update_post_meta` | integration + browser: HTML kept, `<script>` removed, bio line breaks kept after a real editor save |
| REST meta missing | `/wp-json/wp/v2/podcast_episode/{id}` had no `meta` | HTTP: meta present; hidden for password-protected episodes |
| Global styles ignored on classic themes | Twenty Twenty-One + shortcode page: computed `--epm-accent` stayed the fallback | browser (before/after on the same page): configured accent applied |
| Theme button styles on the player | screenshots on Hello Elementor (pink `button:focus`) and Twenty Twenty-One | browser: focused skip button stays transparent |
| Feed title double-escaping, explicit values, durations | reading the generated XML | integration: DOM/XPath assertions on the feed |
| Duplicate control `container_border_color` | debug log while rendering the Elementor page | integration: widgets build controls with no `_doing_it_wrong` |
| Player JS: Elementor hook timing | code review, confirmed by editor re-render test | browser: players initialize in the editor preview and after control changes; AJAX-inserted players initialize |
| Audio upload hidden in the block editor | screenshot of the block editor (collapsed "Meta Boxes" drawer) | browser: audio box between title and description; drag-and-drop upload; publish flow |
| Episode picker race / entities / teardown error | browser: typed search replaced by the empty search; `&#038;` in titles; `pageerror` when switching widgets | browser: search returns only matches, selection updates the preview, no page errors |
| Chapter seeking | browser | browser: chapter click seeks the right episode and highlights it (needs HTTP Range; the test router supports it like Apache/nginx) |
| Mobile layout | 390 px screenshot | browser: full-width timeline, compact card height |

## Suites (all passing on a freshly provisioned site)

| Suite | Result |
|---|---|
| Lint | all PHP files `php -l`, all scripts `node --check` |
| Integration | 158 assertions |
| HTTP | 30 checks |
| Browser | frontend player (13), Elementor editor (6), episode admin (9), Elementor page + sticky player (7), design presets/export/import (5) |
| PHP notices from plugin files | none |

## Not verified (needs real services or licenses)

- **Elementor Pro Theme Builder:** the automatic episode page is skipped when a Theme Builder single template applies. Two guarded checks detect it: the `elementor/theme/before_do_single` action and Elementor Pro's conditions manager. Elementor Pro was not available, so this path is untested; `add_filter( 'epm_auto_embed', '__return_false' )` or the setting turns it off manually.
- **Directory submission:** the feed was validated structurally (XML parsing, required Apple tags, Podcasting 2.0 spec example for `podcast:guid`), not by submitting to Apple Podcasts Connect, Spotify or a hosted validator.
- **Real devices:** screen readers, iOS Safari and Android lock-screen controls were not tested. Media Session support is feature-detected and best-effort.
- **Scale:** large catalogs (1000+ episodes) and MySQL-specific query behavior were not profiled. The suites run on SQLite.
- **Hosting:** the transport behavior of production hosts (HEAD and byte-range support for enclosures) depends on the host and must be checked against the live feed.
