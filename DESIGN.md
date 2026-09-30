---
version: 1.3.0
name: Elementor Podcast Manager
description: "A theme-neutral podcast UI kit that lives inside other people's WordPress sites. Every visual value is a --epm-* custom property with a literal fallback at specificity 0, so the site's Global Podcast Styles, presets and per-widget Elementor controls decide the look, never the plugin code. Out of the box it is quiet: the theme's own fonts, white and near-white surfaces, 1px hairlines, a 12px container radius, one blue accent and a round play button. Character comes from presets (eleven, from neutral to dark studio looks), which set values only. The admin screens are the opposite case: they do not have a look of their own and stay native to wp-admin."

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

typography:
  font: "inherit (theme and Elementor Global Fonts)"
  title-size: 22px
  meta-size: 14px
  label: "meta-size, uppercase, letter-spacing 0.12em"
  times: "12px, tabular numbers"
  long-text-line-height: 1.7

rounded:
  container: 12px
  artwork: 8px
  card-artwork: 0
  button-rounded: 8px
  button-pill: 999px
  button-square: 2px
  play: 50%

spacing:
  gap: 24px
  gap-half: 12px

elevation:
  none: none
  soft: "0 1px 2px rgb(0 0 0 / 0.06), 0 4px 12px rgb(0 0 0 / 0.06)"
  lifted: "0 2px 6px rgb(0 0 0 / 0.08), 0 12px 32px rgb(0 0 0 / 0.12)"

motion:
  ease-out: "cubic-bezier(0.23, 1, 0.32, 1)"
  ease-in-out: "cubic-bezier(0.77, 0, 0.175, 1)"
  ease-drawer: "cubic-bezier(0.32, 0.72, 0, 1)"

components:
  player:
    backgroundColor: "{colors.background}"
    textColor: "{colors.text}"
    border: "1px solid {colors.border}"
    rounded: "{rounded.container}"
    padding: "{spacing.gap}"
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
`includes/Renderer.php` (markup), `admin/css/epm-app.css` and
`admin/css/epm-admin.css` (admin screens). If this file and the code
disagree, the code wins; fix this file.

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
  progress, the active chapter, focus rings and the main call to action.
- **Theme-proof.** Themes style bare `<button>` elements aggressively.
  Every button rule of the plugin carries `:not(#epm)` (ID-level
  specificity) so theme rules cannot restyle it, while Elementor controls
  still work because they set variables, not properties.
- **Calm motion.** A few short transform/opacity transitions that confirm
  state changes (play/pause, the sticky bar, the active chapter, button
  presses). Nothing moves on page load.

The **admin screens** follow the opposite rule: they have no look of
their own. They use the WordPress admin font, the admin color scheme's
highlight color (`--wp-admin-theme-color`), core buttons and form
controls, so the plugin feels like part of wp-admin.

**Key characteristics**

- Two-token type scale: a title size (22px) and a meta size (14px); all
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

Printed on `:root` by `DesignSettings::output_tokens()` from the design
option `epm_design_settings`. The values below are the defaults (the
Neutral preset).

| Token | Design option | Default | Role |
|---|---|---|---|
| `--epm-accent` | `accent` | `#1d4ed8` | Play button, progress fill and handle, active chapter marker, chapter times, focus outline, hover color of titles, current page in pagination, primary call to action |
| `--epm-on-accent` | `on_accent` | `#ffffff` | Glyphs and text on the accent |
| `--epm-text` | `text` | `#111827` | Titles, body text, controls at rest in the sticky bar |
| `--epm-text-muted` | `muted` | `#6b7280` | Labels, metadata, times, excerpts, secondary controls, subscribe links at rest |
| `--epm-background` | `background` | `#ffffff` | Player background |
| `--epm-surface` | `surface` | `#f9fafb` | Cards, the sticky bar, the error box |
| `--epm-border` | `border_color` | `#e5e7eb` | Hairlines: container borders, row separators, outline buttons |
| `--epm-track` | `track_color` | not printed | Unplayed part of timelines. When unset, the stylesheet derives it from the muted color |
| `--epm-danger` | (derived) | not printed on light designs | Error accents. Printed as `#f87171` when the background is dark |
| `--epm-image-outline` | (derived) | not printed on light designs | 1px outline that separates artwork from the background. Printed as `oklch(1 0 0 / 0.1)` when the background is dark |

A background counts as dark when its relative luminance is below 0.2
(`DesignSettings::is_dark()`).

Widget-level color variables are set only by Elementor controls with
Style Source *Custom* and have no `:root` value: `--epm-play-background`,
`--epm-play-color`, `--epm-play-background-hover`,
`--epm-play-color-hover`, `--epm-secondary-color`,
`--epm-secondary-hover`, `--epm-subscribe-hover`,
`--epm-hero-background` and `--epm-latest-background` (the last two have
`transparent` fallbacks).

### Contrast rules

Every preset shipped with 1.3.0 meets these ratios (WCAG relative
luminance formula), and a new preset must too:

| Pair | Minimum |
|---|---|
| text on background and on surface | 7:1 |
| muted on background and on surface | 4.5:1 |
| on-accent on accent | 4.5:1 |
| track on background and on surface | 3:1 |

The accent is also used as a text color (chapter times, hovered titles),
so keep it at 4.5:1 on the background as well.

### Presets

`includes/Presets.php`, filter `epm_presets`. Applying a preset writes
its values into Global Podcast Styles; they stay editable afterwards.

| Preset | Background | Surface | Text | Muted | Accent / on-accent | Radius / artwork | Gap | Title / meta | Buttons | Font | Shadow | Track | Player / list |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `neutral` | `#ffffff` | `#f9fafb` | `#111827` | `#6b7280` | `#1d4ed8` / `#ffffff` | 12 / 8 | 24 | 22 / 14 | rounded | inherit | none | derived | minimal / list |
| `minimal` | `#ffffff` | `#fafafa` | `#111111` | `#737373` | `#111111` / `#ffffff` | 6 / 6 | 16 | 18 / 13 | pill | inherit | none | derived | minimal / minimal |
| `editorial` | `#ffffff` | `#f7f7f5` | `#111111` | `#6b6b6b` | `#111111` / `#ffffff` | 4 / 4 | 32 | 32 / 14 | pill | inherit | none | derived | editorial / editorial-rows |
| `card` | `#ffffff` | `#f3f4f6` | `#1f2937` | `#646b78` | `#2563eb` / `#ffffff` | 20 / 16 | 24 | 20 / 14 | pill | inherit | none | derived | artwork / cards |
| `business-tuning` | `#07090a` | `#171c1e` | `#f2f0e9` | `#9ba1a4` | `#b9ff22` / `#07090a` | 4 / 4 | 32 | 34 / 14 | pill | inherit | none | derived | editorial / editorial-rows |
| `clean-light` | `#ffffff` | `#f5f5f7` | `#1d1d1f` | `#6e6e73` | `#0066cc` / `#ffffff` | 18 / 12 | 24 | 24 / 14 | pill | system | none | `#86868b` | artwork / cards |
| `soft-voice` | `#f5f5f5` | `#ffffff` | `#0c0a09` | `#6b665f` | `#292524` / `#ffffff` | 16 / 12 | 24 | 22 / 14 | pill | system | soft | `#8c867e` | full / list |
| `warm-paper` | `#faf9f5` | `#f0ebe1` | `#141413` | `#66645e` | `#b05a3c` / `#ffffff` | 12 / 8 | 28 | 26 / 14 | rounded | serif | soft | `#878175` | editorial / editorial-rows |
| `ink-mono` | `#ffffff` | `#fafafa` | `#171717` | `#5f5f5f` | `#171717` / `#ffffff` | 6 / 4 | 20 | 18 / 13 | square | mono | none | `#8f8f8f` | minimal / minimal |
| `night-studio` | `#121212` | `#1c1c1c` | `#ffffff` | `#b3b3b3` | `#1ed760` / `#000000` | 8 / 6 | 16 | 20 / 14 | pill | rounded | lifted | `#6f6f6f` | artwork / grid |
| `midnight` | `#08090a` | `#141516` | `#f7f8f8` | `#8a8f98` | `#828fff` / `#08090a` | 12 / 8 | 20 | 20 / 13 | rounded | system | none | `#62666d` | compact / list |

Border colors: neutral `#e5e7eb`, minimal `#e5e5e5`, editorial `#e2e2e2`,
card `#e5e7eb`, business-tuning `#303638`, clean-light `#e0e0e0`,
soft-voice `#e7e5e4`, warm-paper `#e6dfd8`, ink-mono `#ebebeb`,
night-studio `#2a2a2a`, midnight `#23252a`.

- `business-tuning` is the reference implementation for one client
  (values observed on businesstuning.at). It is the only preset tied to
  a real site, and it is values only.
- The six presets from `clean-light` to `midnight` are derived from the
  public design-language analyses in the awesome-design-md collection.
  They take values only (surface ladder, text and muted colors, one
  accent, radii, spacing, type scale, button shape, font family and
  elevation) and carry generic names. They contain no brand names, logos,
  copy or proprietary fonts; the font choice maps to a generic stack.
- Presets also set behavior maps (`visibility`, `player`, `episodeList`),
  for example which metadata a list shows. See `Presets::neutral()` for
  the keys.

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
| `--epm-app-ok`, `-warn`, `-error` (+ `-soft` backgrounds) | `#00753b`, `#8a5a00`, `#b32d2e` |

`epm-admin.css` (episode screen, settings, design, dashboard extras)
defines the same roles as `--epm-admin-*` on `:root`, plus a type scale
on WordPress's 13px base (`--epm-admin-text-sm` 12px, `-md` 13px,
`-lg` 14px).

## 3. Typography Rules

### Font family

The plugin sets **no font family by default**. Headings, body text and
buttons inherit the theme's fonts and Elementor's Global Fonts.

The *Font* design option (`font_family`) can choose a generic stack. It
is printed as `--epm-font`; with *Inherit* nothing is printed. When a
stack is chosen, `<body>` also gets the class `epm-custom-font`, so the
stylesheet can apply the font to headings and buttons inside podcast
components, which themes often style directly.

| Option | `--epm-font` |
|---|---|
| `inherit` | not printed |
| `system` | `system-ui, -apple-system, Segoe UI, Roboto, Helvetica Neue, Arial, sans-serif` |
| `serif` | `ui-serif, Georgia, Cambria, Times New Roman, Times, serif` |
| `rounded` | `ui-rounded, SF Pro Rounded, system-ui, -apple-system, sans-serif` |
| `mono` | `ui-monospace, SFMono-Regular, Menlo, Consolas, Liberation Mono, monospace` |

No web fonts are loaded by the plugin.

### Scale

Two tokens drive the scale: `--epm-title-size` (design option
`title_font_size`, default 22px) and `--epm-meta-size` (`meta_font_size`,
default 14px).

| Role | Size | Line height | Other |
|---|---|---|---|
| Player title, row and card title, section headings | title | 1.2–1.25 | |
| Compact player title | title × 0.75 | 1.2 | |
| Editorial player title | title × 1.4 | 1.2 | letter-spacing −0.01em |
| Row number (editorial rows) | title × 1.1 | | tabular numbers, muted |
| Episode header title | title × 1.6 | 1.15 | |
| Hero title | title × 1.8 | 1.1 | |
| Label (episode label, host line, card label) | meta | | uppercase, letter-spacing 0.12em, muted |
| Metadata, excerpts, guest role and bio, subscribe links, chapter rows | meta | | muted |
| Times, speed | 12px | | tabular numbers |
| Sticky bar title | 14px | | one line, ellipsis |
| Show notes, transcript | inherited | 1.7 | |

- Descriptions are limited to a comfortable measure: hero description
  60ch, episode header description 70ch.
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
`.epm-{component}--{modifier}`. State classes are `is-*` (`is-playing`,
`is-active`). Markup comes from `includes/Renderer.php` and is shared by
widgets, shortcodes and the automatic episode page.

### Player (one engine, five layouts)

Structure: artwork | main column (label, title, metadata, controls row
with the transport group and the timeline, secondary row, description,
chapters, platform links).

- Container: background `--epm-background`, 1px `--epm-border`, radius
  `--epm-radius`, padding and gap `--epm-gap`; inner gaps are half the
  gap.
- **Play button:** `--epm-play-size` (56px, never below 44px), circle
  (`--epm-play-radius`, 50%), background and border `--epm-accent`,
  glyph `--epm-on-accent` at 22px. Play and pause glyphs are stacked in
  one grid cell (`.epm-icon-swap`) and cross-fade from the button's own
  `.is-playing` class.
- **Skip buttons** (−15 s, +30 s): transparent, muted, 20px icon plus a
  12px number, 44px minimum height. Hover: text color.
- **Timeline:** a 20px-tall hit area (`role="slider"`) around a track of
  `--epm-progress-height` (4px) with fully rounded ends. The unplayed
  part uses the track color, the played part the accent, and a 14px
  accent handle marks the position. The value is announced as "1:05 of
  42:10".
- **Secondary row:** speed (a text button with a 1px border, 12px
  tabular value, button shape), volume (native range input with
  `accent-color`), download link. Icons 20px (`--epm-secondary-icon-size`).
- **Layouts** are configurations of the same markup:

| Layout | Artwork | Differences |
|---|---|---|
| Minimal | hidden | Play, title and timeline; secondary row and description hidden |
| Compact | 56px | Play 44px, title × 0.75, padding half the gap; secondary row and description hidden |
| Editorial | hidden | Title × 1.4; hairline above the controls row |
| Artwork | 160px | Stacks below 768px (artwork up to 320px wide) |
| Full | 140px | Everything, wraps; stacks below 768px |

States:

| State | Treatment |
|---|---|
| Idle | Play glyph |
| Playing | `.is-playing` on the player and on every play button of that episode (full player, sticky bar, card and row buttons); pause glyph |
| Hover (pointer devices) | Play button `filter: brightness(1.08)`; secondary controls switch from muted to text color |
| Focus | 2px solid accent outline, 2px offset, on `:focus-visible` only |
| Pressed | Play buttons scale to 0.96; list play and retry buttons to 0.97 |
| Error | `.epm-player__error`: surface background, 1px border, 3px left border in the danger color, 8px radius, message plus a Retry button (accent, button shape, 44px) |
| Fallback | `.epm-player--fallback` reveals the native `<audio>` element when the engine cannot start |

### Sticky mini player

A bar docked to the bottom edge (`position: fixed`, `z-index: 9990`),
`role="region"` labelled "Audio player". Surface background, 1px top
hairline, 10px × 20px padding. Artwork 44px with 8px radius; title 14px
on one line with ellipsis; time 12px tabular; a 16px-tall timeline around
a 3px track with a handle; controls (play, speed, close) with 44px
minimum targets and 22px icons. It is hidden until an episode plays and
slides in from the bottom edge.

### Episode lists

Five layouts (`list`, `editorial-rows`, `cards`, `grid`, `minimal`).

- **Rows** (`list`, `editorial-rows`, `minimal`): a hairline under each
  row, vertical padding `gap × 0.9`. Editorial rows use `gap × 1.25` and
  show the episode number at `title × 1.1` in muted tabular numbers.
  Minimal rows use half the gap and never show numbers. The aside (date,
  duration, play button) is right-aligned and moves under the content
  below 768px.
- **Cards:** surface background, 1px border, radius `--epm-radius`,
  artwork at 16:10 with `--epm-card-artwork-radius` (0), body padding
  `--epm-gap`, 8px between elements. **Grid** places cards in
  `repeat(auto-fill, minmax(260px, 1fr))`.
- **List play button:** transparent with a 1px border, text color,
  button shape, 8px × 16px padding, 44px minimum height, 14px glyph that
  swaps between play and pause like the main button. Hover (pointer
  devices) and playing: border and text in the accent.
- **Titles** link to the episode page; hover turns them accent.
- **Pagination:** 8px-radius chips with a 1px border; the current page
  is filled with the accent.
- **Empty state:** one line of muted text at meta size.

### Chapters

An ordered list. Each row is one seek button containing the time (accent,
tabular) and the title, so the part people read is the part they tap. An
optional link follows at the end of the row, muted, with the chapter
title as hidden text. Rows are separated by hairlines, 8px vertical
padding. The chapter that is playing (`.is-active`) gets a 3px accent
marker at its start edge and a 600-weight title; the marker grows in
while the previous one shrinks.

### Show notes and transcript

A heading at the configured level and the content at line height 1.7 in
the text color. A collapsible transcript is a `<details>` whose
`<summary>` contains the real heading, so heading navigation still finds
it while it is closed.

### Subscribe links

A wrapping list of links (gap `--epm-subscribe-gap`, 12px). Each link is
a chip: 1px border, button shape, 8px × 16px padding, meta size, muted
text; an 18px platform glyph (`--epm-subscribe-icon-size`) in
`currentColor`. Hover: text color (or `--epm-subscribe-hover`) and a
border in the current color. Three display modes: icon + text, text,
icon only. In icon-only mode a service without a recognizable glyph
still shows its label, so a row of identical fallback icons cannot
happen. The RSS link points to the public feed (the host's feed when
another host publishes the show).

Glyphs come from Simple Icons 16.33.0 (CC0) through
`includes/BrandIcons.php`. They are single-color by design; do not
recolor them per brand in the default styles, and follow each platform's
brand guidelines when you do. Brands that asked Simple Icons to remove
their logo get a neutral icon.

### Hero, latest episode, episode header, guest

- **Podcast hero:** artwork `min(320px, 40%)` with the artwork radius,
  title × 1.8, host line as a label, description muted at 60ch, actions
  in a wrapping row. Variants: centered, artwork right. Stacks below
  768px. The call to action is a filled accent button (on-accent text,
  button shape, 12px × 28px, weight 600; hover brightness 1.08).
- **Latest episode:** 120px artwork beside the title and description;
  optional call to action styled like the hero's.
- **Episode header:** artwork up to 280px, label, title × 1.6,
  description muted at 70ch.
- **Guest:** 72px round image (`--epm-guest-image-size`), name 600,
  role, company and bio muted at meta size.

### Editor placeholder

Only in the Elementor editor, for widgets that currently render nothing:
16px padding, 1px dashed border, 6px radius, 13px muted centered text.
Visitors never see it.

### Admin app components (`admin/css/epm-app.css`)

Shared by the setup assistant, Hosting & import, Distribution and the
dashboard. Scoped under `.epm-app` (max width 1040px; `--narrow` 760px).

| Component | Description |
|---|---|
| `.epm-app__header` | Eyebrow, 23px title, lede; actions on the right |
| `.epm-card` | White, 1px line, 8px radius, `--epm-app-shadow`, 24px padding; `__title`, `__lede`, `__footer` (hairline above, `--split` for back/next) |
| `.epm-steps` | Numbered step list; current step filled with the admin accent (`aria-current="step"`), done steps get a check in the ok color |
| `.epm-panel` | One step of the setup assistant; only one is visible; receives focus on change |
| `.epm-choice` | Radio/checkbox cards: 1px strong line, 8px radius, 16px padding; hover accent border; checked: accent border, soft accent background, inset accent ring; `--tile` variant with a 32px icon and hidden input |
| `.epm-field`, `.epm-field-row`, `.epm-check` | Label (600), help (13px muted), error (13px 600 error color); fields in auto-fit columns of at least 220px; checkbox and label form one hit target |
| `.epm-badge` | Pill status label: neutral, `--ok`, `--warn`, `--error`, `--info` |
| `.epm-callout` | 4px start border and soft background: info, `--warn`, `--error`, `--ok` |
| `.epm-copy` | Monospace value on the canvas color plus a copy button |
| `.epm-preview`, `.epm-facts` | Feed preview: 96px artwork (64px on phones), title, meta, key figures |
| `.epm-progress` | 8px track, bar scaled with `transform: scaleX(var(--epm-progress))`, label row |
| `.epm-log`, `.epm-checklist`, `.epm-kv`, `.epm-details` | Import log, numbered or status checklists, key-value lists, disclosure with a rotating chevron (44px summary) |
| `.epm-platform` | Distribution row: 40px icon tile, name with badges, actions, text and details |
| `.epm-artwork-picker` | 160px square preview with a dashed border until an image is chosen |
| `.epm-button-link` | Text button in the accent, underlined; `--muted`, `--danger` |
| Busy buttons | `aria-busy="true"`: label stays, a 12px spinner joins it, clicks are ignored |

Core `.button` / `.button-primary` classes are used for every button;
the app only sets minimum heights (32px, 40px for large).

## 5. Layout Principles

### Spacing

One token, `--epm-gap` (design option `spacing`, default 24px):

- player padding and the gap between artwork and main column: 1 × gap;
- gaps inside the player (label, title, controls): gap ÷ 2;
- row padding: gap × 0.9 (list), × 1.25 (editorial rows), ÷ 2 (minimal);
- hero gap: gap × 1.5; card body padding: 1 × gap;
- automatic episode page: 1 × gap between the parts.

Fixed micro spacing for small elements: 2, 4, 6, 8, 12 and 16px.

### Radius scale

| Element | Radius |
|---|---|
| Containers (player, cards) | `--epm-radius` (12px) |
| Artwork | `--epm-artwork-radius` (8px) |
| Card artwork | `--epm-card-artwork-radius` (0) |
| Text buttons (list play, speed, subscribe chips, calls to action, retry) | `--epm-button-radius`: 8px rounded, 999px pill, 2px square |
| Main play button | `--epm-play-radius` (50%) |
| Error box, pagination chips, sticky artwork | 8px |
| Editor placeholder | 6px |
| Timeline tracks | fully rounded |

Designs saved before 1.3.0 have no `button_shape`; they are read as
`pill`, which is what their text buttons looked like, so updating does
not change them. New designs default to `rounded`.

### Measure and alignment

Descriptions stop at 60–70ch. Components fill their column and never set
their own outer width; Elementor and the theme decide the grid. The
player's main column is a size container, so its internal layout reacts
to the column width, not the viewport.

### Admin layout

`.epm-app` caps content at 1040px (760px for the setup assistant) with
24px between cards and 16px/8px stacks (`.epm-stack`,
`.epm-stack--tight`). Hosting & import uses two columns (settings |
moving guides) from 1200px.

## 6. Depth & Elevation

| Level | Treatment | Use |
|---|---|---|
| Flat (default) | 1px `--epm-border` hairline, no shadow | Player, cards, rows, chips |
| Soft | `0 1px 2px rgb(0 0 0 / 0.06), 0 4px 12px rgb(0 0 0 / 0.06)` | `--epm-shadow` with shadow *soft* |
| Lifted | `0 2px 6px rgb(0 0 0 / 0.08), 0 12px 32px rgb(0 0 0 / 0.12)` | `--epm-shadow` with shadow *lifted* |
| Docked | fixed at the bottom, z-index 9990, top hairline | Sticky mini player |

`--epm-shadow` applies to the player, cards and the sticky bar. On dark
backgrounds a 1px light outline (`--epm-image-outline`) keeps artwork
edges visible.

Admin: cards use `--epm-app-shadow`
(`0 1px 1px rgb(0 0 0 / 0.04), 0 1px 3px rgb(0 0 0 / 0.06)`);
`--epm-app-shadow-raised` exists for raised surfaces
(`0 2px 4px rgb(0 0 0 / 0.06), 0 8px 24px rgb(0 0 0 / 0.08)`).

## 7. Do's and Don'ts

### Do

- Consume every value as `var(--epm-x, <literal fallback>)`.
- Put new global tokens in two places: the `:where(:root)` block of
  `epm-frontend.css` (literal value) and, if users can change them,
  `DesignSettings` (default, sanitizer, `output_tokens()`).
- Add `:not(#epm)` to every rule that styles a `<button>`, and reset the
  properties themes like to set (background, border, box-shadow, margin,
  padding, text-transform, letter-spacing, line-height, font-weight).
- Give every control that plays, seeks or toggles a 44px minimum hit
  area, a visible `:focus-visible` outline (2px accent, 2px offset) and
  an accessible name. Keep visible text part of the accessible name.
- Reflect state with a class on the control itself (`is-playing`,
  `is-active`) and `aria-pressed` / `aria-valuetext` where they apply.
- Use tabular numbers for anything that counts or ticks.
- Keep motion on `transform` and `opacity`, 100–300ms, with the easing
  tokens; exits faster than entries.
- Gate hover-only styles to `@media (hover: hover) and (pointer: fine)`
  so they do not stick after a tap.
- Test a new component on the Neutral preset and on a dark preset
  (`night-studio` or `midnight`), with Hello Elementor and a block theme.
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
- Don't animate width, height, padding, margins or colors on frequent
  interactions, and don't animate anything on page load.
- Don't wait for `transitionend`: Elementor turns transitions off under
  reduced motion, and some themes do too.
- Don't use the accent for decoration or large backgrounds; it marks
  interaction and state.
- Don't give the admin screens a custom visual identity (own fonts, brand
  colors, custom form controls).

## 8. Responsive Behavior

| Trigger | Change |
|---|---|
| Player main column ≤ 440px (container query) | The timeline moves to its own full-width row; controls wrap |
| Viewport ≤ 768px | Artwork and Full players stack (artwork full width up to 320px); episode rows wrap and their aside becomes a left-aligned row; the hero stacks (artwork up to 280px); the volume slider shrinks to 56px |
| Grid list | `auto-fill` columns of at least 260px |
| Admin ≤ 600px | Distribution rows drop the action column under the text |
| Admin ≤ 480px | Key-value lists and the artwork picker become one column; feed preview artwork 64px |
| Admin ≥ 1200px | Hosting & import shows two columns |

Touch: all playback controls keep 44px targets at every width; hover
styles apply only on devices with a fine pointer.

### Motion and reduced motion

Motion is opt-in: it is declared inside
`@media (prefers-reduced-motion: no-preference)` or replaced by a shorter
opacity-only version under `prefers-reduced-motion: reduce`.

| Interaction | Motion | Reduced motion |
|---|---|---|
| Play/pause glyph | Cross-fade: opacity with scale 0.25 → 1, 150ms, ease-out | Opacity only, 100ms |
| Sticky bar | Enters with `translateY(100%)` → 0 in 300ms (ease-drawer); leaves in 200ms (ease-out) | Opacity fade, 150ms |
| Active chapter | Marker scales in (`scaleY`), row content shifts 10px, 200ms, ease-out | Marker fades in 150ms; no shift |
| Press | Play buttons scale 0.96, list play and retry 0.97, 160ms, ease-out | Scale 0.98 |
| Admin setup step | New panel fades and rises 8px, 240ms | Fade, 160ms |
| Admin choice card press | Scale 0.99, 100ms | none |
| Admin progress bar | `scaleX`, 300ms | none |

Easing tokens (frontend `:where(:root)`, admin `:root`):

- `--epm-ease-out: cubic-bezier(0.23, 1, 0.32, 1)` — entries and state
  changes
- `--epm-ease-in-out: cubic-bezier(0.77, 0, 0.175, 1)` — movement between
  two positions
- `--epm-ease-drawer: cubic-bezier(0.32, 0.72, 0, 1)` — elements docked
  to an edge (sticky bar)

## 9. Agent Prompt Guide

### Quick reference

- Background `var(--epm-background, #ffffff)`, surface
  `var(--epm-surface, #f9fafb)`, text `var(--epm-text, #111827)`, muted
  `var(--epm-text-muted, #6b7280)`, hairline `var(--epm-border, #e5e7eb)`,
  accent `var(--epm-accent, #1d4ed8)` on `var(--epm-on-accent, #ffffff)`
- Radius `var(--epm-radius, 12px)`, artwork `var(--epm-artwork-radius, 8px)`,
  text buttons `var(--epm-button-radius, 8px)`
- Spacing `var(--epm-gap, 24px)`; title `var(--epm-title-size, 22px)`,
  meta `var(--epm-meta-size, 14px)`
- Shadow `var(--epm-shadow, none)`; font: inherit unless `--epm-font` is set
- Motion `var(--epm-ease-out, cubic-bezier(0.23, 1, 0.32, 1))`

### Building a new frontend component or widget

1. **Markup in the Renderer.** Add a method to `includes/Renderer.php`
   that returns escaped HTML with BEM classes (`.epm-newthing`,
   `.epm-newthing__part`). Widgets, shortcodes and the automatic episode
   page all call it; never duplicate markup in a widget.
2. **Episode data only through `Episodes`.** Use
   `epm()->episodes->get_public_data()`; it hides unpublished and
   password-protected episodes and resolves audio (Media Library or audio
   URL) and artwork.
3. **Styles from tokens.** Every color, radius, size and gap is a
   `var(--epm-…, literal)`. Derive sizes from `--epm-title-size`,
   `--epm-meta-size` and `--epm-gap` instead of inventing new ones. Add a
   token to `:where(:root)` only when Elementor controls need to set it.
4. **Buttons are theme-proof.** `.epm-newthing__button:not(#epm) { … }`
   with the usual resets, 44px minimum size, `:focus-visible` outline.
   Play toggles use `Renderer::play_toggle_icons()` and the
   `data-epm-play` attribute so the one player engine drives them.
5. **States as classes.** `is-playing`, `is-active`; hover gated to
   fine pointers; pressed via `:active` transforms; errors in the shared
   error-box style.
6. **Elementor widget.** One widget per component in
   `includes/Elementor/Widgets/`, with the episode source control
   (current, latest, specific), Style Source *Use Global Podcast Styles*
   (emits nothing) or *Custom* (controls write `--epm-*` variables on
   `{{WRAPPER}}`, never properties), and an editor placeholder when it
   renders nothing.
7. **Shortcode.** Add a `[podcast_…]` shortcode for sites without
   Elementor.
8. **Motion last.** Only if it explains a state change; transform or
   opacity, 150–300ms, `--epm-ease-out`, inside
   `prefers-reduced-motion: no-preference`, with an opacity-only fallback.
9. **Check** on the Neutral preset and a dark preset, at 320px and in a
   narrow column, with keyboard only and with a screen reader.

Example prompts:

- "Add a 'Season list' component: rows separated by 1px
  `var(--epm-border)` hairlines, season title at `var(--epm-title-size)`,
  episode count in muted tabular numbers at `var(--epm-meta-size)`, a
  list play button with the shared pill/rounded button shape
  (`var(--epm-button-radius)`), 44px minimum height, accent border and
  text while playing."
- "Style a share button for the player's secondary row: transparent,
  `var(--epm-secondary-color, var(--epm-text-muted))`, 20px icon, 44px
  hit area, `:not(#epm)` resets, text color on hover for fine pointers."

### Building a new preset

1. Start from `Presets::neutral()` (or `editorial()` for numbered rows)
   and override `tokens`: `accent`, `on_accent`, `text`, `muted`,
   `background`, `surface`, `border_color`, `border_radius`,
   `artwork_radius`, `spacing`, `title_font_size`, `meta_font_size`,
   `default_player_layout`, `default_episode_layout`, `button_shape`,
   `font_family`, `shadow`, `track_color`. Adjust `visibility`, `player`
   and `episodeList` flags if the look needs it.
2. Take values from a DESIGN.md (for example one from awesome-design-md):
   the canvas and card colors, body text, secondary text, one accent,
   radii, spacing and the type scale. Map fonts to one of the generic
   stacks (`system`, `serif`, `rounded`, `mono`) or `inherit`.
3. Check contrast: text ≥ 7:1 and muted ≥ 4.5:1 on background and
   surface, on-accent ≥ 4.5:1 on accent, track ≥ 3:1 on both, accent
   ≥ 4.5:1 on background. Write the measured ratios next to the values.
4. Give it a generic name and description. No brand names, logos,
   slogans or proprietary font names.
5. Register it through the `epm_presets` filter from a theme or a small
   plugin (or in `Presets::all()` for presets that ship with the plugin):

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
4. Hairlines first; shadows only through `--epm-shadow`.
5. 44px targets, visible focus, state on the control, tabular numbers.
6. Motion confirms, never decorates; always a reduced-motion version.
7. Admin screens look like WordPress.

## Credits

The structure of this document follows the DESIGN.md files collected in
[VoltAgent/awesome-design-md](https://github.com/VoltAgent/awesome-design-md)
(MIT License, Copyright (c) 2026 VoltAgent), a format introduced by
Google Stitch. The presets `clean-light`, `soft-voice`, `warm-paper`,
`ink-mono`, `night-studio` and `midnight` reuse design values from that
collection's analyses; no names, logos or copy were taken.

Platform glyphs: [Simple Icons](https://simpleicons.org) 16.33.0, CC0-1.0.
Logos are trademarks of their owners and only identify the platform a
link leads to.
