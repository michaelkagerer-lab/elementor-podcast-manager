# Changelog

## 1.3.0 — 2026-09-30

Hosting release. The plugin can host a show on the website, as before,
or act as the website of a show hosted at Spotify for Creators or any
other host, and it can move a show in either direction. Guides:
`docs/HOSTING.md`, `docs/DISTRIBUTION.md`; design system: `DESIGN.md`;
upgrade notes: `MIGRATION.md`.

### Added
- Hosting modes (Podcast → Hosting & import, option `epm_hosting`).
  *This website* publishes the feed as before. *Another podcast host*
  mirrors the host's episodes into WordPress, keeps them in sync and
  answers `/podcast/feed/` and every other feed address of the site with
  a `301` to the host's feed (on by default, can be turned off).
- Host registry (`includes/Providers.php`, filter `epm_hosting_providers`):
  28 hosts plus "another WordPress site" and "another host", recognized by
  feed address and `<generator>`, with where-to-find-the-feed help and
  redirect instructions (Spotify for Creators' steps as Spotify documents
  them).
- Feed import (Hosting & import, setup assistant, WP-CLI): accepts a feed
  address, an Apple Podcasts show link (iTunes lookup API) or a web page
  with `<link rel="alternate">`; recognizes bot-protection pages and
  Spotify show links; tolerant RSS parser (`includes/FeedParser.php`) for
  feeds from any host; paged feeds via `atom:link rel="next"` (up to 50
  pages, `epm_import_max_pages`); 50 MB response limit
  (`epm_feed_max_bytes`); batched AJAX job continued by WP-Cron
  (`epm_import_continue`); one import or sync at a time.
- Import mapping: GUIDs kept as the source lists them, duplicate GUIDs
  skipped after the first, show notes (plain text gets paragraphs and
  links), numbers, types, explicit flag, durations, audio URL/type/length,
  episode image, first `podcast:person` guest; Podcasting 2.0 JSON and
  Podlove chapters and HTML/WebVTT/SRT/text transcripts converted into the
  plugin's fields; optional copy of audio and images into the Media
  Library (also for episodes mirrored earlier); optional filling of empty
  Podcast Settings from the channel. Future-dated items are scheduled;
  undated and `itunes:block` items become drafts.
- Local edits win: per-field hashes of what the importer wrote; a sync
  only overwrites fields that were not edited on the site.
- Sync (cron `epm_sync_feed`, hourly, twice daily or daily; *Sync now*;
  `wp podcast sync`): conditional GET with the stored ETag/Last-Modified;
  up to 25 new episodes per run (`epm_sync_batch_limit`) with a follow-up
  run; an empty feed never changes anything, and a scheduled run stops
  when a feed of 10 or more episodes suddenly lists fewer than half;
  follows `itunes:new-feed-url` and 301/308 moves (never https → http);
  optional unpublishing of episodes the host removed (within the feed's
  time window, after one day); backoff up to 24 hours after failures and
  an admin notice after three.
- Moving a show here: locked feeds (`podcast:locked`) need ownership
  confirmation; the show's `podcast:guid` is adopted (or derived from the
  old feed address); when the move import finishes, the feed episode limit
  is lifted if needed, *This show moved here* is turned on (the feed then
  carries `itunes:new-feed-url` with its own address) and the feed is
  locked.
- External audio: episodes can use an audio URL (`_epm_audio_url`,
  `_epm_audio_type`, `_epm_audio_length`, REST-writable) when no Media
  Library file is attached. Players, the feed, "latest episode"
  (`Episodes::audio_meta_query()`) and the readiness report use it. The
  host's episode image (`_epm_artwork_url`) is shown on the site and used
  as feed item artwork when no Media Library image exists.
- Setup assistant (Podcast → Setup assistant) with three paths: host on
  this website, move my podcast here, keep my current host. Opens once
  after activation on a site without a podcast; a dismissible notice on
  the plugin's screens until the podcast is set up; optional podcast page
  built from shortcodes.
- Distribution center (Podcast → Distribution, `includes/Directories.php`,
  filter `epm_directories`): submission steps, requirements and progress
  for Apple Podcasts, Spotify, YouTube & YouTube Music, Amazon Music &
  Audible, Podcast Index, iHeartRadio, Pocket Casts, Deezer, Podcast
  Addict, Pandora & SiriusXM, TuneIn, podcast.de, Listen Notes, and the
  apps that list a show automatically (Overcast, Castro, Castbox,
  Goodpods, Player FM, Fountain). Listing links become platform links.
  *Test feed and audio delivery* checks the feed, HTTPS, `HEAD` and
  byte-range answers.
- Download statistics for self-hosted feeds: OP3, Podtrac or another
  prefix service in front of every enclosure URL (`epm_stats_services`).
- Feed: `podcast:medium`, `podcast:person` (host from Podcast Settings,
  guest per episode), `podcast:trailer` for trailer episodes.
- Podcast Index notification (`hub/pubnotify`) one minute after a
  self-hosted episode is published on a site that allows search engines
  (cron `epm_ping_podcast_index`, filter `epm_ping_podcast_index`).
- Structured data: schema.org `PodcastEpisode` JSON-LD and `og:audio` on
  episode pages, `PodcastSeries` on the episode archive (filters
  `epm_structured_data`, `epm_structured_data_series`,
  `epm_structured_data_episode`).
- WP-CLI: `wp podcast import <feed> [--move] [--copy-media] [--draft]
  [--show-details] [--owner]`, `wp podcast sync [--force]`,
  `wp podcast status`.
- Platform glyphs for subscribe links and the Distribution screen
  (`includes/BrandIcons.php`, Simple Icons 16.33.0, CC0-1.0; brands that
  asked Simple Icons for removal get a neutral icon). Link service
  registry with URL detection (`epm_link_services`); links saved as
  "Custom" are matched to a known service.
- Readiness report for external mode (host feed, sync status, redirect)
  and for audio URLs (HTTPS, unknown size).
- Design options (Podcast → Design) and tokens: button shape
  (`--epm-button-radius`: rounded 8px, pill 999px, square 2px), font
  family (`--epm-font`: inherit, system, serif, rounded, mono), shadow
  (`--epm-shadow`: none, soft, lifted) and timeline track color
  (`--epm-track`); easing tokens `--epm-ease-out`, `--epm-ease-in-out`,
  `--epm-ease-drawer`; dark designs get `--epm-danger` and
  `--epm-image-outline` variants.
- Presets `clean-light`, `soft-voice`, `warm-paper`, `ink-mono`,
  `night-studio` and `midnight`, derived from the DESIGN.md analyses in
  the awesome-design-md collection (MIT): values only, generic names.
  Every preset meets text ≥ 7:1, muted ≥ 4.5:1, on-accent ≥ 4.5:1 and
  track ≥ 3:1 (measured).
- `DESIGN.md`: the plugin's design system in the awesome-design-md
  format, with an agent guide for new components and presets.
- Admin component library `admin/css/epm-app.css` for the new screens:
  native wp-admin look (admin color scheme, core buttons), motion only
  under `prefers-reduced-motion: no-preference`.
- Podcast Settings → Feed status: *This show moved here from another
  host*.

### Changed
- `epm_distribution_audio_mimes` also distributes `audio/aac`,
  `video/mp4`, `video/x-m4v` and `video/quicktime` by default, so imported
  shows keep every episode. Upload types are unchanged.
- The feed discovery `<link>`, the RSS subscribe link and the structured
  data point to the public feed (the host's feed in external mode).
- Platform link service choices come from the link registry (more
  services); the 1.2.0 keys stay valid.
- Deactivation also clears the plugin's scheduled events. Uninstall
  always removes the scheduled events, temporary import files, the import
  job and lock and the activation flag; opt-in data deletion also removes
  `epm_hosting`, `epm_sync_state`, `epm_setup` and `epm_distribution`.
- Text buttons follow the button shape token. Designs saved before 1.3.0
  keep pill-shaped buttons; new designs default to rounded.
- Requirements unchanged: PHP 8.1+, WordPress 6.2+. Import and sync also
  need outbound HTTPS requests, a writable uploads folder and WP-Cron.

### Fixed
- Distribution readiness primes episode media attachments in bulk; each
  problem links to the screen where it is fixed (the podcast settings
  field, the episode, or the hosting screen), and links only appear for
  users who can open that screen.
- The latest-episode CTA shortcode loads its stylesheet without loading the
  audio player JavaScript.
- Dashboard: readiness progress (“x of y checks complete”) and a
  first-episode empty state; the design token for text on the accent color
  is labelled “Text on accent”.
- Episode capabilities: the same bug was fixed independently on the `work`
  branch; the 1.2.0 fix is kept because it also guards against a filtered
  meta capability.

### Verified
- WordPress with PHP 8.3 activates the plugin; Elementor 4.3.3 registers all
  11 widgets; administrator/editor episode permissions and admin dashboard
  rendering work; the plain-permalink RSS query serves a parseable feed with a
  playable MP3 enclosure (`docs/VERIFICATION-UNRELEASED.md`).

### Security
- Feeds, pages, linked chapter/transcript files and media downloads go
  through `wp_safe_remote_get` / `download_url` (no requests to private
  networks), with timeouts and size limits. Only the delivery check
  requests the site's own feed and audio with loopback allowed.
- XML is parsed with network access disabled (`LIBXML_NONET`) and without
  loading external entities or DTDs.
- Imported HTML (show notes, descriptions, transcripts) always passes
  `wp_kses_post`, also for administrators with `unfiltered_html` and in
  cron; titles and plain fields are sanitized as text.
- Every import, sync, setup and distribution endpoint checks a nonce and
  the podcast capability (`epm_cap_manage_podcast`).
- The import's temporary feed file has a random name in
  `uploads/epm-import/` with `index.php` and a deny-all `.htaccess`, and
  is deleted when the import ends; only files inside that folder are
  ever deleted.
- Locked feeds are not moved without the owner's confirmation; the docs
  warn against importing private or paid feeds.

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
- Minimum: WordPress 6.2+, PHP 8.1+ (as declared in the plugin header);
  Elementor is needed only for widgets.
- 11 Elementor widgets (was 10).
- Deactivation remains non-destructive; uninstall deletion remains opt-in.
