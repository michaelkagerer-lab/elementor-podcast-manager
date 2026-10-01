# Elementor Podcast Manager

A WordPress plugin for running a podcast's website on Elementor, whichever way the show is hosted:

- **As the podcast host.** Episodes and audio live on the website, and the plugin publishes a directory-ready RSS feed for Apple Podcasts, Spotify and every other app. No hosting service is needed.
- **As the website of a show hosted elsewhere.** The show stays at Spotify for Creators, Buzzsprout, Libsyn or any other host. The plugin imports the episodes, keeps them in sync and sends apps that request the site's feed to the host's feed.
- **Moving in either direction.** A show can move from its host to the website (with its audio, episode IDs and podcast GUID) or from the website to a host, without losing subscribers.

Either way the site gets automatic episode pages, one custom audio player, a share menu with timestamp links, embeddable episode cards and twelve Elementor widgets. A setup assistant covers every case. Brand-independent, white-label, built as three clean layers.

**Slug:** `elementor-podcast-manager` · **Text domain:** `elementor-podcast-manager` · **Namespace:** `EPM\` · **Version:** 1.3.0

## What it does

- **Set up:** a setup assistant asks where the show should live, imports existing episodes, collects the show details and artwork, applies a design preset, creates a podcast page and leads to the directory submissions.
- **Host:** Podcast → Add episode: title → drop the MP3 → description → Publish. Duration and file size are read from the file. Audio can also come from a CDN or storage bucket URL.
- **Connect to a host:** paste the host's feed address (or the show's Apple Podcasts link, or its web page). Episodes are imported and synced every hour; edits made on the site are kept.
- **Move:** import a show with its audio and episode IDs from another host and redirect the old feed here, or hand the show to a new host and redirect this site's feed there. See [docs/HOSTING.md](docs/HOSTING.md).
- **Distribute:** an RSS feed at `/podcast/feed/` that follows Apple's requirements and adds Podcasting 2.0 tags (chapters, transcripts and caption files, GUID, lock, funding, medium, person, trailer). Optional download statistics through OP3, Podtrac or another prefix service. The dashboard shows a readiness report; the Distribution screen tests feed and audio delivery and walks through Apple Podcasts, Spotify, YouTube, Amazon Music and other platforms. See [docs/DISTRIBUTION.md](docs/DISTRIBUTION.md).
- **Display:** every episode gets a complete page automatically (player with share menu, video, topics, guest, show notes, chapters, transcript, schema.org structured data) with any theme. Designers build custom layouts with the Elementor widgets or shortcodes instead.
- **Share:** a share menu copies the episode link, a link that starts at the current position (`?t=1m23s`), or the embed code; pasted into another WordPress site, an episode URL becomes a playable card.
- **Listen:** one player engine for all layouts:
  - A sticky mini player
  - Lock-screen and OS media controls
  - Resume where the listener stopped
  - Remembered playback speed
  - Chapters that seek and highlight
- **Design:** Podcast → Design with a preset gallery, a live preview and a contrast check; every value is a `--epm-*` token. The design system is documented in [DESIGN.md](DESIGN.md).

## The three layers

| Layer | Contents | Elementor? | Brand? |
|---|---|---|---|
| 1 — Podcast Engine | Settings, episodes (CPT) and topics, audio/artwork/transcript files, RSS feed, readiness, hosting modes, feed import and sync, host and directory registries | No | No |
| 2 — UI Components | Renderer (one player engine, cards, rows, hero, chapters, transcript, share menu, video facade, topic chips, subscribe links with platform glyphs), automatic episode pages, episode embeds, shortcodes, Global Podcast Styles, presets | No | No |
| 3 — Elementor Presentation | Category, 12 widgets, style controls, current-episode context, episode picker | Yes | Only via presets |

`businesstuning.at` is the **first design preset / reference implementation** (`business-tuning` preset), never hardcoded identity.

The admin screens (setup assistant, Hosting & import, Distribution, dashboard, Design) are part of layers 1 and 2 and stay native to wp-admin. The visual rules for both the frontend and the admin screens are written down in [DESIGN.md](DESIGN.md).

## Requirements

- PHP 8.1+ with SimpleXML and libxml (part of standard PHP builds; the feed importer uses them)
- WordPress 6.2+ (the embed card uses `is_post_embeddable()` where it exists, WordPress 6.8+)
- Elementor (only for the widgets; publishing, the feed, import/sync, episode pages, embeds and shortcodes work without it)
- For import and sync: outbound HTTPS requests from the server, a writable uploads folder (an import stores its progress there), and WP-Cron or a server cron job for the scheduled sync and background imports
- For hosting on this website: HTTPS, and a web server that answers `HEAD` and byte-range requests for the audio files (see [docs/HOSTING.md](docs/HOSTING.md#server-requirements))

## Installation

1. Copy this folder to `wp-content/plugins/elementor-podcast-manager/` and activate it.
2. On a site without a podcast, the first admin page after activation is the **setup assistant** (Podcast → Setup assistant). It can be left at any time and reopened from the menu; each step is saved as you go.
3. Without the assistant, configure everything by hand:
   - **Podcast → Hosting & import:** keep *This website*, or choose *Another podcast host* and enter its feed address (required for that mode).
   - **Podcast → Podcast settings:** title, description and author; host (presenter); owner name and email; category (Apple categories and subcategories); language; content rating; episode order; square artwork (1400–3000 px, JPEG or PNG); platform links.
   - **Podcast → Add episode:** title → drop the MP3/M4A onto *Episode Audio* → description → Publish.
   - **Podcast → Distribution:** copy the feed address and submit it to the directories.

Updating in place is safe: on the first request after an update the plugin re-flushes its rewrite rules (which also registers the topic archives), rebuilds the feed cache and regenerates Elementor's widget CSS. The setup assistant does not open on sites that already have a podcast. Details: [MIGRATION.md](MIGRATION.md).

## Setup assistant

Podcast → Setup assistant (`admin.php?page=epm-setup`). One step is visible at a time; every step saves through AJAX, so leaving halfway keeps what was entered. Focus moves to each new step, errors are shown next to their field and announced.

1. **Hosting.** Three paths:
   - *Host it on this website*: this site publishes the feed.
   - *Move my podcast to this website*: imports every episode (optionally with audio, images and caption files copied into the Media Library), then explains how to redirect the old feed.
   - *Keep my current host*: imports the episodes, turns on hourly sync and the feed redirect. The hosting mode switches to *Another podcast host* only in the next step, once the host's feed address is known.
2. **Your host** (move and keep paths). Pick the host from tiles (Spotify for Creators, Buzzsprout, Libsyn, Podbean, Transistor, Captivate, RSS.com, Acast, Podigee, Simplecast, Megaphone) or a longer list, paste the feed address, and check it. The preview shows the show, the episode count and dates, and notes about locked feeds, existing episodes, duplicate IDs, hidden episodes and statistics prefixes. Moving a locked feed requires *I own this podcast and have the right to move it*.
3. **Episodes.** Import progress with a log; the import continues in the background if the page is closed. When audio could not be copied, the step lists those episodes with links to fix them.
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
| Audio | Media Library or an audio URL | The host's URLs (loaded only when a visitor presses play) |
| Download statistics | Optional prefix (OP3, Podtrac, another service) | The host's |
| Readiness report | Directory requirements | Sync status, host feed, redirect |

*Another podcast host* needs the host's feed address: saving the mode without one keeps *This website* (or the address saved before) and says so. The site switches back to *This website* by itself in two cases, so the feed can never redirect in a loop: when a move import (with audio copied) finishes, and when the sync sees the host's feed redirect to this site's own feed.

Recognized hosts (feed address and `<generator>` detection, where-to-find-the-feed help and redirect instructions) are registered in `includes/Providers.php`: Spotify for Creators, Buzzsprout, Libsyn, Podbean, Transistor, Captivate, RSS.com, Acast, Simplecast, Megaphone, Omny Studio, Podigee, LetsCast.fm, podcaster.de, Julep, Castos, Blubrry, Spreaker, RedCircle, Riverside, Ausha, Zencastr, ART19, Audioboom, SoundCloud, Fireside, Substack, Squarespace, other WordPress sites (PowerPress, Seriously Simple Podcasting, Podlove) and "another host". The `epm_hosting_providers` filter adds or changes entries. Host names are trademarks of their owners and only identify the service.

## Import and sync

- **Finding the feed.** The import accepts a feed address, an Apple Podcasts show link (resolved through Apple's public lookup API) or a web page that links its feed with `<link rel="alternate" type="application/rss+xml">`; on such a page a podcast feed wins over the blog feed, and comment feeds are never taken. `feed://`, `podcast://`, `pcast://` and `itpc://` addresses are rewritten to `https://`. Requests use `wp_safe_remote_get` (no requests to private networks), a 30-second timeout and a 50 MB size limit (`epm_feed_max_bytes`). Bot-protection pages (Cloudflare challenges, SiteGround captchas) and Spotify show links get specific error messages.
- **Parsing.** `includes/FeedParser.php` reads RSS feeds from any host: namespace URIs are matched case-insensitively, CDATA and escaped HTML are both accepted, byte-order marks, stray ampersands, HTML named entities and undeclared Windows-1252 bytes are repaired, and external entities are never loaded (libxml's entity-expansion limits stay on). Publish dates with a wrong or localized weekday ("Mon, 16 Jun 2020", "Di, …") keep their date. Atom feeds are rejected with an explanation. Paged feeds (`<atom:link rel="next">`, relative links resolved against the page's address) are read page by page over several requests, up to 50 pages (`epm_import_max_pages`) and 200 MB (`epm_import_max_bytes`). The preview says whether the catalog is complete and, when it is not, why (a page that answered an HTTP error, could not be loaded or parsed, an empty page that links on, the page or size limit), with the page's address; *Try reading the rest again* continues from that page.
- **Mapping.** Title, show notes (plain-text notes get paragraphs and links), short description, audio URL/type/length, duration, episode and season numbers, episode type, explicit flag, episode image URL, the item link and the first `podcast:person` with the role guest. Chapters (Podcasting 2.0 JSON or inline Podlove chapters) and transcripts (HTML, WebVTT, SRT, Podcasting 2.0 JSON, plain text) are converted into the plugin's own chapter and transcript fields when an episode is created, before any media is copied (an episode whose import died before that gets them on the next run). The host's timed transcript file (WebVTT first, then SRT, then JSON) is kept for the feed's captions: linked where it is, and copied into the Media Library with *Copy audio* (WebVTT and SRT). Channel details can fill empty Podcast settings; the source's `<link>`, `<itunes:block>` and `<itunes:new-feed-url>` are never copied.
- **Identity.** The item GUID becomes the episode's immutable GUID, stored byte-for-byte as the feed lists it (surrounding whitespace removed, `%`-escapes kept), so a moved show keeps its episode IDs. Duplicate GUIDs inside a feed are skipped after the first. When the show moves here, its `<podcast:guid>` is adopted.
- **Local edits win.** For every field, the importer remembers a hash of what it last wrote. A later sync only overwrites a field whose current value still matches that hash. Saving an imported episode in the editor without changing it does not count as an edit (line endings and the paragraph tags the editor removes are ignored). Fields the importer never wrote are only filled when empty.
- **Media.** Audio, images and transcript files stay on the host unless *Copy audio and episode images* is chosen. Then every file of every episode in the feed that still loads from the host is copied into the Media Library, each kind on its own (WebVTT/SRT transcript file, episode image, audio), also for episodes that were mirrored earlier; a copied WebVTT/SRT file replaces the link, the transcript text is never touched. Only addresses the import wrote are replaced: an address set on this site (another transcript file, an audio URL on your own CDN) stays, and so does a transcript in a format that is not copied (JSON). A file that is already here is not requested again, so a second run requests exactly what failed. Downloads are bounded: at most 1 GB per audio file, 20 MB per image, 5 MB per transcript file (`epm_media_max_bytes`, enforced while the file streams, also without a Content-Length); at most `epm_media_request_seconds` (20) per request, a file that takes longer continues in the next request with an HTTP Range request; stopped below 1 KB/s over 15 seconds (`epm_media_low_speed`); checked against the free disk space and the Content-Length; a host answering 429 (or 503 with Retry-After) makes the import wait until the time it names. What arrives must be audio WordPress can read, an image, or a WebVTT/SRT file; a web page, JSON or unknown data is refused with a reason that says so. Audio is stored without WordPress's image probe (which read the whole file into memory). Everything that still loads from the old host is listed per kind with the episodes and the reason after the import (Hosting & import, setup assistant, WP-CLI) and in the readiness report after a move.
- **Batched job.** Imports run in batches over AJAX; WP-Cron continues them when the page is closed. The parsed feed is stored in non-autoloaded rows of the options table (`epm_import_chunk_*`), never as a file, and removed when the import ends, is cancelled or replaced, or a checked feed has waited a day without being imported. Only one import or sync runs at a time: the lock is a row changed only with conditional statements (a free lock is taken only when the row is missing, an abandoned one only while it still holds the value read; renewed and released only by its owner), it is checked before every episode, and an abandoned lock expires after five minutes (twenty while audio is being copied). The job is read from the database and saved with compare-and-swap, so a preview never replaces a running import and a step never saves an old copy over newer progress. *Stop the import* lets the episode in flight finish and nothing after it; a cancelled import stays cancelled. Before an episode is created its GUID is checked in the database again. A move finishes only with the whole catalog, or when the missing part was confirmed, and only when every file was copied: a move that leaves files at the old host (or episodes that could not be imported) ends as *not finished* (hosting mode, *This show moved here* and the feed lock stay as they were) until the missing files are copied again (*Copy the missing files again*, `wp podcast import --resume`) or the site owner confirms that they stay behind (*Finish the move*, `wp podcast finish-move`). A request that dies during a copy (memory limit, time limit, a killed process) leaves no partial or orphaned file, counts the attempt with its reason and lets the import go on; an episode interrupted three times is given up for the file in progress.
- **WP-CLI.** `wp podcast import <feed> [--move] [--copy-media] [--draft] [--show-details] [--owner] [--accept-partial]` runs the same import without a browser (a feed that cannot be read completely is an error unless `--accept-partial` is given; a move that leaves files at the old host is an error that names them; `wp podcast import --resume` continues or copies them again); `wp podcast finish-move` finishes such a move knowingly; `wp podcast cancel` stops the import; `wp podcast sync [--force]` syncs now; `wp podcast status` shows the mode, the feeds, the last and next sync and the import.
- **Locked feeds.** Moving a feed that declares `<podcast:locked>yes</podcast:locked>` requires confirming ownership. After a move, the plugin lifts the feed episode limit if needed, turns on *This show moved here* (the feed then announces its own address with `<itunes:new-feed-url>`), locks the feed and, if the site was mirroring the old host, switches to *This website*.
- **Sync.** Cron event `epm_sync_feed`, hourly by default (twice daily and daily are available), plus *Sync now*. Conditional GET with the stored ETag/Last-Modified; feed moves (`itunes:new-feed-url`, 301/308) are followed, never from https to http, and imported episodes are re-tagged with the new address; up to 25 new episodes per run (`epm_sync_batch_limit`) with a follow-up run shortly after for the rest; an empty feed, or one that suddenly lists fewer than half of its episodes, never changes anything; optional unpublishing of episodes the host removed (only within the feed's time window and after one day); backoff up to 24 hours after repeated failures and an admin notice after three. Details and limits: [docs/HOSTING.md](docs/HOSTING.md#4-what-the-sync-does-and-does-not-do).

## External audio URLs

An episode without a Media Library file can use audio hosted elsewhere (a podcast host, a CDN, an S3-compatible bucket): Episode Audio → *Use an audio URL instead*, then *Check URL* (the server reads the file's size and type). The URL, MIME type and size in bytes are stored in `_epm_audio_url`, `_epm_audio_type` and `_epm_audio_length`; imported episodes get them from the source feed, and they are writable through the REST API. A Media Library file always wins. The player, the feed (`<enclosure>`), "latest episode" and the readiness report use them; `Episodes::audio_meta_query()` is the shared query clause for "has audio" (used by the feed and `Episodes::get_latest( true )`). In the feed, an imported episode with neither episode artwork nor a featured image in the Media Library keeps the host's episode image (`_epm_artwork_url`) as `<itunes:image>`.

Players load audio from another host only when the visitor presses play (`preload="none"`): no request carries the visitor's address there before that, and page views never count as downloads. Audio on the site itself keeps `preload="metadata"`. Filter: `epm_player_preload`.

The feed distributes `audio/mpeg`, `audio/mp4`, `audio/x-m4a`, `audio/aac`, `video/mp4`, `video/x-m4v` and `video/quicktime` (`epm_distribution_audio_mimes`). Uploads on the episode screen remain limited to MP3, M4A and WAV; `.m4a` and `.m4b` files are sent as `audio/x-m4a` (WordPress files them as `audio/mpeg`).

## Moving a show

- **To this website:** raise the old host's feed episode limit and unlock the feed, import with media (a move is finished only when every file is here, or after you confirm what stays at the old host), verify, set the 301 redirect at the old host to `/podcast/feed/`, keep the old account for at least four weeks. The setup assistant and Hosting & import show redirect instructions for the chosen host (Spotify for Creators' steps are spelled out as Spotify documents them; other hosts get a generic hint with the host's name).
- **Away from this website:** set the feed episode limit to 0 and unlock the feed, let the new host import `/podcast/feed/`, check the new feed with the plugin's *Check feed* (every episode should be reported as already existing, which proves the GUIDs were kept), then switch to *Another podcast host*: the site answers its old feed address with a 301 to the new feed and keeps showing the episodes.

The complete procedures, including what to do when a host cannot redirect: [docs/HOSTING.md](docs/HOSTING.md).

## Distribution center

Podcast → Distribution (`admin.php?page=epm-distribution`). Shows the feed address to submit (the host's feed in external mode), validator links, readiness errors that directories would reject, a *Test feed and audio delivery* check (feed status and format, HTTPS, `HEAD` and byte-range answers for the newest episode's audio), and the platforms in order:

- **Start here:** Apple Podcasts, Spotify, YouTube & YouTube Music, Amazon Music & Audible, Podcast Index
- **Recommended:** iHeartRadio, Pocket Casts, Deezer, Podcast Addict
- **More platforms:** Pandora & SiriusXM (United States), TuneIn, podcast.de, Listen Notes
- **Listed automatically:** Overcast, Castro, Castbox, Goodpods, Player FM (from Apple Podcasts), Fountain (from Podcast Index)

Each platform has submission steps, requirements (most send a verification code to the feed's owner email) and progress tracking (*Submitted* / *Listed*). The header counts the essential platforms submitted, and the *Submit* button of the next essential platform is the primary one. A listing address saved there is added to the podcast's platform links, so the subscribe buttons fill themselves as the show gets listed. Registry: `includes/Directories.php`, filter `epm_directories`. Details: [docs/DISTRIBUTION.md](docs/DISTRIBUTION.md).

## Publishing episodes

The episode screen puts the audio upload directly under the title (the classic editing screen is used for episodes because the block editor would hide every episode field in its collapsed "Meta Boxes" drawer; `add_filter( 'epm_use_block_editor', '__return_true' )` switches back).

- **Audio:** drag and drop or pick from the Media Library, or *Use an audio URL instead* (see above).
  - MP3/M4A are distributed. WAV is accepted for storage but excluded from the feed; the episode list shows it as "Not in feed".
  - Duration and size are detected. A manual duration is validated.
- **Episode information:** number, season, type (full/trailer/bonus), explicit, canonical URL. *Use next number* fills the highest number in use plus one (per season when a season is set).
- **Artwork:** an optional square image. If it's missing, the featured image is used, then the default episode artwork, then the podcast artwork. Only square images are sent to the feed.
- **Chapters:** rows with time, title and optional link, reorderable by keyboard. *Paste chapters* reads a pasted list (`00:00 Intro`, `1:02:03 - Topic`, `(12:30) Q&A`, `[12:30] Title`, bullets, or the time at the end of the line; a URL at the end becomes the link) and adds the chapters, or replaces the current ones when *Replace the current chapters* is checked (off by default).
- **Transcript:** a transcript file (WebVTT `.vtt` or SubRip `.srt`) for captions in podcast apps, and the readable transcript text for the episode page. When the text is empty and a file is attached, saving fills the text from the file. Imported episodes can also carry a hosted transcript file (shown under the picker).
- **Video (optional):** *Video URL* (YouTube, Vimeo or an MP4 file) and *YouTube URL* (wins over the video URL). Shown as a click-to-load video on the episode page.
- **Topics:** tags for episodes (Podcast → Topics), assigned in the editor, Quick Edit or the REST API.
- **Guest, short description, show notes (rich text), per-episode platform links** — all optional.
- **Imported episodes** say where they came from and that edits made here are kept.
- **Scheduling** works as usual: scheduled episodes join the feed when they go live.

**Episode list:** columns for artwork, episode number, guest, duration, audio status and topics (Author, and Topics while no topic exists, are hidden until Screen Options shows them). **Quick Edit** changes number, season, type and explicit flag of one episode; **Bulk Edit** sets season, type and explicit flag for many and *Number from* numbers the selection by publish date, oldest first.

**Capabilities** follow WordPress's own post roles by default: contributors draft, authors publish their own episodes, editors manage everything. `epm_cap_manage_episodes` / `epm_cap_manage_podcast` switch to custom capabilities. Topics work like core tags: everyone who can edit episodes can assign them, but renaming and deleting topics needs `manage_categories` (editors and administrators; `epm_cap_manage_topics` changes it). A file chosen as episode audio or transcript must be one the user may read. The setup assistant, Hosting & import, Distribution, Podcast settings and Design require the podcast capability (`manage_options` by default).

## RSS feed

`/podcast/feed/` (or `/?epm_podcast_feed=1` with plain permalinks; `/podcast/rss2/` and other archive feed URLs serve the same feed). In external mode with the redirect on, all of these answer with a 301 to the host's feed.

**Channel tags:**
- `title`, `link`, `description`, `language`, `copyright`
- `lastBuildDate`, `pubDate`, `image`
- iTunes: `itunes:image`, `itunes:author`, `itunes:owner`, nested `itunes:category`, `itunes:explicit` (`true`/`false`), `itunes:type`
- Optional: `itunes:new-feed-url` (the *New feed URL* setting, or the feed's own address when *This show moved here* is on), `itunes:block`, `itunes:complete`
- Podcasting 2.0: `podcast:guid` (UUIDv5 of the feed URL, stored once and never changed; adopted from the source when a show moves here), `podcast:locked`, `podcast:medium` (`podcast`), `podcast:person role="host"` (the *Host (presenter)* setting), `podcast:trailer` (for published trailer episodes), `podcast:funding`

**Item tags:**
- `title`, `itunes:title`, `link`
- An immutable `guid`
- `pubDate`
- Plain-text `description` and `itunes:summary`
- `content:encoded` (the description plus show notes as HTML)
- `enclosure` (URL, byte length, MIME type; the URL goes through the download statistics prefix when one is set)
- `itunes:duration` (seconds), `itunes:episode`, `itunes:season`, `itunes:episodeType`, `itunes:explicit`
- Episode `itunes:image`: square JPEG/PNG episode artwork (or featured image) from the Media Library; for imported episodes without one, the host's episode image
- `podcast:transcript` for each transcript file (WebVTT and SRT with `rel="captions"`, which Apple Podcasts uses; JSON), then the HTML transcript at `/?epm_transcript={id}` when the episode has transcript text
- `podcast:chapters` (JSON chapters at `/?epm_chapters={id}`)
- `podcast:person role="guest"` (the episode's guest, with the guest image)

Control characters pasted into any field are removed, so one stray character cannot make the whole feed invalid XML.

**Download statistics:** Podcast → Hosting & import → *Download statistics* puts a measurement prefix in front of every enclosure URL of the self-hosted feed: OP3, Podtrac or the prefix address of another service (`epm_stats_services`). URLs that already pass through the same service are left alone; episode GUIDs do not change.

**Podcast Index notification:** when a self-hosted episode is published on a site that allows search engines, the plugin tells Podcast Index about the feed one minute later (`hub/pubnotify`, cron event `epm_podcast_index_ping`), so Podcast Index apps pick the episode up without waiting for their next poll. `add_filter( 'epm_ping_podcast_index', '__return_false' )` turns it off.

**Eligibility and window:** only published, non-password episodes with distributable audio (a Media Library file or an audio URL of a distributed type), filtered *before* the "Feed episode limit" window (default 500, 0 = unlimited). The window always keeps the newest episodes. Episodic feeds are newest-first; serial feeds list those episodes oldest-first.

**GUIDs:** immutable per episode. Episodes created before 1.1.0 keep their issued GUIDs; newer ones get `urn:uuid:` GUIDs; imported episodes keep the GUID of the source feed. Title, slug, domain and protocol changes never regenerate them.

**Caching:** the rendered feed is cached and invalidated whenever an episode, its media, the podcast settings or the hosting settings change. Responses carry `ETag`/`Last-Modified` and answer conditional requests with `304 Not Modified`. `Last-Modified` and `<lastBuildDate>` move whenever the feed's content changes (for example a channel setting, a removed episode or a lower episode limit), not only when a newer episode is published; the build time is kept in the option `epm_feed_build`.

## Episode pages

`/podcast/{slug}/` renders with the active theme. With *Podcast settings → Episode pages → Automatic episode page* enabled (default), the plugin adds the player (with its share menu) and the episode's video above the description, and the topics, guest, show notes, chapters and transcript below it. The page's player brings the sticky mini player once playback starts, so pause and seek stay at hand while reading.

The automatic page is skipped when:
- An **Elementor Pro Theme Builder** single template renders the episode (it places the widgets itself)
- The episode is built with Elementor
- The page is shown inside the Elementor editor

Control it with `epm_auto_embed`, `epm_auto_embed_parts` (order/components: `player`, `video`, `content`, `topics`, `guest`, `show_notes`, `chapters`, `transcript`) and `epm_auto_embed_player_args`.

**Structured data:** episode pages print schema.org `PodcastEpisode` JSON-LD (with its `PodcastSeries`, season, episode number, duration and an `AudioObject`) and `og:audio` tags; the episode archive prints the `PodcastSeries`. Only publicly visible episodes get it. Filters: `epm_structured_data` (turn off, for example when an SEO plugin prints its own podcast schema), `epm_structured_data_series`, `epm_structured_data_episode`.

## Sharing, timestamp links and embeds

- **Share menu.** Players in the Editorial, Artwork and Full layouts have a *Share* menu button at the end of their secondary row (Minimal and Compact hide that row): *Copy link*, *Copy link at 12:34* (once the episode has a position), *Share…* (the device share sheet, where the browser offers one) and *Copy embed code*. It follows the WAI-ARIA menu button pattern (arrow keys, Escape returns focus). When the browser blocks copying, the text is offered in a field to copy by hand. Only publicly visible episodes get it. Toggle: Podcast Player widget *Share Menu*, `[podcast_player share="no"]`.
- **Timestamp links.** `?t=` on an episode URL cues the episode page's player at that position without playing: `?t=83`, `?t=1m23s`, `?t=1h2m3s` or `?t=1:23`. The position wins over the remembered resume position, and the play button's accessible name says where it starts ("Play episode, Starts at 1:23") until the first press. The share menu writes the `1m23s` form (`Renderer::timestamp_url()`).
- **Episode embeds.** `/podcast/{slug}/embed/` (WordPress's embed address) shows a compact playable card instead of the generic post card: artwork, show name, the episode title linking to the episode page, play, skip and duration, in the site's podcast design. Only the podcast stylesheet, the player engine and a small bridge script (`assets/js/epm-embed.js`: frame height and link messages) load in the frame; the theme never does. Pasting an episode URL into another WordPress site embeds this card (the oEmbed response announces its 200px height and iframe code); *Copy embed code* copies an `<iframe>` for any other site. Only published, public episodes with audio are embedded. Filter: `epm_embed_player_args`.

## Video

Episodes with a *Video URL* or *YouTube URL* show a click-to-load facade: the episode artwork and a play button. Nothing is requested from the video platform until the visitor presses play; then YouTube plays through `youtube-nocookie.com`, Vimeo with do-not-track, and video files (`.mp4`, `.m4v`, `.webm`, `.mov`, `.ogv`) in a native `<video>`, and focus moves into the video. Audio and video never play over each other. A short note says where the video loads from. Other addresses become a plain "Watch the video" link.

Available as the Episode Video widget, `[podcast_video]` and part of the automatic episode page.

## Topics

`podcast_topic` is a non-hierarchical taxonomy (like tags) for episodes, with archives at `/podcast-topic/{slug}/`, a column and Quick Edit field in the episode list, REST support and Podcast → Topics. Episode pages show the topics as chips linking to their archives. Lists filter by topic with `[podcast_episodes topic="interviews,news"]` (any of the given slugs) or the Episode List widget's *Topic* control (several topics can be chosen), and show chips with `show_topics="yes"` / the widget's *Topics* toggle. A topic or season filter that matches nothing shows "No episodes in this selection" with a link to all episodes.

## Elementor widgets (category "Podcast")

Twelve widgets (`includes/Elementor/Widgets.php`): Podcast Player · Episode List · Latest Episode · Podcast Hero · Podcast Episode Header · Podcast Metadata · Podcast Guest · Podcast Subscribe Links · Podcast Transcript · Podcast Show Notes · Podcast Chapters · Episode Video

- **Episode source:**
  - *Current Episode* resolves to the loop's episode (Loop Grid, related-episode loops) or the episode page.
  - *Latest Episode* is the newest episode with audio.
  - *Specific Episode* uses a searchable picker that reaches the whole catalog and marks drafts/scheduled/private episodes (private ones only for users who may read them).
- **Details:** Podcast Player, Latest Episode and Episode List set each detail to *Default* (follows Podcast → Design → *Details shown by default*), *Show* or *Hide*; *Layout* offers *Default (Podcast → Design: …)*. *Use Podcast → Design defaults* sets every detail of the widget to *Default*. Widgets saved with 1.3.0 or earlier keep what they showed until switched. The Latest Episode widget has its own *Enable Sticky Player* (off).
- **Style Source** (looks only, never which details show): *Use Global Podcast Styles* emits no overrides and hides the per-widget style sections. *Custom* reveals Elementor controls that set `--epm-*` variables. Global Colors/Fonts, responsive values and hover states are supported. A *Background* on the Podcast Hero or Latest Episode widget also adds inner padding.
- **Episode List** filters by season and topics and can show topic chips; **Podcast Player** has a *Share Menu* toggle.
- **Editor placeholders** explain widgets that currently render nothing (e.g. no guest on this episode). Visitors never see them.
- Widgets declare dynamic content, so Elementor's element cache never serves a stale episode list.

**Theme Builder:** create a Single template for *Podcast Episodes* with Episode Header, Podcast Player, Episode Video, Guest, Show Notes, Chapters and Transcript set to *Current Episode* — one template serves every episode.

## Shortcodes

Episode components default to the current episode (the loop's episode or the episode page); `id="123"` picks a specific episode and `source="latest"` the newest episode with audio. Yes/no attributes accept `yes`, `no`, `1`, `0`, `true`, `on`. A `show_*` or `layout` attribute that is present is explicit; without it, Podcast → Design → *Details shown by default* decides.

| Shortcode | Attributes (default) | Output |
|---|---|---|
| `[podcast_player]` | `id`, `source` (`current`), `layout` (design default; `minimal`, `compact`, `editorial`, `artwork`, `full`), `sticky` (`no`), `download` (`no`), `share` (`yes`) | The player |
| `[podcast_latest]` | `layout` (design default), `sticky` (`no`), `show_*` (site details) | Player of the newest episode with audio, with artwork and description by default |
| `[podcast_episodes]` | `limit` (`10`, 1–100), `layout` (design default; `list`, `editorial-rows`, `cards`, `grid`, `minimal`), `orderby` (`date`; any `WP_Query` order or `episode_number`), `order` (`DESC`), `season`, `topic` (comma-separated slugs), `show_topics` (`no`), `show_*` (site details) | Episode list (by `episode_number`, episodes without a number follow the numbered ones) |
| `[podcast_video]` | `id`, `source` (`current`), `note` (`yes`: "The video loads from … when you play it") | Click-to-load video |
| `[podcast_subscribe]` | `display` (`icon-text`, `icon`, `text`), `rss` (`yes`) | Platform links + RSS (the public feed) |
| `[podcast_guest]` | `id`, `source`, `bio` (`yes`) | Guest block |
| `[podcast_show_notes]` | `id`, `source`, `heading` (`Show notes`), `heading_tag` (`h3`; `h2`, `h4`) | Show notes |
| `[podcast_chapters]` | `id`, `source`, `heading` (`Chapters`), `heading_tag` (`h3`) | Chapter list that seeks the episode |
| `[podcast_transcript]` | `id`, `source`, `heading` (`Transcript`), `heading_tag` (`h3`), `collapsible` (`no`) | Transcript |
| `[podcast_latest_cta]` | `label` | Button to the newest episode (enable under Podcast settings → Feed and links) |

## Player

- **One engine:** `Renderer::player()` + `assets/js/epm-player.js`. The five layouts (Minimal, Compact, Editorial, Artwork, Full) are configurations of it.
- **Shared playback:** one `PlaybackController` per episode is shared by the full player, card/row buttons, chapters and the sticky bar. Starting an episode pauses the others (and any video started from a facade). When a re-render brings another audio file for the episode (the file was replaced or fixed), that file takes over; the same file keeps playing across re-renders, including Elementor editor control changes. Speed and volume belong to the visitor and apply to every player on the page; where the device owns the volume (iOS), the volume slider is hidden.
- **Sticky mini player:** docked to the bottom edge, hidden until something plays. Its shell is printed for players with *sticky*, the automatic episode page, and list play buttons and chapter lists (`epm_sticky_player_for_lists`); it opens only for playback started from one of those (a player with *sticky* off never opens it, even next to a list). While it is open the page reserves its height, so it never covers the last content or the focused element; it respects the safe areas of notched phones.
- **Keyboard and screen readers:** Right/Up +5 s, Left/Down −5 s, PageUp/PageDown ±30 s, Home/End; the volume is read as a percentage; live speed announcements; list play buttons keep their width while their label switches between Play, Pause and Retry.
- **Error handling:** an error + retry state, and a native-audio fallback.
- **Lock-screen controls** via the Media Session API.
- **Remembers per visitor** (browser storage) the resume position per episode and the preferred speed. Disable resume with `add_filter( 'epm_player_resume', '__return_false' )`.
- **Theme-proof buttons:** player buttons use ID-level specificity (`:not(#epm)`), so theme button styles (Hello Elementor, Twenty Twenty-One…) cannot restyle them; titles and links resist Elementor Kit heading and link rules. Elementor controls stay effective because they set `--epm-play-*` variables.
- **Narrow players** (phones, narrow columns) put the timeline on its own row (container query).
- **Initializes content inserted later:** Elementor widgets through Elementor's `frontend/element_ready/widget` hook (editor, popups, loops), everything else (AJAX "load more", other builders) through a MutationObserver; copies of bound markup (carousel loop slides) are bound too. Integrations can call `window.epmPlayerEngine.init(element)`.

## Global Podcast Styles & presets

**Podcast → Design** (`admin.php?page=epm-design`):

- **Preset gallery:** keyboard-accessible tiles with live swatches. Selecting a tile shows the preset in the preview; *Apply preset* asks for confirmation (a native dialog that also warns about unsaved changes) and then fills every value.
- **Fields** in four groups: *Colors* (background, surface, text, muted text, accent, text on accent, borders, timeline track — empty means automatic, from the muted color), *Shape and depth* (corner radius, artwork corner radius, button shape `rounded`/`pill`/`square`, shadow `none`/`soft`/`lifted`), *Typography* (font `inherit`/`system`/`serif`/`rounded`/`mono`, player title size, details size), *Layout defaults* (spacing, player layout, episode list layout).
- **Details shown by default** per place (player, latest episode, episode lists, episode page). Widgets and shortcodes that name a detail win; otherwise this setting; otherwise the 1.3.0 default. Maps stored by 1.1–1.3 appear as *Suggested details* with *Apply suggestions* (lists every change) and *Dismiss*.
- **Live preview** of the real player, the episode page player, the episode list in every layout and subscribe links, rendered like the site; every `--epm-*` variable updates as you type (from a table built in PHP that matches the site's token output).
- **Contrast check** of eight pairs with pass/fail badges: text, muted text on background and surface (4.5:1), accent text on background (4.5:1), text on accent (4.5:1), timeline track on background and surface (3:1).
- A sticky save bar, *Discard changes*, and a warning before leaving with unsaved changes; a summary of what differs from the preset and which details the site shows by default.

The values are printed once as `:root` custom properties (`--epm-accent`, `--epm-radius`, `--epm-gap`, `--epm-button-radius`, `--epm-font`, `--epm-shadow`, `--epm-track` …); the stylesheet's fallbacks use `:where(:root)` (specificity 0), so Global Podcast Styles win regardless of load order. `font_family: inherit` keeps the theme's and Elementor's fonts. On a dark background the plugin also prints a white image outline, a lighter error red and, so light text stays readable on a light theme page, a design-colored surface with padding for sections that have none of their own (`epm_dark_section_surface` turns that off).

**Presets** (`epm_presets` filter), eleven in total: `neutral`, `minimal`, `editorial`, `card`, `business-tuning`, and six presets whose values are derived from the DESIGN.md files of the [awesome-design-md](https://github.com/VoltAgent/awesome-design-md) collection (MIT): `clean-light`, `soft-voice`, `warm-paper`, `ink-mono`, `night-studio`, `midnight`. Those six take design values only (colors, radii, spacing, type scale, button shape, font stack, shadow) and carry generic names; they use no brand names, logos, copy or proprietary fonts. Every preset meets text ≥ 7:1, muted ≥ 4.5:1, on-accent ≥ 4.5:1, accent ≥ 4.5:1 and track ≥ 3:1 (measured). Applying a preset fills its tokens and sets *Details shown by default* (the confirmation lists the changes); nothing is locked, and widgets or shortcodes that name a detail keep it. Designs saved before 1.3.0 keep pill-shaped text buttons; new designs default to rounded.

**Export/Import** moves a design between sites as versioned JSON (format 2: tokens and details; no IDs, URLs or content); the import accepts only known keys and allowed values, and a 1.x export's details arrive as suggestions.

Precedence: theme / Elementor Site Settings → Global Podcast Styles → preset (applied into global styles) → widget overrides.

The full design system (tokens, every preset's values, components, states, motion and accessibility rules, and how to build a new component or preset consistent with it) is in [DESIGN.md](DESIGN.md).

## Subscribe links and platform glyphs

Subscribe links (Subscribe Links widget, `[podcast_subscribe]`, the player's platform row) and the Distribution screen show platform glyphs from `includes/BrandIcons.php`: single-path 24×24 icons from [Simple Icons](https://simpleicons.org) 16.33.0, licensed CC0-1.0. They render in `currentColor`, so they follow the link color. Brands that asked Simple Icons to remove their logo (for example Amazon, LinkedIn and TuneIn) have no glyph and get a neutral icon. The service of a pasted link is recognized from its address (`Directories::detect_service()`); services, labels and icons are filterable with `epm_link_services`.

Logos are trademarks of their owners. They only identify the platform a link leads to; follow each platform's brand guidelines when styling them.

## Client deployment workflow

1. Install the plugin; run the setup assistant (or configure hosting, podcast settings, artwork and platform links by hand).
2. Podcast → Design: choose a preset, adjust tokens, check the contrast.
3. Elementor: build the podcast landing page and episode archive — and, with Elementor Pro, one Single Podcast Episode template (otherwise the automatic episode page is used).
4. Self-hosted: publish episode 1; the RSS feed updates automatically. Hosted elsewhere: new episodes arrive through the sync.
5. Podcast → Distribution: submit the feed and record the listing links.
6. Editors publish via Podcast → Add episode (or at the host) forever; publishing episode 25 never requires Elementor.

### Key rules

- **One player engine**, **one episode rendering system** shared by widgets, shortcodes, episode pages and embeds.
- **Content ≠ design:** editors work in Podcast → Episodes (no design controls); designers work in Elementor and Podcast → Design.
- **No hardcoded brand:** no client colors, fonts, copy or class names in PHP logic. Presets are design values only. Host and platform names appear only as data in the registries (`Providers`, `Directories`, `BrandIcons`) to identify services.

### Acceptance test (second client)

Blue brand, serif headings, rounded cards, large artwork, light gray backgrounds, no episode numbers, no guests, compact player, different spacing — all achievable via Elementor controls + Global Podcast Styles + presets. If any of this requires editing plugin source, the abstraction is incomplete — file an issue.

### Business Tuning preset notes

Design values observed on businesstuning.at: dark `#07090a`, lime `#b9ff22`, Barlow Condensed + Inter, numbered editorial sections. They live only in `Presets::business_tuning()` as token values (the preset keeps the theme's fonts, `font_family: inherit`); the engine is untouched by them. Fix visual differences through the preset, Design settings or Elementor controls, never the engine.

## Architecture

```
elementor-podcast-manager.php   bootstrap, constants, autoloader, activation/deactivation
includes/
  Plugin.php            wiring, upgrade routine, REST meta registration, canonical URLs
  Capabilities.php      capability mapping (filters)
  PodcastSettings.php   option epm_podcast_settings
  Categories.php        Apple Podcasts categories + subcategories
  DesignSettings.php    option epm_design_settings → :root --epm-* tokens (dark-design extras)
  Presets.php           design presets (JSON-portable)
  EpisodePostType.php   podcast_episode CPT (/podcast/{slug}/), podcast_topic taxonomy (/podcast-topic/{slug}/)
  Episodes.php          queries + normalized episode data (per-request cache), external audio
  EpisodeMeta.php       episode screen, save, audio upload/URL check AJAX, episode search AJAX, next number
  AudioMetadata.php     duration/size/MIME detection and validation, distributed types
  Transcripts.php       transcript files (WebVTT, SRT, JSON): types, feed list, text from a file
  Feed.php              RSS feed, cache, build time, conditional GET, external-mode redirect, chapters/transcript endpoints
  Readiness.php         distribution readiness report (self-hosted and external checks)
  Hosting.php           hosting modes (option epm_hosting), feed fetch/locate, scheduled sync
  Providers.php         podcast host registry (detection, feed and redirect help)
  FeedParser.php        tolerant podcast RSS parser
  Importer.php          feed item → episode mapping, local-edit hashes, media copy, chapters/transcripts
  ImportJob.php         batched import job (AJAX + WP-Cron), lock, waits, unfinished moves, move completion
  MediaCopy.php         copies one media file into the Media Library: checks the content, stores it, reuses copies
  MediaDownload.php     one bounded, resumable media download (limits, Range, Retry-After)
  MediaWatch.php        watches a download while it streams (size, disk, low speed, time)
  Directories.php       distribution platforms and link services
  BrandIcons.php        platform glyphs (Simple Icons, CC0)
  AdminPages.php        setup assistant, Hosting & import, Distribution screens; activation redirect; notices; delivery check
  StructuredData.php    schema.org JSON-LD and og:audio on episode pages
  Embed.php             episode embed card (/podcast/{slug}/embed/), oEmbed height, embed code
  Cli.php               WP-CLI: wp podcast import|cancel|finish-move|sync|status
  EpisodeTemplate.php   automatic episode pages
  Renderer.php          ONE player + shared markup (share menu, video facade, topic chips, timestamp links)
  Assets.php            conditional enqueue, sticky player shell
  Shortcodes.php        shortcodes
  Admin.php             menu, dashboard, list columns, Quick/Bulk Edit, Design screen, design export/import
  Elementor/            integration, widget registry, 12 widgets, episode picker control
admin/                  views (dashboard, settings, design, setup, hosting, distribution), admin CSS/JS
                        (epm-app.css is the shared admin component library; epm-design.css the Design screen)
assets/                 epm-frontend.css, epm-player.js, epm-embed.js (vanilla JS)
docs/                   HOSTING.md, DISTRIBUTION.md, verification reports
DESIGN.md               design system (awesome-design-md format)
languages/              translation template
tests/                  test suites (see tests/README.md)
```

## Data storage

- Episodes: CPT `podcast_episode` + post meta (`_epm_*`, including `_epm_duration_seconds`). Attachment IDs for audio, artwork, guest image and transcript file.
  - External audio and artwork: `_epm_audio_url`, `_epm_audio_type`, `_epm_audio_length`, `_epm_artwork_url`.
  - Transcript files: `_epm_transcript_file_id` (a WebVTT/SRT attachment), `_epm_transcript_url` and `_epm_transcript_type` (a hosted file, from an import).
  - Video: `_epm_video_url`, `_epm_youtube_url`.
  - Import bookkeeping: `_epm_source` (`import`), `_epm_source_feed`, `_epm_source_link` (REST-readable), and the private `_epm_import_hash` (also records whether the transcript address came with the import), `_epm_import_fingerprint`, `_epm_import_extras` (chapters and transcripts still to fetch, only until they are), `_epm_missing_since`, `_epm_copying` (set only while an audio download runs). Attachments downloaded by the importer carry `_epm_source_url`.
- Topics: taxonomy `podcast_topic` (terms and term relationships).
- Episode meta is registered for the REST API (block editor, headless sites, integrations). It is hidden for password-protected episodes.
- Options:
  - `epm_podcast_settings` and `epm_design_settings` hold the podcast settings and design.
  - `epm_podcast_guid` stores the podcast's feed identity; `epm_feed_build` the hash and time of the last feed content (for `Last-Modified`).
  - `epm_hosting` (hosting mode, sync settings, download statistics prefix) and `epm_sync_state` (last sync, validators, failure count).
  - `epm_setup` (setup assistant progress) and `epm_distribution` (per-platform progress).
  - `epm_import_job` and `epm_import_lock` (the current import and the import/sync lock); `epm_import_chunk_*` rows hold the parsed feed of a checked or running import (removed when it ends, after a day without import, on uninstall).
  - `epm_activation_redirect` (set on activation, removed by the first admin request).
  - `epm_version` records the installed version for the upgrade routine; `epm_guids_migrated` marks the 1.1.0 GUID migration.
- Files: none of its own besides copied media in the Media Library. While a media file is copied, its download lives in the temp folder as `epm-media-*`; it is removed when the copy ends, fails or is cancelled, and leftovers older than an hour are removed by the next import step or the daily cleanup. (1.3.0 kept the parsed feed in `wp-content/uploads/epm-import/`; that folder is removed after the update.)
- Cron events: `epm_sync_feed` (host sync), `epm_import_continue` (background import), `epm_import_cleanup` (expires a checked feed nobody imported) and `epm_podcast_index_ping` (Podcast Index notification; `epm_ping_podcast_index` in 1.3.0).
- The rendered feed is cached in a transient (`epm_feed_cache`).
- No custom tables.

## Developer hooks

All hooks are filters.

| Hook | Purpose |
|---|---|
| `epm_episode_query_args` | modify episode queries |
| `epm_episode_data` | modify normalized episode data |
| `epm_feed_episode` / `epm_feed_episode_html` | modify feed item data / `content:encoded` HTML |
| `epm_feed_cache_enabled` | disable the feed cache |
| `epm_podcast_categories` | category list |
| `epm_allowed_audio_mimes` / `epm_distribution_audio_mimes` | accepted / distributed audio types (1.3.0 adds AAC and MP4/M4V/MOV video to the distributed list) |
| `epm_transcript_files` | an episode's transcript files in the feed (1.3.0) |
| `epm_auto_embed` / `epm_auto_embed_parts` / `epm_auto_embed_player_args` | automatic episode pages |
| `epm_embed_player_args` | player of the episode embed card (1.3.0) |
| `epm_use_block_editor` | use the block editor for episodes |
| `epm_player_classes` / `epm_player_html` | player markup |
| `epm_player_resume` | resume playback position |
| `epm_player_preload` | `preload` of the player's audio: `metadata` on this site, `none` on another host (1.3.0) |
| `epm_sticky_player_for_lists` | list play buttons and chapter lists bring the sticky player, default on (1.3.0) |
| `epm_episode_metadata` | custom metadata fields |
| `epm_presets` | register presets (`tokens`, optional `details` per place) |
| `epm_details` | details a player, list or episode page shows: `( $details, $context, $explicit, $consumer )` |
| `epm_dequeue_unused_player` | drop the player script on pages without podcast markup, default on |
| `epm_dark_section_surface` | design surface and padding for sections on dark designs, default on (1.3.0) |
| `epm_hosting_providers` | podcast host registry (1.3.0) |
| `epm_directories` | distribution platforms (1.3.0) |
| `epm_link_services` | subscribe/social link services: labels, icons, URL detection (1.3.0) |
| `epm_feed_max_bytes` | maximum size of a fetched feed, default 50 MB (1.3.0) |
| `epm_sync_batch_limit` | new episodes per sync run, default 25 (1.3.0) |
| `epm_import_max_pages` | pages of a paged feed followed by an import, default 50 (1.3.0) |
| `epm_import_max_bytes` | total size of a paged feed an import reads, default 200 MB (unreleased) |
| `epm_import_request_seconds` | seconds one preview request spends reading pages, default 10 (unreleased) |
| `epm_import_ttl` | seconds a checked feed waits to be imported before it expires, default one day (unreleased) |
| `epm_media_max_bytes` | largest media file an import copies, per kind (`audio` 1 GB, `image` 20 MB, `transcript` 5 MB) (unreleased) |
| `epm_media_request_seconds` | seconds one request spends on a media download before the next request continues it, default 20, at most 50 (unreleased) |
| `epm_media_low_speed` | `[ 'bytes' => 1024, 'seconds' => 15 ]`: a download slower than this is stopped and tried again (unreleased) |
| `epm_media_max_attempts` | failed attempts after which a file counts as not copyable in this run, default 3 (unreleased) |
| `epm_media_max_waits` / `epm_media_max_wait` | how often (5) and how long at most (six hours) an import waits for a host that answers 429/503 with Retry-After (unreleased) |
| `epm_media_disk_free` | free disk space a media download counts on (tests, unusual storage) (unreleased) |
| `epm_stats_services` | download statistics prefix services (OP3, Podtrac) (1.3.0) |
| `epm_ping_podcast_index` | notify Podcast Index when an episode is published, default on (1.3.0) |
| `epm_structured_data` / `epm_structured_data_series` / `epm_structured_data_episode` | schema.org JSON-LD on episode pages and the archive (1.3.0) |
| `epm_cap_manage_podcast` / `epm_cap_manage_episodes` | capabilities |
| `epm_cap_manage_topics` | capability to manage, edit and delete topics, default `manage_categories` (1.3.0) |
| `epm_delete_data_on_uninstall` | opt-in data deletion |

JavaScript: `window.epmPlayerEngine.init(element)` initializes players, buttons, chapters, share menus and video facades inside `element`.

## WP-CLI

| Command | What it does |
|---|---|
| `wp podcast import <feed> [--move] [--copy-media] [--draft] [--show-details] [--owner] [--accept-partial]` | Import from a feed address, Apple Podcasts link or web page. `--move` takes the show over (adopts its `podcast:guid`, lifts the feed episode limit, announces the new home and locks the feed when done); `--copy-media` copies audio, episode images and WebVTT/SRT transcript files, also of episodes that exist already; `--draft` creates new episodes as drafts; `--show-details` fills empty podcast settings; `--owner` confirms ownership of a locked feed. When the feed cannot be read completely, nothing is imported and the command exits with an error naming the page and the error; `--accept-partial` imports the episodes found (and finishes a `--move`) with a warning instead of a success message. Lists every file still at the old host (episode, address, reason). A `--move` that leaves files there is not finished and exits with an error; a wait the host asks for (HTTP 429) of up to ten minutes is sat through. |
| `wp podcast import --resume [options]` | Continue the last import: read the rest of a feed that could not be read completely (then import with the given options), keep an interrupted or waiting import going, or copy again what an unfinished move left at the old host. |
| `wp podcast finish-move [--yes]` | Finish a move that left files at the old host: lists them and asks first. |
| `wp podcast cancel` | Stop the import (also a waiting one or an unfinished move); episodes already imported stay. |
| `wp podcast sync [--force]` | Sync with the host now; `--force` ignores the stored ETag/Last-Modified. |
| `wp podcast status` | Hosting mode, feeds, last and next sync, and the import: state, progress, the file being copied, a host's wait, what is still at the old host. |

## Testing

`tests/run-all.sh` provisions a disposable WordPress + SQLite + Elementor site and runs every suite; new suite files are picked up without editing the script. Podcast hosts are simulated by a test-only HTTP fixture server, so imports and syncs never leave localhost.

| Suite | Files | Covers |
|---|---|---|
| Lint | `tests/bin/lint.sh` | `php -l` on every PHP file, `node --check` on every script |
| Integration | `tests/integration/run.php` | feed, capabilities, visibility, rendering, widgets |
| | `tests/integration/hosting.php` | parser against 26 real feeds, import, sync, moves, transcript files, setup and distribution |
| | `tests/integration/admin.php` | topics, menu, list columns, next number, transcript files, Quick/Bulk Edit, design export/import, Design screen tokens and contrast |
| | `tests/integration/design.php`, `widgets.php` | details shown by default (1.3.0 snapshot, explicit > site > neutral, migration into suggestions, export format 2), 1.3.0 Elementor widgets, artwork radius, paginated lists, order by number |
| | `tests/integration/frontend.php` | timestamp links, share menu, embeds, video facade, topic filters, audio preloading, sticky player for lists, dark designs |
| | `tests/integration/media.php` | moving media: every kind of file after a mirror, local choices kept, what stays listed per kind, unfinished moves (retry, confirmation), limits, wrong content, HTTP errors, waits, interrupted copies (a separate process runs out of memory), no duplicates, WP-CLI cancel/status |
| Media downloads | `tests/media/run.sh` | real sockets against a local media host: size limit while streaming, stalled and slow hosts, Range resumption across steps (byte-identical), no Range support, wrong content, 429, a large file under a 128M memory limit, full disks (root + tmpfs) |
| HTTP | `tests/http/run.sh` | feed URLs, conditional GET, endpoints, pages, REST, byte ranges, the external-mode redirect |
| Browser (Playwright) | `tests/e2e/run.mjs`, `setup.mjs`, `admin.mjs`, `design.mjs`, `frontend.mjs`, `player.mjs`, `style-audit.mjs`, `widgets.mjs` | player, Elementor editor, episode admin, setup assistant, Hosting & import, Distribution, Design screen, editor speed-ups, share menu, embeds, video, sticky bar, design on real pages |

It fails on any PHP notice from the plugin. CI runs lint on PHP 8.1–8.4, the integration and HTTP suites on PHP 8.1 and 8.4, and the browser suites. See [tests/README.md](tests/README.md) for exactly what each suite covers, and [docs/VERIFICATION-1.3.0.md](docs/VERIFICATION-1.3.0.md) for the latest results.

## Translations

All UI strings (PHP and JavaScript) use the `elementor-podcast-manager` text domain. The template is `languages/elementor-podcast-manager.pot`; place `elementor-podcast-manager-{locale}.po/.mo` files (and the JSON files for the scripts) in `languages/` (or `wp-content/languages/plugins/`). Regenerate the template after changing strings:

```bash
wp i18n make-pot . languages/elementor-podcast-manager.pot --exclude=tests,docs,node_modules --domain=elementor-podcast-manager
```

## Deactivation and uninstall

Deactivation flushes rewrite rules and removes the plugin's scheduled events (host sync, background import, import cleanup, Podcast Index notification); nothing else is changed. Reactivation schedules the sync again when it is enabled.

Uninstall always removes the scheduled events, the parsed feed of a checked or running import (`epm_import_chunk_*` rows, and the 1.3.0 folder `uploads/epm-import/` if one is left), the import job and lock, and the activation flag. Everything else (episodes, topics, settings, design, hosting and distribution settings, the feed build record) is deleted only when `EPM_DELETE_DATA` is defined or `epm_delete_data_on_uninstall` returns true.
