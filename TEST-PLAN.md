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
> Still manual: duplicate-listener checks (F4 #1, #3–4), natural end
> (F7 #11), chapters of a second episode (F8 #12), asset loading per page
> type (F14), screen readers, error/retry and fallback (F15 #21–24) and
> touch input (F15 #25).
>
> The list stays useful for manual checks on real devices and screen
> readers, which the automated suites do not replace.

## F4 — Idempotent initialization

1. Load a page with a Podcast Player. In devtools, run the equivalent of
   `init(document)` three times (e.g. re-dispatch the init path via the
   Elementor hook). Click play once → audio must start exactly once;
   `document.querySelectorAll('[data-epm-player]')` — each root must carry
   `data-epm-initialized="1"` exactly once and have exactly one click
   listener on its play button (verify via getEventListeners in Chrome).
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
   `aria-pressed="true"`, label "Pause {title}").
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

20. Keyboard: focus the timeline slider → ArrowLeft/Right seek ∓5s,
    Home/End jump to start/end; `aria-valuenow` and the time text update on
    every change; the visible handle (`[data-epm-handle]`) moves with
    playback and seeking.
21. Screen reader: play/pause toggles announce via updated `aria-label`;
    speed change announces "Playback speed: 1.5×" via the polite live
    region; card buttons expose `aria-pressed` and correct labels.
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

## Known scope notes for the verifier

- Text-only widgets enqueue only the stylesheet (`Assets::enqueue_style()`);
  shortcodes and the automatic episode page use `Assets::enqueue()`.
- Seeking requires HTTP Range support on the host (Apache/nginx provide it;
  PHP's built-in server does not — the test router adds it).
