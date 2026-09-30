# Elementor Podcast Manager

A WordPress plugin for running a podcast's website on Elementor, whichever way the show is hosted:

- **As the podcast host.** Episodes and audio live on the website, and the plugin publishes a directory-ready RSS feed for Apple Podcasts, Spotify and every other app. No hosting service is needed.
- **As the front end of a show hosted elsewhere.** The show stays at Spotify for Creators, Buzzsprout, Libsyn or any other host. The plugin imports the episodes, keeps them in sync and sends apps that request the site's feed to the host's feed.

Either way the site gets automatic episode pages, one custom audio player and eleven Elementor widgets. A setup assistant covers both cases and moving a show between them. Brand-independent, white-label, built as three clean layers.

**Slug:** `elementor-podcast-manager` · **Text domain:** `elementor-podcast-manager` · **Namespace:** `EPM\` · **Version:** 1.3.0

## What it does

- **Set up:** a setup assistant asks where the show should live, imports existing episodes, collects the show details and artwork, applies a design preset, creates a podcast page and leads to the directory submissions.
- **Host:** Podcast → Add Episode: title → drop the MP3 → description → Publish. Duration and file size are read from the file. Audio can also come from a CDN or storage bucket URL.
- **Connect to a host:** paste the host's feed address (or the show's Apple Podcasts link, or its web page). Episodes are imported and synced every hour; edits made on the site are kept.
- **Move:** import a show with its audio and episode IDs from another host and redirect the old feed here, or hand the show to a new host and redirect this site's feed there. See [docs/HOSTING.md](docs/HOSTING.md).
- **Distribute:** an RSS feed at `/podcast/feed/` that follows Apple's requirements and adds Podcasting 2.0 tags (chapters, transcripts, GUID, lock, funding, medium, person, trailer). Optional download statistics through OP3, Podtrac or another prefix service. The dashboard shows a readiness report; the Distribution screen tests feed and audio delivery and walks through Apple Podcasts, Spotify, YouTube, Amazon Music and other platforms. See [docs/DISTRIBUTION.md](docs/DISTRIBUTION.md).
- **Display:** every episode gets a complete page automatically (player, guest, show notes, chapters, transcript, schema.org podcast structured data) with any theme. Designers build custom layouts with the Elementor widgets or shortcodes instead.
- **Listen:** one player engine for all layouts:
  - A sticky mini player
  - Lock-screen and OS media controls
  - Resume where the listener stopped
  - Remembered playback speed
  - Chapters that seek and highlight

## The three layers

| Layer | Contents | Elementor? | Brand? |
|---|---|---|---|
| 1 — Podcast Engine | Settings, episodes (CPT), audio/artwork handling, RSS feed, readiness, hosting modes, feed import and sync, host and directory registries | No | No |
| 2 — UI Components | Renderer (one player engine, cards, rows, hero, chapters, transcript, subscribe links with platform glyphs), automatic episode pages, shortcodes, Global Podcast Styles, presets | No | No |
| 3 — Elementor Presentation | Category, 11 widgets, style controls, current-episode context, episode picker | Yes | Only via presets |

`businesstuning.at` is the **first design preset / reference implementation** (`business-tuning` preset), never hardcoded identity.

The admin screens (setup assistant, Hosting & import, Distribution, dashboard) are part of layers 1 and 2 and stay native to wp-admin. The visual rules for both the frontend and the admin screens are written down in [DESIGN.md](DESIGN.md).

## Requirements

- PHP 8.1+ with SimpleXML and libxml (part of standard PHP builds; the feed importer uses them)
- WordPress 6.2+
- Elementor (only for the widgets; publishing, the feed, import/sync, episode pages and shortcodes work without it)
- For import and sync: outbound HTTPS requests from the server, a writable uploads folder (an import stores its progress there), and WP-Cron or a server cron job for the scheduled sync and background imports
- For hosting on this website: HTTPS, and a web server that answers `HEAD` and byte-range requests for the audio files (see [docs/HOSTING.md](docs/HOSTING.md#server-requirements))

## Installation

1. Copy this folder to `wp-content/plugins/elementor-podcast-manager/` and activate it.
2. On a site without a podcast, the first admin page after activation is the **setup assistant** (Podcast → Setup assistant). It can be left at any time and reopened from the menu; each step is saved as you go.
3. Without the assistant, configure everything by hand:
   - **Podcast → Hosting & import:** keep *This website*, or choose *Another podcast host* and enter its feed address.
   - **Podcast → Podcast Settings:** title, description and author; owner name and email; category (Apple categories and subcategories); language; square artwork (1400–3000 px, JPEG or PNG); platform links.
   - **Podcast → Add Episode:** title → drop the MP3/M4A onto *Episode Audio* → description → Publish.
   - **Podcast → Distribution:** copy the feed address and submit it to the directories.

Updating in place is safe: on the first request after an update the plugin re-flushes its rewrite rules, rebuilds the feed cache and regenerates Elementor's widget CSS. The setup assistant does not open on sites that already have a podcast.

## Setup assistant

Podcast → Setup assistant (`admin.php?page=epm-setup`). One step is visible at a time; every step saves through AJAX, so leaving halfway keeps what was entered.

1. **Hosting.** Three paths:
   - *Host it on this website*: this site publishes the feed.
   - *Move my podcast to this website*: imports every episode (optionally with audio and images copied into the Media Library), then explains how to redirect the old feed.
   - *Keep my current host*: imports the episodes, turns on hourly sync and the feed redirect.
2. **Your host** (move and keep paths). Pick the host from tiles (Spotify for Creators, Buzzsprout, Libsyn, Podbean, Transistor, Captivate, RSS.com, Acast, Podigee, Simplecast, Megaphone) or a longer list, paste the feed address, and check it. The preview shows the show, the episode count and dates, and notes about locked feeds, existing episodes, duplicate IDs, hidden episodes and statistics prefixes.
3. **Episodes.** Import progress with a log; the import continues in the background if the page is closed.
4. **Show details.** Title, description, author, category, owner name and email, language, explicit flag, episode order and artwork, with the directory requirements checked on the artwork.
5. **Look & page.** A design preset, and optionally a published "Podcast" page with the latest episode, subscribe buttons and the episode list (built from shortcodes, so it works with any theme).
6. **Get listed.** Next steps for the chosen path: the feed address to submit, the redirect instructions for the old host, or the connected host's feed; plus the readiness report.

Activation opens the assistant once, and only when the site has no podcast title, no published episodes, the assistant was neither finished nor dismissed, and the activation was not a bulk activation. Plugin screens show a dismissible notice while the podcast is not set up.

## Hosting modes

Podcast → Hosting & import (`admin.php?page=epm-hosting`), option `epm_hosting`.

| | This website (`self`) | Another podcast host (`external`) |
|---|---|---|
| Feed | Published at `/podcast/feed/` | Published by the host |
| `/podcast/feed/` | The podcast feed | `301 Moved Permanently` to the host's feed (setting, on by default) |
| Episodes | Created here | Mirrored from the host's feed by the sync |
| Audio | Media Library or an audio URL | The host's URLs |
| Download statistics | Optional prefix (OP3, Podtrac, another service) | The host's |
| Readiness report | Directory requirements | Sync status, host feed, redirect |

Recognized hosts (feed address and `<generator>` detection, where-to-find-the-feed help and redirect instructions) are registered in `includes/Providers.php`: Spotify for Creators, Buzzsprout, Libsyn, Podbean, Transistor, Captivate, RSS.com, Acast, Simplecast, Megaphone, Omny Studio, Podigee, LetsCast.fm, podcaster.de, Julep, Castos, Blubrry, Spreaker, RedCircle, Riverside, Ausha, Zencastr, ART19, Audioboom, SoundCloud, Fireside, Substack, Squarespace, other WordPress sites (PowerPress, Seriously Simple Podcasting, Podlove) and "another host". The `epm_hosting_providers` filter adds or changes entries. Host names are trademarks of their owners and only identify the service.

## Import and sync

- **Finding the feed.** The import accepts a feed address, an Apple Podcasts show link (resolved through Apple's public lookup API) or a web page that links its feed with `<link rel="alternate" type="application/rss+xml">`. `feed://`, `podcast://`, `pcast://` and `itpc://` addresses are rewritten to `https://`. Requests use `wp_safe_remote_get` (no requests to private networks), a 30-second timeout and a 50 MB size limit (`epm_feed_max_bytes`). Bot-protection pages (Cloudflare challenges, SiteGround captchas) and Spotify show links get specific error messages.
- **Parsing.** `includes/FeedParser.php` reads RSS feeds from any host: namespace URIs are matched case-insensitively, CDATA and escaped HTML are both accepted, byte-order marks, stray ampersands, HTML named entities and undeclared Windows-1252 bytes are repaired, and external entities are never loaded. Atom feeds are rejected with an explanation. Paged feeds (`<atom:link rel="next">`) are followed up to 50 pages (`epm_import_max_pages`).
- **Mapping.** Title, show notes (plain-text notes get paragraphs and links), short description, audio URL/type/length, duration, episode and season numbers, episode type, explicit flag, episode image URL, the item link and the first `podcast:person` with the role guest. Chapters (Podcasting 2.0 JSON or inline Podlove chapters) and transcripts (HTML, WebVTT, SRT, plain text) are converted into the plugin's own chapter and transcript fields when an episode is created. Channel details can fill empty Podcast Settings; the source's `<link>`, `<itunes:block>` and `<itunes:new-feed-url>` are never copied.
- **Identity.** The item GUID becomes the episode's immutable GUID, stored as the feed lists it (surrounding whitespace removed), so a moved show keeps its episode IDs. Duplicate GUIDs inside a feed are skipped after the first. When the show moves here, its `<podcast:guid>` is adopted.
- **Local edits win.** For every field, the importer remembers a hash of what it last wrote. A later sync only overwrites a field whose current value still matches that hash. Fields the importer never wrote are only filled when empty.
- **Media.** Audio and images stay on the host unless *Copy audio and episode images* is chosen; then they are downloaded into the Media Library (images are reused by source URL).
- **Batched job.** Imports run in batches over AJAX; WP-Cron continues them when the page is closed. The parsed feed is stored in a private file under `wp-content/uploads/epm-import/` while the job runs. Only one import or sync runs at a time. Importing with media copy also copies the files of episodes that were mirrored earlier without them.
- **WP-CLI.** `wp podcast import <feed> [--move] [--copy-media] [--draft] [--show-details] [--owner]` runs the same import without browser or request time limits; `wp podcast sync [--force]` syncs now; `wp podcast status` shows the mode, the feeds and the last and next sync.
- **Locked feeds.** Moving a feed that declares `<podcast:locked>yes</podcast:locked>` requires confirming ownership. After a move, the plugin lifts the feed episode limit if needed, turns on *This show moved here* (the feed then announces its own address with `<itunes:new-feed-url>`) and locks the feed.
- **Sync.** Cron event `epm_sync_feed`, hourly by default (twice daily and daily are available), plus *Sync now*. Conditional GET with the stored ETag/Last-Modified; feed moves (`itunes:new-feed-url`, 301/308) are followed, never from https to http; up to 25 new episodes per run (`epm_sync_batch_limit`) with a follow-up run shortly after for the rest; an empty feed, or one that suddenly lists fewer than half of its episodes, never changes anything; optional unpublishing of episodes the host removed (only within the feed's time window and after one day); backoff up to 24 hours after repeated failures and an admin notice after three. Details and limits: [docs/HOSTING.md](docs/HOSTING.md#4-what-the-sync-does-and-does-not-do).

## External audio URLs

An episode without a Media Library file can use audio hosted elsewhere (a podcast host, a CDN, an S3-compatible bucket). The URL, MIME type and size in bytes are stored in `_epm_audio_url`, `_epm_audio_type` and `_epm_audio_length`; imported episodes get them from the source feed, and they are writable through the REST API. The player, the feed (`<enclosure>`), "latest episode" and the readiness report use them; `Episodes::audio_meta_query()` is the shared query clause for "has audio" (used by the feed and `Episodes::get_latest( true )`). In the feed, an imported episode with neither episode artwork nor a featured image in the Media Library keeps the host's episode image (`_epm_artwork_url`) as `<itunes:image>`.

The feed distributes `audio/mpeg`, `audio/mp4`, `audio/x-m4a`, `audio/aac`, `video/mp4`, `video/x-m4v` and `video/quicktime` (`epm_distribution_audio_mimes`). Uploads on the episode screen remain limited to MP3, M4A and WAV.

## Moving a show

- **To this website:** raise the old host's feed episode limit and unlock the feed, import with media, verify, set the 301 redirect at the old host to `/podcast/feed/`, keep the old account for at least four weeks. The setup assistant and Hosting & import show host-specific redirect instructions (Spotify for Creators' steps are spelled out as Spotify documents them).
- **Away from this website:** set the feed episode limit to 0 and unlock the feed, let the new host import `/podcast/feed/`, check the new feed with the plugin's *Check feed* (every episode should be reported as already existing, which proves the GUIDs were kept), then switch to *Another podcast host*: the site answers its old feed address with a 301 to the new feed and keeps showing the episodes.

The complete procedures, including what to do when a host cannot redirect: [docs/HOSTING.md](docs/HOSTING.md).

## Distribution center

Podcast → Distribution (`admin.php?page=epm-distribution`). Shows the feed address to submit (the host's feed in external mode), validator links, readiness errors that directories would reject, a *Test feed and audio delivery* check (feed status and format, HTTPS, `HEAD` and byte-range answers for the newest episode's audio), and the platforms in order:

- **Start here:** Apple Podcasts, Spotify, YouTube & YouTube Music, Amazon Music & Audible, Podcast Index
- **Recommended:** iHeartRadio, Pocket Casts, Deezer, Podcast Addict
- **More platforms:** Pandora & SiriusXM (United States), TuneIn, podcast.de, Listen Notes
- **Listed automatically:** Overcast, Castro, Castbox, Goodpods, Player FM (from Apple Podcasts), Fountain (from Podcast Index)

Each platform has submission steps, requirements (most send a verification code to the feed's owner email) and progress tracking (*Submitted* / *Listed*). A listing address saved there is added to the podcast's platform links, so the subscribe buttons fill themselves as the show gets listed. Registry: `includes/Directories.php`, filter `epm_directories`. Details: [docs/DISTRIBUTION.md](docs/DISTRIBUTION.md).

## Publishing episodes

The episode screen puts the audio upload directly under the title (the classic editing screen is used for episodes because the block editor would hide every episode field in its collapsed "Meta Boxes" drawer; `add_filter( 'epm_use_block_editor', '__return_true' )` switches back).

- **Audio:** drag and drop or pick from the Media Library.
  - MP3/M4A are distributed. WAV is accepted for storage but excluded from the feed; the episode list shows it as "Not in feed".
  - Duration and size are detected. A manual duration is validated.
- **Episode information:** number, season, type (full/trailer/bonus), explicit, canonical URL.
- **Artwork:** an optional square image. If it's missing, the featured image is used, then the default episode artwork, then the podcast artwork. Only square images are sent to the feed.
- **Guest, short description, show notes (rich text), chapters, transcript, per-episode platform links** — all optional.
- **Scheduling** works as usual: scheduled episodes join the feed when they go live.

Capabilities follow WordPress's own post roles by default: contributors draft, authors publish their own episodes, editors manage everything. `epm_cap_manage_episodes` / `epm_cap_manage_podcast` switch to custom capabilities. The setup assistant, Hosting & import and Distribution require the podcast capability (`manage_options` by default).

## RSS feed

`/podcast/feed/` (or `/?epm_podcast_feed=1` with plain permalinks; `/podcast/rss2/` and other archive feed URLs serve the same feed). In external mode with the redirect on, all of these answer with a 301 to the host's feed.

**Channel tags:**
- `title`, `link`, `description`, `language`, `copyright`
- `lastBuildDate`, `pubDate`, `image`
- iTunes: `itunes:image`, `itunes:author`, `itunes:owner`, nested `itunes:category`, `itunes:explicit` (`true`/`false`), `itunes:type`
- Optional: `itunes:new-feed-url` (the *New feed URL* setting, or the feed's own address when *This show moved here* is on), `itunes:block`, `itunes:complete`
- Podcasting 2.0: `podcast:guid` (UUIDv5 of the feed URL, stored once and never changed; adopted from the source when a show moves here), `podcast:locked`, `podcast:medium` (`podcast`), `podcast:person role="host"` (the *Host* setting), `podcast:trailer` (for published trailer episodes), `podcast:funding`

**Item tags:**
- `title`, `itunes:title`, `link`
- An immutable `guid`
- `pubDate`
- Plain-text `description` and `itunes:summary`
- `content:encoded` (the description plus show notes as HTML)
- `enclosure` (URL, byte length, MIME type; the URL goes through the download statistics prefix when one is set)
- `itunes:duration` (seconds), `itunes:episode`, `itunes:season`, `itunes:episodeType`, `itunes:explicit`
- Episode `itunes:image`: square JPEG/PNG episode artwork (or featured image) from the Media Library; for imported episodes without one, the host's episode image
- `podcast:chapters` (JSON chapters at `/?epm_chapters={id}`)
- `podcast:transcript` (HTML at `/?epm_transcript={id}`)
- `podcast:person role="guest"` (the episode's guest, with the guest image)

**Download statistics:** Podcast → Hosting & import → *Download statistics* puts a measurement prefix in front of every enclosure URL of the self-hosted feed: OP3, Podtrac or the prefix address of another service (`epm_stats_services`). URLs that already pass through the same service are left alone; episode GUIDs do not change.

**Podcast Index notification:** when a self-hosted episode is published on a site that allows search engines, the plugin tells Podcast Index about the feed one minute later (`hub/pubnotify`, cron event `epm_ping_podcast_index`), so Podcast Index apps pick the episode up without waiting for their next poll. `add_filter( 'epm_ping_podcast_index', '__return_false' )` turns it off.

**Eligibility and window:** only published, non-password episodes with distributable audio (a Media Library file or an audio URL of a distributed type), filtered *before* the "Feed episode limit" window (default 500, 0 = unlimited). Episodic feeds are newest-first; serial feeds are oldest-first.

**GUIDs:** immutable per episode. Episodes created before 1.1.0 keep their issued GUIDs; newer ones get `urn:uuid:` GUIDs; imported episodes keep the GUID of the source feed. Title, slug, domain and protocol changes never regenerate them.

**Caching:** the rendered feed is cached and invalidated whenever an episode, its media, the podcast settings or the hosting settings change. Responses carry `ETag`/`Last-Modified` and answer conditional requests with `304 Not Modified`.

## Episode pages

`/podcast/{slug}/` renders with the active theme. With *Podcast Settings → Episode pages → Automatic episode page* enabled (default), the plugin adds the player above the description and the guest, show notes, chapters and transcript below it.

The automatic page is skipped when:
- An **Elementor Pro Theme Builder** single template renders the episode (it places the widgets itself)
- The episode is built with Elementor
- The page is shown inside the Elementor editor

Control it with `epm_auto_embed`, `epm_auto_embed_parts` (order/components) and `epm_auto_embed_player_args`.

**Structured data:** episode pages print schema.org `PodcastEpisode` JSON-LD (with its `PodcastSeries`, season, episode number, duration and an `AudioObject`) and `og:audio` tags; the episode archive prints the `PodcastSeries`. Only publicly visible episodes get it. Filters: `epm_structured_data` (turn off, for example when an SEO plugin prints its own podcast schema), `epm_structured_data_series`, `epm_structured_data_episode`.

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
| `[podcast_subscribe display="icon-text" rss="yes"]` | platform links + RSS (`icon`, `text`, `icon-text`) |
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

**Podcast → Design:** accent / on-accent / text / muted / background / surface / border colors, radii, spacing, title/meta sizes, default player and list layouts, and (new in 1.3.0) button shape (`rounded`, `pill`, `square`), font family (`inherit`, `system`, `serif`, `rounded`, `mono`), shadow (`none`, `soft`, `lifted`) and timeline track color. The values are printed once as `:root` custom properties (`--epm-accent`, `--epm-button-radius`, `--epm-font`, `--epm-shadow`, `--epm-track` …); the stylesheet's fallbacks use `:where(:root)` (specificity 0), so Global Podcast Styles win regardless of load order. `font_family: inherit` keeps the theme's and Elementor's fonts, as before.

**Presets** (`epm_presets` filter): `neutral`, `minimal`, `editorial`, `card`, `business-tuning`, plus presets derived from the DESIGN.md files of the [awesome-design-md](https://github.com/VoltAgent/awesome-design-md) collection (MIT). Those presets take design values only (colors, radii, spacing, type scale, shape, shadow) and carry generic names; they use no brand names, logos or proprietary fonts. Applying a preset fills tokens and visibility/player/list defaults; nothing is locked.

**Export/Import** moves a design between sites as versioned JSON with visual tokens only (no IDs, URLs or content).

Precedence: theme / Elementor Site Settings → Global Podcast Styles → preset (applied into global styles) → widget overrides.

The full design system (tokens, components, states, motion and accessibility rules, and how to build a new component or preset consistent with it) is in [DESIGN.md](DESIGN.md).

## Subscribe links and platform glyphs

Subscribe links (Subscribe Links widget, `[podcast_subscribe]`, the player's platform row) and the Distribution screen show platform glyphs from `includes/BrandIcons.php`: single-path 24×24 icons from [Simple Icons](https://simpleicons.org) 16.33.0, licensed CC0-1.0. They render in `currentColor`, so they follow the link color. Brands that asked Simple Icons to remove their logo (for example Amazon, LinkedIn and TuneIn) have no glyph and get a neutral icon. The service of a pasted link is recognized from its address (`Directories::detect_service()`); services, labels and icons are filterable with `epm_link_services`.

Logos are trademarks of their owners. They only identify the platform a link leads to; follow each platform's brand guidelines when styling them.

## Client deployment workflow

1. Install the plugin; run the setup assistant (or configure hosting, podcast settings, artwork and platform links by hand).
2. Podcast → Design: choose a preset, adjust tokens.
3. Elementor: build the podcast landing page and episode archive — and, with Elementor Pro, one Single Podcast Episode template (otherwise the automatic episode page is used).
4. Self-hosted: publish episode 1; the RSS feed updates automatically. Hosted elsewhere: new episodes arrive through the sync.
5. Podcast → Distribution: submit the feed and record the listing links.
6. Editors publish via Podcast → Add Episode (or at the host) forever; publishing episode 25 never requires Elementor.

### Key rules

- **One player engine**, **one episode rendering system** shared by widgets, shortcodes and episode pages.
- **Content ≠ design:** editors work in Podcast → Episodes (no design controls); designers work in Elementor.
- **No hardcoded brand:** no client colors, fonts, copy or class names in PHP logic. Presets are design values only. Host and platform names appear only as data in the registries (`Providers`, `Directories`, `BrandIcons`) to identify services.

### Acceptance test (second client)

Blue brand, serif headings, rounded cards, large artwork, light gray backgrounds, no episode numbers, no guests, compact player, different spacing — all achievable via Elementor controls + Global Podcast Styles + presets. If any of this requires editing plugin source, the abstraction is incomplete — file an issue.

### Business Tuning preset notes

Design values observed on businesstuning.at: dark `#07090a`, lime `#b9ff22`, Barlow Condensed + Inter, numbered editorial sections. They live only in `Presets::business_tuning()` as token values; the engine is untouched by them. Fix visual differences through the preset, Design settings or Elementor controls, never the engine.

## Architecture

```
elementor-podcast-manager.php   bootstrap, constants, autoloader, activation/deactivation
includes/
  Plugin.php            wiring, upgrade routine, REST meta registration, canonical URLs
  Capabilities.php      capability mapping (filters)
  PodcastSettings.php   option epm_podcast_settings
  Categories.php        Apple Podcasts categories + subcategories
  DesignSettings.php    option epm_design_settings → :root --epm-* tokens
  Presets.php           design presets (JSON-portable)
  EpisodePostType.php   podcast_episode CPT, /podcast/{slug}/
  Episodes.php          queries + normalized episode data (per-request cache), external audio
  EpisodeMeta.php       episode screen, save, audio upload/describe AJAX, episode search AJAX
  AudioMetadata.php     duration/size/MIME detection and validation, distributed types
  Feed.php              RSS feed, cache, conditional GET, external-mode redirect, chapters/transcript endpoints
  Readiness.php         distribution readiness report (self-hosted and external checks)
  Hosting.php           hosting modes (option epm_hosting), feed fetch/locate, scheduled sync
  Providers.php         podcast host registry (detection, feed and redirect help)
  FeedParser.php        tolerant podcast RSS parser
  Importer.php          feed item → episode mapping, local-edit hashes, media copy, chapters/transcripts
  ImportJob.php         batched import job (AJAX + WP-Cron), lock, move completion
  Directories.php       distribution platforms and link services
  BrandIcons.php        platform glyphs (Simple Icons, CC0)
  AdminPages.php        setup assistant, Hosting & import, Distribution screens; activation redirect; notices; delivery check
  StructuredData.php    schema.org JSON-LD and og:audio on episode pages
  Cli.php               WP-CLI: wp podcast import|sync|status
  EpisodeTemplate.php   automatic episode pages
  Renderer.php          ONE player + shared markup primitives
  Assets.php            conditional enqueue, sticky player shell
  Shortcodes.php        shortcodes
  Admin.php             menu, dashboard, list columns, design export/import
  Elementor/            integration, widget registry, 11 widgets, episode picker control
admin/                  views (dashboard, settings, design, setup, hosting, distribution), admin CSS/JS
                        (epm-app.css is the shared admin component library)
assets/                 epm-frontend.css, epm-player.js (vanilla JS)
docs/                   HOSTING.md, DISTRIBUTION.md, verification reports
DESIGN.md               design system (awesome-design-md format)
tests/                  test suites (see tests/README.md)
```

## Data storage

- Episodes: CPT `podcast_episode` + post meta (`_epm_*`, including `_epm_duration_seconds`). Attachment IDs for audio and artwork.
  - External audio and artwork: `_epm_audio_url`, `_epm_audio_type`, `_epm_audio_length`, `_epm_artwork_url`.
  - Import bookkeeping: `_epm_source` (`import`), `_epm_source_feed`, `_epm_source_link` (REST-readable), and the private `_epm_import_hash`, `_epm_import_fingerprint`, `_epm_missing_since`. Attachments downloaded by the importer carry `_epm_source_url`.
- Episode meta is registered for the REST API (block editor, headless sites, integrations). It is hidden for password-protected episodes.
- Options:
  - `epm_podcast_settings` and `epm_design_settings` hold the podcast settings and design.
  - `epm_podcast_guid` stores the podcast's feed identity.
  - `epm_hosting` (hosting mode, sync settings, download statistics prefix) and `epm_sync_state` (last sync, validators, failure count).
  - `epm_setup` (setup assistant progress) and `epm_distribution` (per-platform progress).
  - `epm_import_job` and `epm_import_lock` (the running import and the import/sync lock).
  - `epm_activation_redirect` (set on activation, removed by the first admin request).
  - `epm_version` records the installed version for the upgrade routine.
- Files: `wp-content/uploads/epm-import/` holds the parsed feed of a running import (random file name; deleted when the import ends).
- Cron events: `epm_sync_feed` (host sync), `epm_import_continue` (background import) and `epm_ping_podcast_index` (Podcast Index notification).
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
| `epm_allowed_audio_mimes` / `epm_distribution_audio_mimes` | accepted / distributed audio types (1.3.0 adds AAC and MP4/M4V/MOV video to the distributed list) |
| `epm_auto_embed` / `epm_auto_embed_parts` / `epm_auto_embed_player_args` | automatic episode pages |
| `epm_use_block_editor` | use the block editor for episodes |
| `epm_player_classes` / `epm_player_html` | player markup |
| `epm_player_resume` | resume playback position |
| `epm_episode_metadata` | custom metadata fields |
| `epm_presets` | register presets |
| `epm_hosting_providers` | podcast host registry (1.3.0) |
| `epm_directories` | distribution platforms (1.3.0) |
| `epm_link_services` | subscribe/social link services: labels, icons, URL detection (1.3.0) |
| `epm_feed_max_bytes` | maximum size of a fetched feed, default 50 MB (1.3.0) |
| `epm_sync_batch_limit` | new episodes per sync run, default 25 (1.3.0) |
| `epm_import_max_pages` | pages of a paged feed followed by an import, default 50 (1.3.0) |
| `epm_stats_services` | download statistics prefix services (OP3, Podtrac) (1.3.0) |
| `epm_ping_podcast_index` | notify Podcast Index when an episode is published, default on (1.3.0) |
| `epm_structured_data` / `epm_structured_data_series` / `epm_structured_data_episode` | schema.org JSON-LD on episode pages and the archive (1.3.0) |
| `epm_cap_manage_podcast` / `epm_cap_manage_episodes` | capabilities |
| `epm_delete_data_on_uninstall` | opt-in data deletion |

## Testing

`tests/run-all.sh` provisions a disposable WordPress + SQLite + Elementor site and runs these suites:

- **Lint**
- **Integration:** WP-CLI assertions on the feed, capabilities, visibility, rendering and widgets
- **HTTP:** feed URLs, conditional GET, endpoints, REST
- **Browser (Playwright):** player, Elementor editor, episode admin, sticky player, design import/export

It fails on any PHP notice from the plugin. CI runs it on PHP 8.1–8.4. See [tests/README.md](tests/README.md) for exactly what each suite covers.

## Translations

All UI strings use the `elementor-podcast-manager` text domain. The template is `languages/elementor-podcast-manager.pot`; place `elementor-podcast-manager-{locale}.po/.mo` files in `languages/` (or `wp-content/languages/plugins/`). Regenerate the template after changing strings:

```bash
wp i18n make-pot . languages/elementor-podcast-manager.pot --exclude=tests,docs
```

## Deactivation and uninstall

Deactivation flushes rewrite rules and removes the plugin's scheduled events (host sync, background import, Podcast Index notification); nothing else is changed. Reactivation schedules the sync again when it is enabled.

Uninstall always removes the scheduled events, the temporary import files, the import job and lock, and the activation flag. Everything else (episodes, settings, design, hosting and distribution settings) is deleted only when `EPM_DELETE_DATA` is defined or `epm_delete_data_on_uninstall` returns true.
