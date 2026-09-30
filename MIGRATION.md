# Migration notes — 1.2.0 → 1.3.0

## Nothing to do for existing self-hosted sites

1.3.0 adds hosting modes, feed import and sync, a setup assistant and a
distribution center. An existing site keeps working exactly as before:

- The hosting mode defaults to *This website* (`self`). Until the new
  option `epm_hosting` is saved, the defaults apply: the site publishes
  its feed at `/podcast/feed/`, nothing is synced and nothing redirects.
- Nothing is deleted, renamed or restructured. Episodes, meta, media,
  settings, design, URLs, episode GUIDs, the stored `podcast:guid` and
  Elementor widget settings carry over unchanged.
- The upgrade routine is the same as in 1.2.0 (rewrite rules flushed,
  feed cache and Elementor CSS regenerated, `epm_version` updated). There
  is no 1.3.0-specific data migration.

## The setup assistant does not force itself on existing sites

- Updating in place does not run the activation hook, so there is no
  redirect after an update.
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
| `epm_import_job` | no | The current or last import: status, feed address, channel data, counters, the last 50 log lines | When a feed is checked for import |
| `epm_import_lock` | no | A timestamp while an import or sync runs (expires after five minutes) | During an import or sync |
| `epm_activation_redirect` | no | One-time flag set on activation | On activation; removed by the first admin request |

## New post meta

On episodes (`podcast_episode`):

| Meta key | Written by | REST |
|---|---|---|
| `_epm_audio_url`, `_epm_audio_type`, `_epm_audio_length` | Importer; REST | readable and writable by users who can edit the episode |
| `_epm_artwork_url` | Importer; REST | readable and writable |
| `_epm_source` (`import`), `_epm_source_feed`, `_epm_source_link` | Importer | readable only |
| `_epm_import_hash`, `_epm_import_fingerprint` | Importer (local-edit detection) | not exposed |
| `_epm_missing_since` | Sync (removed-episode policy) | not exposed |

On attachments downloaded by the importer: `_epm_source_url` (used to
reuse an image instead of downloading it twice).

`_epm_guid` is unchanged in meaning: imported episodes store the GUID of
the source feed there.

## New scheduled events and their cleanup

| Event | Scheduled | Cleared |
|---|---|---|
| `epm_sync_feed` | Recurring (hourly, twice daily or daily) only in *Another podcast host* mode with a feed address and sync on; plus single follow-up runs when more than 25 new episodes are waiting | When the hosting settings change (rescheduled), on deactivation, on uninstall |
| `epm_import_continue` | Single events while an import runs, so it continues without a browser | When the import finishes or is cancelled, on deactivation, on uninstall |
| `epm_ping_podcast_index` | A single event one minute after a self-hosted episode is published on a site that allows search engines | On deactivation, on uninstall |

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
`epm_hosting`, `epm_sync_state`, `epm_setup` and `epm_distribution` are
deleted as well.

## Behavior changes to review

- **Distributed audio types.** The default of
  `epm_distribution_audio_mimes` grows from `audio/mpeg`, `audio/mp4`,
  `audio/x-m4a` to also include `audio/aac`, `video/mp4`, `video/x-m4v`
  and `video/quicktime`, so imported shows keep their AAC and video
  episodes in the feed. Upload types (`epm_allowed_audio_mimes`) are
  unchanged, so a site that never filtered them has no such episodes. If
  you allowed video uploads through that filter, those episodes now
  appear in the feed; filter `epm_distribution_audio_mimes` back to the
  1.2.0 list if that is not wanted.
- **Episodes with an audio URL count as having audio** (feed, players,
  "latest episode", readiness report). Existing episodes have no
  `_epm_audio_url`, so nothing changes for them.
- **Subscribe links show platform glyphs** (Simple Icons) instead of the
  generic line icons. Links saved with the service "Custom" are now
  matched to a known service by their address, so their list item class
  changes from `epm-subscribe__item--custom` to, for example,
  `epm-subscribe__item--spotify`. Update custom CSS that targets
  `--custom`.
- **More platform link services.** The service dropdowns in Podcast
  Settings and on the episode screen list every service of the new link
  registry (`epm_link_services`). The keys used by 1.2.0 (`spotify`,
  `apple`, `youtube`, `amazon`, `rss`, `custom`) stay valid.
- **Feed additions:** `<podcast:medium>podcast</podcast:medium>`, a
  channel `<podcast:person role="host">` when *Host* is set in Podcast
  Settings, a channel `<podcast:trailer>` for each published trailer
  episode, and an item `<podcast:person role="guest">` for episodes with
  a guest name. Directories that read Podcasting 2.0 tags show these.
- **Download statistics** are off by default. Choosing a service under
  Hosting & import changes every enclosure URL in the feed (episode GUIDs
  stay the same).
- **Structured data.** Episode pages print schema.org `PodcastEpisode`
  JSON-LD and `og:audio` tags; the episode archive prints
  `PodcastSeries`. If an SEO plugin prints its own podcast schema, turn
  this off with `add_filter( 'epm_structured_data', '__return_false' )`.
- **Podcast Index notification.** On sites that allow search engines,
  publishing a self-hosted episode sends the feed address to Podcast
  Index (`api.podcastindex.org/api/1.0/hub/pubnotify`) one minute later.
  Turn it off with `add_filter( 'epm_ping_podcast_index', '__return_false' )`.
- **Deactivation** now also clears the plugin's scheduled events.
- **Admin menu.** Podcast gains *Setup assistant*, *Hosting & import* and
  *Distribution*; they require the podcast capability
  (`epm_cap_manage_podcast`, `manage_options` by default).

## Rollback

Deactivate 1.3.0 and reactivate 1.2.0: the data stays readable. Before
rolling back, check two things:

- **External hosting mode.** 1.2.0 ignores `epm_hosting`: the site's
  `/podcast/feed/` stops redirecting and serves a feed of the mirrored
  episodes again, which apps may pick up as a second copy of the show.
  Switch to *This website* and delete the mirrored episodes, or keep
  1.3.0.
- **Episodes that only have an audio URL** (imported without copying
  media) have no audio in 1.2.0: they drop out of the feed and their
  players show no audio. AAC and video episodes are excluded from the
  feed again.

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
  Turn it off under Podcast Settings → Episode pages if your theme
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
