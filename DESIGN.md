---
version: 1.3.0
name: Elementor Podcast Manager
description: "A theme-neutral podcast UI kit that lives inside other people's WordPress sites. Every visual value is a --epm-* custom property with a literal fallback at specificity 0, so the site's Global Podcast Styles, presets and per-widget Elementor controls decide the look, never the plugin code. Out of the box it is quiet: the theme's own fonts, white and near-white surfaces, 1px hairlines, a 12px container radius, one blue accent and a round play button. Character comes from eleven presets, from neutral to dark studio looks, which set values only. The admin screens are the opposite case: they have no look of their own and stay native to wp-admin."

colors:
  accent: "#1d4ed8"
  on-accent: "#ffffff"
  text: "#111827"
  text-muted: "#6b7280"
  background: "#ffffff"
  surface: "#f9fafb"
  border: "#e5e7eb"
  track: "derived from text-muted when unset"
  danger: "#b91c1c"
  danger-on-dark: "#f87171"
  image-outline: "oklch(0 0 0 / 0.1)"
  image-outline-on-dark: "oklch(1 0 0 / 0.1)"
  video-frame: "#0b0b0c"

typography:
  font: "inherit (theme and Elementor Global Fonts)"
  title-size: 22px
  meta-size: 14px
  label: "meta-size, uppercase, letter-spacing 0.12em"
  times: "12px, tabular numbers"
  share: "13px toggle, 14px menu items"
  topic-chip: 12px
  long-text-line-height: 1.7

rounded:
  container: 12px
  artwork: 8px
  card-artwork: 0
  button-rounded: 8px
  button-pill: 999px
  button-square: 2px
  play: 50%
  menu: 12px
  menu-item: 6px
  topic-chip: 999px

spacing:
  gap: 24px
  gap-half: 12px

elevation:
  none: none
  soft: "0 1px 2px rgb(0 0 0 / 0.06), 0 4px 12px rgb(0 0 0 / 0.06)"
  lifted: "0 2px 6px rgb(0 0 0 / 0.08), 0 12px 32px rgb(0 0 0 / 0.12)"
  menu: "0 0 0 1px {colors.border}, 0 2px 6px -1px rgb(0 0 0 / 0.08), 0 14px 32px -6px rgb(0 0 0 / 0.2)"

motion:
  ease-out: "cubic-bezier(0.23, 1, 0.32, 1)"
  ease-in-out: "cubic-bezier(0.77, 0, 0.175, 1)"
  ease-drawer: "cubic-bezier(0.32, 0.72, 0, 1)"
  icon: "cubic-bezier(0.2, 0, 0, 1)"

components:
  player:
    backgroundColor: "{colors.background}"
    textColor: "{colors.text}"
    border: "1px solid {colors.border}"
    rounded: "{rounded.container}"
    padding: "{spacing.gap}"
    shadow: "--epm-shadow"
  play-button:
    backgroundColor: "{colors.accent}"
    textColor: "{colors.on-accent}"
    size: 56px
    rounded: "{rounded.play}"
  episode-card:
    backgroundColor: "{colors.surface}"
    border: "1px solid {colors.border}"
    rounded: "{rounded.container}"
    padding: "{spacing.gap}"
  list-play-button:
    backgroundColor: transparent
    textColor: "{colors.text}"
    border: "1px solid {colors.border}"
    rounded: "button shape"
    minHeight: 44px
  subscribe-link:
    textColor: "{colors.text-muted}"
    border: "1px solid {colors.border}"
    rounded: "button shape"
    padding: 8px 16px
    minHeight: 44px
  share-toggle:
    textColor: "{colors.text-muted}"
    border: "1px solid {colors.track}"
    rounded: "button shape"
    minHeight: "32px (44px on touch)"
  share-menu:
    backgroundColor: "{colors.background}"
    rounded: "{rounded.menu}"
    padding: 6px
    shadow: "{elevation.menu}"
  video-facade:
    backgroundColor: "{colors.video-frame}"
    rounded: "{rounded.container}"
    aspectRatio: "16 / 9"
    playButton: "72px circle, rgb(0 0 0 / 0.64), white glyph"
  topic-chip:
    textColor: "{colors.text-muted}"
    border: "inset 1px {colors.border}"
    rounded: "{rounded.topic-chip}"
    padding: 2px 10px
  embed-card:
    layout: "compact player, 152px artwork (96px below 520px)"
    height: 200px
  sticky-bar:
    backgroundColor: "{colors.surface}"
    borderTop: "1px solid {colors.border}"
    position: "fixed, bottom, z-index 9990"
---

# Design System: Elementor Podcast Manager

This file describes the design system of the plugin as it is in the code
(version 1.3.0). It uses the DESIGN.md format of the
[awesome-design-md](https://github.com/VoltAgent/awesome-design-md)
collection (MIT License): a plain-text design system document that AI
agents and people read before building UI.

Sources of truth, in this order: `assets/css/epm-frontend.css` (frontend
tokens and components), `includes/DesignSettings.php` (the design options
and the tokens printed on `:root`), `includes/Presets.php` (presets),
`includes/Renderer.php` (markup), `admin/css/epm-app.css`,
`admin/css/epm-design.css` and `admin/css/epm-admin.css` (admin screens).
If this file and the code disagree, the code wins; fix this file.

## 1. Visual Theme & Atmosphere

The plugin's frontend is a guest. It renders podcast players, episode
lists and pages inside themes and Elementor layouts it does not control,
for clients whose brands it does not know. Its design system is built
around that:

- **Neutral by default, branded by values.** The default look is plain:
  the theme's fonts, white and near-white surfaces (`#ffffff`,
  `#f9fafb`), 1px hairlines (`#e5e7eb`), a 12px container radius, one
  blue accent (`#1d4ed8`) and a round play button. Everything else comes
  from Global Podcast Styles (Podcast → Design), presets and per-widget
  Elementor controls. The code contains no client colors, fonts or copy.
- **Tokens all the way down.** Every visual value is a `--epm-*` custom
  property, consumed as `var(--epm-x, <literal fallback>)`. The static
  fallbacks sit in a `:where(:root)` block with specificity 0, so the
  site's values win no matter where the stylesheet loads.
- **Artwork brings the color.** Podcast artwork is the most colorful
  thing on the page. The UI around it stays achromatic except for the
  accent, which marks what is interactive or active: the play button,
  progress, the active chapter, focus rings, the copied state and the
  main call to action.
- **Theme-proof.** Themes style bare `<button>` elements, headings and
  links aggressively. Every button rule of the plugin carries `:not(#epm)`
  (ID-level specificity), and titles and links inside components use two
  classes (0,2,0), so theme and Elementor Kit rules (0,1,1) cannot restyle
  them, while Elementor controls still work because they set variables or
  use higher specificity.
- **Private until asked.** Nothing is requested from another server
  before the visitor acts: videos are facades until pressed, and audio on
  another domain loads on the first press (`preload="none"`).
- **Calm motion.** A few short transform/opacity transitions that confirm
  state changes (play/pause, copied, the sticky bar, the active chapter,
  presses). Nothing moves on page load.

The **admin screens** follow the opposite rule: they have no look of
their own. They use the WordPress admin font, the admin color scheme's
highlight color (`--wp-admin-theme-color`), core buttons and form
controls, so the plugin feels like part of wp-admin. The Design screen
is the one place that shows the frontend look inside wp-admin: in its
live preview.

**Key characteristics**

- Two-token type scale: a title size (22px) and a meta size (14px); most
  other sizes are multiples of these.
- One spacing token (`--epm-gap`, 24px); inner spacing is derived from it.
- Hairline borders define containers; shadows are off by default.
- Round play button (56px, accent); text buttons follow one shape token
  (rounded 8px, pill 999px or square 2px).
- 44px minimum hit area for every control that plays, seeks or toggles.
- Tabular numbers for every time, speed and episode number.
- Admin: native wp-admin, `--epm-app-*` component tokens, motion only
  when the user has no reduced-motion preference.

## 2. Color Palette & Roles

### Color tokens

Printed on `:root` by `DesignSettings::output_tokens()` (as
`<style id="epm-design-tokens">`) from the design option
`epm_design_settings`. The values below are the defaults (the Neutral
preset).

| Token | Design option | Default | Role |
|---|---|---|---|
| `--epm-accent` | `accent` | `#1d4ed8` | Play button, progress fill and handle, active chapter marker, chapter times, focus outline, hover color of titles, current page in pagination, the copied check, primary call to action |
| `--epm-on-accent` | `on_accent` | `#ffffff` | Glyphs and text on the accent |
| `--epm-text` | `text` | `#111827` | Titles, body text, share menu items, controls at rest in the sticky bar |
| `--epm-text-muted` | `muted` | `#6b7280` | Labels, metadata, times, excerpts, secondary controls, subscribe links and topic chips at rest |
| `--epm-background` | `background` | `#ffffff` | Player and share menu background; on dark designs also the section surface |
| `--epm-surface` | `surface` | `#f9fafb` | Cards, the sticky bar, the error box, the manual-copy field |
| `--epm-border` | `border_color` | `#e5e7eb` | Hairlines: container borders, row separators, outline buttons, topic chips, the share menu ring |
| `--epm-track` | `track_color` | not printed | Unplayed part of timelines and the outline of the speed and share buttons. When unset, the stylesheet uses the muted color |
| `--epm-danger` | (derived) | `#b91c1c` (stylesheet) | Error accents. Printed as `#f87171` when the background is dark |
| `--epm-image-outline` | (derived) | `oklch(0 0 0 / 0.1)` (stylesheet) | 1px inner edge on artwork. Printed as `oklch(1 0 0 / 0.1)` when the background is dark |
| `--epm-section-background`, `--epm-section-padding` | (derived) | not set | Printed only for dark designs (`var(--epm-background)`, `var(--epm-gap)`): sections without a surface of their own get the design background and padding (filter `epm_dark_section_surface`) |

A background counts as dark when its relative luminance is below 0.2
(`DesignSettings::is_dark()`). The extra variables for dark designs come
from `DesignSettings::dark_vars()`; the Design screen preview must use
the same list.

Widget-level variables are set only by Elementor controls with Style
Source *Custom* and have no `:root` value: `--epm-play-background`,
`--epm-play-color`, `--epm-play-background-hover`,
`--epm-play-color-hover`, `--epm-secondary-color`,
`--epm-secondary-hover`, `--epm-subscribe-hover`,
`--epm-hero-background` + `--epm-hero-padding` (gap × 1.5) and
`--epm-latest-background` + `--epm-latest-padding` (1 × gap): the
Background control of the Podcast Hero and Latest Episode widgets sets
both, so a colored area never has content on its edge.

### Contrast rules

The Design screen's contrast check (`Admin::contrast_pairs()`) tests
eight pairs live, at WCAG AA:

| Pair | Minimum |
|---|---|
| Text on background, text on surface | 4.5:1 |
| Muted text on background, muted text on surface | 4.5:1 |
| Accent text on background | 4.5:1 |
| Text on accent | 4.5:1 |
| Timeline track on background, on surface | 3:1 |

Every preset shipped with 1.3.0 goes further, and a new shipped preset
must too: text ≥ 7:1 on background and surface, muted ≥ 4.5:1, on-accent
≥ 4.5:1, accent ≥ 4.5:1 on background, track ≥ 3:1 on both. Measured
minimums (WCAG relative luminance formula):

| Preset | Text | Muted | On-accent | Accent on background | Track |
|---|---|---|---|---|---|
| `neutral` | 16.98 | 4.63 | 6.70 | 6.70 | 4.63 |
| `minimal` | 18.09 | 4.54 | 18.88 | 18.88 | 4.54 |
| `editorial` | 17.60 | 4.97 | 18.88 | 18.88 | 4.97 |
| `card` | 13.34 | 4.87 | 5.17 | 5.17 | 4.87 |
| `business-tuning` | 15.08 | 6.57 | 16.52 | 16.52 | 6.57 |
| `clean-light` | 15.46 | 4.66 | 5.57 | 5.57 | 3.33 |
| `soft-voice` | 18.12 | 5.22 | 15.17 | 13.91 | 3.31 |
| `warm-paper` | 15.52 | 4.98 | 5.74 | 5.44 | 3.26 |
| `ink-mono` | 17.18 | 6.12 | 17.93 | 17.93 | 3.10 |
| `night-studio` | 17.04 | 8.13 | 10.94 | 9.76 | 3.39 |
| `midnight` | 17.18 | 5.63 | 6.95 | 6.95 | 3.17 |

(Text, muted and track: the lower of the two ratios on background and
surface. Presets without a track color use the muted color.)

### Presets

`includes/Presets.php`, filter `epm_presets`. Applying a preset writes
its values into Global Podcast Styles; they stay editable afterwards.

| Preset | Background | Surface | Text | Muted | Accent / on-accent | Border | Radius / artwork | Gap | Title / meta | Buttons | Font | Shadow | Track | Player / list |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `neutral` | `#ffffff` | `#f9fafb` | `#111827` | `#6b7280` | `#1d4ed8` / `#ffffff` | `#e5e7eb` | 12 / 8 | 24 | 22 / 14 | rounded | inherit | none | derived | minimal / list |
| `minimal` | `#ffffff` | `#fafafa` | `#111111` | `#737373` | `#111111` / `#ffffff` | `#e5e5e5` | 6 / 6 | 16 | 18 / 13 | pill | inherit | none | derived | minimal / minimal |
| `editorial` | `#ffffff` | `#f7f7f5` | `#111111` | `#6b6b6b` | `#111111` / `#ffffff` | `#e2e2e2` | 4 / 4 | 32 | 32 / 14 | pill | inherit | none | derived | editorial / editorial-rows |
| `card` | `#ffffff` | `#f3f4f6` | `#1f2937` | `#646b78` | `#2563eb` / `#ffffff` | `#e5e7eb` | 20 / 16 | 24 | 20 / 14 | pill | inherit | none | derived | artwork / cards |
| `business-tuning` | `#07090a` | `#171c1e` | `#f2f0e9` | `#9ba1a4` | `#b9ff22` / `#07090a` | `#303638` | 4 / 4 | 32 | 34 / 14 | pill | inherit | none | derived | editorial / editorial-rows |
| `clean-light` | `#ffffff` | `#f5f5f7` | `#1d1d1f` | `#6e6e73` | `#0066cc` / `#ffffff` | `#e0e0e0` | 18 / 12 | 24 | 24 / 14 | pill | system | none | `#86868b` | artwork / cards |
| `soft-voice` | `#f5f5f5` | `#ffffff` | `#0c0a09` | `#6b665f` | `#292524` / `#ffffff` | `#e7e5e4` | 16 / 12 | 24 | 22 / 14 | pill | system | soft | `#8c867e` | full / list |
| `warm-paper` | `#faf9f5` | `#f0ebe1` | `#141413` | `#66645e` | `#a04f33` / `#ffffff` | `#e6dfd8` | 12 / 8 | 28 | 26 / 14 | rounded | serif | soft | `#878175` | editorial / editorial-rows |
| `ink-mono` | `#ffffff` | `#fafafa` | `#171717` | `#5f5f5f` | `#171717` / `#ffffff` | `#ebebeb` | 6 / 4 | 20 | 18 / 13 | square | mono | none | `#8f8f8f` | minimal / minimal |
| `night-studio` | `#121212` | `#1c1c1c` | `#ffffff` | `#b3b3b3` | `#1ed760` / `#000000` | `#2a2a2a` | 8 / 6 | 16 | 20 / 14 | pill | rounded | lifted | `#6f6f6f` | artwork / grid |
| `midnight` | `#08090a` | `#141516` | `#f7f8f8` | `#8a8f98` | `#828fff` / `#08090a` | `#23252a` | 12 / 8 | 20 | 20 / 13 | rounded | system | none | `#62666d` | compact / list |

Dark presets (background luminance below 0.2): `business-tuning`,
`night-studio`, `midnight`.

- `business-tuning` is the reference implementation for one client
  (values observed on businesstuning.at). It is the only preset tied to
  a real site, and it is values only.
- The six presets from `clean-light` to `midnight` are derived from the
  values of the DESIGN.md files in the awesome-design-md collection. They
  take values only (surface ladder, text and muted colors, one accent,
  radii, spacing, type scale, button shape, font family and elevation)
  and carry generic names. They contain no brand names, logos, copy or
  proprietary fonts; the font choice maps to a generic stack.
- Presets also set behavior maps. `visibility` (`show_artwork`,
  `show_episode_label`, `show_title`, `show_episode_number`,
  `show_season`, `show_guest`, `show_description`, `show_date`,
  `show_duration`), `player` (`show_playback_speed`,
  `show_skip_backward`, `show_skip_forward`, `show_volume`,
  `show_download`) and `episodeList` (empty in every shipped preset).
  Differences from Neutral: `minimal` hides artwork; `business-tuning`,
  `soft-voice` and `warm-paper` show the description; `business-tuning`
  hides the volume slider.

### Admin colors

The admin screens take their accent from the WordPress admin color
scheme and use WordPress's own grays and status colors:

| Token (`epm-app.css`, on `.epm-app`) | Value |
|---|---|
| `--epm-app-accent` | `var(--wp-admin-theme-color, #2271b1)` |
| `--epm-app-accent-strong` | `var(--wp-admin-theme-color-darker-10, #135e96)` |
| `--epm-app-accent-soft` | the accent mixed 8% into white |
| `--epm-app-text` / `-muted` / `-subtle` | `#1d2327` / `#50575e` / `#646970` |
| `--epm-app-surface` / `-canvas` | `#fff` / `#f6f7f7` |
| `--epm-app-line` / `-line-strong` | `#dcdcde` / `#c3c4c7` |
| `--epm-app-ok`, `-warn`, `-error` | `#00753b`, `#8a5a00`, `#b32d2e` |
| `--epm-app-ok-soft`, `-warn-soft`, `-error-soft` | `#edfaef`, `#fcf9e8`, `#fcf0f1` |
| `--epm-app-radius` / `-radius-sm` | 8px / 4px |

`epm-admin.css` (episode screen, settings, dashboard extras) defines the
same roles as `--epm-admin-*` on `:root`, plus a type scale on
WordPress's 13px base (`--epm-admin-text-sm` 12px, `-md` 13px, `-lg`
14px) and the easing tokens, including `--epm-ease-icon`.

## 3. Typography Rules

### Font family

The plugin sets **no font family by default**. Headings, body text and
buttons inherit the theme's fonts and Elementor's Global Fonts.

The *Font* design option (`font_family`) can choose a generic stack. It
is printed as `--epm-font`; with *Inherit* nothing is printed. When a
stack is chosen, `<body>` also gets the class `epm-custom-font`, so the
stylesheet can apply the font to headings, buttons and summaries inside
podcast components, which themes often style directly.

| Option | `--epm-font` |
|---|---|
| `inherit` | not printed |
| `system` | `system-ui, -apple-system, Segoe UI, Roboto, Helvetica Neue, Arial, sans-serif` |
| `serif` | `ui-serif, Georgia, Cambria, Times New Roman, Times, serif` |
| `rounded` | `ui-rounded, SF Pro Rounded, system-ui, -apple-system, sans-serif` |
| `mono` | `ui-monospace, SFMono-Regular, Menlo, Consolas, Liberation Mono, monospace` |

No web fonts are loaded by the plugin. The embed card has no theme, so it
uses a system stack unless the design sets a font.

### Scale

Two tokens drive the scale: `--epm-title-size` (design option
`title_font_size`, default 22px) and `--epm-meta-size` (`meta_font_size`,
default 14px).

| Role | Size | Line height | Other |
|---|---|---|---|
| Player title, row and card title, latest-episode title, section headings | title | 1.2–1.25 | |
| Compact player title | title × 0.75 | 1.2 | |
| Editorial player title | title × 1.4 | 1.2 | letter-spacing −0.01em |
| Grid card title | `clamp(16px, title × 0.8, 22px)` | 1.25 | |
| Embed card title | `clamp(16px, title × 0.85, 20px)` | 1.25 | two lines at most |
| Row number (editorial rows) | title × 1.1 | | tabular numbers, muted |
| Episode header title | title × 1.6 | 1.15 | |
| Hero title | title × 1.8 | 1.1 | |
| Guest name, headings inside show notes | title × 0.82 | 1.25–1.3 | guest name 600 |
| Label (episode label, host line, card label) | meta | | uppercase, letter-spacing 0.12em, muted |
| Metadata, excerpts, guest role and bio, subscribe links, chapter rows, video note | meta | | muted |
| Share toggle / share menu items | 13px / 14px | 1.2 / 1.3 | position label in tabular numbers |
| Times, speed | 12px (speed 13px) | | tabular numbers |
| Topic chips | 12px | 1.3 | |
| Sticky bar title | 14px | | one line, ellipsis |
| Show notes, transcript | inherited | 1.7 | 65ch measure |

- Descriptions are limited to a comfortable measure: hero description
  60ch, episode header description 70ch, show notes, transcripts and
  guest bios 65ch.
- Titles use `text-wrap: balance`, descriptions `text-wrap: pretty`, and
  long single words (German compounds, URLs as titles) break
  (`overflow-wrap: anywhere; hyphens: auto`) instead of widening the page.
- Times, durations, speeds and episode numbers use
  `font-variant-numeric: tabular-nums`, so running clocks do not jitter.
- Section headings for show notes, chapters and transcripts use a
  configurable level (`h2`, `h3` or `h4`, default `h3`; the automatic
  episode page uses `h2` under the theme's `h1`).
- Weights: 600 for guest names, the active chapter title and calls to
  action; everything else inherits.

### Admin

System font (wp-admin's), 13px base. Screen titles 23px/400 (as core),
panel titles 20px/600, card titles 16px/600, eyebrows 12px/600 uppercase
with 0.04em tracking, help text 13px/1.5, ledes 14px/1.55 with a 65ch
measure. Titles use `text-wrap: balance`, paragraphs `text-wrap: pretty`.

## 4. Component Stylings

Class names follow BEM: `.epm-{component}`, `.epm-{component}__{element}`,
`.epm-{component}--{modifier}`. State classes are `is-*` / `has-*`
(`is-playing`, `is-active`, `is-copied`, `is-loaded`, `has-error`).
Markup comes from `includes/Renderer.php` and is shared by widgets,
shortcodes, the automatic episode page and the embed card.

### Player (one engine, five layouts)

Structure: artwork | main column (label, title, metadata, controls row
with the transport group and the timeline, secondary row, description,
chapters, platform links).

- Container: background `--epm-background`, 1px `--epm-border`, radius
  `--epm-radius`, `--epm-shadow`, padding and gap `--epm-gap`; inner gaps
  are half the gap.
- **Play button:** `--epm-play-size` (56px, never below 44px), circle
  (`--epm-play-radius`, 50%), background and border `--epm-accent`,
  glyph `--epm-on-accent` at 22px. Play and pause glyphs are stacked in
  one grid cell (`.epm-icon-swap`) and cross-fade from the button's own
  `.is-playing` class.
- **Skip buttons** (−15 s, +30 s): transparent, muted, 20px icon plus a
  12px number, 44px minimum height. Hover: text color.
- **Timeline:** a 20px-tall hit area (`role="slider"`) around a track of
  `--epm-progress-height` (4px) with fully rounded ends; on coarse
  pointers the hit area extends to 28px (a pseudo-element, so the layout
  does not move). The unplayed part uses the track color, the played
  part the accent, and a 14px accent handle marks the position. The
  value is announced as "1:05 of 42:10". Transport and timeline stay
  left to right on right-to-left pages.
- **Secondary row:** speed (a text button with a 1px track-colored
  border, 13px tabular value, button shape, 32px high, 44px on touch
  screens), volume (native range input with `accent-color`, 28px high on
  touch screens; hidden where the device owns the volume, as on iOS),
  download link, and the share menu at the trailing end. Icons 20px
  (`--epm-secondary-icon-size`).
- **Shared settings:** speed and volume belong to the visitor, not to an
  episode: changing them on one player changes every player and episode
  on the page (the speed is also remembered). Raising the volume
  unmutes; while muted the sliders show 0.
- **Layouts** are configurations of the same markup:

| Layout | Artwork | Differences |
|---|---|---|
| Minimal | hidden | Play, title and timeline; secondary row (and share menu) and description hidden |
| Compact | 56px | Play 44px, title × 0.75, padding half the gap; secondary row and description hidden |
| Editorial | hidden | Title × 1.4; hairline above the controls row |
| Artwork | 160px | Stacks below 768px (artwork up to 320px wide) |
| Full | 140px | Everything, wraps; stacks below 768px |

States:

| State | Treatment |
|---|---|
| Idle | Play glyph |
| Cued (`?t=` link) | Timeline and time show the start position; the play button's name adds "Starts at 1:05" until the first press. A position at or past the end (or beyond 24 hours) is ignored: no hint, playback starts at 0 |
| Playing | `.is-playing` on the player and on every play button of that episode (full player, sticky bar, card and row buttons); pause glyph |
| Hover (fine pointers) | Play button `filter: brightness(1.08)`; secondary controls switch from muted to text color |
| Focus | 2px solid accent outline, 2px offset, on `:focus-visible` only; never transitioned |
| Pressed | Play, sticky play, list play, retry and share buttons scale to 0.96 |
| Error | `.epm-player__error` (`role="alert"`): surface background, 1px border, 3px start border in the danger color, 8px radius, 10px × 14px padding, message plus a Retry button (accent, button shape, 44px); list buttons switch to "Retry" (`.has-error`) |
| Fallback | `.epm-player--fallback` reveals the native `<audio>` element when the engine cannot start |

### Share menu

A menu button (WAI-ARIA menu button pattern) at the trailing end of the
player's secondary row: `.epm-share__toggle` with a 16px share glyph and
the label "Share", 1px border in the track color, button shape, 32px high
(44px on coarse pointers), muted text. The menu (`role="menu"`) hangs
below the button, aligned to its trailing edge, or above it when the
viewport has no room below (`.epm-share--up`).

- Menu: `--epm-background`, 12px radius, 6px padding, a 1px
  `--epm-border` ring plus a two-layer shadow; 232px minimum width, at
  most `min(320px, 100vw − 32px)`. Radii are concentric: 12px menu = 6px
  items + 6px inset.
- Items: 40px high (44px on coarse pointers), 18px muted icon, 14px text.
  Pointer hover and keyboard focus land on the same highlight (the text
  color mixed 8% into transparent); the focus ring sits inside the item.
- Items: *Copy link*, *Copy link at 12:34* (hidden until the episode has
  a position), *Share…* (only where `navigator.share` exists), *Copy
  embed code* (public episodes only).
- Copied: the toggle gets `.is-copied`, the share glyph turns into an
  accent check and "Share" into "Copied" in the same grid cell, so the
  button never changes width; a live region announces it.
- Blocked clipboard: a manual-copy panel with a read-only monospace
  field (16px on touch screens, so iOS does not zoom) and a hint.
- Keyboard: the toggle opens the menu with Enter or Space, ArrowDown
  (first item) or ArrowUp (last item); arrows, Home and End move between
  items; Escape closes and returns focus to the toggle; Tab and focus
  leaving the component close it.

### Sticky mini player

A bar docked to the bottom edge (`position: fixed`, `z-index: 9990`),
`role="region"` labelled "Audio player". Surface background, 1px top
hairline, `--epm-shadow`, 10px × 20px padding; the bottom safe-area
inset adds to the padding, the left and right insets replace the 20px
when they are larger (landscape phones with a notch).
Artwork 44px with 8px radius; title 14px on one line with ellipsis
(danger color on error); time 12px tabular; a 24px-tall timeline around a
4px track with a 12px handle; controls (play, speed, close) with 44px
minimum targets and 22px icons. It is hidden until an episode plays and
slides in from the bottom edge. While it is open, the page reserves its
height (`--epm-sticky-height`, measured by the engine) as bottom padding
and scroll padding, so it never covers the last content or a focused
element. At 480px and below the timeline runs along its top edge.

Its (hidden) shell is printed when players with *sticky*, the automatic
episode page, list play buttons or chapter lists
(`epm_sticky_player_for_lists`) are on the page, and always in the
Elementor editor's preview. It opens only for playback started from a
view that asks for it: each player, list play button and chapter list
carries `data-epm-sticky-player` ("1" or "0"; chapters inside a player
follow the player). An open bar that shows the playing episode stays;
when a player without *sticky* starts another episode, an open bar
closes (with its leave transition) instead of showing it.

### Episode lists

Five layouts (`list`, `editorial-rows`, `cards`, `grid`, `minimal`).

- **Rows** (`list`, `editorial-rows`, `minimal`): a hairline under each
  row, vertical padding `gap × 0.9`. Editorial rows use `gap × 1.25` and
  show the episode number at `title × 1.1` in muted tabular numbers; the
  number column is reserved on every row only when at least one listed
  episode has a number. Minimal rows use half the gap and never show
  numbers. Number, first title line, date and Play label share one
  baseline. The aside (date, duration, play button) is right-aligned and
  moves under the content below 768px.
- **Cards:** surface background, 1px border, radius `--epm-radius`,
  `--epm-shadow`, artwork at 16:10 with `--epm-card-artwork-radius` (0),
  body padding `--epm-gap`, 8px between elements. **Grid** places cards
  in `repeat(auto-fill, minmax(260px, 1fr))`.
- **List play button:** transparent with a 1px border, text color,
  button shape, 8px × 16px padding, 44px minimum height, 14px glyph that
  swaps between play and pause like the main button. "Play", "Pause" and
  "Retry" share one grid cell, so the button is as wide as the longest
  word in every state. Hover (fine pointers) and playing: border and text
  in the accent.
- **Titles** link to the episode page; hover turns them accent.
- **Topic chips** (`show_topics`) follow the excerpt.
- **Pagination:** 8px-radius chips with a 1px border, 8px × 14px; the
  current page is filled with the accent; "…" has no chip.
- **Empty state:** one line of muted text at meta size; a filtered list
  that matches nothing links to all episodes (underlined accent link).

### Chapters

An ordered list. Each row is one seek button (44px minimum, 10px vertical
padding) containing the time (accent, tabular) and the title, so the part
people read is the part they tap. An optional link follows at the end of
the row, muted, with the chapter title as hidden text. Rows are separated
by hairlines; the inline start padding (13px) is always reserved for the
3px active marker. The chapter that is playing (`.is-active`) gets the
accent marker and a 600-weight title; the marker grows in place and the
row never moves.

### Show notes and transcript

A heading at the configured level (title size) and the content at line
height 1.7 in the text color, 65ch wide. Headings inside the notes are
title × 0.82. A collapsible transcript is a `<details>` whose `<summary>`
contains the real heading, so heading navigation still finds it while it
is closed.

### Subscribe links

A wrapping list of links (gap `--epm-subscribe-gap`, 12px). Each link is
a chip: 1px border, button shape, 8px × 16px padding, 44px minimum
height, meta size, muted text; an 18px platform glyph
(`--epm-subscribe-icon-size`) in `currentColor`. Hover: text color (or
`--epm-subscribe-hover`) and a border in the current color. Three display
modes: icon + text, text, icon only. In icon-only mode a service without
a recognizable glyph still shows its label, so a row of identical
fallback icons cannot happen. The RSS link points to the public feed (the
host's feed when another host publishes the show).

Glyphs come from Simple Icons 16.33.0 (CC0) through
`includes/BrandIcons.php`. They are single-color by design; do not
recolor them per brand in the default styles, and follow each platform's
brand guidelines when you do. Brands that asked Simple Icons to remove
their logo get a neutral icon.

### Topic chips

`.epm-topics` is a wrapping list with 6px gaps, 8px below what it
follows. Each `.epm-topic` is a link to the topic archive: pill-shaped
(999px), 26px minimum height, 2px × 10px padding, an inset 1px
`--epm-border` ring, muted 12px text, no underline. Hover (fine
pointers): text color and a muted ring.

### Video facade

`figure.epm-video` → `.epm-video__frame`: 16:9, radius `--epm-radius`,
`#0b0b0c` background, `--epm-shadow`. Inside, one button covers the
frame: the episode artwork twice (a blurred, 50% opaque backdrop that
fills the frame and the square artwork itself, uncropped, with a light
1px inner edge) and a 72px round play button of neutral glass
(`rgb(0 0 0 / 0.64)`, white 30px glyph shifted 2px right to look
centered, a light ring and a soft shadow), readable on any artwork.
Hover (fine pointers): the button takes the accent and on-accent colors.
Focus: a 3px white outline around the play button over a 7px dark band,
visible on pale and on dark artwork. Below the frame, a muted note at
meta size ("The video loads from YouTube when you play it") until the
video is loaded (`.is-loaded`). Addresses that are not YouTube, Vimeo or
a video file become a plain accent link.

### Embed card

`/podcast/{slug}/embed/` renders the player in the compact layout with
the class `epm-player--embed`: 152px artwork (96px below 520px, hidden
below 340px), the show name as the label, the title (two lines at most)
linking to the episode page, play and skip buttons and one row of
controls at every width; below 520px the skip buttons and metadata hide.
It fills the frame (`min-height: 100vh`, WordPress embeds are at least
200px tall) on a transparent page. The linked title clamps on the link
itself, so its focus ring is never clipped.

### Section surface (dark designs)

Show notes, chapters, transcripts, guest blocks, the episode header, the
latest-episode header, subscribe links, row lists, the empty state,
pagination, topic chips on the episode page, the Episode Metadata
widget's line (`.epm-meta--standalone`) and the video with its note paint
no surface of their own. On a dark design they get
`--epm-section-background` and `--epm-section-padding` with the container
radius, so light text never lands on a light theme page. The video's
corner radius grows by the padding, so its frame and surface stay
concentric. Inside a player or hero the container is already the
surface. The hero reads the same tokens.

### Hero, latest episode, episode header, guest

- **Podcast hero:** artwork `min(320px, 40%)` with the artwork radius,
  title × 1.8, host line as a label, description muted at 60ch, actions
  in a wrapping row. Variants: centered, artwork right. Stacks below
  768px (artwork up to 280px). The call to action is a filled accent
  button (on-accent text, button shape, 12px × 28px, weight 600; hover
  brightness 1.08). Transparent with no padding unless a background is
  set: the widget's Background control pads it by gap × 1.5, the
  dark-design section surface by one gap, both with the container
  radius.
- **Latest episode:** 120px artwork beside the title and description;
  optional call to action styled like the hero's; padded by one gap when
  its Background control is set.
- **Episode header:** artwork up to 280px, label, title × 1.6,
  description muted at 70ch.
- **Guest:** 72px round image (`--epm-guest-image-size`), name at
  title × 0.82 weight 600, role, company and bio muted at meta size.

### Editor placeholder

Only in the Elementor editor, for widgets that currently render nothing:
16px padding, 1px dashed border, 6px radius, 13px muted centered text.
Visitors never see it.

### Admin app components (`admin/css/epm-app.css`)

Shared by the setup assistant, Hosting & import, Distribution, the
dashboard and the Design screen. Scoped under `.epm-app` (max width
1040px; `--narrow` 760px).

| Component | Description |
|---|---|
| `.epm-app__header` | Eyebrow, 23px title, lede; actions on the right |
| `.epm-card` | White, 1px line, 8px radius, `--epm-app-shadow`, 24px padding; `__title`, `__lede`, `__footer` (hairline above, `--split` for back/next) |
| `.epm-steps` | Numbered step list; current step filled with the admin accent (`aria-current="step"`), done steps get a check in the ok color |
| `.epm-panel` | One step of the setup assistant; only one is visible; receives focus on change |
| `.epm-choice` | Radio/checkbox cards: 1px strong line, 8px radius, 16px padding; hover accent border; checked: accent border, soft accent background, inset accent ring; `--tile` variant with a 32px icon and hidden input |
| `.epm-field`, `.epm-field-row`, `.epm-check` | Label (600), help (13px muted), error (13px 600 error color, tied to its field with `aria-describedby`); fields in auto-fit columns of at least 220px; checkbox and label form one hit target |
| `.epm-badge` | Pill status label: neutral, `--ok`, `--warn`, `--error`, `--info` |
| `.epm-callout` | 4px start border and soft background: info, `--warn`, `--error`, `--ok`; `__list` for lists of links (audio not copied) |
| `.epm-copy` | Monospace value on the canvas color plus a copy button |
| `.epm-preview`, `.epm-facts` | Feed preview: 96px artwork (64px on phones), title, meta, key figures |
| `.epm-progress` | 8px track, bar scaled with `transform: scaleX(var(--epm-progress))`, label row |
| `.epm-log`, `.epm-checklist`, `.epm-kv`, `.epm-details` | Import log, numbered or status checklists, key-value lists, disclosure with a rotating chevron (44px summary) |
| `.epm-platform` | Distribution row: 40px icon tile, name with badges, actions, text and details; only the next essential platform's submit link is `button-primary` |
| `.epm-artwork-picker` | 160px square preview with a dashed border until an image is chosen |
| `.epm-button-link` | Text button in the accent, underlined; `--muted`, `--danger` |
| Busy buttons | `aria-busy="true"`: label stays, a 12px spinner joins it, clicks are ignored |

Core `.button` / `.button-primary` classes are used for every button;
the app only sets minimum heights (32px, 40px for large).

### Design screen (`admin/css/epm-design.css`)

Podcast → Design builds on the app components and renders the real
frontend components (`epm-frontend.css`) in its preview.

| Component | Description |
|---|---|
| Layout | One column on narrow screens (presets, preview, fields, summary, import/export); from a 1000px container, fields on the left and a sticky preview column (`minmax(400px, 44%)`) on the right |
| `.epm-presets__grid`, `.epm-preset` | Radio tiles in `auto-fill` columns of at least 150px; 12px radius = 4px swatch radius + 8px padding; a live mini swatch per preset (background, surface, text lines, accent, track); selected: soft accent background and accent ring; "In use" badge on the active preset |
| `.epm-dialog` | Native `<dialog>` to confirm *Apply preset*: 24px padding, 12px radius, 45% black backdrop; an extra warning when unsaved changes would be lost |
| `.epm-chip` | Choice chips for button shape, shadow and font: 44px high, at least 92px wide, 8px radius, each showing a sample of the choice |
| `.epm-color__control` | Native color picker plus a hex field; the timeline track also has *Automatic* |
| `.epm-contrast-list`, `.epm-contrast-item`, `.epm-contrast-badge` | Eight pairs, each with a sample, the ratio and a pill badge (ok or error color, icon plus text: pass/fail never by color alone) |
| `.epm-design-preview` | Status line ("Your design" / the previewed preset), *Show my design*, and a canvas (20px padding, the design background) with a player, an episode list and subscribe links; every `--epm-*` variable is set inline on the canvas from `Admin::design_css_vars()` |
| `.epm-design__savebar` | Sticky at the bottom of the form: unsaved-changes note (warn color), *Discard changes*, *Save*; the page keeps 96px (112px on small screens) of scroll padding so focused fields stay above it |
| `.epm-design-summary` | What differs from the preset and which details the preset shows by default (On/Off badges) |

## 5. Layout Principles

### Spacing

One token, `--epm-gap` (design option `spacing`, default 24px):

- player padding and the gap between artwork and main column: 1 × gap;
- gaps inside the player (label, title, controls, secondary row): gap ÷ 2;
- row padding: gap × 0.9 (list), × 1.25 (editorial rows), ÷ 2 (minimal);
- hero gap and hero padding (with a background): gap × 1.5; card body
  padding, latest-episode padding (with a background) and dark-design
  section padding: 1 × gap;
- automatic episode page: 1 × gap between the parts.

Fixed micro spacing for small elements: 2, 4, 6, 8, 10, 12 and 16px.

### Radius scale

| Element | Radius |
|---|---|
| Containers (player, cards, video frame, hero/latest/section surfaces) | `--epm-radius` (12px) |
| Artwork | `--epm-artwork-radius` (8px) |
| Card artwork | `--epm-card-artwork-radius` (0) |
| Text buttons (list play, speed, share toggle, subscribe chips, calls to action, retry) | `--epm-button-radius`: 8px rounded, 999px pill, 2px square |
| Main play button, video play button, guest image | 50% |
| Share menu / menu items / manual-copy field | 12px / 6px / 6px |
| Topic chips | 999px |
| Error box, pagination chips, sticky artwork | 8px |
| Editor placeholder | 6px |
| Skip buttons, sticky controls | 4px |
| Timeline tracks | fully rounded |

Designs saved before 1.3.0 have no `button_shape`; they are read as
`pill`, which is what their text buttons looked like, so updating does
not change them. New designs default to `rounded`.

### Measure and alignment

Descriptions stop at 60–70ch. Components fill their column and never set
their own outer width; Elementor and the theme decide the grid. The
player's main column is a size container (`epm-player-main`), so its
internal layout reacts to the column width, not the viewport.

### Admin layout

`.epm-app` caps content at 1040px (760px for the setup assistant) with
24px between cards and 16px/8px stacks (`.epm-stack`,
`.epm-stack--tight`). Hosting & import uses two columns (settings |
moving guides) from 1200px. The Design screen is a container
(`epm-design`) that switches to two columns at 1000px.

## 6. Depth & Elevation

| Level | Treatment | Use |
|---|---|---|
| Flat (default) | 1px `--epm-border` hairline, no shadow | Player, cards, rows, chips |
| Soft | `0 1px 2px rgb(0 0 0 / 0.06), 0 4px 12px rgb(0 0 0 / 0.06)` | `--epm-shadow` with shadow *soft* |
| Lifted | `0 2px 6px rgb(0 0 0 / 0.08), 0 12px 32px rgb(0 0 0 / 0.12)` | `--epm-shadow` with shadow *lifted* |
| Menu | 1px border ring, `0 2px 6px -1px rgb(0 0 0 / 0.08)`, `0 14px 32px -6px rgb(0 0 0 / 0.2)`; `z-index: 30` | Share menu and manual-copy panel (always, independent of `--epm-shadow`) |
| Docked | fixed at the bottom, z-index 9990, top hairline, `--epm-shadow` | Sticky mini player |

`--epm-shadow` applies to the player, cards, the video frame and the
sticky bar. On dark backgrounds a 1px light outline
(`--epm-image-outline`) keeps artwork edges visible.

Admin: cards use `--epm-app-shadow`
(`0 1px 1px rgb(0 0 0 / 0.04), 0 1px 3px rgb(0 0 0 / 0.06)`);
`--epm-app-shadow-raised` exists for raised surfaces
(`0 2px 4px rgb(0 0 0 / 0.06), 0 8px 24px rgb(0 0 0 / 0.08)`). The
Design screen's save bar adds a 1px line ring and an upward shadow
(`0 -4px 16px rgb(0 0 0 / 0.06)`).

## 7. Do's and Don'ts

### Do

- Consume every value as `var(--epm-x, <literal fallback>)`.
- Put new global tokens in two places: the `:where(:root)` block of
  `epm-frontend.css` (literal value) and, if users can change them,
  `DesignSettings` (default, sanitizer, `output_tokens()`), plus
  `Admin::design_token_map()` and `Admin::design_export_keys()` so the
  Design preview and the design export know them.
- Add `:not(#epm)` to every rule that styles a `<button>`, and reset the
  properties themes like to set (background, border, box-shadow, margin,
  padding, text-transform, letter-spacing, line-height, font-weight,
  transition).
- Give titles and links inside components a second class in the selector
  (`.epm-episode-row .epm-episode-row__title`), so theme and Elementor Kit
  element rules stay out.
- Give every control that plays, seeks or toggles a 44px minimum hit
  area, a visible `:focus-visible` outline (2px accent, 2px offset) and
  an accessible name. Keep visible text part of the accessible name.
- Reflect state with a class on the control itself (`is-playing`,
  `is-active`, `is-copied`) and `aria-expanded` / `aria-valuetext` where
  they apply.
- Keep controls the same width in every state: stack alternative labels
  and icons in one grid cell and switch them with the state class.
- Use tabular numbers for anything that counts or ticks.
- Keep motion on `transform`/`scale`/`translate` and `opacity`,
  100–300ms, with the easing tokens; exits faster than entries.
- Gate hover-only styles to `@media (hover: hover) and (pointer: fine)`
  so they do not stick after a tap.
- Load third-party content (video platforms, audio on another domain)
  only after the visitor asks for it.
- Test a new component on the Neutral preset and on a dark preset
  (`night-studio` or `midnight`) on a light theme page, with Hello
  Elementor and a block theme, and at 320px.
- In the admin, use core `.button` classes, `--wp-admin-theme-color` and
  the `epm-app.css` components.

### Don't

- Don't set `font-family` unless `--epm-font` is set; never load web
  fonts.
- Don't write derived tokens in `:where(:root)` (`--epm-x: var(--epm-y)`
  is computed once at `:root` and ignores widget overrides), and never
  self-referential ones (`--epm-x: var(--epm-x, …)`).
- Don't hardcode client or brand colors, fonts, copy or class names in
  PHP or CSS. Presets hold values only.
- Don't use `!important` in frontend CSS.
- Don't animate width, height, padding, margins or layout on frequent
  interactions, and don't animate anything on page load.
- Don't wait for `transitionend`: Elementor turns transitions off under
  reduced motion, and some themes do too.
- Don't transition the focus outline.
- Don't use the accent for decoration or large backgrounds; it marks
  interaction and state.
- Don't signal state by color alone (contrast badges carry an icon and a
  word).
- Don't give the admin screens a custom visual identity (own fonts, brand
  colors, custom form controls).

## 8. Responsive Behavior

| Trigger | Change |
|---|---|
| Player main column ≤ 440px (container query) | The timeline moves to its own full-width row; controls wrap (the embed card keeps one row) |
| Viewport ≤ 768px | Artwork and Full players stack (artwork full width up to 320px); episode rows wrap and their aside becomes a left-aligned row; the hero stacks (artwork up to 280px); the volume slider shrinks to 56px |
| Viewport ≤ 520px | Embed card: 96px artwork, skip buttons and metadata hidden |
| Viewport ≤ 480px | Sticky bar: 12px side padding (or the safe-area inset, when larger), title over time, the timeline along the top edge |
| Viewport ≤ 340px | Embed card: artwork hidden |
| Coarse pointers | Share toggle, speed button and share items grow to 44px; the seek and volume sliders take touches across 28px; the manual-copy field uses 16px text |
| Grid list | `auto-fill` columns of at least 260px |
| Admin ≤ 600px | Distribution rows drop the action column under the text |
| Admin ≤ 480px | Key-value lists and the artwork picker become one column; feed preview artwork 64px |
| Admin ≤ 782px | Design screen save bar: more scroll padding |
| Design screen container ≥ 1000px | Two columns with a sticky preview |
| Admin ≥ 1200px | Hosting & import shows two columns |

Touch: all playback buttons keep 44px targets at every width; the seek
and volume sliders take touches across 28px (WCAG 2.5.8 asks for 24px)
without changing their visible track; hover styles apply only on devices
with a fine pointer. No component may cause
horizontal scrolling at 320px.

### Motion and reduced motion

Plugin controls reset any theme transition (`transition: none`), then
motion is opt-in: it is declared inside
`@media (prefers-reduced-motion: no-preference)`, and
`prefers-reduced-motion: reduce` gets a gentler version (no movement,
short opacity and color fades) instead of none.

| Interaction | Motion | Reduced motion |
|---|---|---|
| Control colors (hover, playing, copied) | color, background and border 150ms, ease-out | 100ms, ease-out |
| Play/pause glyph | Cross-fade: the leaving glyph scales to 0.25 and blurs 4px, the arriving one returns to 1; 300ms, `cubic-bezier(0.2, 0, 0, 1)` | Opacity only, 100ms |
| Press | Play, sticky play, list play, retry and share buttons `scale: 0.96` | Opacity 0.8 |
| Share copied | Share glyph → check like the play glyph; "Share" → "Copied" slides 4px, 150ms, ease-out | Opacity only, 100ms |
| Sticky bar | Enters with `translate: 0 100%` → 0 in 300ms (ease-drawer, `@starting-style`); leaves in 200ms (ease-out) | Opacity fade, 150ms |
| Active chapter | Marker grows in place (`scale: 1 0` → `1 1`), 200ms, ease-out; the row never moves | Marker fades, 150ms |
| Video play button | Hover `scale: 1.04`, press 0.96, color change, 150ms | Color change without transition or scale |
| Admin setup step | New panel fades and rises 8px, 240ms | Fade, 160ms |
| Admin choice card press | `scale(0.99)`, 100ms | none |
| Admin progress bar | `scaleX`, 300ms | none |
| Admin disclosure chevron | Rotates, 150ms | none |
| Design screen dialog | Opens from `scale(0.96)` and opacity 0, 200ms | Opacity, 150ms |

Field changes, preset tile selection and preview updates on the Design
screen are instant: they happen dozens of times per session.

Easing tokens (frontend `:where(:root)`, admin `:root`):

- `--epm-ease-out: cubic-bezier(0.23, 1, 0.32, 1)` — entries and state
  changes
- `--epm-ease-in-out: cubic-bezier(0.77, 0, 0.175, 1)` — movement between
  two positions
- `--epm-ease-drawer: cubic-bezier(0.32, 0.72, 0, 1)` — elements docked
  to an edge (sticky bar)
- `--epm-ease-icon: cubic-bezier(0.2, 0, 0, 1)` — icon swaps (admin
  token; the frontend writes the curve inline)

### Accessibility rules

- Every control has an accessible name; icon-only controls use
  `aria-label` with the episode title where it helps ("Play Episode
  Two"). Visible labels are part of the name.
- The timeline is a `role="slider"` with `aria-valuetext` ("1:05 of
  42:10") and keyboard support (WAI-ARIA slider): Right/Up +5 s,
  Left/Down −5 s, PageUp/PageDown ±30 s, Home/End; handled keys never
  scroll the page.
- The volume slider is spoken as a percentage ("70%").
- The share toggle uses `aria-haspopup="menu"` and `aria-expanded`; items
  are `role="menuitem"` with roving focus.
- The sticky bar is a labelled region; the page reserves its height so it
  never hides focus.
- Status changes (speed, copied, import progress, errors) are announced
  through one polite live region; errors are `role="alert"`.
- Collapsible transcripts keep their heading inside `<summary>`.
- The video facade moves focus into the loaded video.
- Admin: steps receive focus on change, field errors are tied to their
  field with `aria-describedby`, and forced-colors mode keeps selections
  and progress visible (`Highlight`).

## 9. Agent Prompt Guide

### Quick reference

- Background `var(--epm-background, #ffffff)`, surface
  `var(--epm-surface, #f9fafb)`, text `var(--epm-text, #111827)`, muted
  `var(--epm-text-muted, #6b7280)`, hairline `var(--epm-border, #e5e7eb)`,
  accent `var(--epm-accent, #1d4ed8)` on `var(--epm-on-accent, #ffffff)`,
  track `var(--epm-track, var(--epm-text-muted, #6b7280))`
- Radius `var(--epm-radius, 12px)`, artwork `var(--epm-artwork-radius, 8px)`,
  text buttons `var(--epm-button-radius, 8px)`
- Spacing `var(--epm-gap, 24px)`; title `var(--epm-title-size, 22px)`,
  meta `var(--epm-meta-size, 14px)`
- Shadow `var(--epm-shadow, none)`; font: inherit unless `--epm-font` is set
- Motion `var(--epm-ease-out, cubic-bezier(0.23, 1, 0.32, 1))`
- Buttons: `.epm-x__button:not(#epm) { … }`, 44px, `:focus-visible` 2px
  accent outline

### Building a new frontend component or widget

1. **Markup in the Renderer.** Add a method to `includes/Renderer.php`
   that returns escaped HTML with BEM classes (`.epm-newthing`,
   `.epm-newthing__part`). Widgets, shortcodes and the automatic episode
   page all call it; never duplicate markup in a widget.
2. **Episode data only through `Episodes`.** Use
   `epm()->episodes->get_public_data()`; it hides unpublished and
   password-protected episodes and resolves audio (Media Library or audio
   URL), artwork, transcript files and video URLs.
3. **Styles from tokens.** Every color, radius, size and gap is a
   `var(--epm-…, literal)`. Derive sizes from `--epm-title-size`,
   `--epm-meta-size` and `--epm-gap` instead of inventing new ones. Add a
   token to `:where(:root)` only when Elementor controls need to set it.
   If the component paints no surface of its own, add it to the
   dark-design section surface selector.
4. **Buttons are theme-proof.** `.epm-newthing__button:not(#epm) { … }`
   with the usual resets, 44px minimum size, `:focus-visible` outline;
   titles and links get two-class selectors. Play toggles use
   `Renderer::play_toggle_icons()` and the `data-epm-play` /
   `data-epm-card-play` attributes so the one player engine drives them.
5. **States as classes.** `is-playing`, `is-active`, `is-copied`; hover
   gated to fine pointers; pressed via `:active` scale; errors in the
   shared error-box style; alternative labels stacked in one grid cell.
6. **Nothing from third parties before a click.** Facades for embeds,
   `preload="none"` for remote media.
7. **Elementor widget.** One widget per component in
   `includes/Elementor/Widgets/`, registered in `Widgets.php`, with the
   episode source control (current, latest, specific), Style Source *Use
   Global Podcast Styles* (emits nothing) or *Custom* (controls write
   `--epm-*` variables on `{{WRAPPER}}`, never properties), and an editor
   placeholder when it renders nothing.
8. **Shortcode.** Add a `[podcast_…]` shortcode for sites without
   Elementor, with `id` / `source` and yes/no attributes.
9. **Motion last.** Only if it explains a state change; transform or
   opacity, 150–300ms, `--epm-ease-out`, inside
   `prefers-reduced-motion: no-preference`, with an opacity-only version
   under `reduce`.
10. **Check** on the Neutral preset and a dark preset, at 320px and in a
    narrow column, with keyboard only and with a screen reader; add
    integration and browser tests (`tests/integration/frontend.php`,
    `tests/e2e/frontend.mjs`).

Example prompts:

- "Add a 'Season list' component: rows separated by 1px
  `var(--epm-border)` hairlines, season title at `var(--epm-title-size)`,
  episode count in muted tabular numbers at `var(--epm-meta-size)`, a
  list play button with the shared button shape
  (`var(--epm-button-radius)`), 44px minimum height, accent border and
  text while playing, and the dark-design section surface."
- "Add a 'Transcript search' field above the transcript: a native
  `<input type="search">` with a visible label at meta size, 44px high,
  1px `var(--epm-track)` border, `var(--epm-button-radius)`, matches
  highlighted with the accent at 20% behind the text, the count announced
  through the live region, no motion."

### Building a new preset

1. Start from `Presets::neutral()` (or `editorial()` for numbered rows)
   and override `tokens`: `accent`, `on_accent`, `text`, `muted`,
   `background`, `surface`, `border_color`, `border_radius`,
   `artwork_radius`, `spacing`, `title_font_size`, `meta_font_size`,
   `default_player_layout`, `default_episode_layout`, `button_shape`,
   `font_family`, `shadow`, `track_color`. Adjust the `visibility`,
   `player` and `episodeList` flags if the look needs it.
2. Take values from a DESIGN.md (for example one from awesome-design-md):
   the canvas and card colors, body text, secondary text, one accent,
   radii, spacing and the type scale. Map fonts to one of the generic
   stacks (`system`, `serif`, `rounded`, `mono`) or `inherit`.
3. Check contrast: text ≥ 7:1 and muted ≥ 4.5:1 on background and
   surface, on-accent ≥ 4.5:1 on accent, accent ≥ 4.5:1 on background,
   track ≥ 3:1 on both. The Design screen's contrast check shows the AA
   pairs live; write the measured ratios next to the values.
4. Give it a generic name and description. No brand names, logos,
   slogans or proprietary font names.
5. Register it through the `epm_presets` filter from a theme or a small
   plugin (or in `Presets::all()` for presets that ship with the plugin,
   together with a line in the tables above and a run of
   `tests/integration/admin.php`, which checks every preset against the
   contrast pairs and the Design preview):

```php
add_filter( 'epm_presets', function ( array $presets ) {
	$base = $presets['neutral'];
	$base['name']        = 'Harbor';
	$base['description'] = 'Deep teal accent on white, rounded buttons.';
	$base['tokens']      = array_merge(
		$base['tokens'],
		[
			'accent'       => '#0f766e', // White on accent 5.47:1.
			'on_accent'    => '#ffffff',
			'button_shape' => 'rounded',
			'shadow'       => 'soft',
		]
	);
	$presets['harbor'] = $base;
	return $presets;
} );
```

### Iteration guide

1. Change values before changing CSS: a token, a preset or an Elementor
   control solves most requests.
2. Keep the achromatic frame; one accent, used for interaction and state.
3. One spacing token, two type tokens, one button shape.
4. Hairlines first; shadows only through `--epm-shadow` (menus excepted).
5. 44px targets, visible focus, state on the control, tabular numbers,
   no width changes between states.
6. Motion confirms, never decorates; always a reduced-motion version.
7. Nothing loads from another server before the visitor asks.
8. Admin screens look like WordPress.

## Credits

The structure of this document follows the DESIGN.md files collected in
[VoltAgent/awesome-design-md](https://github.com/VoltAgent/awesome-design-md)
(MIT License, Copyright (c) 2026 VoltAgent), a format introduced by
Google Stitch. The presets `clean-light`, `soft-voice`, `warm-paper`,
`ink-mono`, `night-studio` and `midnight` reuse design values from that
collection's DESIGN.md files; no names, logos or copy were taken.

Platform glyphs: [Simple Icons](https://simpleicons.org) 16.33.0, CC0-1.0.
Logos are trademarks of their owners and only identify the platform a
link leads to.
