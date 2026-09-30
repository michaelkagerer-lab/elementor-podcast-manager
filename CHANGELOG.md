# Changelog

## 1.2.0 — 2026-09-30

Runtime-verified release. The plugin was installed in WordPress 7.1 +
Elementor 4.3 and exercised in a browser; the bugs below were found that
way. `docs/VERIFICATION-1.2.0.md` lists what was tested and how.

### Fixed (critical)
- `/podcast/feed/` served WordPress's generic archive RSS instead of the
  podcast feed (no enclosures or iTunes tags; password-protected and
  audio-less episodes listed). The feed rule is now registered before the
  post type, every archive feed URL serves the podcast feed, and updated
  installs re-flush their rewrite rules automatically.
- Mapping the episode meta capabilities to `edit_posts` removed
  `edit_posts` from every user site-wide (administrators could not edit
  posts or Elementor templates). Only primitive capabilities are mapped;
  by default episodes follow core post roles, so contributors can no
  longer publish episodes.
- Show notes and transcripts lost all HTML, and bios/short descriptions
  their line breaks, on every save (`register_post_meta()` used
  `sanitize_text_field` for every key). Per-key sanitizers.
- Episode meta never reached the REST API (missing `custom-fields`
  support); it now does, and stays hidden for password-protected episodes.
- Global Podcast Styles were overridden by the stylesheet's fallback
  tokens whenever the stylesheet loaded late (classic themes, shortcodes).
- Duplicate Elementor control ID `container_border_color` (Podcast Player).

### Fixed
- Feed: titles were double-escaped (`&amp;#8220;`); `itunes:explicit` now
  `true`/`false`; durations in seconds with a fallback to the audio file;
  empty channel `<link>` fallback; episode artwork only when square.
- Theme button styles (Hello Elementor, Twenty Twenty-One) restyled the
  player buttons.
- Player JS registered its Elementor hooks before Elementor's frontend
  existed; players inserted later were not initialized.
- "Latest episode" could pick an episode without audio.
- "Current episode" ignored the loop (Loop Grid items all showed the
  page's episode).
- Episode picker: out-of-order search results, HTML entities in titles,
  error when the panel switched mid-request.
- Episode list pagination on static front pages.
- Settings/Design screens never showed "Settings saved".
- Readiness report hid its warnings below the passed checks.
- Episode list sorted durations as text and dropped episodes without a
  value when sorting.
- Reorder buttons were hidden from assistive technology.
- Hero description lost its paragraphs; guest bios their line breaks.
- Design tokens were printed twice per page.

### Added
- Automatic episode pages for any theme: player, guest, show notes,
  chapters and transcript (skipped for Theme Builder templates and
  Elementor-built episodes).
- Feed: Apple categories with subcategories (settings select),
  `content:encoded`, `itunes:title`, `lastBuildDate`/`pubDate`,
  `podcast:guid`, `podcast:locked`, `podcast:funding`, `podcast:chapters`
  (JSON endpoint), `podcast:transcript` (HTML endpoint),
  `itunes:new-feed-url`, `itunes:block`, `itunes:complete`; cached output
  with ETag/Last-Modified and 304 responses.
- Player: lock-screen/OS media controls, resume position, remembered
  speed, active chapter highlight, PageUp/PageDown seeking, narrow-player
  layout, `window.epmPlayerEngine`.
- Elementor: editor placeholders for empty widgets, dynamic-content flag
  (element caching), style sections hidden in "global" mode, episode
  status in the picker.
- Admin: audio upload directly under the title (classic screen for
  episodes; `epm_use_block_editor` filter), episode-specific messages,
  "Not in feed" status for WAV episodes, featured image as artwork
  fallback, feed discovery `<link>` on the site.
- Shortcodes `[podcast_subscribe]`, `[podcast_guest]`,
  `[podcast_show_notes]`, `[podcast_chapters]`, `[podcast_transcript]`;
  `sticky`/`download` options for `[podcast_player]`.
- Test suites (integration, HTTP, browser) and CI; see `tests/README.md`.

### Changed
- Requirements unchanged: PHP 8.1+, WordPress 6.2+.

## 1.1.0 — 2026-09-30

Code-review repair release. All 18 review findings addressed; see
`docs/FINDINGS-1.1.0.md` for the finding-by-finding table and
`docs/VERIFICATION-1.1.0.md` for the honest test report.

### Fixed
- Episode audio editor: media selection and drag-and-drop upload now update
  the editor in place (no page reload, no lost title/description); upload
  progress, MIME validation and error states; WAV flagged as internal-only.
- Episode save validates the audio attachment (existence, audio MIME type),
  clears stale duration/size on replace/remove, validates manual duration
  format, and surfaces failures as admin notices.
- Feed: immutable per-episode GUIDs; RSS language tag normalization;
  explicit feed-window policy (setting, default 500); eligibility filtering
  before windowing; serial ordering; plain-permalink support; correct XML
  URL escaping.
- Visibility: public widgets/shortcodes/lists/feeds expose only published,
  non-password episodes; authorized editor preview; password-protected posts
  excluded from public distribution.
- Player engine: one `PlaybackController` contract for full/card/row/sticky;
  episode-scoped shared state; idempotent per-widget Elementor init;
  chapters bound to their episode; sticky follows the active audio.
- CSS tokens: self-referential variables removed; single `:root` source;
  documented precedence; genuine inherit state; on-accent token.
- Elementor: duplicate `style_source` control IDs removed; full control
  audit (dead controls fixed or explicitly restricted); per-widget script
  and style depends; AJAX searchable episode select; new Show Notes widget.
- Presets: applying a preset applies visibility/player/list settings too;
  preset identity preserved on customized saves; versioned design
  export/import; effective-design summary.
- Repeaters: stable unique row keys; accessible move up/down reordering.
- Content: editable show notes; per-episode platform links; defined
  canonical URL behavior; effective author fallback; latest-episode CTA
  (`[podcast_latest_cta]`); episode meta registered for REST/dynamic tags.
- Capabilities: `epm_cap_manage_episodes` / `epm_cap_manage_podcast`
  filters now govern CPT operations, menus, editor, meta saves, uploads,
  settings pages and REST.
- New distribution readiness report on the dashboard.
- Performance: per-request episode data cache; bulk attachment priming;
  deliberate `preload="metadata"` policy.
- Docs no longer claim unmeasured production-readiness or compatibility.

### Added
- `Podcast → Dashboard → Distribution Readiness` report.
- `Podcast → Design → Export/Import Design` (versioned JSON).
- `Podcast Settings → Distribution`: feed episode limit, latest-episode CTA.
- Shortcode `[podcast_latest_cta]`; widget "Podcast Show Notes".
- Feed item artwork; `itunes:author` fallback chain.
- `urn:uuid:` GUIDs for new episodes.

### Changed
- Minimum: WordPress 6.2+, PHP 8.1+ (as declared in the plugin header).
- 11 Elementor widgets (was 10).
- Deactivation remains non-destructive; uninstall deletion remains opt-in.
