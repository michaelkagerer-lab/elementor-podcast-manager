## 2026-10-02 network lifecycle and uninstall

On a separate marked WordPress 7.1.2 / PHP 8.3.35 / MariaDB 11.4 network:

- Network lifecycle: 17 baseline failures; 42 assertions pass after repair,
  covering existing/new sites, deactivation schedules (including upgrade
  work), keep/delete uninstall, all episode statuses and retained media.
- Uninstall: the old loop exceeded 35 seconds for 5,000 episodes. Bounded
  SQL deletion finishes in 0.55 seconds with 686 queries; 14 assertions pass,
  including revisions/comments/metadata, attachment preservation and
  detachment, settings retained on database failure, and successful retry.
  Timing was measured through WP-CLI; php-fpm timing remains unverified.
- The single-site run and hosting suites remain green (186 / 1178 assertions).
- Network tests refuse unmarked sites and SQLite. CI runs them last on its
  disposable MariaDB site with three-minute and one-minute step limits.

Bulk episode deletion runs only with the existing explicit data-delete opt-in.
It directly removes plugin episodes, revisions, comments, metadata and term
relationships, clears relevant caches and recounts affected terms. It does not
invoke per-post deletion hooks from other plugins.

## 2026-10-01 additional audit repairs

Local verification on the same marked disposable SQLite site:

- All nine PHP suites pass: run 186, admin 338, design 228, feed 147,
  frontend 217, hosting 1178, import 92, media 165, widgets 131 assertions.
- Player, Setup, Frontend, audio-dialog focus, sticky focus, setup resumption,
  and translated-label layout browser suites pass. The previously flaky
  Design suite passed twice after selecting the live Elementor model.
- Newly reproduced regressions cover HTTPS redirect downgrades, explicit
  remote-file size errors, legacy credentialed feed metadata exposed through
  anonymous REST, catalog-wide transcript metadata loading during sync,
  lost audio-picker focus, setup progress/dirty edits, sticky focus overlap,
  and German-length actions overflowing five admin screens.
- Paged automatic sync explicitly reports its first-page scope and keeps
  episodes from unexamined pages. A full re-import checks older pages.
- Headless imports receive an existing author; updating them retains local
  attribution. The test fails with the old current-user assignment.
- Markdown and JSON findings statuses agree; historical reproduction
  evidence is retained. Translation, the minimum-version/browser CI matrix,
  multisite, remaining targeted verification and named performance/download
  leftovers remain open. This is not an all-findings-closed declaration.

Hosted run 36905182422 completed: seven jobs passed; Browser failed at the
legacy-widget selection now repaired locally. No hosted workflow is currently
running. No merge, release, deployment or production operation was performed.

# Unreleased verification

## 2026-10-01 audit follow-up

The bounded hosted run 36905182422 finished in 17m14s: all four PHP lint
checks, both PHP integration jobs and MariaDB passed. Its browser job failed
only while opening the legacy widget's editor controls. The test now selects
the widget, explicitly opens Content and waits for the correct panel/model.
The complete Design browser suite passed twice locally after that change.

New regression tests reproduced and then verified fixes for UX-N1, UX-N3,
UX-N4, UX-N13, UX-N15, SEC-N5, SEC-N10, LIFE-N3, QA-N2 and QA-N4. The
Frontend browser suite passes, including the WordPress handshake and
script-disabled embed; the Copy suite passes on all three admin screens.
Package tests produce identical ZIP bytes twice, verify the checksum and
runtime-only contents, and require LICENSE and NOTICE.

PHP checks pass locally: run 186, admin 337, design 228, feed 147,
frontend 217, hosting 1155, import 92, media 165, widgets 131 assertions.
An existing REST test was updated to expect 400 for an invalid file type;
unreadable media still returns 403. The corrected Admin suite passes.
PHP/JavaScript syntax checks pass. Remaining audit items and the hosted
checks for this follow-up are still open; no merge or release is authorized.

## 2026-10-01 CI regression repair

Verified on a marked disposable SQLite site with WordPress 7.1.2,
Elementor 4.3.3, PHP 8.3.35, and Playwright 1.63.0:

- PHP and JavaScript syntax checks and `git diff --check` pass.
- All nine PHP integration suites pass: run 182, admin 333, design 221,
  feed 147, frontend 212, hosting 1154, import 92, media 165, widgets 131
  assertions, with no failed assertions.
- All eight browser suites pass: run, admin, design, frontend, player,
  setup, style-audit, widgets. The Design test now waits for a newly
  created Elementor widget to render before opening its controls.
- Design forms emit distinct nonce fields, and preset/detail saves and
  export/import complete successfully. The duplicate-ID assertion no
  longer mistakes `data-epm-episode-id` attributes for HTML IDs.
- Credentialed feed identifiers survive metadata sanitization without
  storing secrets; moves retain episode IDs, and missing-episode checks
  find the hashed feed identifiers.
- Numbered episode rows retain usable title widths at 320 and 390 pixels
  and inside narrow Elementor columns.

Workflow 36889162480 was canceled after its Playwright installation
stalled for over an hour. Job limits are now 5 minutes for lint,
15 minutes for PHP integration and MariaDB, and 30 minutes for browser
checks; Playwright installation has a separate 5-minute limit.
Playwright is pinned and installed using the committed lockfile.

The final hosted matrix remains to be checked after this patch is pushed.
The findings register still contains open audit items; these passing checks
are evidence for the paths listed above, not a claim that every 1.4.0
finding is closed. No merge, release, deployment, or production operation
was performed.

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
