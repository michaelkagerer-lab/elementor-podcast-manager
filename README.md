# Elementor Podcast Manager

A WordPress plugin for managing and displaying podcasts on Elementor websites. Brand-independent, white-label, and built as three clean layers.

**Working name:** Elementor Podcast Manager · **Slug:** `elementor-podcast-manager` · **Text domain:** `elementor-podcast-manager` · **Namespace:** `EPM\`

## The three layers

| Layer | Contents | Elementor? | Brand? |
|---|---|---|---|
| 1 — Podcast Engine | Settings, episodes (CPT), audio/artwork handling, RSS feed | No | No |
| 2 — UI Components | Renderer (one player engine, cards, rows, hero, chapters, transcript, subscribe links), shortcodes, Global Podcast Styles, presets | No | No |
| 3 — Elementor Presentation | Category, 10 widgets, style controls, current-episode context | Yes | Only via presets |

`businesstuning.at` is the **first design preset / reference implementation** (`business-tuning` preset), never hardcoded identity. The generic engine never depends on it.

## Requirements

- PHP 8.1+
- WordPress 6.2+
- Elementor (only for the widgets; engine + RSS work without it)

## Installation

1. Copy this folder to `wp-content/plugins/elementor-podcast-manager/`.
2. Activate in wp-admin (rewrite rules flush automatically).
3. **Podcast → Podcast Settings**: title, description, artwork, owner, defaults.
4. **Podcast → Add Episode**: title → Upload Episode Audio → description → Publish. Duration/size auto-detected.
5. **Podcast → Dashboard**: copy the RSS feed URL (`/podcast/feed/`) for directory submission.

## Architecture

```
elementor-podcast-manager.php   bootstrap, constants, autoloader, activation
includes/
  Plugin.php                    singleton wiring
  Capabilities.php              centralized capability mapping
  PodcastSettings.php           option epm_podcast_settings (Settings API)
  DesignSettings.php            option epm_design_settings → :root --epm-* tokens
  Presets.php                   neutral/minimal/editorial/card/business-tuning (JSON-portable)
  EpisodePostType.php           podcast_episode CPT, /podcast/{slug}/
  Episodes.php                  queries + normalized episode data arrays
  EpisodeMeta.php               grouped meta boxes + save + audio upload AJAX
  AudioMetadata.php             duration/size/mime detection
  Feed.php                      /podcast/feed/ — RSS 2.0 + iTunes + Podcast namespaces
  Admin.php                     menu, dashboard, list columns, sortable
  Renderer.php                  ONE player markup + shared primitives
  Assets.php                    conditional enqueue, sticky player shell
  Shortcodes.php                [podcast_player] [podcast_latest] [podcast_episodes]
  Elementor/
    Integration.php             category + widget registration
    Widgets.php                 widget registry
    Widgets/*.php               10 widgets
  helpers.php                   epm_esc_xml()
admin/views/                    dashboard.php, settings.php, design.php
admin/css/js                    epm-admin.*
assets/css/js                   epm-frontend.css, epm-player.js (vanilla, one engine)
templates/                      reserved for theme overrides (future)
uninstall.php                   opt-in data deletion only
```

### Key rules

- **One player engine**: `Renderer::player()` + `assets/js/epm-player.js`. Layouts (`minimal`, `compact`, `editorial`, `artwork`, `full`) are CSS/configurations, never duplicated players.
- **One episode rendering system**: `Renderer` primitives (`artwork`, `metadata`, `guest`, `chapters`, `transcript`, `subscribe_links`, `episode_card`, `episode_row`, `episode_list`) shared by widgets and shortcodes.
- **Content ≠ design**: editors work in Podcast → Episodes (no design controls); designers work in Elementor. Publishing episode 25 never requires Elementor.
- **Styling hierarchy**: Theme/Elementor Site Settings → Global Podcast Styles (`:root --epm-*`) → Preset → widget overrides (Elementor selectors set `--epm-*` vars on `{{WRAPPER}}`). Low specificity, no `!important`.
- **No hardcoded brand**: no client colors, fonts, copy, or class names in PHP logic. Presets are design values only.

## Data storage

- Episodes: CPT `podcast_episode` + post meta (`_epm_*`). Attachment **IDs** stored for audio/artwork.
- Podcast settings: option `epm_podcast_settings`. Design: option `epm_design_settings`.
- No custom tables. Stable GUIDs: `/?epm_episode_guid={id}` (survive title/slug changes).

## RSS feed

`/podcast/feed/` (or `?epm_podcast_feed=1` with plain permalinks) — RSS 2.0 with `itunes:` and `podcast:` namespaces. Includes channel metadata, per-episode enclosure (URL, length, MIME), duration, episode/season numbers, explicit flags.

**Feed window policy:** the feed contains the newest N episodes with distribution-ready audio (MP3/M4A), newest-first — N is the "Feed episode limit" setting (default 500, 0 = unlimited). Episodes without audio or with internal-only audio (WAV) are filtered out *before* the window is applied, so they never displace eligible episodes. Serial podcasts are ordered oldest-first.

**Eligibility:** only published, non-password episodes with a valid distribution audio attachment. Drafts, private, scheduled, trashed and password-protected episodes are excluded.

**GUIDs:** immutable per episode. Episodes created before 1.1.0 keep their already-issued GUIDs byte-for-byte; newer episodes get domain-independent `urn:uuid:` GUIDs. Title, slug, domain and HTTP/HTTPS changes never regenerate them.

## Elementor widgets (category "Podcast")

Podcast Player · Episode List · Latest Episode · Podcast Hero · Episode Header · Episode Metadata · Guest · Subscribe Links · Transcript · Show Notes · Chapters

**Current Episode support**: data source `current` resolves via `get_queried_object()` in single-episode context (with editor-preview fallback for authorized users). Build one Theme Builder Single template for the `podcast_episode` post type containing header/player/guest/show-notes/chapters/transcript widgets — every episode renders automatically. Public visitors only ever see published, non-password episodes; editors previewing drafts in Elementor see them because they can edit them.

**Style Source** control per widget: `global` (inherit Global Podcast Styles — emits zero overrides) or `custom` (Elementor controls → `--epm-*` variables). Supports Global Colors/Fonts, responsive controls, hover states. Precedence: theme / Elementor Site Settings → Global Podcast Styles → preset (applied into global styles) → explicit widget overrides.

**Episode picker**: the "Specific Episode" control is an AJAX searchable select — it reaches the whole catalog, not just the newest 100.

## Episode page templates

**With Elementor Pro (Theme Builder):** create a Single template, set the display condition to Podcast Episodes, and add Episode Header, Podcast Player, Guest, Show Notes, Chapters and Transcript widgets with data source "Current Episode". One template serves every episode — editors never edit episodes in Elementor. Elementor Pro is required for Theme Builder; the free version cannot create Single templates.

**Without Elementor Pro (ordinary theme / shortcodes):** the plugin works without Elementor. Use the shortcodes in any page, post or theme template:
- `[podcast_player id="123"]` — player for one episode (`source="current"` inside the Loop)
- `[podcast_latest]` — newest episode
- `[podcast_episodes limit="10" layout="cards"]` — episode list
- `[podcast_latest_cta]` — automatic latest-episode button (enable it in Podcast Settings → Distribution first)

The plugin also renders a plain single-episode view at `/podcast/{slug}/` via the theme's `single.php` fallback when no Elementor template exists.

## Global Podcast Styles & presets

**Podcast → Design**: accent/on-accent/text/muted/background/surface/border colors, radii, spacing, title/meta sizes, default player + episode layouts. Outputs `:root` CSS custom properties. An "Effective design" summary shows which values are customized vs inherited from the active preset.

**Presets** (`Presets.php`, filterable via `epm_presets`): `neutral`, `minimal`, `editorial`, `card`, `business-tuning`. Applying a preset fills tokens AND visibility/player/list defaults — it never locks them. Preset identity survives customized saves.

**Design export/import** (Podcast → Design): export downloads a versioned JSON file with scalar visual tokens only — no episode IDs, media IDs, content or URLs. Import validates format, version and every value through the same sanitizer as the settings screen. This is how a customized client design moves to a clean installation.

## Client deployment workflow

1. Install plugin, configure podcast settings + artwork + platform links.
2. Podcast → Design: choose a preset, adjust tokens.
3. Elementor: build podcast landing page, episode archive, one Single Podcast Episode template.
4. Publish episode 1. RSS updates automatically.
5. Editors publish via Podcast → Add Episode forever.

## Acceptance test (second client)

Blue brand, serif headings, rounded cards, large artwork, light gray backgrounds, no episode numbers, no guests, compact player, different spacing — all achievable via Elementor controls + Global Podcast Styles + presets. If any of this requires editing plugin source, the abstraction is incomplete — file an issue.

## Developer hooks

- `epm_episode_query_args` — modify episode queries
- `epm_episode_data` — modify normalized episode data
- `epm_feed_episode` — modify feed item data
- `epm_player_classes` — player CSS classes
- `epm_player_html` — final player markup
- `epm_episode_metadata` — custom metadata fields
- `epm_presets` — register presets
- `epm_cap_manage_podcast` / `epm_cap_manage_episodes` — capability mapping
- `epm_delete_data_on_uninstall` — opt-in data deletion

## Security & performance

- Nonces on all forms/AJAX, capability checks centralized in `Capabilities`, sanitization per field, escaping on output (`epm_esc_xml()` for the feed).
- Assets enqueue only when podcast components render (late footer enqueue safety net). No frameworks. Lazy artwork. Episode data is normalized once per post per request (per-request cache); attachment posts are primed in bulk before lists and feeds. Audio uses `preload="metadata"`; card/row buttons create their audio element lazily on first play. Query behavior has not been profiled under production load — measure before large catalogs.

## Uninstall

Deactivation flushes rewrites; **nothing is deleted**. Data deletion only when `EPM_DELETE_DATA` is defined or the `epm_delete_data_on_uninstall` filter returns true.

## Business Tuning preset notes

Design values observed on businesstuning.at (dark `#07090a`, lime `#b9ff22`, Barlow Condensed + Inter, numbered editorial sections). Lives only in `Presets::business_tuning()` as token values — the engine is untouched by it. Verify visually on the live site: typography inheritance, spacing, player proportions, editorial rows, mobile behavior. Fix visual differences through preset/Design/Elementor controls, never the engine.
