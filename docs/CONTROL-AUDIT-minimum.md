# Minimum-version control audit

Measured on WordPress 6.2 Multisite, Elementor 3.12.2, PHP 8.3 and MariaDB 11.4.
All 88 controls must work and this table must match exactly. The primary
`CONTROL-AUDIT.md` remains unchanged for the current environment. Four controls
change a different ancestor first under the older layout engine; their own
intended properties still change. This is an additional measured baseline,
not an exemption from the runtime assertions.

<!-- style-audit:start -->
| Widget | Control | Label | State | Result | First element changed |
|---|---|---|---|---|---|
| Podcast Player | `container_background` | Container → Background | rest | works | `.epm-player` |
| Podcast Player | `container_radius` | Container → Border Radius | rest | works | `.epm-player` |
| Podcast Player | `container_padding` | Container → Padding | rest | works | `.epm-player-frame` |
| Podcast Player | `container_gap` | Container → Gap | rest | works | `.epm-player-frame` |
| Podcast Player | `artwork_size` | Artwork → Size | rest | works | `.epm-player-frame` |
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
| Episode List | `list_meta_typography (group)` | Typography → Metadata | rest | works | `.epm-episode-list` |
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
| Podcast Hero | `hero_title_typography (group)` | Hero → Title | rest | works | `.epm-podcast-hero` |
| Podcast Hero | `hero_description_typography (group)` | Hero → Description | rest | works | `.epm-podcast-hero` |
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
