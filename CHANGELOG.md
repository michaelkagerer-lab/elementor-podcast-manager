# Changelog

## Unreleased

### Fixed

- Podcast Index notification: the cron event and the opt-out filter
  shared the name `epm_ping_podcast_index`, so publishing an episode ran
  the notification immediately (inside `apply_filters()`) and
  `add_filter( 'epm_ping_podcast_index', '__return_false' )` could not
  stop it. The event is now `epm_podcast_index_ping`; the filter keeps
  its name and turns the notification off. An event 1.3.0 already
  scheduled is moved to the new name, keeping its time. Test sites
  answer Podcast Index requests locally, so no suite notifies the real
  service.
- Import/sync lock (IMP-02): two requests could both take a free or an
  abandoned lock, a renewal could overwrite a takeover, a release could
  delete another request's fresh lock, and a loop (WP-Cron,
  `wp podcast import`) re-took a live lock; with a persistent object
  cache even a fresh request could. The lock row is now written only
  with conditional statements that bypass the options caches (insert
  only when missing, take over only the stale value read, renew and
  release only the own value), on MySQL/MariaDB and SQLite alike.
- Import job state (IMP-02): a step read the job before taking the lock
  and saved that copy afterwards, so a finished import could be marked
  failed, a new preview destroyed ("This import expired"), progress set
  back and counters reset; a preview could replace an import started
  meanwhile. The job is now read from the database after the lock is
  taken and every save is a compare-and-swap on a job version; before
  each episode the step checks that it still holds the lock and that the
  job is still running, the same job and at the same position, and it
  records each outcome on the current job, so counts match what was
  imported. A preview, start and cancel can no longer overwrite each
  other; a preview never replaces a running import. A move is finished
  only by the request that still holds the lock.
- Duplicate episodes: when two requests imported the same items (cron's
  loop and the import screen, two tabs, `wp podcast import` while the
  screen was open), episodes were created twice. Besides the lock, the
  importer now checks the GUID in the database right before it creates
  an episode.
- Cancel (IMP-N2): a running mirror batch kept importing up to ten
  episodes after *Stop the import*. The episode in flight (for example
  an audio download) still finishes; nothing after it, and a cancelled
  import is never started again.
- Host sync: when another request took over its lock, the sync carried
  on. It now stops before the next episode, stores no validators (so the
  next run reads the whole feed) and reports that it was interrupted.
- Paged feeds (IMP-01): an error on a later page (HTTP 500, a timeout,
  invalid XML), an empty page that still links on, the page limit or a
  relative `rel="next"` link (taken as a host name) ended the preview
  silently; it looked complete, and a move then finished with part of
  the show: the site switched to *This website*, locked the feed and
  published only the episodes read. The preview now records why the
  catalog ends (`complete`, `http_error`, `transport_error`,
  `parse_error`, `empty_page_with_next`, `page_limit`, `budget`), the
  error and the page address; next links are resolved against the
  page's address. *Try reading the rest again* continues from the page
  that failed, without reading a page or importing an episode twice. A
  move with an incomplete catalog is refused unless the missing part is
  accepted explicitly (`accept_partial`); a mirror import is allowed and
  says that it covers part of the feed.
- Large catalogs (IMP-N1): the preview read every page in one request
  (a fatal error at 128 MB from about 35 pages of 500 items, a proxy
  timeout behind a slow host) and every import step decoded the whole
  catalog (60+ MB on 10,000 items). Pages are now read over several
  requests, about ten seconds each and never close to the memory limit
  (filters `epm_import_request_seconds`, `epm_import_max_pages`, default
  50, and `epm_import_max_bytes`, default 200 MB); a step reads only the
  rows it needs. Measured with a 128M limit: 25,000 episodes (50 × 500)
  checked in requests of 9–11 MB above the booted site; a step needs
  2.0 MB on 1,000 and 2.3 MB on 10,000 items (1.3.0: a fatal error, and
  6.2 vs. 61.5 MB). Through nginx and php-fpm (stock 128M) with 1.3 s per
  page, the same feed took 7 requests of at most 11.2 s and 16.4 MB peak
  (1.3.0: a 504 after 60 s, or a fatal error).
- Where an import keeps the parsed feed (IMP-05): 1.3.0 wrote it as JSON
  to `wp-content/uploads/epm-import/`, protected only by an Apache
  `.htaccess`, so nginx and Apache without `AllowOverride` served it to
  anyone who knew the file name. It is now kept in non-autoloaded rows of
  the options table (`epm_import_chunk_*`, at most 512 KB each), never in
  a file. After the update a running 1.3.0 import continues from the
  database; the folder and any other 1.3.0 file in it are removed.
- Moving a show that was mirrored before (IMP-03): the move copied the
  audio (the episode image only together with it) and never a WebVTT or
  SRT transcript file of an existing episode; a failed image was reported
  nowhere and never retried; the result ("5 updated · 1 audio not
  copied"), a second run and the readiness report all read as a complete
  move while the feed still pointed to the old host 13 times. Now every
  episode in the feed, new or existing, gets each kind of file copied on
  its own (transcript file, episode image, audio); a copied WebVTT/SRT
  file replaces the link, the transcript text is never touched. Only
  addresses the import wrote are replaced: the plugin now records where a
  transcript address came from (1.3.0 addresses: the import's when the
  feed item lists them, otherwise the site's choice), so a transcript
  file chosen on this site or an audio URL on your own CDN stays. A file
  that is here is not requested again, so the next run requests exactly
  what failed. Everything that stays at the old host is listed per kind
  (audio, episode images, transcript files, transcripts in formats that
  are not copied such as JSON) with the episode and the reason, after
  the import and in the readiness report.
- A move with files left at the old host was finished anyway (FEED-N7):
  the site switched to *This website*, announced itself as the show's
  new home and locked the feed although episodes' audio still loaded
  from the old host. Such a move now ends as *not finished*
  (`done_with_problems`): hosting mode, *This show moved here* and the
  lock stay; *Copy the missing files again* (or `wp podcast import
  --resume`) finishes it when nothing is left, *Finish the move* after an
  informed confirmation that names what stays behind (or
  `wp podcast finish-move`) finishes it anyway.
- Media downloads had no size, disk, speed or rate limit (IMP-04): a
  400 MB file with Content-Length, a 300 MB chunked one and a 200 MB one
  without length were accepted; a stalled host held the import (and the
  lock) for 900 seconds; behind nginx a step answered 504 after 60
  seconds while PHP went on downloading, and the screen stopped; HTTP 429
  was ignored. Downloads now stream through `wp_safe_remote_get()` (no
  private addresses, also after redirects) with limits per kind (audio
  1 GB, image 20 MB, transcript file 5 MB; filter `epm_media_max_bytes`)
  enforced while the file arrives, a free-space check of the temp and
  uploads folders, a low-speed limit (1 KB/s, `epm_media_low_speed`), at
  most 20 seconds per request (`epm_media_request_seconds`; the import
  screen's steps use 8) with the rest continued by the next request
  through HTTP Range, and a *waiting* state until the time a host's 429
  (or 503 with Retry-After) names. Measured through nginx and php-fpm
  (stock 128M, `fastcgi_read_timeout` 60 s): the 3 MB file at 40 KB/s
  that ended in a 504 arrives in 10 steps of at most 8.2 s,
  byte-identical; a stalled host is reported after 3 steps of 8.1 s.
- Copying long audio ran out of memory (IMPB-N1): WordPress's
  `media_handle_sideload()` probes every file with `getimagesize()`,
  which read a 100 MB MP3 into memory (a fatal error at 128M, 240 MB at
  256M); the job stayed stuck with the lock held, and every retry left
  another full-size file in uploads (`m100.mp3`, `m100-1.mp3`). Audio is
  now stored without the image probe (audio metadata only): through
  admin-ajax at 128M a 100 MB file took 0.4 s and a 300 MB file 1.3 s,
  each request at 22 MB; the test suite copies 60 MB and 300 MB with a
  peak of 18.7 MB above the booted site. A request that dies during a
  copy (memory or time limit, a killed process) removes its download and
  any file not yet in the Media Library, counts the attempt with its
  reason, releases the lock and lets the import continue in the
  background, also when WordPress's own fatal error handler ends the
  request; an identical file a dead request left behind is taken over,
  so no "-1" copies appear.
- A full disk stored a cut-off file as the episode's audio (IMPB-N2): 20
  MB of a 50 MB file became the audio and counted as copied; a full
  uploads folder left a partial file. A download is now complete only
  when every byte the host announced is on the disk (bytes that arrive
  but are not written stop the transfer), the free space is checked
  first, and a failed move into uploads removes what it wrote.
- An interrupted copy lost the episode's chapters and transcript for
  good (IMPB-N3), left partial files in the temp folder, and WP-CLI could
  not stop the job. Chapters and transcripts are now fetched before any
  file is copied (and completed by a later run when the import died
  before them); downloads use `epm-media-*` temp files that are removed
  when the copy ends or fails, and leftovers after an hour;
  `wp podcast cancel` stops the import and `wp podcast status` shows it.
- Wrong content was stored as the episode's audio (IMPB-N4): random bytes
  without a file extension became `r4.mp3` and counted as
  distribution-ready; an HTML login page gave "Sorry, you are not allowed
  to upload this file type". What arrives must now be audio WordPress can
  read (MP3, M4A or WAV), an image, or a WebVTT/SRT file; otherwise the
  episode keeps its address at the host and the reason says what the
  host returned ("The host returned a web page instead of the audio file
  (often a login, error or consent page)."). Audio in other formats (AAC,
  Ogg, FLAC), which 1.3.0 stored as `.mp3`, stays at the host and is
  reported.
- The episode editor called every hosted transcript address one that
  "came with the import", also one chosen on the site; it now says where
  the address came from.
- Orphaned import data (IMPB-N5): two overlapping previews or a preview
  nobody imported left job files behind indefinitely. A new preview
  removes the data of the one it replaces (a replaced preview removes
  what it wrote itself), a checked feed nobody imports expires after a
  day (filter `epm_import_ttl`, cron event `epm_import_cleanup`), and the
  data goes when an import finishes, fails or is cancelled. Uninstalling
  removes it too.

### Changed

- Hosting & import and the setup assistant read a paged feed over several
  requests and say which page they are reading (visible next to the
  address and announced to screen readers). A feed that could not be
  read completely gets a warning next to the result that names the page,
  its address and the error, with *Try reading the rest again* when a
  retry can help. Mirroring the episodes found needs no extra step;
  copying the audio (Hosting & import) or moving the show (setup
  assistant) asks to confirm "Move only the N episodes that were found.
  The missing episodes stay at the old host and will not be on this
  website."; without it the import does not start, the message appears
  under the checkbox and focus moves there. After the import, the result
  says that it covers only part of the feed.
- `wp podcast import` reads paged feeds page by page and says when the
  feed could not be read completely: it then imports nothing, exits with
  an error that names the page, the address and the error, and keeps the
  check. `--resume` continues it (reads the rest again, or continues an
  interrupted import); `--accept-partial` imports only the episodes found
  and warns instead of reporting success. `<feed>` is optional with
  `--resume`.
- New AJAX action `epm_import_more` (continue reading a feed); the
  preview's summary has a `catalog` object (complete, reason, error, url,
  pages, message), and so does the import's state.
- Hosting & import and the setup assistant show the result of a copy per
  kind (audio, episode images, transcript files, transcripts linked in
  other formats) with links to the episodes and the reason, the file
  being copied over several requests, a host's wait (until when and
  why), and for an unfinished move *Copy the missing files again* and
  *Finish the move* behind the checkbox "Finish the move anyway. These
  stay at the old host and stop working when that account is closed: …".
  The setup assistant goes on only after one of them. When nothing was
  left behind, the result says that every file is here.
- The readiness report lists after a move (and while one is unfinished)
  one warning per kind of file still at the old host, each with up to ten
  episodes linked to their editor; checks carry `items` and `more`.
- WP-CLI: `wp podcast cancel`, `wp podcast finish-move [--yes]`;
  `wp podcast status` shows the import; `wp podcast import` lists every
  file still at the old host and exits with an error for an unfinished
  move; `--resume` also copies the missing files of an unfinished move.
- New job statuses `waiting` and `done_with_problems`, new AJAX actions
  `epm_import_retry` and `epm_import_confirm`; new filters
  `epm_media_max_bytes`, `epm_media_request_seconds`,
  `epm_media_low_speed`, `epm_media_max_attempts`, `epm_media_max_waits`,
  `epm_media_max_wait`, `epm_media_disk_free` (see MIGRATION.md).
- An import step handles up to ten episodes also when media are copied
  (1.3.0: one); each media download is bounded by the step's time.

### Tests

- `tests/concurrency/`: two-process race tests (lock: free, abandoned,
  renew vs. takeover, release vs. takeover, loop; job: cron loop plus
  step, failed over done, failed over a new preview, progress going
  back, preview vs. start, cancel mid-batch; an optional barrier-free
  stress run), part of `run-all.sh`. They fail on 1.3.0 and pass now, on
  SQLite and MariaDB.
- `tests/bin/setup-wp.sh`: `WP_DB=mysql` installs the test site on
  MySQL/MariaDB (SQLite stays the default). CI runs the import suite and
  the race tests (with the stress run) on MariaDB 10.11 as well.
- `tests/integration/import.php`: paged feeds (every stop reason, relative
  links, cycle, duplicates, resume), the move guard, `wp podcast import`,
  storage, cleanup, the 1.3.0 migration and uninstall.
- `tests/perf/run.sh`: memory and time per request of a paged preview and
  of import steps, with a 128M limit (`PERF_HEAVY=1` for 50 × 500 items
  and 1,000 vs. 10,000).
- `tests/http/run.sh`: a feed check through admin-ajax leaves nothing in
  uploads, no import folder is served, the job token is in no URL or log.
- `tests/e2e/setup.mjs`: an incomplete feed on Hosting & import and in
  the setup assistant (the warning, try again, the move confirmation,
  focus and error, the partial result); fixture
  `synthetic/paged-broken-1.xml`.
- `tests/integration/media.php`: moving media after a mirror per kind,
  local choices, retries, unfinished moves, limits, wrong content, HTTP
  errors and waits, interrupted copies (a separate process that runs out
  of memory), WP-CLI cancel and status. Written before the fixes: on
  1.3.0 code every one of its 14 tests failed (60 assertions passed, 95
  failed).
- `tests/media/run.sh`: downloads over real sockets from a local media
  host (`tests/fixtures/mediaserver.py`; the test-only mu-plugin
  `epm-test-loopback.php` opens exactly its port): limits while
  streaming, stalled and slow hosts, Range resumption (byte-identical),
  no Range support, a large file under 128M, full disks via tmpfs. Part
  of `run-all.sh`.
- `tests/e2e/setup.mjs`: an unfinished move on Hosting & import and in
  the setup assistant, a waiting import; fixture
  `synthetic/rate-limited.xml`. The HTTP fixture sends a Content-Length
  with its files, like a real server.

## 1.3.0 — 2026-09-30

Hosting and design-system release. The plugin can host a show on the
website, as before, or be the website of a show hosted at Spotify for
Creators or any other host, and it can move a show in either direction.
It adds transcript files, a share menu with timestamp links, episode
embeds, click-to-load video, topics, a rebuilt Design screen with six
new presets, and editor shortcuts. Guides: `docs/HOSTING.md`,
`docs/DISTRIBUTION.md`; design system: `DESIGN.md`; upgrade notes:
`MIGRATION.md`; test results: `docs/VERIFICATION-1.3.0.md`.

### Added

Hosting, import and sync
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
- Import mapping: GUIDs kept byte-for-byte as the source lists them,
  duplicate GUIDs skipped after the first, show notes (plain text gets
  paragraphs and links), numbers, types, explicit flag, durations, audio
  URL/type/length, episode image, first `podcast:person` guest;
  Podcasting 2.0 JSON and Podlove chapters and HTML/WebVTT/SRT/JSON/text
  transcripts converted into the plugin's fields; optional copy of audio
  and images into the Media Library (also for episodes mirrored earlier);
  optional filling of empty Podcast settings from the channel.
  Future-dated items are scheduled; undated and `itunes:block` items
  become drafts. Episodes whose audio could not be copied are listed
  after the import (Hosting & import, setup assistant, WP-CLI warning).
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
  carries `itunes:new-feed-url` with its own address), the feed is locked
  and a site that mirrored the old host switches to *This website*.
- External audio: episodes can use an audio URL (`_epm_audio_url`,
  `_epm_audio_type`, `_epm_audio_length`, REST-writable) when no Media
  Library file is attached; the episode screen's *Use an audio URL
  instead* checks the address on the server (size, type, host). Players,
  the feed, "latest episode" (`Episodes::audio_meta_query()`) and the
  readiness report use it. The host's episode image (`_epm_artwork_url`)
  is shown on the site and used as feed item artwork when no Media
  Library image exists.
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
- WP-CLI: `wp podcast import <feed> [--move] [--copy-media] [--draft]
  [--show-details] [--owner]`, `wp podcast sync [--force]`,
  `wp podcast status`.
- Podcast settings → Feed status: *This show moved here from another
  host*.

Feed
- `podcast:medium`, `podcast:person` (host from Podcast settings, guest
  per episode), `podcast:trailer` for trailer episodes.
- Transcript files (`EPM\Transcripts`): an episode can carry a WebVTT or
  SRT file (uploaded, `_epm_transcript_file_id`) or a hosted transcript
  kept from an import (`_epm_transcript_url`, `_epm_transcript_type`;
  WebVTT first, then SRT, then Podcasting 2.0 JSON; copied into the Media
  Library with *Copy audio*). The feed lists each as `podcast:transcript`
  (WebVTT/SRT with `rel="captions"`, which Apple Podcasts uses) before the
  HTML transcript page. Filter `epm_transcript_files`.
- Podcast Index notification (`hub/pubnotify`) one minute after a
  self-hosted episode is published on a site that allows search engines
  (cron `epm_ping_podcast_index`, filter `epm_ping_podcast_index`).
- Structured data: schema.org `PodcastEpisode` JSON-LD and `og:audio` on
  episode pages, `PodcastSeries` on the episode archive (filters
  `epm_structured_data`, `epm_structured_data_series`,
  `epm_structured_data_episode`).

Frontend
- Share menu on the player (Editorial, Artwork and Full layouts): copy
  link, copy link at the current position, the device share sheet where
  the browser has one, copy embed code; a menu button with keyboard
  support and a manual-copy field when the browser blocks copying.
  `[podcast_player share="no"]` and the Podcast Player widget's *Share
  Menu* toggle turn it off.
- Timestamp links: `?t=83`, `?t=1m23s`, `?t=1h2m3s` or `?t=1:23` on an
  episode URL cue the episode page's player without playing; the position
  wins over the remembered resume position, and the play button's
  accessible name names it until the first press.
- Episode embeds (`EPM\Embed`): `/podcast/{slug}/embed/` shows a compact
  playable card (artwork, show name, linked title, play, skip, duration)
  with only the podcast stylesheet, the player and a bridge script
  (`assets/js/epm-embed.js`, height and link messages); pasted episode
  URLs embed it through oEmbed; *Copy embed code* gives an `<iframe>`.
  Filter `epm_embed_player_args`.
- Click-to-load video: YouTube (`youtube-nocookie.com`), Vimeo
  (do-not-track) or a video file behind a facade with the episode artwork;
  nothing loads from the platform before play. `[podcast_video]`, the
  Episode Video widget (12 widgets now) and a part of the automatic
  episode page.
- Topics: taxonomy `podcast_topic` with archives at
  `/podcast-topic/{slug}/`, Podcast → Topics, a list column and Quick Edit
  field, REST support; topic chips on episode pages;
  `[podcast_episodes topic="a,b" show_topics="yes"]`; topic filter and
  chips in the Episode List widget.
- Automatic episode pages add the video and the topics
  (`epm_auto_embed_parts`: `player`, `video`, `content`, `topics`,
  `guest`, `show_notes`, `chapters`, `transcript`); the page's player
  brings the sticky mini player once playback starts.
- List play buttons and chapter lists bring the sticky player too
  (filter `epm_sticky_player_for_lists`); while it is open the page
  reserves its height.
- Players load audio hosted on another domain only on the first press
  (`preload="none"`; filter `epm_player_preload`).

Design system
- Design tokens: button shape (`--epm-button-radius`: rounded 8px, pill
  999px, square 2px), font family (`--epm-font`: inherit, system, serif,
  rounded, mono), shadow (`--epm-shadow`: none, soft, lifted) and timeline
  track color (`--epm-track`); easing tokens `--epm-ease-out`,
  `--epm-ease-in-out`, `--epm-ease-drawer`. Dark designs also print
  `--epm-danger`, `--epm-image-outline` and a section surface
  (`--epm-section-background`, `--epm-section-padding`; filter
  `epm_dark_section_surface`).
- Presets `clean-light`, `soft-voice`, `warm-paper`, `ink-mono`,
  `night-studio` and `midnight`, with values derived from the DESIGN.md
  files of the awesome-design-md collection (MIT): values only, generic
  names. Every preset meets text ≥ 7:1, muted ≥ 4.5:1, on-accent ≥ 4.5:1,
  accent ≥ 4.5:1 and track ≥ 3:1 on background and surface (measured).
- Design screen (Podcast → Design): preset gallery with live swatches and
  a confirmed apply (native dialog); fields grouped into Colors, Shape and
  depth, Typography and Layout defaults; a sticky live preview of the real
  player, episode list and subscribe links; a contrast check of eight
  pairs; a sticky save bar, *Discard changes* and a warning before leaving
  with unsaved changes; a summary of what differs from the preset. The
  design import checks format and version and accepts only allowlisted
  values.
- `DESIGN.md`: the plugin's design system in the awesome-design-md
  format, with an agent guide for new components and presets.
- Admin component library `admin/css/epm-app.css` for the new screens:
  native wp-admin look (admin color scheme, core buttons), motion only
  under `prefers-reduced-motion: no-preference`.

Admin
- Episode editor: *Use next number* (per season), *Paste chapters*
  (reads lists like `00:00 Intro`, `(12:30) Q&A`, `[1:02:03] Title`,
  times at the end of a line, trailing links), a transcript file picker
  (`.vtt`, `.srt`) that fills an empty transcript text on save, help for
  the video fields, and a note on imported episodes that local edits stay.
- Quick Edit (number, season, type, explicit) and Bulk Edit (season,
  type, explicit, and *Number from*, which numbers the selection by
  publish date, oldest first).
- Dashboard: hosting, distribution, latest episode and readiness cards;
  a dismissible setup reminder; readiness progress ("x of y checks
  complete") with deep links and a first-episode empty state (from the
  `work` branch).
- Platform glyphs for subscribe links and the Distribution screen
  (`includes/BrandIcons.php`, Simple Icons 16.33.0, CC0-1.0; brands that
  asked Simple Icons for removal get a neutral icon). Link service
  registry with URL detection (`epm_link_services`); links saved as
  "Custom" are matched to a known service.
- Readiness report for external mode (host feed, sync status, redirect)
  and for audio URLs (HTTPS, unknown size).

Tests
- `tests/integration/hosting.php` (parser against 26 real feeds and
  synthetic edge cases, import, sync, moves, transcripts),
  `tests/integration/admin.php`, `tests/integration/frontend.php`,
  `tests/e2e/setup.mjs`, `tests/e2e/admin.mjs`, `tests/e2e/frontend.mjs`;
  a test-only HTTP fixture server
  (`tests/fixtures/mu-plugins/epm-test-http.php`); `run-all.sh` discovers
  suites by file name.

### Changed
- `epm_distribution_audio_mimes` also distributes `audio/aac`,
  `video/mp4`, `video/x-m4v` and `video/quicktime` by default, so imported
  shows keep every episode. Upload types are unchanged.
- The feed discovery `<link>`, the RSS subscribe link and the structured
  data point to the public feed (the host's feed in external mode).
- Platform link service choices come from the link registry (more
  services); the 1.2.0 keys stay valid.
- Text buttons follow the button shape token. Designs saved before 1.3.0
  keep pill-shaped buttons; new designs default to rounded.
- The feed episode limit keeps the newest episodes for serial shows too
  (they are then listed oldest first).
- *Another podcast host* needs the host's feed address: saving it without
  one keeps *This website* (or the address saved before) with a message.
  The setup assistant switches to that mode at the host step, once the
  address is known. The sync switches back to *This website* when the
  host's feed redirects to this site's feed.
- Importing with *Copy audio* while mirroring a host asks for
  confirmation, because the finished move switches hosting to *This
  website*.
- A move import adopts the show's `podcast:guid` unless this site already
  stored one on purpose or from an earlier import (a value the site only
  derived from its own address is replaced).
- *Paste chapters* adds to the existing chapters by default; *Replace the
  current chapters* is opt-in and the button says which it will do.
- Admin wording: menu items in sentence case (*Add episode*, *Podcast
  settings*); Podcast settings uses *Host (presenter)*, *Content*
  (*Suitable for all ages* / *Explicit*), *Episode order* (*Newest first
  (episodic)* / *Oldest first (serial)*), *Feed address*, and the section
  *Distribution* is now *Feed and links*.
- Episode list: Author is hidden by default, and Topics while no topic
  exists (Screen Options shows them).
- Distribution: the header counts essential platforms submitted and
  updates after each save; only the next essential platform's *Submit*
  button is primary.
- The readiness report links its delivery note to *Test feed and audio
  delivery*, and after a move it warns while imported episodes still
  load their audio from the old host (*Audio at the old host*, with a link
  to import again with *Copy audio*).
- Episode lists reserve the number column only when an episode has a
  number; rows align number, title, date and play button on one baseline.
- A *Background* on the Podcast Hero or Latest Episode widget also adds
  inner padding and the container radius.
- Deactivation also clears the plugin's scheduled events. Uninstall
  always removes the scheduled events, temporary import files, the import
  job and lock and the activation flag; opt-in data deletion also removes
  `epm_hosting`, `epm_sync_state`, `epm_setup`, `epm_distribution`,
  `epm_feed_build`, the topics and their relationships.
- Requirements unchanged: PHP 8.1+, WordPress 6.2+. Import and sync also
  need outbound HTTPS requests, a writable uploads folder and WP-Cron.

### Fixed

Includes the fixes from the 1.3.0 reviews, some of them to features first
added in this release.

- Feed: `Last-Modified` and `<lastBuildDate>` only moved with a newer
  episode, so clients asking with `If-Modified-Since` kept an old feed
  after a channel change, a removed episode or a lower episode limit; the
  build time now follows the content (option `epm_feed_build`).
- Feed: a control character pasted into any field made the whole feed
  invalid XML; control characters are removed.
- Feed: a serial show with an episode limit dropped its newest episodes.
- Feed and structured data: `.m4a`/`.m4b` uploads were announced as
  `audio/mpeg` (WordPress files them that way); they are `audio/x-m4a`
  now.
- Import: saving an imported episode in the editor counted as a local
  edit (line endings, the paragraph tags the editor removes), so later
  syncs stopped updating it. Hashes written by earlier versions stay
  valid.
- Import: publish dates with a wrong weekday moved up to six days, and
  localized weekday names were rejected.
- Import: on a WordPress site, the feed search picked the blog feed
  listed first instead of the podcast feed.
- Import: a lock could expire during a long *Copy audio* step and let a
  second import start, and a retried step could download the same file
  twice; the lock now belongs to its request, is renewed while the import
  runs and lasts 20 minutes during media copies. Cancelling during a
  batch no longer turns back into "running", and a cancelled move is
  never finished.
- Import: GUIDs with `%`-escapes lost them, so the next import created
  the episode again; SRT transcripts were not copied during imports from
  WP-Cron or WP-CLI.
- Sync: `itunes:new-feed-url` could move the stored feed from https to
  http; imported episodes lost the removed-episode tracking after the
  host's feed moved (they are re-tagged with the new address).
- Hosting: text with spaces or another scheme was accepted as a feed
  address; the redirect could point at one of the site's own feed
  addresses (another scheme, a sub-path or the query form).
- Moving a show here while mirroring it left the site redirecting its
  feed to the old host, which redirected back.
- Transcript files: whether an SRT upload was accepted depended on the
  server's file-type detection (CI failed on PHP 8.1). SRT files are now
  stored as `application/x-subrip` on every server; the site's allowed
  file types still decide whether `.srt` is accepted.
- Embeds: the oEmbed iframe HTML used WordPress's default height; the
  focus ring of the linked title was clipped.
- Player and lists: list play buttons changed width between Play, Pause
  and Retry; Elementor Kit heading and link rules restyled episode titles,
  the download link and chapter links; long single words overflowed on
  phones; the video facade's focus ring was invisible on pale artwork;
  pagination drew a chip around "…"; the empty-list link was not
  underlined.
- Dark designs on light theme pages showed light text on a light page in
  sections without their own surface (also the Episode Metadata widget's
  line and the note under a video).
- Frontend review fixes: the sticky bar never hides the focused element
  and is a labelled region; audio errors show a retry state; sticky layout
  on phones; play/pause cross-fade and press feedback; the active chapter
  marker; the timeline keeps left-to-right on right-to-left pages; theme
  link and focus styles no longer leak into the components; reduced
  motion is gentler instead of off.
- Admin: the contrast check missed muted text on surface, accent text on
  background and the track on surface; leaving the Design screen dropped
  unsaved changes without a warning; Distribution errors were not tied to
  their field; *Open the host's feed* showed without an address; the setup
  assistant focuses the first field to fix and ties errors to their
  fields; labelled repeaters with inline errors, upload progress and
  announcements, a drop zone without flicker and copy feedback.
- Distribution readiness primes episode media attachments in bulk; each
  problem links to the screen where it is fixed (the podcast settings
  field, the episode, or the hosting screen), and links only appear for
  users who can open that screen (from the `work` branch).
- The latest-episode CTA shortcode loads its stylesheet without loading
  the audio player JavaScript (from the `work` branch).
- Dashboard: the design token for text on the accent color is labelled
  "Text on accent" (from the `work` branch).
- Episode capabilities: the same bug was fixed independently on the
  `work` branch; the 1.2.0 fix is kept because it also guards against a
  filtered meta capability.

### Security
- Feeds, pages, linked chapter/transcript files and media downloads go
  through `wp_safe_remote_get` / `download_url` (no requests to private
  networks), with timeouts; feeds and linked chapter/transcript files
  also with size limits. (Correction: media downloads through
  `download_url` had no size limit in 1.3.0; see Unreleased.) *Test feed and audio
  delivery* uses `wp_safe_remote_*` as well, because any user who can
  publish episodes can set an audio URL.
- XML is parsed with network access disabled (`LIBXML_NONET`), without
  loading external entities or DTDs, and without `LIBXML_PARSEHUGE`, so
  libxml's entity-expansion limits reject "billion laughs" documents.
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
- A file chosen as episode audio or transcript (editor save, the audio
  AJAX and the REST API's `meta` field) must be one the user may read, so
  media attached to someone else's unpublished episode cannot be attached
  or inspected. Through REST, a file that is not audio or not a WebVTT/SRT
  transcript is refused with 403; an unchanged stored value always passes.
- The Elementor episode picker lists private episodes only to users who
  may read them.
- Topics: renaming and deleting terms needs `manage_categories` by
  default (filter `epm_cap_manage_topics`), assigning them the episode
  capability; the Topics menu shows only for users who can manage them.
- Privacy: no request reaches a video platform before the visitor presses
  play, and players request audio from another host only on the first
  press.

`docs/VERIFICATION-UNRELEASED.md` (Docker smoke test of the `work`
branch) is superseded by `docs/VERIFICATION-1.3.0.md`.

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
