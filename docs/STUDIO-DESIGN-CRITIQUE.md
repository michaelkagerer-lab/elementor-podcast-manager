# Studio dashboard review — rc.3

Scope: dashboard entry, episode discovery, latest-episode listening, publishing action, feed/hosting/distribution summaries. PHP/jQuery/WordPress, with existing frontend styling preserved. Project guidance consulted: AGENTS.md, DESIGN.md, CLAUDE.md, project-design and design-learnings. The user's explicit redesign instruction overrides the older native-admin visual preference. The episode editor and every settings screen have not received this same visual redesign.

## Findings and repairs

| Severity | Domain | Location | Before | Repair | Why |
|---|---|---|---|---|---|
| Medium | Layout | `admin/views/dashboard.php` show actions | Disconnected top-level add button and several equally weighted controls | One contextual primary action beside the show; settings as secondary links | Make the next task recognisable without scanning a button cluster |
| Medium | Layout | `admin/views/partials/editorial-queue.php` empty state | Empty-draft message displaced an existing published library | Show that message only when there is no work or published shelf | Existing content is more useful than describing absent content |
| Medium | Layout | `admin/css/epm-studio.css` published shelf | Metadata rows with tiny artwork looked like a CMS table | Six bounded artwork-led entries; compact horizontal entries on narrow screens | Content recognition and appropriate density for available space |
| Medium | Writing | `admin/views/dashboard.php` next action | Healthy show pushed directory submission above publishing | Directory actions stay in distribution; errors/setup still take priority | Match the returning publisher's task |
| Medium | Layout | `admin/css/epm-studio.css` container queries | 200% zoom technically fitted but squeezed text | Switch columns based on available component width | An overflow assertion alone is not usability proof |
| Medium | UI | `admin/views/dashboard.php` preview | Unloaded native control read 0:00 / 0:00 alongside a known duration | Explicit Play episode; native controls appear once playback begins | Avoid a misleading first impression while preserving familiar playback controls |
| Medium | Accessibility | `admin/js/epm-admin-ui.js` preview | No explicit loading/retry flow for new preview | Loading feedback, busy guard, persistent error, Retry and conditional focus handoff | Recovery must work and delayed playback must not steal focus |
| Low | UI | `admin/views/partials/editorial-queue.php` artwork | Episode with no image broke the cover-grid rhythm | Use the show's artwork as fallback | Missing media needs an intentional presentation |
| Low | Writing | `admin/views/dashboard.php` readiness | Technical count repeated the passed-check disclosure | Remove redundant count | Reduce duplication while preserving every check and warning |

## Coverage

| Domain | Evidence | Result |
|---|---|---|
| Accessibility | Scoped axe at 320, 390, 834 and 1440; keyboard play, focus, create episode, error/retry; reduced-motion CSS | Automated and keyboard checks pass; VoiceOver/NVDA not verified |
| Layout | Rendered dashboard at 320x568, 390x844, 834x900, 1440x1000, 844x390 and 200% CSS zoom | Repaired crowding and mobile typography; actual browser zoom and RTL not separately verified |
| Writing | Dashboard templates, German catalog, translated long draft and contributor state | Labels and outcomes retained; private live distribution records are not known |
| Typography | System stack, rendered long German titles, responsive shelf and heading | No clipping in inspected contexts |
| Colors | Scoped axe text contrast; explicit focus ring and non-color status labels | No axe violations; forced-colors behavior not manually verified |
| UI | Real covers, fallback show cover, playback before/after loading, blocked audio and retry | Functional preview and stable surfaces; browser-native playback appearance differs across engines |

## Verification and boundaries

- Elementor 4.3.3 is active on the local WordPress 7.1.2 site. Existing frontend/Elementor browser suite and the detailed player suite pass.
- `studio-preview.mjs`: bounded shelf, one primary action, no preload, keyboard playback, blocked audio recovery, scoped axe, responsive overflow, 200% CSS zoom and create-episode navigation pass.
- `editorial-workspace.mjs`: search, status navigation, responsive layout, accessibility, keyboard Undo and preservation of later edits pass.
- `editorial-states.mjs`: contributor draft isolation, translated publishing/library labels, long German titles and Undo pass. The selector was corrected to distinguish public episodes from private draft queues; public entries are intentionally visible to contributors.
- 574 integration assertions across core/admin/analytics/editorial preservation passed locally. The final upgrade preservation test also passed.
- Seven public BUSINESS TUNING episodes imported locally. GUID and enclosure URL/type/length match the original feed. Real frontend playback, seek and pause passed. This does not establish every minute of every audio file or the private live settings.
- The 320px core WordPress update notice has a known false-positive overlap finding: the bounding box of a wrapping inline link intersects the adjacent link's box; its actual text fragments remain separate.
- Third-party images are deliberately blocked by the hermetic German-state suite. Public-show visual captures permit the real artwork; blocked-image captures do not prove host availability.

Verdict: approve the inspected dashboard candidate for local review, not production acceptance or a claim that the whole plugin meets an Apple-level design bar. No live changes. Private production configuration, Safari/Firefox, assistive-technology walkthroughs and production acceptance remain unverified.
