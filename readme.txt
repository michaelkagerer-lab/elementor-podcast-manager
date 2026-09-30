=== Elementor Podcast Manager ===
Contributors: internal
Tags: podcast, elementor, audio player, rss, episodes
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage and display a podcast on Elementor websites. Episodes, RSS feed, custom audio player, and Elementor widgets — no external podcast platform required.

== Description ==

Elementor Podcast Manager turns a WordPress + Elementor website into a complete podcast home:

* **Podcast → Add Episode** — title, audio upload (MP3/M4A), description, publish. Duration and file size are detected automatically.
* **Automatic podcast RSS feed** at `/podcast/feed/` — ready to submit to Apple Podcasts, Spotify, Pocket Casts, Overcast and any RSS podcast client.
* **One custom audio player engine** (vanilla JS, no frameworks) with five layout configurations: Minimal, Compact, Editorial, Artwork, Full.
* **Elementor widgets** (Podcast category): Podcast Player, Episode List, Latest Episode, Podcast Hero, Episode Header, Episode Metadata, Guest, Subscribe Links, Transcript, Chapters.
* **Current Episode support** — build one Elementor Theme Builder Single Podcast Episode template; every episode renders automatically. Editors never open Elementor to publish.
* **Global Podcast Styles** (Podcast → Design) with a JSON-portable preset system. Ships with Neutral, Minimal, Editorial, Card and Business Tuning presets.
* **Brand-independent architecture** — three layers (podcast engine, UI components, Elementor presentation). No hardcoded branding anywhere.

The plugin works without Elementor (management + RSS keep working); only the widgets require it.

== Installation ==

1. Upload the `elementor-podcast-manager` folder to `/wp-content/plugins/`.
2. Activate the plugin. Rewrite rules are flushed automatically.
3. Go to **Podcast → Podcast Settings**, enter title, description, artwork, owner details.
4. Go to **Podcast → Add Episode**, upload audio, publish.
5. Copy the RSS feed URL from **Podcast → Dashboard** and submit it to podcast directories.
6. In Elementor, add the Podcast widgets and style them with the site's Global Colors/Fonts.

== Frequently Asked Questions ==

= Where is the RSS feed? =
At `https://your-site.com/podcast/feed/`. Find it any time under Podcast → Dashboard or Podcast → Podcast Settings → Distribution.

= Do I need Elementor? =
Only for the widgets. Episode management, audio uploads and the RSS feed work without it.

= Will my episodes survive if I remove Elementor? =
Yes. Episodes are normal WordPress posts, audio stays in the Media Library, the feed keeps working.

= How do I build the episode template? =
With Elementor Pro's Theme Builder, create a Single template, set the condition to Podcast Episodes, and drop in Episode Header, Podcast Player, Guest, Show Notes, Chapters and Transcript widgets with data source "Current Episode". Without Elementor Pro, use the shortcodes ([podcast_player], [podcast_latest], [podcast_episodes], [podcast_latest_cta]) in any theme template.

== Changelog ==

= 1.1.0 =
* Audio editor: media selection and drag-and-drop upload update the episode editor in place (no reload, no lost fields); upload progress, validation and error states; WAV flagged as internal-only.
* Episode save: audio attachment validated (existence, audio MIME); stale duration/size cleared on replace/remove; manual duration format validated; admin notices for failures.
* Feed: immutable per-episode GUIDs (existing episodes keep their issued GUIDs; new episodes get domain-independent URNs); RSS language tag normalization; explicit feed-window policy setting (default 500, 0 = unlimited); eligibility filtering before windowing; serial podcasts ordered oldest-first; plain-permalink feed URL support; correct XML URL escaping.
* Visibility: public widgets/shortcodes/lists/feeds only expose published, non-password episodes; authorized editor preview for permitted users; password-protected posts excluded everywhere public.
* Player engine rewritten: one PlaybackController contract for full/card/row/sticky; episode-scoped shared state; idempotent per-widget Elementor initialization; chapters bound to their episode (work before playback); sticky syncs with the active audio.
* Accessibility: moving timeline handle, accurate slider ARIA, synced play/pause labels, speed announcements, media error + retry states, native-audio fallback, keyboard seeking, 44px touch targets, on-accent foreground token.
* Design tokens: removed self-referential variables; single :root source; documented precedence (theme → global styles → preset → widget override); genuine inherit state; Elementor Global Colors/Fonts compatible.
* Elementor: duplicate style_source control IDs removed; every visible control audited (dead controls fixed or explicitly restricted); asset depends declared per widget; new AJAX searchable episode select (no 100-item cap); new Show Notes widget (11 widgets total).
* Presets: applying a preset now applies visibility/player/list settings too; preset identity preserved on customized saves; versioned export/import of the client design (no IDs or URLs exported); effective-design summary (customized vs inherited).
* Repeaters: stable unique row keys (no data loss on delete+add); accessible move up/down reordering for chapters and links.
* Content: editable show notes; per-episode platform links (UI + save + renderer merge); defined canonical URL behavior; effective author fallback chain; automatic latest-episode CTA ([podcast_latest_cta]); episode meta registered for REST/dynamic tags.
* Capabilities: epm_cap_manage_episodes / epm_cap_manage_podcast filters now govern CPT operations, menus, editor, meta saves, uploads, settings pages and REST — not just menu visibility.
* Readiness: distribution readiness report on the dashboard (metadata, category, language, artwork, episodes, media, duplicate enclosures).
* Performance: per-request episode data cache; bulk attachment priming for lists/feeds; deliberate preload="metadata" policy.
* Docs: honest verification report; no unmeasured compatibility claims.

= 1.0.0 =
* Initial release.
