# Changelog

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
- Minimum: WordPress 6.0+, PHP 7.4+, Elementor 3.0+ (unchanged).
- 11 Elementor widgets (was 10).
- Deactivation remains non-destructive; uninstall deletion remains opt-in.
