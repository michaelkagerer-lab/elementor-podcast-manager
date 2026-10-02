# TEST-PLAN — Player engine (manual scenarios)

> **Status (1.2.0):** most scenarios below are now automated and run in CI:
> `tests/e2e/run.mjs` drives Chromium against a real WordPress + Elementor
> site (see `tests/README.md` and `docs/VERIFICATION-1.2.0.md`). Covered:
> - initialization after an Elementor re-render (F4 #2)
> - shared state between cards and players, and pause-others (F7 #5–7)
> - the sticky bar: shows the active episode, speed, pause, close (F7 #8–9)
> - chapter seeking and highlighting (F8 #13–14)
> - keyboard seeking and aria-valuenow updates (F15 #20)
> - the narrow-width layout (F15 #26)
>
> `tests/e2e/player.mjs` (Unreleased) adds: initialization through
> Elementor's hooks alone and together with the MutationObserver in the
> real editor (insert, switch episode, duplicate, delete, undo/redo,
> re-renders), one listener per control and one click = one playback
> (F4 #1–4), cloned DOM (Swiper loop copies), source changes and
> re-renders (F17), volume and touch targets (F18), speed, resume and
> timestamp links, Media Session position, slider keys (F15 #20), and
> the sticky bar (F19).
>
> Still manual: natural end (F7 #11), chapters of a second episode
> (F8 #12), asset loading per page type (F14), screen readers, the
> fallback (F15 #24), and everything in F17–F19 marked *device*.
>
> The list stays useful for manual checks on real devices and screen
> readers, which the automated suites do not replace.

Scope: `assets/js/epm-player.js`, `assets/css/epm-frontend.css`,
`includes/Assets.php` (early registration + localization),
`get_script_depends()` / `get_style_depends()` on the 12 widgets.

## F4 — Idempotent initialization

1. Load a page with a Podcast Player. In devtools, run
   `epmPlayerEngine.init(document)` three times and Elementor's
   `elementorFrontend.elementsHandler.runReadyTrigger(widget)` for the
   widget. Click play once → audio must start exactly once; each play
   button has exactly one click listener (getEventListeners in Chrome).
   `data-epm-initialized="1"` is only a debugging marker: bindings are
   kept in memory, so a copied element (a carousel's loop slide) carries
   the attribute but is bound on its own.
2. In the Elementor editor, add a Podcast Player widget, then edit any
   control so the widget re-renders 3×. Click play → exactly one action per
   click (no double-play, no overlapping audio).
3. Add two Podcast Player widgets for the same episode. Both play buttons
   must toggle the same audio; both timelines must move together.
4. Remove a widget in the editor (delete), then trigger controller events
   (play/pause on another widget). No console errors; detached views must
   not receive updates (views prune via `isConnected`).

## F7 — Unified playback (one controller per episode)

5. Page with an Episode List (cards) + a full Podcast Player for the same
   episode. Click the card's Play → card button shows pause state; open the
   full player → its timeline advances and its play button shows pause.
   Both represent one audio element.
6. Duplicate cards for the same episode in one list. Click card A → card A
   AND card B both switch to pause state/label (class `is-playing`,
   label "Pause {title}"; no `aria-pressed`, the label is the state).
7. Play episode A (card), then play episode B (full player). A pauses and
   keeps its position; B starts. Return to A → resumes from kept position,
   not from 0.
8. With sticky enabled: start playback from a card button (no full player
   on page). Sticky bar appears, shows the episode title, progress moves,
   time labels update. Click sticky speed button → cycles 1× → 1.25× →
   1.5× → 2× → 0.75× with no console error; audio rate actually changes.
9. Sticky close (×) → pauses and hides. Click play on any button again →
   sticky reappears bound to the new active episode.
10. Seek via sticky timeline (click + arrow keys) → the underlying audio
    seeks; the originating card/full player (if any) reflects the new
    position.
11. Natural completion (`ended`): all bound UIs return to play state;
    position stays at end.

## F8 — Chapters bound to their episode

12. Standalone Chapters widget for episode A on a page; start playing
    episode B via a card. Click a chapter of A → episode B pauses, episode
    A seeks to the chapter timestamp and starts playing. A's player/card
    UI (if present) reflects playback; B's UI shows paused.
13. Click a chapter of episode A while nothing is playing → A seeks and
    starts (lazy controller creation from the chapter container's
    `data-epm-src`).
14. Chapters rendered inside a full player's `<details>`: clicking seeks
    that player's episode (same controller), not any other active episode.

## F14 — Asset lifecycle

15. Frontend page with only text widgets (Transcript, Guest): verify
    `epm-player.js` is NOT loaded, `epm-frontend.css` IS loaded.
16. Page with a Podcast Player widget: both load; no unstyled flash of the
    player (CSS present before first paint where Elementor ordering allows).
17. Shortcode-only page (`[podcast_player]`, no Elementor): player works via
    the `Assets::enqueue()` + late footer path.
18. Elementor editor: insert the first Podcast Player into an empty preview
    → widget renders and initializes without a full page reload.
19. Page with no podcast components: neither asset loads.

## F15 — Accessibility and error states

20. Keyboard: focus the timeline slider → ArrowLeft/ArrowDown −5 s,
    ArrowRight/ArrowUp +5 s (the page does not scroll), Home/End jump to
    start/end; `aria-valuenow` and the time text update on every change;
    the visible handle (`[data-epm-handle]`) moves with playback and
    seeking.
21. Screen reader: play/pause toggles announce via updated `aria-label`;
    speed change announces "Playback speed: 1.5×" via the polite live
    region (once per change); card buttons are named "Play/Pause/Retry
    {title}" without `aria-pressed`; the volume slider reads "70%".
22. Simulate a media error (point `data-epm-src` at a 404): an inline error
    message with a Retry button appears (`role="alert"`); Retry re-attempts
    playback.
23. Block `audio.play()` (e.g. via devtools override to reject): no
    unhandled promise rejection in the console; error UI appears.
24. Force a JS init exception (e.g. temporarily break a data attribute):
    the native `<audio controls>` fallback becomes visible and playable
    (`.epm-player--fallback`).
25. Touch: all play buttons meet the 44px minimum; timeline drag works with
    touch pointer events; `pointercancel` ends the drag cleanly.
26. Zoom to 200% and 360px width: player stacks without overlap; sticky bar
    remains operable.

## F16 — Admin capabilities and guided setup

27. With default capability filters, an administrator and an editor can open
    Podcast → Dashboard, Episodes and Add episode; both can create and edit an
    episode. A role without `edit_posts` cannot access episode management.
28. Configure `epm_cap_manage_episodes` to a dedicated primitive capability:
    only users granted that capability can manage episodes, while normal
    WordPress `edit_posts` checks remain unaffected.
29. With missing podcast metadata, readiness links take podcast managers to
    the matching field. Episode editors without settings access see actionable
    check text without links to inaccessible settings.
30. `[podcast_latest_cta]` renders a link and enqueues the stylesheet without
    enqueuing `epm-player`; player and episode-list shortcodes enqueue it.

## F17 — Source changes and re-renders

31. Elementor editor: play a Podcast Player, change any of its controls →
    playback continues and the re-rendered widget shows it.
32. Replace the episode's audio file in WordPress while the editor is
    open, then change a control of the widget → the next play uses the
    new file; title and duration follow; the sticky bar and the lock
    screen show the new title.
33. Break the episode's file (404), press play (error), fix it and
    re-render → play works and every view (player, cards, sticky bar)
    leaves the error state.
34. Delete every widget of a playing episode on a page without a sticky
    bar → playback stops; with the sticky bar open → it keeps playing in
    the bar.
35. *Device / Elementor Pro:* a Loop Carousel with looping, and a Popup
    with a player closed and reopened → every copy plays, one click = one
    playback.

## F18 — Volume and touch

36. Two players of one episode: move one volume slider (keyboard,
    pointer) → the other follows; a screen reader reads "70%".
37. Mute the audio from the browser (or set it from devtools) → both
    sliders show 0; moving a slider unmutes.
38. *Device:* on an iPhone or iPad the volume slider is hidden and the
    device buttons set the volume.
39. *Device:* on a phone, the seek and volume sliders are easy to hit
    (28px touch band), dragging the timeline does not scroll the page.

## F19 — Sticky bar

40. A player with *Enable Sticky Player* off next to an episode list:
    playing the player leaves the bar closed; playing from the list opens
    it; starting the player again closes it.
41. Turn *Enable Sticky Player* on in the Elementor editor on a page
    without any sticky player → playing shows the bar in the preview.
42. *Device:* landscape iPhone with a theme using `viewport-fit=cover`:
    the bar's artwork and close button stay clear of the notch and the
    rounded corners; the bottom stays above the home indicator.

## D1 — Details shown by default (design package)

Automated in `tests/integration/design.php`, `widgets.php` and
`tests/e2e/design.mjs`, `widgets.mjs`. Manual checks on a copy of a
real site:

1. Update a 1.3.0 site without touching anything: episode pages, widget
   pages and shortcode pages show the same details and layouts as before.
2. A site that applied a preset in 1.1–1.3: Podcast → Design shows
   *Suggested details*; the frontend is unchanged until *Apply
   suggestions*, which lists each change first.
3. Apply *Minimal*: new Player widgets and shortcodes without `show_*`
   lose artwork; a widget set to *Show* keeps it; the automatic episode
   page keeps the full player.
4. Open a 1.3.0 page in Elementor: every *Show …* shows Show or Hide (not
   Default); *Use Podcast → Design defaults* switches them to Default.
5. Lists in a 320 px column, a sidebar and a row container: titles
   readable, no horizontal scroll; two paginated lists page separately.

## Asset lifecycle notes for the verifier

- Text-only Elementor widgets call `\EPM\Assets::enqueue_style()` and
  declare only the stylesheet dependency. Player/list/latest/chapters
  widgets declare the player script dependency.
- `[podcast_latest_cta]` renders only a link and loads the stylesheet without
  the player script. Player and episode-list shortcodes still load the shared
  playback engine.
- The automatic episode page loads the player like the shortcodes do.
- Seeking requires HTTP Range support on the host (Apache/nginx provide it;
  PHP's built-in server does not — the test router adds it).
- The renderer markup is shared by widgets and shortcodes; changes to its
  playback contract should be verified against the scenarios above.
