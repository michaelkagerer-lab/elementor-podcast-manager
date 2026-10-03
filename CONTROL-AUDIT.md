# Control Audit — Elementor Podcast Manager

Every control of the twelve Elementor widgets: what it changes and which
test proves it. The style table below is **generated** by a browser
harness and checked in CI; the content tables are maintained by hand and
backed by the integration and browser suites named in them.

## How this is verified

| What | Harness | Runs in CI |
|---|---|---|
| Every style control changes a computed style of its widget (hover controls while hovering their target) | `tests/e2e/style-audit.mjs` builds one Elementor page per widget: a baseline instance (Style Source "Custom", nothing set) and one instance per control or group control with a distinctive value, then compares the computed styles of every element. A control without effect fails; a table that differs from this file fails ("regenerate with `EPM_WRITE_AUDIT=1`"). | yes (`tests/run-all.sh`, browser job) |
| Details (show/hide) of Podcast Player, Latest Episode, Episode List | `tests/integration/design.php` (inheritance, legacy widgets, presets), `tests/e2e/design.mjs` (real editor) | yes |
| Layout controls store explicit choices | `tests/integration/design.php`, `tests/e2e/design.mjs` (editor save, preset change) | yes |
| Pagination, order by number, CTA hints, separator, assets | `tests/integration/widgets.php`, `tests/e2e/widgets.mjs`, `tests/http/run.sh` | yes |
| Registration (no duplicate IDs, no `_doing_it_wrong`) | `tests/integration/run.php` | yes |

Not verified here: Elementor Pro Theme Builder templates, real Safari and
screen readers (see the verification report).

## Content controls

### Details: Default / Show / Hide

Podcast Player, Latest Episode and Episode List show their details
(artwork, label, title, guest, date, … and the player controls) as
three-way selects. **Default** (`''`, the control default, never stored)
follows *Podcast → Design → Details shown by default* for the widget's
context (Player, Latest episode, Episode lists); **Show** (`yes`) and
**Hide** (`no`) are stored and win. The Default option names what the
site shows now ("Default (shown)"). *Use Podcast → Design defaults* (a
button above the details) sets every detail of the widget back to
Default in one undoable step (`admin/js/epm-elementor-editor.js`).

New and re-saved widgets carry a hidden `epm_schema = '2'` (always
stored). Widgets saved before the details existed have no marker: their
stored `yes`/`''` stay Show/Hide and a missing detail keeps the value
1.3.0 showed, so a later change of the site's details never changes
them. The editor loads such a widget with those values made explicit
(`get_raw_data()`), which renders the same.

| Widget | Details (context) | Renderer | Test |
|---|---|---|---|
| Podcast Player | artwork, episode label, number and season in the label, title, guest, description, date, duration, speed, skip back, skip forward, volume, download, share, chapters toggle, platform links (Player) | `Details::resolve( 'player', … )` → `Renderer::player()` | design.php, design.mjs |
| Latest Episode | the same (Latest episode); with the player off the card shows artwork, title, guest, date, duration, description | `Details::resolve( 'latest', … )` | design.php |
| Episode List | artwork (cards/grid), episode number, title, guest, excerpt, date, duration, play button, topics (Episode lists) | `Details::resolve( 'list', … )` → `Renderer::episode_list()` | design.php |

### Other content controls

| Widget · control | Behavior | Test |
|---|---|---|
| Player, Latest, List · Layout | "Default (Podcast → Design: …)" (`''`) is the control default, so every other choice is stored, also one equal to the current Design layout | design.php, design.mjs (real editor, preset change) |
| Player · Enable Sticky Player; Latest · Enable Sticky Player (new, off by default) | `data-epm-sticky-player`: playback started here opens the sticky bar | widgets.php, widgets.mjs, player.mjs |
| Player · Episode Label | description says what the label holds (number, season, Bonus/Trailer; empty for a regular episode without them) | widgets.php |
| Latest · Artwork / Description | notes for the layouts that hide them | widgets.php |
| Latest, Hero · Call to Action | renders once it has text and link; without a link the editor shows a hint | widgets.php |
| Episode List · Pagination | the first paginated list of a page uses `/page/N/`; every further list its own `?epm-page-<id>=N` | widgets.mjs |
| Episode List · Order By "Episode Number" | episodes without a number stay in the list, after the numbered ones (by date) | widgets.php |
| Episode Metadata · Separator | printed exactly as typed, spaces included | widgets.php |
| Source / Choose Episode (all episode widgets) | current, specific, latest | run.php, run.mjs |

## Style controls (generated)

Changes in this release (WID-N6):

- `artwork_radius`, `list_artwork_radius` and the guest photo's circle
  had no effect inside Elementor (`.elementor img { border-radius: 0 }`
  won); the image rules now carry their container's class (0,2,0).
- `list_background` set a variable nothing used: it now paints the list
  with inner padding (`--epm-list-background`, `--epm-list-padding`).
- `subscribe_text` only reached the hover color: links now use it
  (`--epm-subscribe-color`).
- `list_accent` is labelled "Hover and Playing Color", `video_accent` and
  `video_on_accent` "Play Button Hover Color" and "Play Icon Hover
  Color": that is all they color (the resting video button is neutral
  glass so it reads on any artwork).
- `header_accent`, `show_notes_muted` and `transcript_muted` colored
  nothing (no accent or muted text in those widgets): removed from the
  panel. Stored values are ignored, as before.

Measured with Chromium, hello-elementor and the seeded fixtures; "First
element changed" is the first podcast element whose computed style
differs from the baseline instance.

<!-- style-audit:start -->
| Widget | Control | Label | State | Result | First element changed |
|---|---|---|---|---|---|
| Podcast Player | `container_background` | Container → Background | rest | works | `.epm-player` |
| Podcast Player | `container_radius` | Container → Border Radius | rest | works | `.epm-player` |
| Podcast Player | `container_padding` | Container → Padding | rest | works | `.epm-player-frame` |
| Podcast Player | `container_gap` | Container → Gap | rest | works | `.epm-player-frame` |
| Podcast Player | `artwork_size` | Artwork → Size | rest | works | `.epm-player__artwork` |
| Podcast Player | `artwork_radius` | Artwork → Border Radius | rest | works | `.epm-artwork` |
| Podcast Player | `label_color` | Label → Color | rest | works | `.epm-player__label` |
| Podcast Player | `title_color` | Title → Color | rest | works | `.epm-player__title` |
| Podcast Player | `meta_color` | Metadata → Color | rest | works | `.epm-meta` |
| Podcast Player | `play_button_size` | Play Button → Size | rest | works | `.epm-player-frame` |
| Podcast Player | `play_button_radius` | Play Button → Border Radius | rest | works | `.epm-player__play` |
| Podcast Player | `play_button_background` | Play Button → Background | rest | works | `.epm-player__play` |
| Podcast Player | `play_button_color` | Play Button → Icon Color | rest | works | `.epm-player__play` |
| Podcast Player | `play_button_background_hover` | Play Button → Background | hover | works | `.epm-player__play` |
| Podcast Player | `play_button_color_hover` | Play Button → Icon Color | hover | works | `.epm-player__play` |
| Podcast Player | `timeline_track_color` | Timeline → Track Color | rest | works | `.epm-player__track` |
| Podcast Player | `timeline_played_color` | Timeline → Played Color | rest | works | `.epm-player__progress` |
| Podcast Player | `timeline_height` | Timeline → Height | rest | works | `.epm-player__track` |
| Podcast Player | `time_color` | Time & Secondary Controls → Time Color | rest | works | `.epm-player__times` |
| Podcast Player | `secondary_icon_size` | Time & Secondary Controls → Secondary Icon Size | rest | works | `.epm-player-frame` |
| Podcast Player | `secondary_button_shape` | Time & Secondary Controls → Button Shape | rest | works | `.epm-player__speed` |
| Podcast Player | `secondary_color` | Time & Secondary Controls → Secondary Color | rest | works | `.epm-player__speed` |
| Podcast Player | `secondary_color_hover` | Time & Secondary Controls → Secondary Hover Color | hover | works | `.epm-player__speed` |
| Podcast Player | `container_border (group)` | Container → Border Type | rest | works | `.epm-player-frame` |
| Podcast Player | `container_shadow (group)` | Container → Box Shadow | rest | works | `.epm-player` |
| Podcast Player | `label_typography (group)` | Label → Typography | rest | works | `.epm-player-frame` |
| Podcast Player | `title_typography (group)` | Title → Typography | rest | works | `.epm-player-frame` |
| Podcast Player | `meta_typography (group)` | Metadata → Typography | rest | works | `.epm-player-frame` |
| Podcast Player | `play_button_border (group)` | Play Button → Border Type | rest | works | `.epm-player__play` |
| Podcast Player | `time_typography (group)` | Time & Secondary Controls → Typography | rest | works | `.epm-player-frame` |
| Episode List | `list_background` | List → Background | rest | works | `.epm-episode-list` |
| Episode List | `list_text` | List → Text Color | rest | works | `.epm-episode-card` |
| Episode List | `list_muted` | List → Muted Text Color | rest | works | `.epm-meta` |
| Episode List | `list_accent` | List → Hover and Playing Color | hover | works | `.epm-episode-card__play` |
| Episode List | `list_border` | List → Border Color | rest | works | `.epm-episode-card` |
| Episode List | `list_radius` | List → Border Radius | rest | works | `.epm-episode-list` |
| Episode List | `list_gap` | List → Gap | rest | works | `.epm-episode-list` |
| Episode List | `list_artwork_radius` | List → Card Artwork Radius | rest | works | `.epm-artwork` |
| Episode List | `list_button_shape` | List → Button Shape | rest | works | `.epm-episode-card__play` |
| Episode List | `list_title_typography (group)` | Typography → Title | rest | works | `.epm-episode-card__title` |
| Episode List | `list_meta_typography (group)` | Typography → Metadata | rest | works | `.epm-meta` |
| Episode List | `list_item_border (group)` | Typography → Border Type | rest | works | `.epm-episode-list` |
| Latest Episode | `latest_background` | Latest Episode → Background | rest | works | `.epm-latest` |
| Latest Episode | `latest_text` | Latest Episode → Text Color | rest | works | `.epm-player` |
| Latest Episode | `latest_muted` | Latest Episode → Muted Text Color | rest | works | `.epm-meta` |
| Latest Episode | `latest_accent` | Latest Episode → Accent Color | rest | works | `.epm-player__play` |
| Latest Episode | `latest_button_shape` | Latest Episode → Button Shape | rest | works | `.epm-player__speed` |
| Latest Episode | `latest_title_typography (group)` | Latest Episode → Title | rest | works | `.epm-latest` |
| Latest Episode | `latest_meta_typography (group)` | Latest Episode → Metadata | rest | works | `.epm-latest` |
| Podcast Hero | `hero_background` | Hero → Background | rest | works | `.epm-podcast-hero` |
| Podcast Hero | `hero_text` | Hero → Text Color | rest | works | `.epm-podcast-hero` |
| Podcast Hero | `hero_muted` | Hero → Muted Text Color | rest | works | `.epm-podcast-hero__description` |
| Podcast Hero | `hero_accent` | Hero → Accent Color | rest | works | `.epm-podcast-hero__cta` |
| Podcast Hero | `hero_gap` | Hero → Gap | rest | works | `.epm-podcast-hero` |
| Podcast Hero | `hero_button_shape` | Hero → Button Shape | rest | works | `.epm-podcast-hero__cta` |
| Podcast Hero | `hero_title_typography (group)` | Hero → Title | rest | works | `.epm-podcast-hero__content` |
| Podcast Hero | `hero_description_typography (group)` | Hero → Description | rest | works | `.epm-podcast-hero__content` |
| Podcast Episode Header | `header_text` | Header → Text Color | rest | works | `.epm-episode-header` |
| Podcast Episode Header | `header_muted` | Header → Muted Text Color | rest | works | `.epm-episode-header__label` |
| Podcast Episode Header | `header_label_typography (group)` | Header → Label | rest | works | `.epm-episode-header` |
| Podcast Episode Header | `header_title_typography (group)` | Header → Title | rest | works | `.epm-episode-header` |
| Podcast Episode Header | `header_meta_typography (group)` | Header → Metadata | rest | works | `.epm-episode-header` |
| Podcast Metadata | `metadata_color` | Metadata → Color | rest | works | `.epm-meta` |
| Podcast Metadata | `metadata_typography (group)` | Metadata → Typography | rest | works | `.epm-meta` |
| Podcast Guest | `guest_text` | Guest → Text Color | rest | works | `.epm-guest` |
| Podcast Guest | `guest_muted` | Guest → Muted Text Color | rest | works | `.epm-guest__details` |
| Podcast Guest | `guest_image_size` | Guest → Image Size | rest | works | `.epm-guest__image` |
| Podcast Guest | `guest_name_typography (group)` | Guest → Name | rest | works | `.epm-guest` |
| Podcast Guest | `guest_detail_typography (group)` | Guest → Role / Company | rest | works | `.epm-guest` |
| Podcast Subscribe Links | `subscribe_text` | Subscribe Links → Text Color | rest | works | `.epm-subscribe__link` |
| Podcast Subscribe Links | `subscribe_accent` | Subscribe Links → Hover Color | hover | works | `.epm-subscribe__link` |
| Podcast Subscribe Links | `subscribe_icon_size` | Subscribe Links → Icon Size | rest | works | `.epm-subscribe` |
| Podcast Subscribe Links | `subscribe_gap` | Subscribe Links → Gap | rest | works | `.epm-subscribe` |
| Podcast Subscribe Links | `subscribe_button_shape` | Subscribe Links → Button Shape | rest | works | `.epm-subscribe__link` |
| Podcast Subscribe Links | `subscribe_typography (group)` | Subscribe Links → Label | rest | works | `.epm-subscribe` |
| Podcast Transcript | `transcript_text` | Transcript → Text Color | rest | works | `.epm-transcript__heading` |
| Podcast Transcript | `transcript_heading_typography (group)` | Transcript → Heading | rest | works | `.epm-transcript` |
| Podcast Transcript | `transcript_content_typography (group)` | Transcript → Content | rest | works | `.epm-transcript` |
| Podcast Show Notes | `show_notes_text` | Show Notes → Text Color | rest | works | `.epm-show-notes` |
| Podcast Show Notes | `show_notes_heading_typography (group)` | Show Notes → Heading | rest | works | `.epm-show-notes` |
| Podcast Show Notes | `show_notes_content_typography (group)` | Show Notes → Content | rest | works | `.epm-show-notes` |
| Podcast Chapters | `chapters_text` | Chapters → Text Color | rest | works | `.epm-chapters__heading` |
| Podcast Chapters | `chapters_time` | Chapters → Timestamp Color | rest | works | `.epm-chapters__time` |
| Podcast Chapters | `chapters_heading_typography (group)` | Chapters → Heading | rest | works | `.epm-chapters` |
| Podcast Chapters | `chapters_list_typography (group)` | Chapters → Chapters | rest | works | `.epm-chapters` |
| Episode Video | `video_accent` | Video → Play Button Hover Color | hover | works | `.epm-video__button` |
| Episode Video | `video_on_accent` | Video → Play Icon Hover Color | hover | works | `.epm-video__button` |
| Episode Video | `video_radius` | Video → Border Radius | rest | works | `.epm-video` |
<!-- style-audit:end -->

## History

The sections below are earlier audits, kept for reference. Where they
disagree with the tables above, the tables above are current.

## 1.2.0 update (runtime-verified)

The control IDs are unchanged, so saved widget settings keep working. What changed:

- **Theme-proof buttons.** Plugin buttons are styled with `:not(#epm)` (ID-level specificity), so theme rules on bare `<button>`s (Hello Elementor's `button:focus`, Twenty Twenty-One's `.site button:not(:hover)…`) cannot override them. The Play Button controls set variables consumed by those rules:
  - `play_button_size` → `--epm-play-size`
  - `play_button_radius` → `--epm-play-radius`
  - `play_button_background` → `--epm-play-background`
  - `play_button_color` → `--epm-play-color`
  - `play_button_background_hover` → `--epm-play-background-hover`
  - `play_button_color_hover` → `--epm-play-color-hover`
  - `play_button_border` (group) targets `{{WRAPPER}} .epm-player__play:not(#epm)`
- **`secondary_color_hover`** sets `--epm-secondary-hover` on the player; the hover states of skip, speed and download consume it.
- **Duplicate control ID fixed.** The `container_border` group's own color field was `container_border_color`, the same ID as the Border Color token control. That field was silently dropped by Elementor; it is now excluded explicitly, and the token control sets the border color.
- **Style sections other than the one holding Style Source are hidden** unless Style Source is "Custom" (they contain only custom-mode controls).
- **Token precedence:** the stylesheet's fallback tokens use `:where(:root)` (specificity 0), so Global Podcast Styles win regardless of load order. Derived fallbacks (`--epm-subscribe-hover`, `--epm-secondary-color`) are no longer defined at `:root`, where they ignored widget-level overrides.
- **Runtime check:** `tests/integration/run.php` builds every widget's controls and fails on any `_doing_it_wrong` (e.g. duplicate IDs). `tests/e2e/run.mjs` verifies a custom play button color and size render on the frontend.

The tables below are the 1.1.0 audit, with the Play Button rows updated.

## 1.1.0 audit

Static audit of every Elementor style control: control → Elementor selector → CSS rule it targets → status. Generated 2026-09-30 during the F2/F11 token-architecture repair.

**Scope:** `assets/css/epm-frontend.css`, `includes/DesignSettings.php`, `includes/Presets.php`, widget style sections (selectors/descriptions/conditions only — no control logic changed; all control IDs preserved).

**Not verified here (requires a real WordPress + Elementor install):** computed styles in a browser, Elementor editor registration with debug logging, save/close/reopen round-trips, two-client-design visual check, Theme Builder preview, and the acceptance scenario from the product brief. Nothing below claims runtime verification.

## F2 — Token architecture (repaired)

- **Removed** the self-referential component-root block (`--epm-x: var(--epm-x, …)` on `.epm-player`, `.epm-episode-list`, etc.). It invalidated variables and shadowed wrapper-level overrides in nested components (cards in lists, players in latest-episode, chapters, guests, subscribe links all inherit cleanly now).
- **One `:root` definition site** in CSS (`assets/css/epm-frontend.css:29`, static neutral fallback) plus `DesignSettings::output_tokens()` (`<style id="epm-design-tokens">`). The output moved to **wp_head priority 20** (after `wp_print_styles` at 8) and **wp_footer priority 1** (before late-enqueued assets), so it always prints after stylesheets and wins over the static fallback — this was previously inverted (priority 5 printed *before* stylesheets, so the fallback would have won).
- **Every consumption** is `var(--epm-x, <literal fallback>)`; grep-verified: 23 consumed tokens, 0 missing from `:root`, 0 self-references.
- **Precedence documented** in the CSS header comment and the `DesignSettings` class docblock: 1) theme/Elementor Site Settings → 2) Global Podcast Styles (`:root` tokens) → 3) presets (applied *into* Global Podcast Styles) → 4) explicit widget overrides (`{{WRAPPER}}`-scoped vars).
- **Genuine inherit state:** verified every style control is gated by `custom_condition()` (Style Source = "Use Global Podcast Styles" emits zero token overrides). Tab-level conditions cover the play-button tab children.
- **`--epm-on-accent` token added:** `DesignSettings::defaults()` + `sanitize()` + `output_tokens()`; all 5 presets (`neutral/minimal/editorial/card` → `#ffffff`, `business-tuning` → `#07090a` for the lime accent). Used for `.epm-player__play`, hero/latest CTAs, pagination `.current`, and the error-retry button. **Follow-up for the main agent:** `admin/views/design.php` hardcodes its color field list — add an "On-accent color" field there so the token is editable in Podcast → Design (out of this task's file scope).
- **No hardcoded `font-family`** in CSS — typography fully inherits theme / Elementor Global Fonts. No hardcoded `#fff` remains.

## F11 — Control audit

Legend: **works** = selector hits a real rule that consumes the property · **fixed** = repaired in this pass · **restricted-with-note** = layout intentionally limits it, editor now says so · **new** = control added to implement a requested capability.

### Podcast Player (`epm-podcast-player`)

| Control | Selector | Target rule | Status |
|---|---|---|---|
| container_background | `{{WRAPPER}} .epm-player` → `--epm-background` | `background: var(--epm-background, #ffffff)` | works |
| container_border_color | → `--epm-border` | `border: 1px solid var(--epm-border, …)` | works |
| container_radius | → `--epm-radius` | `border-radius: var(--epm-radius, 12px)` | works |
| container_padding | → `padding:` | direct | works |
| container_gap | → `--epm-gap` | `gap` / `padding` calc()s | works |
| container_border / container_shadow (groups) | `.epm-player` | real element; border group excludes its color field (duplicate ID with container_border_color) | fixed (1.2.0) |
| artwork_size | → `--epm-artwork-size` | `.epm-player__artwork { width: var(--epm-artwork-size, 96px) }`; beats layout default by specificity | works |
| artwork_radius | → `--epm-artwork-radius` | consumed by artwork imgs | works |
| label_color / label_typography | `.epm-player__label` | real rules | works |
| title_color / title_typography | `.epm-player__title` | layout sizing wrapped in `:where()` so the widget override wins deterministically | fixed |
| meta_color / meta_typography | `.epm-player .epm-meta` | real rules | works |
| play_button_size | `.epm-player__play` → `--epm-play-size` | consumed by the theme-proof play rule; Compact sets the variable on the player root | fixed (1.2.0) |
| play_button_radius/background/color + hover | `.epm-player__play` → `--epm-play-radius/-background/-color(-hover)` | consumed by the theme-proof play rules | fixed (1.2.0) |
| play_button_border (group) | `.epm-player__play:not(#epm)` | matches the theme-proof rule's specificity | fixed (1.2.0) |
| timeline_track_color | `.epm-player__track` → background | real rule | works |
| timeline_played_color | `.epm-player__progress` → background | real rule | works |
| timeline_height | `{{WRAPPER}}` → `--epm-progress-height` | inherited by `.epm-player__track` | works |
| time_color / time_typography | `.epm-player__times` | real rules | works |
| secondary_icon_size | → `--epm-secondary-icon-size` (was `font-size`, ignored by fixed SVGs) | new `.epm-player__secondary svg` rule consumes it | fixed |
| secondary_color | → `--epm-secondary-color` (was `color`, overridden by children's own `color`) | skip/speed/download rules now consume the token, default = `--epm-text-muted` | fixed |
| secondary_color_hover | `.epm-player` → `--epm-secondary-hover` | consumed by skip/speed/download hover rules | fixed (1.2.0) |
| show_artwork | — | hidden by Minimal/Editorial layout CSS | restricted-with-note |
| show_description | — | hidden by Minimal/Compact layout CSS | restricted-with-note |
| show_playback_speed / show_volume / show_download | — | `.epm-player__secondary` hidden by Minimal/Compact layout CSS | restricted-with-note |

### Episode List (`epm-episode-list`)

| Control | Selector | Target rule | Status |
|---|---|---|---|
| show_artwork | — | renderer emits artwork only for cards/grid | restricted-with-note: label "Artwork (cards/grid only)" + `condition: layout ∈ [cards, grid]`; saved value preserved |
| show_episode_number | — | `.epm-episode-list--minimal .epm-episode-row__number { display:none }` | restricted-with-note |
| list_background/text/muted/accent/border | → tokens on `.epm-episode-list` | consumed by nested rows/cards via inheritance | works |
| list_radius | → `--epm-radius` | `.epm-episode-card { border-radius }` | works |
| list_gap | → `--epm-gap` | grid/row gaps | works |
| list_artwork_radius | → `--epm-card-artwork-radius` | `.epm-episode-card__artwork-img` | **new** (requested capability) |
| list_title_typography | card + row titles | real rules | works |
| list_meta_typography | `.epm-episode-list .epm-meta` | real rule | works |
| list_item_border (group) | cards + rows | real elements | works |

Note: excerpts follow `--epm-meta-size`; there is no separate excerpt typography control (documented, not added — changing `list_meta_typography` to include excerpts would alter existing saved designs).

### Latest Episode (`epm-latest-episode`)

| Control | Selector | Target rule | Status |
|---|---|---|---|
| latest_background | → `--epm-latest-background` (was `--epm-surface`, consumed by nothing) | new `.epm-latest` rule | fixed |
| latest_text/muted/accent | → tokens on `.epm-latest` | inherited by nested player/rows | works |
| latest_title_typography | `.epm-latest__title`, nested `.epm-player__title` | real rules | works |
| latest_meta_typography | `.epm-latest .epm-meta` | real rule | works |

### Podcast Hero (`epm-podcast-hero`)

| Control | Selector | Target rule | Status |
|---|---|---|---|
| hero_background | → `--epm-hero-background` (was `--epm-background`, consumed by nothing) | new `.epm-podcast-hero { background: var(--epm-hero-background, transparent) }` | fixed |
| hero_text/muted/accent | → tokens on `.epm-podcast-hero` | inherited by title/description/CTA | works |
| hero_gap | → `--epm-gap` | `gap: calc(var(--epm-gap, 24px) * 1.5)` | works |
| hero_title_typography / hero_description_typography | real classes | real rules | works |

### Episode Header / Metadata / Guest / Chapters / Transcript / Show Notes

| Widget · Control | Selector | Status |
|---|---|---|
| header: text/muted/accent tokens + label/title/meta typography | `.epm-episode-header*` | works |
| metadata: color token + typography | `.epm-meta` | works |
| guest: text/muted tokens + name/detail typography | `.epm-guest*` | works |
| guest: **guest_image_size** (new) → `--epm-guest-image-size` | `.epm-guest__image { width/height: var(--epm-guest-image-size, 72px) }` | **new** (requested capability) |
| chapters: text/time tokens + heading/list typography | `.epm-chapters*` | works |
| transcript: text/muted tokens + heading/content typography | `.epm-transcript*` | works |
| show_notes: text/muted tokens | `.epm-show-notes` — **no CSS rules existed**; added `.epm-show-notes[__heading/__content]` block consuming the tokens | fixed |

### Subscribe Links (`epm-subscribe-links`)

| Control | Selector | Target rule | Status |
|---|---|---|---|
| subscribe_text | → `--epm-text` | link color | works |
| subscribe_accent ("Hover Color") | → `--epm-subscribe-hover` (was `--epm-accent`, which the hover rule never consumed) | `.epm-subscribe__link:hover { color: var(--epm-subscribe-hover, var(--epm-text, …)); border-color: currentColor }` | fixed |
| subscribe_icon_size | → `--epm-subscribe-icon-size` (was `font-size`, ignored by fixed 18px SVG) | `.epm-subscribe__icon svg { width/height: var(--epm-subscribe-icon-size, 18px) }` | fixed |
| subscribe_gap | → `--epm-subscribe-gap` (was `--epm-gap`, which the fixed `gap: 12px` ignored — and would have leaked into nested components) | `.epm-subscribe { gap: var(--epm-subscribe-gap, 12px) }` | fixed |
| subscribe_typography | `.epm-subscribe__label` | group control generates its own declarations | works |

## Files changed

- `assets/css/epm-frontend.css` — full token-architecture repair (see F2), `:where()` specificity guards on three layout-sizing rules, new rules for `.epm-latest`, `.epm-show-notes*`, subscribe/secondary/guest/card-artwork tokens, error-state token fallbacks, `--epm-on-accent` usage.
- `includes/DesignSettings.php` — `on_accent` default/sanitize/output; token output moved to wp_head:20 + wp_footer:1; precedence documented.
- `includes/Presets.php` — `on_accent` in all 5 presets (`#ffffff`, business-tuning `#07090a`).
- `includes/Elementor/Widgets/WidgetHelpers.php` — `add_toggle()` accepts extra args (description/condition).
- `includes/Elementor/Widgets/PodcastPlayerWidget.php` — secondary icon-size/color rewired to tokens; restriction descriptions on 5 toggles.
- `includes/Elementor/Widgets/EpisodeListWidget.php` — artwork toggle condition + label; episode-number restriction note; new `list_artwork_radius`.
- `includes/Elementor/Widgets/LatestEpisodeWidget.php` — background rewired to `--epm-latest-background`.
- `includes/Elementor/Widgets/PodcastHeroWidget.php` — background rewired to `--epm-hero-background`.
- `includes/Elementor/Widgets/SubscribeLinksWidget.php` — icon-size/gap/hover rewired to dedicated tokens.
- `includes/Elementor/Widgets/GuestWidget.php` — new `guest_image_size` control.

## Follow-ups

- `admin/views/design.php` exposes the `on_accent` token as “Text on accent”.
- Runtime checks now cover plugin activation, Elementor widget registration,
  admin rendering and permissions, RSS generation, and CTA asset loading; the
  browser and device scenarios remain listed in `TEST-PLAN.md`.
