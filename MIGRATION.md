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
