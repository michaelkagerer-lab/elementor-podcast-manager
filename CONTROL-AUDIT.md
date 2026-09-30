# Control Audit — Elementor Podcast Manager

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
| container_border / container_shadow (groups) | `.epm-player` | real element | works |
| artwork_size | → `--epm-artwork-size` | `.epm-player__artwork { width: var(--epm-artwork-size, 96px) }`; beats layout default by specificity | works |
| artwork_radius | → `--epm-artwork-radius` | consumed by artwork imgs | works |
| label_color / label_typography | `.epm-player__label` | real rules | works |
| title_color / title_typography | `.epm-player__title` | layout sizing wrapped in `:where()` so the widget override wins deterministically | fixed |
| meta_color / meta_typography | `.epm-player .epm-meta` | real rules | works |
| play_button_size | `.epm-player__play` → width/height | layout default wrapped in `:where()` so override wins | fixed |
| play_button_radius/background/color + hover | `.epm-player__play[:hover]` | real rules | works |
| play_button_border (group) | `.epm-player__play` | real element | works |
| timeline_track_color | `.epm-player__track` → background | real rule | works |
| timeline_played_color | `.epm-player__progress` → background | real rule | works |
| timeline_height | `{{WRAPPER}}` → `--epm-progress-height` | inherited by `.epm-player__track` | works |
| time_color / time_typography | `.epm-player__times` | real rules | works |
| secondary_icon_size | → `--epm-secondary-icon-size` (was `font-size`, ignored by fixed SVGs) | new `.epm-player__secondary svg` rule consumes it | fixed |
| secondary_color | → `--epm-secondary-color` (was `color`, overridden by children's own `color`) | skip/speed/download rules now consume the token, default = `--epm-text-muted` | fixed |
| secondary_color_hover | `button:hover` / `a:hover` → color | beats CSS hover rules by specificity | works |
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

## Open follow-ups (for the main agent, out of this task's file scope)

1. `admin/views/design.php` hardcodes its color field list — add an "On-accent color" field for `on_accent` (sanitize + presets already handle it).
2. Real-environment verification still required: browser computed styles, Elementor editor registration/save/reopen, two client designs, feed + player runtime behavior.
