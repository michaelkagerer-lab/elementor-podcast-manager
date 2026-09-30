# Hosting, importing and moving a podcast

This guide covers version 1.3.0. It explains where a show can be hosted,
what the plugin does in each mode, and how to move a show to this website
or away from it.

Host-specific steps come from each host's own help pages and from the
plugin's host registry (`includes/Providers.php`). They were checked on
2026-09-30. Hosts rename menus from time to time; where a step could not
be verified, this guide says so.

Getting listed on Apple Podcasts, Spotify and other apps is covered in
[DISTRIBUTION.md](DISTRIBUTION.md).

## Contents

1. [Choosing a mode](#1-choosing-a-mode)
2. [Hosting on this website](#2-hosting-on-this-website)
3. [Keeping Spotify for Creators or another host](#3-keeping-spotify-for-creators-or-another-host)
4. [What the sync does and does not do](#4-what-the-sync-does-and-does-not-do)
5. [Importing episodes](#5-importing-episodes)
6. [Moving a show to this website](#6-moving-a-show-to-this-website)
7. [Moving a show away from this website](#7-moving-a-show-away-from-this-website)
8. [Troubleshooting](#8-troubleshooting)

## 1. Choosing a mode

The *host* is where the audio files and the RSS feed live. Apple Podcasts,
Spotify and every other app read the RSS feed. The website shows the
episodes in both modes: episode pages, players, lists and the Elementor
widgets work the same way.

| | This website (`self`) | Another podcast host (`external`) |
|---|---|---|
| Who publishes the RSS feed | This site, at `/podcast/feed/` | The host (Spotify for Creators, Buzzsprout, Libsyn …) |
| Where you publish episodes | Podcast → Add episode | At the host; the site picks them up |
| Where the audio is served from | The Media Library, or any audio URL you enter | The host |
| What `/podcast/feed/` returns | The podcast feed | A permanent (301) redirect to the host's feed (can be turned off) |
| Hosting costs | Your web hosting and its bandwidth | The host's plan |
| Download statistics | Your server's logs, or a measurement prefix (OP3, Podtrac or another service) | The host's statistics |

Choose **this website** when you want no hosting fees and your server can
deliver large audio files reliably (see [section 2](#2-hosting-on-this-website)).

Choose **another host** when the show already lives at a host and should
stay there, or when you want the host's statistics, monetization or
distribution tools. The website then acts as the show's home page.

**Where to set it:** the setup assistant (Podcast → Setup assistant) asks
once, with three choices:

- *Host it on this website*: mode `self`.
- *Move my podcast to this website*: imports every episode from the old
  host, then mode `self`. See [section 6](#6-moving-a-show-to-this-website).
- *Keep my current host*: imports the episodes, then mode `external` with
  hourly sync and the feed redirect turned on. The mode switches in the
  assistant's second step, once the host's feed address is known.

You can change the mode later under **Podcast → Hosting & import**.
*Another podcast host* needs the host's feed address: saving the mode
without one keeps *This website* (or the address saved before) and says
so. The site also switches back to *This website* by itself when a move
import finishes ([section 6](#6-moving-a-show-to-this-website)) and when
the sync sees the host's feed redirect to this site's feed, so the two
feeds can never redirect to each other.

## 2. Hosting on this website

The plugin publishes the feed at `https://your-site/podcast/feed/`. With
plain permalinks the address is `https://your-site/?epm_podcast_feed=1`.
The address does not depend on the theme, and the show's
`<podcast:guid>` is stored once and never changes.

### Server requirements

The plugin writes the feed. The audio files themselves are delivered by
your web server or by the storage service they live on, not by the
plugin. Check these points with your hosting company:

- **HTTPS.** Serve the site and the audio over `https://`. The readiness
  report warns about `http://` audio URLs because some apps refuse them.
  Spotify's delivery specification (v1.10) requires HTTPS when the app
  streams audio directly from your server.
- **HEAD and byte-range requests.** Apple Podcasts asks hosting servers to
  answer HTTP `HEAD` requests and byte-range requests (`Range:` headers,
  answered with `206 Partial Content`), so apps can check a file and
  stream or resume it. The Pandora/SiriusXM submission has the same
  requirement. Most web servers do this for static files; check with
  `curl -I https://your-site/wp-content/uploads/…/episode.mp3` and
  `curl -r 0-99 -o /dev/null -w '%{http_code}\n' https://…/episode.mp3`
  (expect `206`).
- **Upload size.** WordPress's upload limit comes from the PHP settings
  `upload_max_filesize` and `post_max_size` (Media → Add New shows the
  current maximum). A 60-minute MP3 at 128 kbit/s is about 58 MB
  (60 × 60 s × 128,000 bit/s ÷ 8). Spotify's specification recommends
  MP3 at 128 kbit/s or more, or MP4/AAC-LC, and supports episodes up to
  12 hours.
- **Bandwidth.** Every download of every episode is served by your
  server. Popular shows can exceed a shared hosting plan; in that case
  put the audio on a CDN or storage bucket (next section).
- **No bot protection on the feed and audio.** Podcast apps and directory
  crawlers are automated clients. A captcha or "checking your browser"
  page in front of `/podcast/feed/` or the audio files stops them (see
  [troubleshooting](#bot-protection-pages)).

**Podcast → Distribution → Test feed and audio delivery** checks these
points from the server: the feed answers with HTTP 200 and RSS, the feed
address uses HTTPS, the newest episode's audio answers a `HEAD` request
with 200, a `Content-Length` and an audio or video type, and a byte-range
request with `206`. The requests come from the site's own server, so a
firewall or CDN rule that treats outside visitors differently is not
covered; check from outside with `curl` as well.

### Where the audio can live

Each episode has one audio source:

1. **A Media Library file** (Episode Audio on the episode screen). MP3 and
   M4A are distributed. WAV can be stored but is left out of the feed.
2. **An audio URL** on another server: a CDN, an S3-compatible bucket
   (Amazon S3, Cloudflare R2, Bunny Storage, Backblaze B2 …) or an old
   host. On the episode screen, open *Use an audio URL instead* under
   Episode Audio and paste the direct link to the MP3 or M4A file. It is
   used only when no Media Library file is attached. When the episode is
   saved (or with *Check URL*), the plugin asks the server for the file's
   size and type (a `HEAD` request, or a one-byte range request when the
   server refuses `HEAD`) and stores them. The URL, MIME type and size in
   bytes are stored in the episode meta `_epm_audio_url`,
   `_epm_audio_type` and `_epm_audio_length`. The feed needs the size for
   the `<enclosure length>` attribute; when it cannot be read, the
   readiness report warns and the feed reports length 0. Imported
   episodes get all three values from the source feed. The fields are
   also writable through the REST API (`meta._epm_audio_url` …) by anyone
   who can edit the episode.

Players on the website request audio from another domain only when a
visitor presses play (`preload="none"`), so page views do not count as
downloads at the host or the measurement service, and no visitor address
reaches them before that. Audio on the site's own domain keeps
`preload="metadata"`. The `epm_player_preload` filter changes this.

Uploaded `.m4a` and `.m4b` files are announced as `audio/x-m4a`
(WordPress files them as `audio/mpeg`).

The feed carries these types: `audio/mpeg`, `audio/mp4`, `audio/x-m4a`,
`audio/aac`, `video/mp4`, `video/x-m4v` and `video/quicktime`. The video
and AAC types are there so that imported shows keep every episode; new
uploads through the episode screen are limited to MP3, M4A and WAV. The
list can be changed with the `epm_distribution_audio_mimes` filter.

### Download statistics

A self-hosted feed can route its audio links through a measurement
service (Podcast → Hosting & import → *Download statistics*): OP3
(`https://op3.dev/e/`, free, statistics are public at op3.dev), Podtrac
(`https://dts.podtrac.com/redirect.mp3/`, free account) or the prefix
address of another service. The prefix is put in front of every
`<enclosure>` URL (and `<podcast:trailer>`) in the feed; `https://` of
the original URL is dropped, as these services expect, and a URL that
already passes through the same service is left alone. Players on the
website and the audio files themselves are unchanged.

Changing the setting changes every enclosure URL in the feed. Episode
GUIDs do not change, so apps do not list episodes twice. The setting has
no effect in external mode, where the host decides. More services can be
added with the `epm_stats_services` filter.

## 3. Keeping Spotify for Creators or another host

In external mode the host keeps publishing the feed. The website mirrors
the episodes into WordPress so it has pages, players and widgets for
them, and checks the host's feed for changes on a schedule.

### Setting it up

1. Podcast → Setup assistant → *Keep my current host*, or Podcast →
   Hosting & import → *Another podcast host*.
2. Choose the host and paste the address of its RSS feed. The field also
   accepts:
   - an **Apple Podcasts show link** (`https://podcasts.apple.com/…/id123456789`);
     the plugin asks Apple's public lookup API for the feed address;
   - the **show's web page** at the host or on another website, if the
     page links its feed with `<link rel="alternate" type="application/rss+xml">`.

   A Spotify show link (`open.spotify.com/show/…`) does not reveal the
   feed; the plugin says so and explains where to find it.
3. *Check feed* shows the title, artwork, episode count and dates, and
   notes such as episodes that already exist on the site, duplicate
   episode IDs, or a feed that announces it moved elsewhere.
4. Import. From then on the sync keeps the site up to date
   ([section 4](#4-what-the-sync-does-and-does-not-do)).

### Where each host shows the feed address

| Host | Where the feed address is | Example address |
|---|---|---|
| Spotify for Creators | On creators.spotify.com: Settings → Availability → scroll to "RSS distribution". The section appears after your first episode is published, and RSS has to be turned on there. On mobile: menu → Settings → Availability. | `https://anchor.fm/s/123abc/podcast/rss` |
| Buzzsprout | Directories page → "RSS Feed" tab → copy. | `https://feeds.buzzsprout.com/123456.rss` (redirects to `rss.buzzsprout.com`) |
| Libsyn | Destinations tab → "View Feed" next to "Libsyn Classic Feed" (also under Quick Links). | `https://feeds.libsyn.com/123456/rss` |
| Podbean | Podcast Dashboard → Settings → Feed; the RSS feed link is at the top. | `https://feed.podbean.com/yourshow/feed.xml` |
| Transistor | The show's Distribution page (the feed is at the top; it is also on Overview). | `https://feeds.transistor.fm/your-show` |
| Captivate | "Copy Feed URL" in the show header, or at the top of the Distribution screen. Keep the trailing slash. | `https://feeds.captivate.fm/your-show/` |
| RSS.com | The "RSS Feed" button on the dashboard copies the address. | `https://media.rss.com/your-show/feed.xml` |
| Acast | Open the show → Distribution (the RSS address is at the bottom), or Share Links → RSS Feed. | `https://feeds.acast.com/public/shows/your-show` |
| Simplecast | Show settings (gear icon) → Distribution → RSS. | `https://feeds.simplecast.com/abcd1234` |
| Megaphone | Podcast Library → your podcast → the "Feed" button. | `https://feeds.megaphone.fm/ABC1234567` |
| Omny Studio | Programs → your program → Playlists → the playlist → Details → RSS feed "Copy to clipboard". | `https://www.omnycontent.com/d/playlist/…/podcast.rss` |
| Podigee | Edit podcast → Feeds → copy the **MP3** feed (Podigee publishes one feed per audio format). | `https://yourshow.podigee.io/feed/mp3` |
| Castos | Podcast Settings → Distribution → Visibility → "Public Podcast RSS Feed" → Copy. | `https://feeds.castos.com/abc123` |
| Blubrry | Podcaster Dashboard → Podcast Hosting → Hosting Settings; the feed is above Save. | `https://feeds.blubrry.com/feeds/yourshow.xml` |
| Spreaker | Dashboard → your podcast → "RSS Customization". | `https://www.spreaker.com/show/1234567/episodes/feed` |
| RedCircle | On the show page, the copy icon under the description. | `https://feeds.redcircle.com/…` |
| Another WordPress site | PowerPress and Seriously Simple Podcasting publish the feed at `/feed/podcast/`; their settings screens show the exact address. | `https://example.com/feed/podcast/` |

**Not verified:** LetsCast.fm, podcaster.de, Julep, Riverside, Ausha,
Zencastr, ART19, Audioboom, SoundCloud, Fireside and Squarespace. The
plugin recognizes their feed addresses, but no current help page
describing where their dashboards show the feed was found. For these, and
for any other host: the feed address is in the host's dashboard, usually
under Distribution, Directories or Settings. It is the address that was
submitted to Apple Podcasts, so pasting the show's Apple Podcasts link
also works.

**Substack:** use the public podcast feed only. Paid subscribers receive
private per-subscriber feeds, which must not be imported (see
[troubleshooting](#private-and-paid-feeds)).

### Spotify for Creators in detail

- The feed address has the form `https://anchor.fm/s/{show id}/podcast/rss`.
  The web pages on anchor.fm now redirect to Spotify, but the feed is
  still served from anchor.fm.
- RSS distribution is opt-in. A show that never turned it on has no
  public feed, and the plugin cannot read it. Turning RSS on makes the
  owner email address in the feed public.
- Video episodes stay video on Spotify; the RSS feed carries only their
  audio, and that is what the website receives.
- Shows with paid Subscriptions deliver paid episodes through private
  feeds. Import only the public feed.
- Whether RSS distribution can be turned off again after it was turned
  on is not documented by Spotify (not verified).

### The feed redirect in external mode

With *Redirect this site's feed address to the host's feed* turned on
(default in external mode), every feed address of this site
(`/podcast/feed/`, `/?epm_podcast_feed=1`, `/podcast/rss2/` and the other
archive feed variants) answers with `301 Moved Permanently` and the
host's feed address. Apps and directories that find the site's feed
therefore end up at the host's feed, and nobody sees the show twice.
Turn it off only if you know why; the readiness report then warns that
apps may find both feeds.

Episode pages, episode embeds (`/podcast/{slug}/embed/`), the chapters
(`?epm_chapters=`) and transcript (`?epm_transcript=`) endpoints and the
site's blog feed are not redirected.

If the host's feed itself starts redirecting to this site's feed (with a
301/308 or `<itunes:new-feed-url>`), the show has moved here: the next
sync switches the site to *This website*, which ends the redirect and
the sync, and its message says so.

## 4. What the sync does and does not do

The sync runs as the WP-Cron event `epm_sync_feed`: hourly by default,
twice daily or daily if you choose, plus **Sync now** on the Hosting &
import screen and `wp podcast sync [--force]` on the command line
(`wp podcast status` shows the mode, the feeds and the last and next
sync).

### What it does

- **Conditional requests.** Scheduled runs send the `ETag` and
  `Last-Modified` values from the last run. When the host answers
  `304 Not Modified`, nothing else happens. *Sync now* always reads the
  whole feed.
- **New episodes** are created, oldest first, at most 25 per run (filter
  `epm_sync_batch_limit`). When more are waiting, a follow-up run is
  scheduled for a minute later (if a regular run is due within ten
  minutes anyway, WordPress lets that one continue instead).
- **Status of new episodes:** published, or drafts for review if you
  chose that. Episodes dated in the future are scheduled; episodes
  without a usable date become drafts; episodes the host hides from
  Apple Podcasts (`<itunes:block>yes</itunes:block>`) become drafts.
- **Changed episodes** are updated field by field. For every field the
  importer remembers a hash of the value it wrote. A later sync only
  overwrites a field whose current value still matches that hash, so
  anything edited on this site (title, show notes, guest, numbers …) is
  kept. Opening an imported episode and saving it without changes is not
  an edit: line endings and the paragraph tags the editor removes are
  ignored. Fields the importer never wrote are only filled when empty.
- **Episode identity** is the item's `<guid>`, stored exactly as the feed
  lists it (surrounding whitespace removed). Items without a GUID use the
  audio URL, then the item link, the same fallback podcast apps use.
  When a GUID appears twice in the feed, the first item wins.
- **Chapters and transcripts** are fetched when an episode is created:
  Podcasting 2.0 JSON chapters, Podlove chapters inside the item, and
  transcripts in HTML, WebVTT, SRT, Podcasting 2.0 JSON or plain text
  (converted into readable paragraphs with speaker names). The host's
  timed transcript file (WebVTT first, then SRT, then JSON) is also
  linked, so this site's feed can list it for captions when the show
  moves here.
- **Publish dates** are read as the feed states them, even with a wrong
  or localized weekday ("Mon, 16 Jun 2020" was a Tuesday; "Di, …").
- **Feed moves:** when the host's feed announces a new address with
  `<itunes:new-feed-url>` or answers with a `301`/`308` redirect, the
  stored feed address is updated, as podcast apps do, and the imported
  episodes still listed are re-tagged with it (so the removed-episode
  option keeps working). A move from `https://` to `http://` is never
  adopted. A move to this site's own feed switches the site to *This
  website* (see [the feed redirect](#the-feed-redirect-in-external-mode)).
- **Removed episodes (optional).** With *Unpublish episodes the host
  removed* turned on, a published episode imported from this feed that is
  no longer in the feed is moved back to drafts, but only if its date
  falls inside the time span the feed still covers (hosts cap how many
  episodes a feed lists) and only after it has been missing for at least
  one day. The default is to keep such episodes.
- **Safety guards.** A feed that suddenly lists no episodes never changes
  anything. A scheduled run also refuses to continue when a feed that
  listed at least 10 episodes suddenly lists fewer than half as many (a
  truncated or broken response); *Sync now* proceeds in that case, so a
  deliberate clean-up at the host can still be applied.
- **Backoff and notices.** After three failures in a row the scheduled
  sync waits 1, 2, 4, 8, 16 and then 24 hours between attempts, and the
  plugin's admin screens and the WordPress dashboard show an error notice
  with the host's message. A successful run resets the counter.
- **One job at a time.** An import and a sync never run at the same
  time. The lock belongs to the request that took it and is renewed while
  the job runs; a lock left behind by a crashed request expires after
  five minutes (twenty while an import copies audio, since one file can
  take that long).

### What it does not do

- It never sends anything to the host. Publish, edit and delete episodes
  at the host.
- It does not copy the audio. Players stream from the host's URLs.
- It does not update the podcast settings (title, description, artwork,
  category …) after the first import. Change them under Podcast settings
  if they change at the host.
- It does not fetch chapters or transcripts again for episodes that
  already exist.
- It never deletes episodes. An episode you move to the trash here is not
  imported again while it is in the trash. Once it is deleted
  permanently (by you, or by WordPress emptying the trash, after 30 days
  by default), the next sync creates it again if it is still in the
  host's feed.
- It needs WP-Cron. WP-Cron runs when the site receives visits; on sites
  with little traffic, or with `DISABLE_WP_CRON` set, add a server cron
  job that requests `wp-cron.php` (for example every 15 minutes).

## 5. Importing episodes

**Podcast → Hosting & import → Import episodes from a feed** imports from
any feed you own, in either mode. The setup assistant uses the same
import.

1. *Check feed* reads the feed once and shows what it found. On a web
   page that links several feeds, the podcast feed wins over the blog
   feed (listed first on every WordPress site), and comment feeds are
   never taken. Paged feeds (`<atom:link rel="next">`, used for example
   by SoundCloud) are followed up to 50 pages (filter
   `epm_import_max_pages`). The response is limited to 50 MB (filter
   `epm_feed_max_bytes`).
2. Options:
   - *Copy audio and episode images to this website* downloads the files
     into the Media Library; for episodes the import creates, the host's
     WebVTT or SRT transcript file is copied too. Needed before you close
     an account at the old host. Without it, episodes keep playing from
     the old host's URLs (and a transcript file stays linked where it
     is). With it, episodes
     that were mirrored earlier without their audio get their files
     copied too, so a show that was first connected and later moved ends
     up complete. When a file cannot be downloaded, the episode is still
     imported and keeps the old address; after the import, Hosting &
     import and the setup assistant list those episodes with links to
     them ("The audio of 2 episodes was not copied"), and
     `wp podcast import` prints a warning. Run the import again or add the
     files by hand before you close the old account.
   - *Import new episodes as drafts.*
   - *Fill in empty podcast settings* copies title, description, short
     description, author, owner name and email, copyright, language,
     category, host, funding link and artwork from the feed into Podcast
     settings, but only into empty fields. (On a site whose podcast
     settings were never saved, every field counts as empty, including
     the explicit flag and the episode order.) The feed's `<link>`,
     `<itunes:block>` and `<itunes:new-feed-url>` are never copied.
3. *Import episodes* runs in batches from the browser. If you leave the
   page, WP-Cron continues it. Episodes that already exist here (same
   GUID, in any status except the trash) are updated, never duplicated;
   episodes in the trash are skipped.

With *Copy audio* on, the Hosting & import screen treats the import as a
move and finishes it the way [section 6](#6-moving-a-show-to-this-website)
describes (feed limit, "moved here" flag, feed lock, and *This website*
mode). In *Another podcast host* mode it asks for confirmation first,
because the site stops syncing from the host and publishes the feed
itself once the import finishes. Cancelling an import stops it for good;
a batch that was still running does not restart it.

While an import runs, the parsed feed is kept as a JSON file with a
random name in `wp-content/uploads/epm-import/` (the folder contains an
`index.php` and an `.htaccess` that denies access on Apache). The file is
deleted when the import finishes, is cancelled, or another feed is
checked.

**Locked feeds.** A feed with `<podcast:locked>yes</podcast:locked>`
asks platforms not to import it without the owner's consent. When you
import such a feed to *move* it, you must confirm that you own the show,
or unlock the feed at the current host first (the setting is often called
"Lock feed").

## 6. Moving a show to this website

This is the procedure Apple and Spotify document for changing hosts,
applied to this plugin. It keeps subscribers, reviews and rankings: apps
follow the old feed's permanent redirect to the new feed, and because the
episode GUIDs do not change, they see the same episodes, not new ones.

### Before you start (at the old host)

1. **Make every episode visible in the feed.** Many hosts cap how many
   episodes the feed lists. Raise the limit, or the import misses the
   back catalog.
2. **Unlock the feed** if the host locked it (or confirm ownership during
   the import).
3. **Check the owner email** in the feed. Directories use it to verify
   you.
4. **Download your statistics.** Download numbers do not move with the
   show.

### Import

5. Podcast → Setup assistant → *Move my podcast to this website* (or
   Hosting & import → Import, with *Copy audio* on).
6. Paste the old feed address, check it, keep *Copy audio and episode
   images to this website* on, and import. Large shows take a while; the
   import continues in the background. For very large catalogs, WP-CLI
   runs the same import without browser or request time limits:

   ```bash
   wp podcast import https://anchor.fm/s/123abc/podcast/rss --move --copy-media --show-details
   ```

   Add `--owner` to confirm ownership of a locked feed.

What the import keeps:

- **Episode GUIDs**, exactly as the old feed lists them. Apple warns that
  changed GUIDs cause duplicate episodes, broken analytics and can affect
  the show's status.
- **The show's `<podcast:guid>`**: adopted from the old feed; when the old
  feed has none, it is derived from the old feed address the way the
  Podcasting 2.0 specification derives it. Every move import does this
  (the setup assistant's move path, Hosting & import with *Copy audio*,
  `wp podcast import --move`), whether or not show details are taken
  from the feed. It replaces a value this site only derived from its own
  feed address (which happens the first time its feed is requested), but
  never one that was adopted by an earlier import or set on purpose.
  Apps and OP3 statistics are keyed by this value.
- Publish dates, season and episode numbers and episode types.

When a move import finishes (the setup assistant's move path, an import
on Hosting & import with *Copy audio* on, or `wp podcast import --move`),
the plugin:

- sets *Feed episode limit* to 0 (unlimited) if the show has more
  published episodes than the limit, because an episode missing from the
  new feed counts as removed on Spotify;
- turns on *This show moved here from another host*, so the feed carries
  `<itunes:new-feed-url>` with its own address, as Apple asks of the new
  feed after a host change;
- locks the feed (`<podcast:locked>yes</podcast:locked>`) when an owner
  email is set;
- switches a site that was mirroring the old host (*Another podcast
  host*) to *This website*, so it stops syncing and stops redirecting its
  feed to the old host (which will soon redirect back here).

Check the list of episodes whose audio was not copied, if the import
shows one, and fix those episodes before you continue.

### Verify

7. Compare the old and new feeds (same number of episodes, same GUIDs,
   dates, titles, numbers and artwork). Apple recommends an RSS viewer for
   this. Validate the new feed with Podbase or Cast Feed Validator (links
   on Podcast → Distribution).
8. Check the readiness report on Podcast → Dashboard.

### Redirect the old feed

9. Copy this site's feed address (`/podcast/feed/`) and set it as the
   permanent (301) redirect at the old host. Use this address and do not
   change it again.

   - **Spotify for Creators** (documented by Spotify): on the web, not in
     the app, log in to creators.spotify.com → Settings → "Redirect your
     podcast" → paste this site's feed address → Redirect. If you use
     Subscriptions, turn them off in the Monetize tab first. The redirect
     can take up to 7 days; Spotify recommends waiting at least a week
     before deleting the account.
   - **Another WordPress site:** use the podcast plugin's feed redirect
     setting, or a redirect plugin, to send the old feed address to this
     site's feed address with a 301.
   - **Other hosts:** look for a setting called "301 redirect",
     "Redirect feed" or "Move podcast". The setup assistant and Hosting &
     import show this hint with the chosen host's name; only Spotify for
     Creators and other WordPress sites get their own steps. If you cannot
     find the setting, the host's support can set the redirect. These
     menus were not verified for each host.

10. **Keep the old account and the redirect for at least four weeks.**
    Apple asks for the 301 and the `<itunes:new-feed-url>` tag to stay in
    place for at least four weeks. Longer is better: apps that rarely
    check a feed may still request the old address later.

### If no redirect is possible

Change the feed address in each directory yourself:

- **Apple Podcasts Connect:** select the show → Edit next to the RSS feed
  URL → enter this site's feed address → Save.
- **Spotify for Creators**, for a show hosted elsewhere and claimed there:
  Settings → Update → enter the new RSS link → confirm the hosting
  provider → Submit.
- Other platforms: contact each one.

### After the move

- Hosting mode stays *This website*. Do not switch to *Another podcast
  host* with the old feed: once the old host redirects to this site, that
  would create a redirect loop. (If it happens anyway, the next sync
  notices the redirect to this site's feed and switches back.)
- Never change the imported GUIDs, and keep the audio URLs working.
- If you imported without *Copy audio*, the readiness report on the
  dashboard shows *Audio at the old host* with the number of episodes
  whose audio still loads from there. Import again with *Copy audio*
  before you close the old account.

## 7. Moving a show away from this website

1. **Prepare this feed.** Podcast settings → Feed and links: set *Feed
   episode limit* to 0 so every episode is in the feed. Podcast settings →
   Feed status: turn off *Lock the feed*, because importers at other hosts
   refuse locked feeds. Keep the owner email in the feed: the new host and
   Spotify verify ownership through it.
2. **Import at the new host** from this site's feed address. The new host
   must keep the episode GUIDs exactly (this plugin's GUIDs look like
   `urn:uuid:…`, or `https://your-site/?epm_episode_guid=123` for episodes
   created before 1.1.0) and should keep the show's `<podcast:guid>`.
3. **Check the new feed with the plugin.** Paste the new host's feed
   address into Podcast → Hosting & import → *Import episodes from a feed*
   and choose *Check feed*. Do not import. The preview should report that
   every episode already exists on this site. That shows the GUIDs were
   kept. If it reports new episodes instead, ask the new host to fix the
   GUIDs before you continue.
4. **Switch to *Another podcast host*** on Podcast → Hosting & import,
   enter the new feed address and keep the redirect on. This site then
   answers every feed address with a `301` to the new feed, and apps move
   over by themselves. The site keeps showing the episodes and syncs new
   ones from the new host.
5. **Purge page and CDN caches** for `/podcast/feed/`, so a cached copy of
   the old feed is not served instead of the redirect.
6. **Keep the redirect for at least four weeks, ideally for good.** Leave
   the audio files that are in the Media Library online: downloaded
   episodes and the new host's copies may still reference them.
7. **Spotify:** a show claimed in Spotify for Creators but hosted
   elsewhere updates the feed under Settings → Update. To move the show
   onto Spotify for Creators hosting, Spotify documents its own Switch
   flow: import the show there, then redirect this feed to the
   `https://anchor.fm/s/…/podcast/rss` address it gives you (step 4).
   Without the redirect, new episodes uploaded to Spotify for Creators do
   not reach listeners.

If a 301 cannot be served (for example a proxy in front of the site
ignores it), stay in *This website* mode and set *New feed URL* under
Podcast settings → Feed status instead. The feed then carries
`<itunes:new-feed-url>` with the new address. Update Apple Podcasts
Connect and Spotify for Creators by hand as described in
[section 6](#if-no-redirect-is-possible).

## 8. Troubleshooting

### Bot-protection pages

Some sites put a challenge page in front of every request: a Cloudflare
"Just a moment…" page (seen with HTTP 403) or a SiteGround captcha (seen
with HTTP 202). Podcast apps and this plugin cannot pass them. The plugin
reports "The server answered with a bot-protection page instead of the
feed."

- When importing from such a site, ask its owner to exempt the feed
  address from the protection, or use the feed address from the podcast
  host instead of the website.
- When your own site hosts the show, exempt `/podcast/feed/`, the audio
  files and the `?epm_chapters=` / `?epm_transcript=` addresses from bot
  protection, or directories cannot read them.

### A Spotify show has no feed

Shows hosted on Spotify for Creators without RSS distribution have no
public feed. Turn on RSS distribution (Settings → Availability) first. A
Spotify show link is not a feed address.

### The import misses older episodes

The host's feed lists only the newest episodes. Raise the episode limit
in the host's feed settings (Spotify's troubleshooting page for switching
hosts names this as the first thing to check), then check the feed
again: the import updates existing episodes and adds the missing ones.

### Download statistics prefixes

Many hosts route audio URLs through measurement services (for example
Podtrac, OP3, Chartable or Podsights). The import preview mentions them.
Imported audio URLs keep the prefix, and it keeps working as long as the
service exists. When audio is copied to this site, the feed uses the
Media Library URL without the old prefix; choose a service under
*Download statistics* ([section 2](#download-statistics)) to measure
downloads again. Download numbers collected by the old host do not move
with the show.

### Private and paid feeds

Never import a private feed: premium feeds from Spotify Subscriptions,
Substack paid subscriptions, Patreon or members' feeds at other hosts.
They contain a personal access token and paid episodes; importing them
would publish paid content on a public website and in a public feed. Use
the show's public feed only.

### Duplicate episodes after a move

The GUIDs changed somewhere. Compare the `<guid>` values of the old and
new feeds. Imports into this plugin keep GUIDs; if the duplicates appear
after moving *away*, the new host changed them.

### "An import is running"

Only one import or sync runs at a time. Wait for it to finish or stop it
on Podcast → Hosting & import. A lock left by a crashed request expires
after five minutes, or twenty while an import copies audio.

### "The audio of … episodes was not copied"

The import could not download those files (for example the old host
answered with an error, the download took longer than 15 minutes, or the
uploads folder is not writable). The episodes were imported and still play from
the old host. Open each listed episode and upload the file, or run the
import again with *Copy audio* on: it only downloads what is still
missing. Do this before you close the old account.

### *Test feed and audio delivery* reports an error on a local site

The test uses WordPress's safe HTTP functions, which refuse addresses on
private networks other than the site itself. Audio on another machine in
a local network cannot be tested; test on the public site instead.

### Sync errors

The Hosting & import screen shows the last status and message. Typical
causes: the host's feed address changed without a redirect, the host is
down, or bot protection (above). After three failures in a row the
plugin backs off and shows an admin notice. Fix the address and choose
*Sync now*.

### The redirect does not happen

A page cache or CDN still serves a stored copy of `/podcast/feed/`.
Purge it. Also check that the mode is *Another podcast host*, the
redirect option is on and the host's feed address is set (without an
address the mode cannot be saved). If the host's feed redirects to this
site, the site switched itself to *This website*; the last sync message
on Hosting & import says so.

### Atom feeds

The importer reads RSS feeds only; Apple Podcasts stopped accepting Atom
feeds too. Hosts list the podcast's RSS feed with their directory links.
