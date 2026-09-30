# Elementor Podcast Manager

A WordPress plugin for publishing, distributing and displaying a podcast on Elementor websites. Episodes, audio uploads, a directory-ready RSS feed, automatic episode pages, one custom audio player and eleven Elementor widgets — no external podcast host required. Brand-independent, white-label, built as three clean layers.

**Slug:** `elementor-podcast-manager` · **Text domain:** `elementor-podcast-manager` · **Namespace:** `EPM\` · **Version:** 1.2.0

## What it does

- **Publish:** Podcast → Add Episode: title → drop the MP3 → description → Publish. Duration and file size are read from the file.
- **Distribute:** an RSS feed at `/podcast/feed/` for Apple Podcasts, Spotify and every podcast app. It follows Apple's requirements and adds Podcasting 2.0 tags (chapters, transcripts, GUID, lock, funding). The dashboard shows a readiness report before you submit.
- **Display:** every episode gets a complete page automatically (player, guest, show notes, chapters, transcript) with any theme. Designers build custom layouts with the Elementor widgets or shortcodes instead.
- **Listen:** one player engine for all layouts:
  - A sticky mini player
  - Lock-screen and OS media controls
  - Resume where the listener stopped
  - Remembered playback speed
  - Chapters that seek and highlight

## The three layers

| Layer | Contents | Elementor? | Brand? |
|---|---|---|---|
| 1 — Podcast Engine | Settings, episodes (CPT), audio/artwork handling, RSS feed, readiness | No | No |
| 2 — UI Components | Renderer (one player engine, cards, rows, hero, chapters, transcript, subscribe links), automatic episode pages, shortcodes, Global Podcast Styles, presets | No | No |
| 3 — Elementor Presentation | Category, 11 widgets, style controls, current-episode context, episode picker | Yes | Only via presets |

`businesstuning.at` is the **first design preset / reference implementation** (`business-tuning` preset), never hardcoded identity.

## Requirements

- PHP 8.1+
- WordPress 6.2+
- Elementor (only for the widgets; publishing, the feed, episode pages and shortcodes work without it)

## Installation

1. Copy this folder to `wp-content/plugins/elementor-podcast-manager/` and activate it.
2. **Podcast → Podcast Settings:** fill in the following, then save:
   - Title, description and author
   - Owner name and email
   - Category (Apple categories and subcategories)
   - Language
   - Square artwork (1400–3000 px, JPEG or PNG)
   - Platform links
3. **Podcast → Add Episode:** title → drop the MP3/M4A onto *Episode Audio* → description → Publish.
4. **Podcast → Dashboard:** check *Distribution Readiness*, then copy the feed URL (`/podcast/feed/`) and submit it to the directories.

Updating in place is safe: on the first request after an update the plugin re-flushes its rewrite rules, rebuilds the feed cache and regenerates Elementor's widget CSS.

## Publishing episodes

The episode screen puts the audio upload directly under the title (the classic editing screen is used for episodes because the block editor would hide every episode field in its collapsed "Meta Boxes" drawer; `add_filter( 'epm_use_block_editor', '__return_true' )` switches back).

- **Audio:** drag and drop or pick from the Media Library.
  - MP3/M4A are distributed. WAV is accepted for storage but excluded from the feed; the episode list shows it as "Not in feed".
  - Duration and size are detected. A manual duration is validated.
- **Episode information:** number, season, type (full/trailer/bonus), explicit, canonical URL.
- **Artwork:** an optional square image. If it's missing, the featured image is used, then the default episode artwork, then the podcast artwork. Only square images are sent to the feed.
- **Guest, short description, show notes (rich text), chapters, transcript, per-episode platform links** — all optional.
- **Scheduling** works as usual: scheduled episodes join the feed when they go live.

Capabilities follow WordPress's own post roles by default: contributors draft, authors publish their own episodes, editors manage everything. `epm_cap_manage_episodes` / `epm_cap_manage_podcast` switch to custom capabilities.

## RSS feed

`/podcast/feed/` (or `/?epm_podcast_feed=1` with plain permalinks; `/podcast/rss2/` and other archive feed URLs serve the same feed).

**Channel tags:**
- `title`, `link`, `description`, `language`, `copyright`
- `lastBuildDate`, `pubDate`, `image`
- iTunes: `itunes:image`, `itunes:author`, `itunes:owner`, nested `itunes:category`, `itunes:explicit` (`true`/`false`), `itunes:type`
- Optional: `itunes:new-feed-url`, `itunes:block`, `itunes:complete`
- Podcasting 2.0: `podcast:guid` (UUIDv5 of the feed URL, stored once and never changed), `podcast:locked`, `podcast:funding`

**Item tags:**
- `title`, `itunes:title`, `link`
- An immutable `guid`
- `pubDate`
- Plain-text `description` and `itunes:summary`
- `content:encoded` (the description plus show notes as HTML)
- `enclosure` (URL, byte length, MIME type)
- `itunes:duration` (seconds), `itunes:episode`, `itunes:season`, `itunes:episodeType`, `itunes:explicit`
- Square episode `itunes:image`
- `podcast:chapters` (JSON chapters at `/?epm_chapters={id}`)
- `podcast:transcript` (HTML at `/?epm_transcript={id}`)

**Eligibility and window:** only published, non-password episodes with MP3/M4A audio, filtered *before* the "Feed episode limit" window (default 500, 0 = unlimited). Episodic feeds are newest-first; serial feeds are oldest-first.

**GUIDs:** immutable per episode. Episodes created before 1.1.0 keep their issued GUIDs; newer ones get `urn:uuid:` GUIDs. Title, slug, domain and protocol changes never regenerate them.

**Caching:** the rendered feed is cached and invalidated whenever an episode, its media or the podcast settings change. Responses carry `ETag`/`Last-Modified` and answer conditional requests with `304 Not Modified`.

## Episode pages

`/podcast/{slug}/` renders with the active theme. With *Podcast Settings → Episode pages → Automatic episode page* enabled (default), the plugin adds the player above the description and the guest, show notes, chapters and transcript below it.

The automatic page is skipped when:
- An **Elementor Pro Theme Builder** single template renders the episode (it places the widgets itself)
- The episode is built with Elementor
- The page is shown inside the Elementor editor

Control it with `epm_auto_embed`, `epm_auto_embed_parts` (order/components) and `epm_auto_embed_player_args`.

## Elementor widgets (category "Podcast")

Podcast Player · Episode List · Latest Episode · Podcast Hero · Episode Header · Episode Metadata · Guest · Subscribe Links · Transcript · Show Notes · Chapters

- **Episode source:**
  - *Current Episode* resolves to the loop's episode (Loop Grid, related-episode loops) or the episode page.
  - *Latest Episode* is the newest episode with audio.
  - *Specific Episode* uses a searchable picker that reaches the whole catalog and marks drafts/scheduled/private episodes.
- **Style Source:** *Use Global Podcast Styles* emits no overrides and hides the per-widget style sections. *Custom* reveals Elementor controls that set `--epm-*` variables. Global Colors/Fonts, responsive values and hover states are supported.
- **Editor placeholders** explain widgets that currently render nothing (e.g. no guest on this episode). Visitors never see them.
- Widgets declare dynamic content, so Elementor's element cache never serves a stale episode list.

**Theme Builder:** create a Single template for *Podcast Episodes* with Episode Header, Podcast Player, Guest, Show Notes, Chapters and Transcript set to *Current Episode* — one template serves every episode.

## Shortcodes

| Shortcode | Output |
|---|---|
| `[podcast_player id="123" layout="full" sticky="yes" download="yes"]` | player (`source="current"` / `"latest"` without `id`) |
| `[podcast_latest layout="artwork"]` | newest episode with audio |
| `[podcast_episodes limit="10" layout="cards" season="1" orderby="date" order="DESC"]` | episode list (`list`, `editorial-rows`, `cards`, `grid`, `minimal`) |
| `[podcast_subscribe display="icon-text" rss="yes"]` | platform links + RSS |
| `[podcast_guest]` `[podcast_show_notes]` `[podcast_chapters]` `[podcast_transcript collapsible="yes"]` | episode components (current episode, or `id="123"`) |
| `[podcast_latest_cta label="Listen now"]` | button to the newest episode (enable under Podcast Settings → Distribution) |

## Player

- **One engine:** `Renderer::player()` + `assets/js/epm-player.js`. The five layouts (Minimal, Compact, Editorial, Artwork, Full) are configurations of it.
- **Shared playback:** one `PlaybackController` per episode is shared by the full player, card/row buttons, chapters and the sticky bar. Starting an episode pauses the others.
- **Keyboard and screen readers:** arrows ±5 s, PageUp/PageDown ±30 s, Home/End; live speed announcements.
- **Error handling:** an error + retry state, and a native-audio fallback.
- **Lock-screen controls** via the Media Session API.
- **Remembers per visitor** (browser storage) the resume position per episode and the preferred speed. Disable resume with `add_filter( 'epm_player_resume', '__return_false' )`.
- **Theme-proof buttons:** player buttons use ID-level specificity (`:not(#epm)`), so theme button styles (Hello Elementor, Twenty Twenty-One…) cannot restyle them. Elementor controls stay effective because they set `--epm-play-*` variables.
- **Narrow players** (phones, narrow columns) put the timeline on its own row (container query).
- **Initializes content inserted later** (Elementor editor, AJAX "load more", popups). Integrations can call `window.epmPlayerEngine.init(element)`.

## Global Podcast Styles & presets

**Podcast → Design:** accent / on-accent / text / muted / background / surface / border colors, radii, spacing, title/meta sizes, default player and list layouts. The values are printed once as `:root` custom properties; the stylesheet's fallbacks use `:where(:root)` (specificity 0), so Global Podcast Styles win regardless of load order.

**Presets** (`epm_presets` filter): `neutral`, `minimal`, `editorial`, `card`, `business-tuning`. Applying a preset fills tokens and visibility/player/list defaults; nothing is locked.

**Export/Import** moves a design between sites as versioned JSON with visual tokens only (no IDs, URLs or content).

Precedence: theme / Elementor Site Settings → Global Podcast Styles → preset (applied into global styles) → widget overrides.

## Client deployment workflow

1. Install the plugin; configure podcast settings, artwork and platform links.
2. Podcast → Design: choose a preset, adjust tokens.
3. Elementor: build the podcast landing page and episode archive — and, with Elementor Pro, one Single Podcast Episode template (otherwise the automatic episode page is used).
4. Publish episode 1. The RSS feed updates automatically.
5. Editors publish via Podcast → Add Episode forever; publishing episode 25 never requires Elementor.

### Key rules

- **One player engine**, **one episode rendering system** shared by widgets, shortcodes and episode pages.
- **Content ≠ design:** editors work in Podcast → Episodes (no design controls); designers work in Elementor.
- **No hardcoded brand:** no client colors, fonts, copy or class names in PHP logic. Presets are design values only.

### Acceptance test (second client)

Blue brand, serif headings, rounded cards, large artwork, light gray backgrounds, no episode numbers, no guests, compact player, different spacing — all achievable via Elementor controls + Global Podcast Styles + presets. If any of this requires editing plugin source, the abstraction is incomplete — file an issue.

### Business Tuning preset notes

Design values observed on businesstuning.at: dark `#07090a`, lime `#b9ff22`, Barlow Condensed + Inter, numbered editorial sections. They live only in `Presets::business_tuning()` as token values; the engine is untouched by them. Fix visual differences through the preset, Design settings or Elementor controls, never the engine.

## Architecture

```
elementor-podcast-manager.php   bootstrap, constants, autoloader, activation
includes/
  Plugin.php            wiring, upgrade routine, REST meta registration, canonical URLs
  Capabilities.php      capability mapping (filters)
  PodcastSettings.php   option epm_podcast_settings
  Categories.php        Apple Podcasts categories + subcategories
  DesignSettings.php    option epm_design_settings → :root --epm-* tokens
  Presets.php           design presets (JSON-portable)
  EpisodePostType.php   podcast_episode CPT, /podcast/{slug}/
  Episodes.php          queries + normalized episode data (per-request cache)
  EpisodeMeta.php       episode screen, save, audio upload/describe AJAX, episode search AJAX
  AudioMetadata.php     duration/size/MIME detection and validation
  Feed.php              RSS feed, cache, conditional GET, chapters/transcript endpoints
  Readiness.php         distribution readiness report
  EpisodeTemplate.php   automatic episode pages
  Renderer.php          ONE player + shared markup primitives
  Assets.php            conditional enqueue, sticky player shell
  Shortcodes.php        shortcodes
  Admin.php             menu, dashboard, list columns, design export/import
  Elementor/            integration, widget registry, 11 widgets, episode picker control
admin/                  views, admin CSS/JS
assets/                 epm-frontend.css, epm-player.js (vanilla JS)
tests/                  test suites (see tests/README.md)
```

## Data storage

- Episodes: CPT `podcast_episode` + post meta (`_epm_*`, including `_epm_duration_seconds`). Attachment IDs for audio and artwork.
- Episode meta is registered for the REST API (block editor, headless sites, integrations). It is hidden for password-protected episodes.
- Options:
  - `epm_podcast_settings` and `epm_design_settings` hold the podcast settings and design.
  - `epm_podcast_guid` stores the podcast's feed identity.
  - `epm_version` records the installed version for the upgrade routine.
- The rendered feed is cached in a transient.
- No custom tables.

## Developer hooks

| Hook | Purpose |
|---|---|
| `epm_episode_query_args` | modify episode queries |
| `epm_episode_data` | modify normalized episode data |
| `epm_feed_episode` / `epm_feed_episode_html` | modify feed item data / `content:encoded` HTML |
| `epm_feed_cache_enabled` | disable the feed cache |
| `epm_podcast_categories` | category list |
| `epm_allowed_audio_mimes` / `epm_distribution_audio_mimes` | accepted / distributed audio types |
| `epm_auto_embed` / `epm_auto_embed_parts` / `epm_auto_embed_player_args` | automatic episode pages |
| `epm_use_block_editor` | use the block editor for episodes |
| `epm_player_classes` / `epm_player_html` | player markup |
| `epm_player_resume` | resume playback position |
| `epm_episode_metadata` | custom metadata fields |
| `epm_presets` | register presets |
| `epm_cap_manage_podcast` / `epm_cap_manage_episodes` | capabilities |
| `epm_delete_data_on_uninstall` | opt-in data deletion |

## Testing

`tests/run-all.sh` provisions a disposable WordPress + SQLite + Elementor site and runs these suites:

- **Lint**
- **Integration:** WP-CLI assertions on the feed, capabilities, visibility, rendering and widgets
- **HTTP:** feed URLs, conditional GET, endpoints, REST
- **Browser (Playwright):** player, Elementor editor, episode admin, sticky player, design import/export

It fails on any PHP notice from the plugin. CI runs it on PHP 8.1–8.4. See [tests/README.md](tests/README.md).

## Uninstall

Deactivation only flushes rewrite rules. Data is deleted on uninstall only when `EPM_DELETE_DATA` is defined or `epm_delete_data_on_uninstall` returns true.
