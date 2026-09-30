# Verification report — 1.1.0

Date: 2026-09-30. No PHP runtime was available in this environment
(`apt-get install php-cli`: package not found in configured sources;
`apt-get update` stalled), so no WordPress/Elementor execution was
possible. Nothing below is presented as a runtime result.

## 1. Static checks (executed)

- `node --check`: `admin/js/epm-admin.js`, `admin/js/epm-episode-select.js`,
  `assets/js/epm-player.js` — all pass.
- PHP brace/paren/bracket balance: custom string-aware scanner over all
  36 PHP files (handles `//`, `/* */`, `#`, quoted strings, heredoc) —
  all balanced. (Not a substitute for `php -l`.)
- Manual re-reads: every changed PHP file read after editing; diffs
  reviewed for logic errors (duplicate blocks from bad edits found and
  fixed in `Feed.php` and `Plugin.php`).
- Grep audits:
  - `--epm-*` tokens: 23 consumed, each defined once at `:root`
    (CSS fallback) + `DesignSettings::output_tokens()`; 0 self-references.
  - `add_style_source_control()`: exactly 1 per widget (11 widgets).
  - `get_script_depends()`: only player/list/latest/chapters widgets;
    `get_style_depends()`: all 11 widgets.
  - No remaining `location.reload()` in admin audio flows.
  - No `production-ready` / `No N+1` claims remain.

## 2. Automated tests

None were added that could run here: without PHP or a DOM, meaningful
regression tests for the repaired failure modes need WordPress +
Elementor. The in-repo `TEST-PLAN.md` (player) and `CONTROL-AUDIT.md`
(control matrix) document the manual scenarios to execute.

Recommended first automated additions once a CI with PHP exists:
- `AudioMetadata::is_valid_duration()` table test (pure function).
- `Feed::rss_language()` table test (pure function).
- Repeater key uniqueness simulation (delete-middle-add-two).
- GUID migration idempotency on fixture posts.

## 3. Real WordPress/Elementor tests — NOT RUN (environment)

Required before production use:
1. Activate/deactivate (fresh + over 1.0.0 data); check rewrite rules,
   `epm_guids_migrated` one-time run, GUID stability.
2. Elementor inactive: engine + shortcodes + feed work; widgets absent.
3. Elementor active: widget registration without debug warnings;
   duplicate-control-ID check; save/close/reopen editor.
4. Elementor Pro Theme Builder: one Single template serving multiple
   episodes (player, guest, show notes, chapters, transcript).
5. Episode lifecycle: upload MP3/M4A via picker + drag-drop, replace,
   remove, save/reopen; feed enclosure correctness.
6. Visibility matrix (anonymous): published visible; draft/private/
   future/trashed/password-protected absent from widgets, shortcodes,
   lists, HTML and RSS — before and after status changes.
7. Feed: XML parser validation; stable GUIDs across title/slug/domain
   change; 200+ episode catalog; serial ordering; plain permalinks.
8. Players: list-only, full-only, mixed pages, duplicate cards, episode
   switching, seeking, speed, pause/resume, natural end; repeated editor
   rerenders → exactly one action per click.
9. Chapters: episode A chapter while idle and while B plays.
10. Presets: apply → customize → save (identity kept); export customized
    design → import on clean install → identical rendering.
11. Two distinct client designs via controls + global styles + presets.
12. Mobile/keyboard/screen-reader/zoom/touch; failed-media retry.
13. Custom restricted role: UI + direct-request authorization.
14. RSS transport: public HEAD + byte-range on the real host.

## 4. Browser/device tests — NOT RUN (environment)

No browser automation was used for this plugin in this pass.

## 5. External feed validation — NOT RUN

No feed was submitted to any directory (not authorized, correctly).

## Verdict

The 18 findings are repaired at the source level and statically
verified. The plugin is **not** declared production-ready: the runtime
acceptance matrix above must pass on a real WordPress + Elementor
install first.
