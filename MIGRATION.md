# Migration notes — 1.3.0 → next release (unreleased)

Nothing to do for most sites. Episodes, GUIDs, the podcast GUID, URLs,
settings, local edits and media are not touched. What changes:

## Import data moves from uploads to the database

1.3.0 kept the parsed feed of an import as a JSON file in
`wp-content/uploads/epm-import/`, protected only by an Apache
`.htaccess` (nginx and Apache without `AllowOverride` served it). It now
lives in non-autoloaded rows of the options table named
`epm_import_chunk_<key>_<id>` (at most 512 KB each), written and read
directly, never through an options cache.

On the first request after the update (and on admin requests and feed
checks while the folder exists):

- an import 1.3.0 was **running** continues: its next step moves the
  file's items into the database and deletes the file, then carries on
  at the same position (it finishes as 1.3.0 would have, since 1.3.0 did
  not record whether the catalog was complete);
- a feed 1.3.0 **checked but did not import** expires (its completeness
  is unknown): check the feed again;
- every other file in `uploads/epm-import/` and the folder are deleted.

## Paged feeds and the completeness of a catalog

- A preview reads a paged feed over several requests and records whether
  the catalog is complete (`catalog.complete`, `catalog.reason`,
  `catalog.error`, `catalog.url` in the preview summary and the import
  state). Integrations that call `epm_import_preview` directly must call
  `epm_import_more` with the token while `catalog.loading` is true.
- A **move** of an incomplete catalog is refused (`epm_import_incomplete`)
  unless `accept_partial` is sent; mirroring is unchanged.
- **`wp podcast import` exits with an error** when the feed cannot be
  read completely (1.3.0 reported success with the pages it had read).
  Scripts that should import a partial catalog anyway need
  `--accept-partial`; `wp podcast import --resume` continues.
- New filters: `epm_import_max_bytes` (200 MB), `epm_import_request_seconds`
  (10), `epm_import_ttl` (one day). `epm_import_max_pages` (50) stays.

## Import lock and job

The lock keeps its option name and value format (`<time>:<owner>`), but
is only changed with conditional SQL; code that wrote
`epm_import_lock` with `update_option()` to "borrow" the lock no longer
works (a lock row it rewrote is not renewed or released by its former
owner). The job option `epm_import_job` gains `version`, `store`,
`catalog` and `stats`; it is read from the database on every use and
saved with compare-and-swap.

## Copying media and moves

Nothing to do; behavior to know when you run imports with *Copy audio*
(`download_media`) or integrate with the import:

- **Existing episodes get their files too.** A run with copies now copies
  every file of every episode in the feed that still loads from the host
  (WebVTT/SRT transcript file, episode image, audio, each on its own),
  also of episodes mirrored earlier. 1.3.0 copied audio (and the image
  only together with it) and never a transcript file of an existing
  episode. Running a move again after updating fills those gaps.
- **Where a transcript address came from** is recorded in the private
  `_epm_import_hash` (key `transcript_url`: a hash when the import wrote
  the address, `local` when it was set on this site). For addresses
  1.3.0 wrote, the next import decides: an address the current feed item
  lists is the import's (copied with *Copy audio*), anything else counts
  as chosen on this site and is never replaced.
- **New private post meta** `_epm_import_extras`: set when an import
  creates an episode, removed once its chapters and transcripts are
  fetched (an episode whose import died before that gets them on the
  next run).
- **A move that leaves files at the old host is not finished.** The job
  ends with the new status `done_with_problems` (1.3.0: `done`, and the
  move was finished anyway): `moved_in`, `locked` and the hosting mode
  stay unchanged and the parsed feed is kept. New AJAX actions
  `epm_import_retry` (copy the missing files again; finishes the move
  when nothing is left) and `epm_import_confirm` (needs
  `confirm_remaining=1`; finishes the move). `wp podcast import --move`
  exits with an error then; scripts can follow up with
  `wp podcast import --resume` or `wp podcast finish-move --yes`.
- **New job status `waiting`** (with `wait_until` and `wait_reason`)
  while a host's HTTP 429 (or 503 with `Retry-After`) is honored. Code
  that checks for `running` to tell whether an import is in progress
  should check `EPM\ImportJob::is_active()`.
- **The job option `epm_import_job`** gains `media` (`inflight`: the
  copy in progress; `refs`, `counts`, `copied`: what was copied and what
  stays at the old host, per kind), `retries` and `confirmed`. The
  client state (AJAX responses, `epmApp.job`) gains `remaining` (per
  kind: count, label, episodes with title, edit link, address, reason),
  `copied`, `copy_media`, `current`, `wait_until`, `wait_reason`,
  `problems`, `can_retry` and `confirmed`. `media_failed` (audio only)
  stays for compatibility.
- **Readiness checks** carry `items` (episodes: title, editor link) and
  `more`. *Audio at the old host* is joined by *Episode images at the
  old host*, *Transcript files at the old host*, *Transcripts linked at
  the old host* and, while a move is unfinished, *Move to this website*.
- **Downloads** no longer use `download_url()` and audio no longer goes
  through `media_handle_sideload()`: audio attachments get audio metadata
  only (no `image_meta`, no attachment for embedded cover art). A file is
  named after what it is (an `.m4a` address that serves MP3 data is
  stored as `.mp3`); audio other than MP3, M4A and WAV (AAC, Ogg, FLAC),
  which 1.3.0 stored as `.mp3`, stays at the host and is reported. New
  filters: `epm_media_max_bytes`, `epm_media_request_seconds`,
  `epm_media_low_speed`, `epm_media_max_attempts`, `epm_media_max_waits`,
  `epm_media_max_wait`, `epm_media_disk_free`.
- **Temp files** of a copy in progress are named `epm-media-*` in the
  temp folder; leftovers older than an hour are removed.
- **WP-CLI:** new `wp podcast cancel` and `wp podcast finish-move`;
  `wp podcast status` shows the import.

## Scheduled events

| Event | Change |
|---|---|
| `epm_import_cleanup` | New: a single event a day after a feed is checked; expires the check when nothing was imported and removes data no job uses |
| `epm_podcast_index_ping` | New name of the Podcast Index notification (1.3.0: `epm_ping_podcast_index`). An event scheduled by 1.3.0 is moved to the new name, keeping its time. `epm_ping_podcast_index` stays the filter that turns the notification off; in 1.3.0 that filter could not stop it, and publishing sent the notification at once |

## Uninstall

Additionally removes every `epm_import_chunk_*` row and the
`epm_import_cleanup` event, whether or not data deletion is enabled.

## Rollback

Reactivating 1.3.0 is safe: it ignores the new job fields and the
`epm_import_chunk_*` rows (remove them with the cleanup below), and
treats a job started by this version as having no stored data (*Check
the feed again*). Finish or cancel a running import before rolling back;
an unfinished move (`done_with_problems`) or a waiting import is not
picked up by 1.3.0 either, so finish or cancel those too
(`wp podcast finish-move`, `wp podcast cancel`). The `transcript_url`
entries in `_epm_import_hash` and the `_epm_import_extras` meta are
ignored by 1.3.0.

```sql
DELETE FROM wp_options WHERE option_name LIKE 'epm\_import\_chunk\_%';
```

---

# Migration notes — 1.2.0 → 1.3.0

## Nothing to do for existing sites

1.3.0 adds hosting modes, feed import and sync, a setup assistant, a
distribution center, transcript files, topics, a share menu with
timestamp links, episode embeds, click-to-load video and a rebuilt Design
screen. An existing site keeps working without any action:

- The hosting mode defaults to *This website* (`self`). Until the new
  option `epm_hosting` is saved, the defaults apply: the site publishes
  its feed at `/podcast/feed/`, nothing is synced and nothing redirects.
- Nothing is deleted, renamed or restructured. Episodes, meta, media,
  settings, design, URLs, episode GUIDs, the stored `podcast:guid` and
  Elementor widget settings carry over unchanged.
- There is no 1.3.0 data migration. New options, meta and terms are
  created when the features that use them are first used.

## What happens on the first request after the update

The upgrade routine (`Plugin::maybe_upgrade()`, `init` priority 99) runs
once, when the stored `epm_version` differs from `1.3.0`:

1. **Rewrite rules are flushed.** The Topics taxonomy (`podcast_topic`)
   is registered on `init` priority 5, before the flush, so its archive
   rules (`/podcast-topic/{slug}/`) work right away. Nothing needs to be
   saved under Settings → Permalinks.
2. The feed cache (transient `epm_feed_cache`) is cleared.
3. `_epm_duration_seconds` is written again for every episode (as in
   1.2.0; idempotent).
4. Elementor's generated CSS is cleared, so updated widget selectors
   apply.
5. `epm_version` is set to `1.3.0`.

Updating in place does not run the activation hook, so there is no
redirect to the setup assistant after an update.

## The setup assistant does not force itself on existing sites

- Even after deactivating and reactivating, the redirect to the setup
  assistant happens once and only when the site has no podcast title, no
  published episodes, the assistant was neither finished nor dismissed,
  and the activation was not a bulk activation (never during AJAX, cron,
  WP-CLI or in the network admin).
- The "Your podcast is not set up yet" notice appears only on the
  plugin's screens and only while the podcast title is empty. It can be
  dismissed.
- The assistant stays available under Podcast → Setup assistant.

## New options

| Option | Autoload | Holds | Created |
|---|---|---|---|
| `epm_hosting` | WordPress default | Hosting mode, host, host feed address, sync on/off and interval, status of new episodes, removed-episode policy, feed redirect on/off, download statistics service and prefix | When Hosting & import is saved or the setup assistant chooses a path |
| `epm_sync_state` | no | Last run and last success, status and message, ETag/Last-Modified of the host feed, counters, consecutive failures, item count | By the first sync |
| `epm_setup` | no | Setup assistant: finished, chosen path, dismissed, ID of the created podcast page | By the setup assistant or when its notice is dismissed |
| `epm_distribution` | no | Per platform: submitted/listed and the listing address | On Podcast → Distribution |
| `epm_feed_build` | no | Hash and time of the last feed content, so `Last-Modified` and `<lastBuildDate>` move when the content changes | By the first feed request after the update |
| `epm_import_job` | no | The current or last import: status, feed address, channel data, counters (including audio not copied), the IDs of episodes whose audio was not copied, the last 50 log lines | When a feed is checked for import |
| `epm_import_lock` | no | `"<time>:<owner>"` while an import or sync runs (stale after five minutes, twenty during a media copy) | During an import or sync |
| `epm_activation_redirect` | no | One-time flag set on activation | On activation; removed by the first admin request |

`epm_design_settings` gains four keys: `button_shape`, `font_family`,
`shadow` and `track_color`. A design saved before 1.3.0 has none of them
and is read as `button_shape: pill` (what its text buttons looked like),
`font_family: inherit`, `shadow: none` and an automatic track color, so
the site looks the same after the update. New designs default to
`rounded` buttons. The keys are written the next time the Design screen
is saved or a preset is applied.

Short-lived transients: `epm_audio_probe_{hash}` (one day; an audio URL
that was just checked is not checked again on every save),
`epm_notices_{user}_{post}` and `epm_field_errors_{user}_{post}` (one
minute; editor messages after a save), `epm_design_import_error_{user}`
(the last design import error).

## New post meta

On episodes (`podcast_episode`):

| Meta key | Written by | REST |
|---|---|---|
| `_epm_audio_url`, `_epm_audio_type`, `_epm_audio_length` | Episode editor (*Use an audio URL instead*); Importer; REST | readable and writable by users who can edit the episode |
| `_epm_artwork_url` | Importer; REST | readable and writable |
| `_epm_transcript_file_id` | Episode editor (transcript file picker); Importer with *Copy audio* | readable and writable |
| `_epm_transcript_url`, `_epm_transcript_type` | Importer (a hosted WebVTT, SRT or JSON transcript); REST | readable and writable |
| `_epm_source` (`import`), `_epm_source_feed`, `_epm_source_link` | Importer (`_epm_source_feed` is updated when the host's feed moves) | readable only |
| `_epm_import_hash`, `_epm_import_fingerprint` | Importer (local-edit detection) | not exposed |
| `_epm_missing_since` | Sync (removed-episode policy) | not exposed |
| `_epm_copying` | Importer, only while an audio download runs (deleted afterwards) | not exposed |

On attachments downloaded by the importer: `_epm_source_url` (used to
reuse an image instead of downloading it twice).

Unchanged in meaning: `_epm_guid` (imported episodes store the GUID of
the source feed there, byte-for-byte), `_epm_video_url` and
`_epm_youtube_url` (now also shown as a click-to-load video on episode
pages), `_epm_transcript` (the readable transcript text; filled from an
attached transcript file when it is empty on save).

The import hashes changed format: they now ignore line endings and the
paragraph tags the editor removes. Hashes written by earlier builds are
still accepted, so episodes imported before keep their local-edit
protection.

## New taxonomy

`podcast_topic` (non-hierarchical, like tags) on `podcast_episode`:
public, archives at `/podcast-topic/{slug}/`, REST (`/wp/v2/podcast_topic`),
list column, Quick Edit, Podcast → Topics. Capabilities:

| Capability | Default |
|---|---|
| `assign_terms` | the episode capability (`edit_posts`, or the `epm_cap_manage_episodes` value) |
| `manage_terms`, `edit_terms`, `delete_terms` | `manage_categories` (editors and administrators); the custom episode capability when `epm_cap_manage_episodes` is filtered; `epm_cap_manage_topics` changes it |

No terms exist until someone adds a topic.

## Episode embeds

`/podcast/{slug}/embed/` is WordPress's own embed address for the post
type; no rewrite rule is added. 1.3.0 renders its own card there instead
of the generic WordPress embed card (published, public episodes with
audio; anything else keeps the WordPress template), and its oEmbed
response announces a height of 200px with a matching iframe. Episode URLs
already embedded on other WordPress sites show the new card the next
time those pages load.

## Scheduled events and their cleanup

| Event | Scheduled | Cleared |
|---|---|---|
| `epm_sync_feed` | Recurring (hourly, twice daily or daily) only in *Another podcast host* mode with a feed address and sync on; plus single follow-up runs when more than 25 new episodes are waiting | When the hosting settings change (rescheduled), on deactivation, on uninstall |
| `epm_import_continue` | Single events while an import runs, so it continues without a browser | When the import finishes or is cancelled, on deactivation, on uninstall |
| `epm_podcast_index_ping` (1.3.0: `epm_ping_podcast_index`, moved to the new name on the next request) | A single event one minute after a self-hosted episode is published on a site that allows search engines | On deactivation, on uninstall |

Deactivation clears all three. Reactivation schedules the sync again on
the next request if it is enabled. Uninstall clears them whether or not
data deletion is enabled.

## New files

While an import runs, the parsed feed is stored as JSON with a random
name in `wp-content/uploads/epm-import/` (with an `index.php` and an
`.htaccess` that denies access on Apache). The file is deleted when the
import finishes, is cancelled or another feed is checked. Uninstall
removes the folder.

## Uninstall

Always removed now, even without opting in to data deletion: the
scheduled events above, `wp-content/uploads/epm-import/`,
`epm_import_job`, `epm_import_lock` and `epm_activation_redirect`.

With `EPM_DELETE_DATA` or `epm_delete_data_on_uninstall`, the new options
`epm_hosting`, `epm_sync_state`, `epm_setup`, `epm_distribution` and
`epm_feed_build` are deleted as well, and so are the topics and their
relationships to episodes.

## Behavior changes to review

Feed
- **Distributed audio types.** The default of
  `epm_distribution_audio_mimes` grows from `audio/mpeg`, `audio/mp4`,
  `audio/x-m4a` to also include `audio/aac`, `video/mp4`, `video/x-m4v`
  and `video/quicktime`, so imported shows keep their AAC and video
  episodes in the feed. Upload types (`epm_allowed_audio_mimes`) are
  unchanged, so a site that never filtered them has no such episodes. If
  you allowed video uploads through that filter, those episodes now
  appear in the feed; filter `epm_distribution_audio_mimes` back to the
  1.2.0 list if that is not wanted.
- **M4A enclosure type.** Episodes whose audio is an uploaded `.m4a` or
  `.m4b` file are now announced as `audio/x-m4a` instead of `audio/mpeg`
  (WordPress files those uploads as `audio/mpeg`). The URL and GUID do not
  change; apps re-read the type.
- **Episodes with an audio URL count as having audio** (feed, players,
  "latest episode", readiness report). Existing episodes have no
  `_epm_audio_url`, so nothing changes for them.
- **Serial feeds with an episode limit** now keep the newest episodes
  (listed oldest first) instead of the oldest.
- **`Last-Modified` / `<lastBuildDate>`** move when the feed's content
  changes, not only when a newer episode is published. The first request
  after the update records the current content, so the dates move once.
- **Feed additions:** `<podcast:medium>podcast</podcast:medium>`, a
  channel `<podcast:person role="host">` when *Host (presenter)* is set in
  Podcast settings, a channel `<podcast:trailer>` for each published
  trailer episode, an item `<podcast:person role="guest">` for episodes
  with a guest name, and item `<podcast:transcript>` tags for transcript
  files. Directories that read Podcasting 2.0 tags show these.
- **Download statistics** are off by default. Choosing a service under
  Hosting & import changes every enclosure URL in the feed (episode GUIDs
  stay the same).
- **Podcast Index notification.** On sites that allow search engines,
  publishing a self-hosted episode sends the feed address to Podcast
  Index (`api.podcastindex.org/api/1.0/hub/pubnotify`) one minute later.
  Turn it off with `add_filter( 'epm_ping_podcast_index', '__return_false' )`.

Frontend
- **Automatic episode pages** add the episode's video (when it has one)
  above the description and topic chips below it, and the page's player
  brings the sticky mini player once playback starts. Change this with
  `epm_auto_embed_parts` and `epm_auto_embed_player_args` (for example
  `$args['sticky'] = false`).
- **Share menu.** Players in the Editorial, Artwork and Full layouts show
  a *Share* button (Podcast Player widgets: *Share Menu*, on by default;
  `[podcast_player share="no"]`; `epm_auto_embed_player_args` with
  `show_share => false`).
- **Sticky player for lists.** Play buttons in episode lists and chapter
  lists now bring the sticky mini player. Turn it off with
  `add_filter( 'epm_sticky_player_for_lists', '__return_false' )`.
- **Audio on another domain** is requested only when a visitor presses
  play (`preload="none"`), so its duration comes from the episode data
  until then. `epm_player_preload` restores `metadata`.
- **Dark designs** (background luminance below 0.2) give show notes,
  chapters, transcripts, guest blocks, headers, row lists, subscribe
  links and pagination the design background and padding, so light text
  stays readable on a light theme page. On sites whose pages are already
  dark, `add_filter( 'epm_dark_section_surface', '__return_false' )`
  keeps them transparent.
- **Titles resist Elementor Kit styles.** Episode row, card, hero,
  header and latest-episode titles (and the player's download and chapter
  links) use two-class selectors (0,2,0). Custom CSS that styled
  `.epm-episode-row__title` with one class needs a second class, for
  example `.epm-episode-row .epm-episode-row__title`. Elementor widget
  typography controls still win.
- **List play buttons** render their label as three stacked words
  (`.epm-list-play__text--play`, `--pause`, `--retry`) that CSS switches
  with `.is-playing` / `.has-error`; scripts that rewrote the label text
  should toggle those classes instead.
- **Numbered row lists** no longer reserve the number column when no
  episode in the list has a number.
- **Podcast Hero / Latest Episode backgrounds** set in Elementor now also
  add inner padding and the container radius.
- **Subscribe links show platform glyphs** (Simple Icons) instead of the
  generic line icons. Links saved with the service "Custom" are now
  matched to a known service by their address, so their list item class
  changes from `epm-subscribe__item--custom` to, for example,
  `epm-subscribe__item--spotify`. Update custom CSS that targets
  `--custom`.
- **Structured data.** Episode pages print schema.org `PodcastEpisode`
  JSON-LD and `og:audio` tags; the episode archive prints
  `PodcastSeries`. If an SEO plugin prints its own podcast schema, turn
  this off with `add_filter( 'epm_structured_data', '__return_false' )`.

Admin
- **Admin menu.** Podcast gains *Topics*, *Setup assistant*, *Hosting &
  import* and *Distribution*; *Add Episode* and *Podcast Settings* are
  now *Add episode* and *Podcast settings*. The new screens require the
  podcast capability (`epm_cap_manage_podcast`, `manage_options` by
  default); Topics requires the topic capability above.
- **Podcast settings wording.** *Host* is *Host (presenter)*, *Explicit*
  is *Content* (*Suitable for all ages* / *Explicit*), *Podcast type* is
  *Episode order* (*Newest first (episodic)* / *Oldest first (serial)*),
  and the section *Distribution* (feed episode limit, latest-episode CTA,
  feed address, links) is now *Feed and links*. Stored values are
  unchanged.
- **Episode list.** The Author column is hidden by default (Screen
  Options shows it), and the Topics column while no topic exists.
- **Paste chapters** adds to the existing chapters unless *Replace the
  current chapters* is checked.
- **More platform link services.** The service dropdowns in Podcast
  settings and on the episode screen list every service of the new link
  registry (`epm_link_services`). The keys used by 1.2.0 (`spotify`,
  `apple`, `youtube`, `amazon`, `rss`, `custom`) stay valid.
- **SRT uploads.** `.srt` files are stored as `application/x-subrip`
  (WordPress lists them as `text/plain`). Whether `.srt` is accepted at
  all is still decided by the site's allowed file types.
- **Deactivation** now also clears the plugin's scheduled events.

## Rollback

Deactivate 1.3.0 and reactivate 1.2.0: the data stays readable. Before
rolling back, check these points:

- **External hosting mode.** 1.2.0 ignores `epm_hosting`: the site's
  `/podcast/feed/` stops redirecting and serves a feed of the mirrored
  episodes again, which apps may pick up as a second copy of the show.
  Switch to *This website* and delete the mirrored episodes, or keep
  1.3.0.
- **Episodes that only have an audio URL** (imported without copying
  media) have no audio in 1.2.0: they drop out of the feed and their
  players show no audio. AAC and video episodes are excluded from the
  feed again.
- **Topics** stay in the database, but 1.2.0 does not register the
  taxonomy: `/podcast-topic/…` archives return 404 and topic filters in
  shortcodes are ignored.
- **Transcript files, the share menu, embeds and video** disappear from
  the feed and the pages; the meta stays and comes back with 1.3.0.
  Embeds on other sites fall back to the WordPress embed card.
- **Design:** the four new design keys are ignored by 1.2.0 and dropped
  the next time the Design screen is saved there.

Deactivating 1.3.0 clears its scheduled events, so 1.2.0 does not inherit
them.

---

# Migration notes — 1.1.0 → 1.2.0

## Data preservation

Nothing is deleted, renamed or restructured. Episodes, meta, media,
settings, design, URLs, feed GUIDs and Elementor widget settings (all
control IDs) carry over unchanged.

## Automatic one-time tasks (first request after the update)

Run when the stored `epm_version` differs from the plugin version:

1. **Rewrite rules are flushed** so `/podcast/feed/` serves the podcast
   feed. Before 1.2.0 that URL returned WordPress's generic archive RSS;
   directories that were given `/podcast/feed/` now receive the real
   podcast feed.
2. **`_epm_duration_seconds`** is written for every episode (numeric
   duration for sorting and integrations).
3. **Feed cache and Elementor's generated CSS are cleared** so the
   updated widget selectors apply.
4. The podcast's `podcast:guid` is derived from the feed URL once and
   stored in `epm_podcast_guid`; it never changes afterwards.

## Behavior changes to review

- **Episode pages get the player and episode details automatically.**
  Turn it off under Podcast settings → Episode pages if your theme
  template already adds them. Sites using an Elementor Pro Theme Builder
  single template, or Elementor-built episodes, are detected and left
  untouched.
- **Capabilities.** With the default capability, episodes now follow
  core post roles: contributors can draft but no longer publish, authors
  publish only their own episodes. Sites filtering
  `epm_cap_manage_episodes` to a custom capability keep a single
  capability for everything.
- **Episode screen.** Episodes open in the classic editing screen with
  the audio upload under the title. Use the `epm_use_block_editor`
  filter to keep the block editor.
- **Feed:** `itunes:explicit` is `true`/`false`; `itunes:duration` is in
  seconds; `content:encoded` carries the description and show notes;
  per-item `itunes:image` is only sent for square episode-specific
  artwork (the channel artwork applies otherwise).
- **"Latest episode"** (widget, shortcode, CTA, player source) now means
  the newest published episode *with audio*.
- **Featured images** are used as episode artwork when no episode
  artwork is set (web display; the feed only uses square images).
- **Player buttons** use theme-proof selectors. Custom CSS that styled
  `.epm-player__play` directly needs `:not(#epm)` or the new
  `--epm-play-background` / `--epm-play-color` / `--epm-play-size`
  variables.
- **Show notes/transcripts saved with 1.1.0 lost their HTML at save
  time;** that formatting cannot be recovered automatically — re-save
  affected episodes with their original formatting.

## Rollback

Deactivate 1.2.0 and reactivate 1.1.0: all data stays readable. Note that
1.1.0 re-introduces the feed routing and capability bugs listed in the
changelog.

---

# Migration notes — 1.0.0 → 1.1.0

## Data preservation

Nothing is deleted or restructured. All existing content survives the
update unchanged:

- Episodes (`podcast_episode` posts), titles, content, slugs, URLs.
- All post meta (`_epm_*`), including audio/artwork attachment IDs.
- Media Library associations.
- Podcast settings (`epm_podcast_settings`) and design settings
  (`epm_design_settings`); new settings get defaults.
- Public episode URLs (`/podcast/{slug}/`) and the feed URL.
- Elementor widget settings: all control IDs preserved; the single
  `style_source` control per widget keeps its saved value; the episode
  picker keeps saving a plain post ID.

## One-time idempotent migrations (run automatically on `init`)

1. **Immutable GUIDs** (`epm_guids_migrated` option flag).
   Every existing episode without `_epm_guid` gets its *already-issued*
   GUID stored byte-for-byte (the `/?epm_episode_guid={id}` form from
   1.0.0), so podcast clients never see a duplicate. Episodes created
   after the migration receive domain-independent `urn:uuid:` GUIDs on
   save. Re-running is a no-op.

2. **No other migrations.** No post meta keys were renamed; no options
   were moved.

## Behavior changes to be aware of

- **Feed eligibility is stricter and more correct.** Episodes with WAV
  audio (previously included with a WAV enclosure) are now excluded
  from the feed — WAV was always documented as internal-only, and the
  editor now warns about it. Episodes with missing/deleted audio files
  are excluded instead of emitting broken enclosures.
- **Feed window:** the silent 200-newest-posts cap is replaced by an
  explicit "Feed episode limit" setting (default 500, 0 = unlimited).
  Eligibility filtering now happens *before* the window, so episodes
  without audio no longer displace valid episodes from the feed.
- **Feed order for serial podcasts** changed to oldest-first
  (episodic stays newest-first).
- **Password-protected episodes** are now excluded from widgets,
  shortcodes, lists and the feed (they were previously exposed).
- **Capability filters are now enforced**, not just cosmetic. If you
  filtered `epm_cap_manage_episodes` / `epm_cap_manage_podcast`, verify
  the target roles still reach the episode editor and settings pages.
  Defaults (`edit_posts` / `manage_options`) behave exactly as before.
- **CPT registration** moved fully onto `init` (was also called directly
  on `plugins_loaded`). Activation still registers explicitly for
  rewrite flushing. No action needed.
- **Design tokens:** the `:root` output moved to `wp_head` priority 20
  (after stylesheets) so Global Podcast Styles reliably win over the
  static CSS fallback. If you had custom CSS *relying* on the old
  (buggy) ordering, re-check it.
- **New Show Notes widget** appears in the Podcast category; the
  Transcript widget is unchanged.

## Rollback

Deactivate 1.1.0 and re-activate 1.0.0: all data remains readable by
1.0.0 (new meta keys like `_epm_guid`, `_epm_show_notes`,
`_epm_platform_urls` are simply ignored by the old version). The
`epm_guids_migrated` flag can be deleted to re-run the GUID migration,
but there is no reason to: GUIDs are stable once written.
