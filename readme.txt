=== Elementor Podcast Manager ===
Contributors: internal
Tags: podcast, elementor, audio player, rss, episodes
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Run a podcast website on Elementor: host episodes and the RSS feed yourself, or connect a show hosted at Spotify for Creators or any other host.

== Description ==

Elementor Podcast Manager turns a WordPress + Elementor website into the home of a podcast, whichever way the show is hosted:

* **Host the show on your website.** Podcast → Add Episode: title, drop the audio (MP3/M4A), description, publish. Duration and file size are detected automatically. Audio can also come from a CDN or storage bucket URL. The plugin publishes a directory-ready RSS feed at `/podcast/feed/`.
* **Or keep your current host.** Spotify for Creators, Buzzsprout, Libsyn, Podbean, Transistor, Captivate, RSS.com, Acast, Podigee, Simplecast, Megaphone or any other host keeps publishing the feed. The plugin imports the episodes, checks the host's feed every hour for new and changed episodes, keeps edits made on the website, and redirects the site's own feed address to the host's feed.
* **Move a show in either direction.** Import a show from another host with its audio, episode IDs (GUIDs) and podcast GUID, then redirect the old feed. Or hand the show to a new host, check that it kept the episode IDs, and redirect this site's feed there.
* **Setup assistant.** Three paths (host on this website, move my podcast here, keep my current host), show details and artwork checks, a design preset and an optional podcast page.
* **Distribution center.** Step-by-step submission to Apple Podcasts, Spotify, YouTube, Amazon Music, Podcast Index, iHeartRadio, Pocket Casts, Deezer and more, with progress tracking. Listing links become subscribe buttons automatically.
* **Directory-ready RSS feed:** Apple categories and subcategories, explicit flags, seasons, episode types, rich show notes, plus Podcasting 2.0 chapters, transcripts, GUID, lock, funding, medium and person tags. Cached, with conditional-GET support.
* **Distribution readiness report** on the dashboard before you submit.
* **Automatic episode pages** with any theme: player, guest, show notes, chapters and transcript, plus schema.org podcast structured data.
* **One custom audio player engine** (vanilla JS) with five layouts, a sticky mini player, lock-screen controls, resume position and remembered speed.
* **Eleven Elementor widgets** (Podcast category): Podcast Player, Episode List, Latest Episode, Podcast Hero, Episode Header, Episode Metadata, Guest, Subscribe Links, Transcript, Show Notes, Chapters — with Theme Builder "Current Episode" support and a searchable episode picker.
* **Shortcodes** for every component, for pages without Elementor.
* **Global Podcast Styles** (Podcast → Design) with presets, button shape, font, shadow and track color settings, and design export/import.
* **WP-CLI:** `wp podcast import`, `wp podcast sync`, `wp podcast status`.
* **Brand-independent architecture** — three layers (podcast engine, UI components, Elementor presentation). No hardcoded branding.

The plugin works without Elementor (publishing, feed, import and sync, episode pages and shortcodes); only the widgets require it.

Host, platform and brand names are trademarks of their owners and only identify the services. Platform icons come from Simple Icons (CC0).

== Installation ==

1. Upload the `elementor-podcast-manager` folder to `/wp-content/plugins/`.
2. Activate the plugin. Rewrite rules are flushed automatically. On a site without a podcast, the setup assistant opens.
3. Follow the setup assistant, or configure by hand:
   * **Podcast → Hosting & import:** keep "This website", or choose "Another podcast host" and paste its feed address.
   * **Podcast → Podcast Settings:** title, description, artwork, owner details.
   * **Podcast → Add Episode:** upload audio, publish.
4. **Podcast → Distribution:** copy the feed address and submit it to Apple Podcasts, Spotify and the other platforms.
5. In Elementor, add the Podcast widgets and style them with the site's Global Colors/Fonts.

Importing and syncing need outbound HTTPS requests from the server, a writable uploads folder and WP-Cron (or a server cron job). Hosting audio on the website needs HTTPS and a web server that answers HEAD and byte-range requests; see `docs/HOSTING.md`.

== Frequently Asked Questions ==

= Where is the RSS feed? =
When the website hosts the show: at `https://your-site.com/podcast/feed/`. When another host publishes the show, the host's feed is the one listeners use, and `/podcast/feed/` redirects to it. Podcast → Distribution always shows the address to submit.

= Can I keep my podcast on Spotify for Creators (or another host)? =
Yes. Choose "Keep my current host" in the setup assistant or "Another podcast host" under Podcast → Hosting & import and paste the host's RSS feed address. On Spotify for Creators it is under Settings → Availability → RSS distribution (RSS has to be turned on there; shows without RSS distribution have no public feed). The website checks the host's feed every hour through WP-Cron; "Sync now" checks immediately.

= How do I move my podcast to my website? =
Choose "Move my podcast to this website" in the setup assistant. The plugin imports every episode with its audio and keeps the episode IDs, so apps do not show duplicates. Then set a permanent (301) redirect from the old feed to this site's feed at the old host and keep the old account for at least four weeks. The full procedure is in `docs/HOSTING.md`.

= Will importing twice create duplicate episodes? =
No. Episodes are matched by their GUID; existing episodes are updated, and fields you edited on the website are kept.

= Do I need Elementor? =
Only for the widgets. Episode management, audio uploads, import and sync, and the RSS feed work without it.

= Will my episodes survive if I remove Elementor? =
Yes. Episodes are normal WordPress posts, audio stays in the Media Library, the feed keeps working.

= How do I build the episode template? =
You don't have to: episode pages get the player and all episode details automatically. For a custom design, use Elementor Pro's Theme Builder: create a Single template for Podcast Episodes with the Episode Header, Podcast Player, Guest, Show Notes, Chapters and Transcript widgets set to "Current Episode" (the automatic content is then skipped). Without Elementor Pro, use the shortcodes ([podcast_player], [podcast_episodes], [podcast_subscribe], [podcast_chapters], [podcast_transcript] …).

= Which audio formats are supported? =
Uploads: MP3 and M4A are distributed in the feed; WAV can be stored but is excluded from the feed (the episode list marks it "Not in feed"). Imported episodes can also carry AAC and MP4 video enclosures, which stay in the feed.

= Can I use the block editor for episodes? =
Episodes use the classic screen so the audio upload sits right under the title. Add `add_filter( 'epm_use_block_editor', '__return_true' );` to switch.

== Changelog ==

= 1.3.0 =
* Added: hosting modes. "This website" publishes the feed as before; "Another podcast host" mirrors a show hosted at Spotify for Creators, Buzzsprout, Libsyn or any other host, syncs it hourly and redirects the site's feed to the host's feed (301).
* Added: feed import from a feed address, an Apple Podcasts link or a web page; tolerant parser for feeds from any host; paged feeds; batched background import; episode GUIDs and the podcast GUID kept; chapters and transcripts converted; optional copy of audio and images into the Media Library; locked feeds require ownership confirmation.
* Added: sync that keeps local edits, never changes anything on an empty or suddenly shrunken feed, follows feed moves, optionally unpublishes removed episodes, and backs off after failures.
* Added: episodes with an external audio URL (CDN, storage bucket or host).
* Added: setup assistant with three paths (host here, move here, keep current host); opens once after activation on a site without a podcast.
* Added: Distribution screen with submission steps and progress for Apple Podcasts, Spotify, YouTube, Amazon Music, Podcast Index and 14 more platforms; listing links become subscribe buttons.
* Added: platform icons (Simple Icons, CC0) for subscribe links; link service detection.
* Added: podcast:medium and podcast:person in the feed; schema.org podcast structured data and og:audio on episode pages; Podcast Index notification when an episode is published.
* Added: WP-CLI commands `wp podcast import|sync|status`.
* Added: design settings for button shape, font family, shadow and timeline track color; presets derived from the awesome-design-md collection (MIT, design values only).
* Changed: AAC and MP4/M4V/MOV video enclosures are distributed (for imported shows); uploads are unchanged.
* Changed: deactivation clears the plugin's scheduled events; uninstall always removes them and temporary import files.

= 1.2.0 =
* Fixed: /podcast/feed/ served WordPress's generic RSS instead of the podcast feed.
* Fixed: episode capabilities removed edit_posts from every user (administrators could not edit posts).
* Fixed: show notes and transcripts lost their HTML on save; bios lost line breaks.
* Fixed: Global Podcast Styles were ignored on classic themes; theme button styles restyled the player.
* Fixed: duplicate Elementor control ID, Elementor init timing, episode picker issues, front-page pagination, latest/current episode resolution.
* Added: automatic episode pages, Podcasting 2.0 tags (chapters, transcripts, GUID, lock, funding), Apple subcategories, content:encoded, feed caching with 304s.
* Added: lock-screen controls, resume position, remembered speed, active chapters; editor placeholders; new shortcodes; audio upload under the title.
* Added: automated integration, HTTP and browser test suites.

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

== Upgrade Notice ==

= 1.3.0 =
Adds hosting modes (keep Spotify for Creators or another host), feed import and sync, a setup assistant and a distribution center. Existing self-hosted sites keep working unchanged; see MIGRATION.md.
