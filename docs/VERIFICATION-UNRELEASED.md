# Unreleased verification

Date: 2026-09-30. Runtime checks used the official WordPress Docker image
with PHP 8.3.35 (WordPress 7.1.2), MariaDB 11.4, and Elementor 4.3.3. The
WordPress database and media used disposable local smoke-test fixtures.

## Passed

- All plugin PHP files pass `php -l` under PHP 8.3.
- The plugin activates in WordPress without a plugin fatal error.
- Elementor loads and registers all 11 EPM widgets.
- Administrators and editors can open the dashboard and edit episodes with
  the default capability policy. A `manage_options` override on
  `epm_cap_manage_episodes` restricts episode editing to administrators
  without changing an editor's general `edit_posts` capability.
- Dashboard HTML renders for both roles. Missing-settings links are present
  for administrators and absent for editors without settings access.
- `[podcast_player]` and `[podcast_episodes]` render expected markup and queue
  the shared player script. `[podcast_latest_cta]` renders a link and queues
  the stylesheet without the player script.
- The plain-permalink RSS endpoint returns HTTP 200. Its output parses as XML
  and includes one fixture episode with a valid MP3 URL, byte length, and
  `audio/mpeg` enclosure type.
- `node --check` passes for the admin, episode-select, and player scripts;
  `git diff --check` passes.

## Still requires browser or hosted-site verification

The local checks do not exercise Elementor editor save/reopen flows, browser
playback, multi-player synchronization, mobile/touch behavior, screen-reader
announcements, third-party theme interactions, Elementor Pro Theme Builder,
or feed transport behavior on a public host. Continue with `TEST-PLAN.md`
before treating those paths as production-verified.
