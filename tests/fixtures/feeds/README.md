# Feed fixtures

Feeds used by `tests/integration/hosting.php` (feed parser, import, sync)
and the browser suite `tests/e2e/setup.mjs`. The test HTTP server
(`tests/fixtures/mu-plugins/epm-test-http.php`, installed on the disposable
test site by `tests/bin/setup-wp.sh`) serves them at
`https://feeds.example.test/<path>`, so no test ever leaves localhost.

## Real feeds

Public podcast feeds as each host publishes them, trimmed to the channel
and the first three items. Markup, whitespace and entity encoding are kept
exactly as published: the quirks are the point. They are test data only;
the shows belong to their owners.

| File | Host / publisher | What it covers |
|---|---|---|
| `acast.xml` | Acast | XHTML before `<channel>`, a category after the last item, owner name `" "` |
| `alitu.xml` | Alitu | no XML declaration, the whole feed on one line, chapters URL with a query |
| `art19.xml` | ART19 | host detection from `<generator>` |
| `audioboom.xml` | Audioboom | entity-encoded titles |
| `bbc-selfhosted.xml` | self-hosted (BBC) | `http://` enclosures, `itunes:new-feed-url` differing from an old `atom:link` |
| `blubrry.xml` | Blubrry | an Atom `<link>` in the default namespace, YouTube-ID GUIDs |
| `buzzsprout.xml` | Buzzsprout | OP3/Podtrac measurement prefixes chained in enclosures, `podcast:locked` |
| `captivate.xml` | Captivate | `MM:SS` and `HH:MM:SS` durations in one feed |
| `castos.xml` | Castos | host detection |
| `libsyn.xml` | Libsyn | `"02:37"` durations |
| `megaphone.xml` | Megaphone | `length="0"` enclosures, HTML in `content:encoded`, plain-text summary, no generator |
| `omny.xml` | Omny Studio | host detection from the address |
| `podbean.xml` | Podbean | host detection |
| `podigee.xml`, `podigee-2.xml` | Podigee | `podcast:chapters` with `href=` instead of `url=`, inline Podlove chapters |
| `redcircle.xml` | RedCircle | entity-escaped `content:encoded`, `&#43;` in `pubDate`, no `atom:link` self |
| `riverside.xml` | Riverside | host detection |
| `rss-com.xml` | RSS.com | host detection |
| `simplecast.xml` | Simplecast | host detection |
| `soundcloud.xml` | SoundCloud | paged feed with `atom:link rel="next"` |
| `spotify-for-creators.xml` | Spotify for Creators (Anchor) | zero-padded `HH:MM:SS` durations |
| `spreaker.xml` | Spreaker | host detection from the address |
| `transistor.xml` | Transistor | capitalised `Yes`/`No` explicit values, `podcast:guid`, self-referencing `itunes:new-feed-url`, five transcripts per item |
| `wordpress-powerpress.xml` | WordPress + PowerPress | the last `<image>` is the artwork when `itunes:image` is missing (the first is a 32 px site icon) |
| `wordpress-ssp.xml`, `wordpress-ssp-2.xml` | WordPress + Seriously Simple Podcasting | no generator |

`hosting.php` asserts that this list is complete: a new file here needs a
line in its fixture list.

## Pages that are not feeds (`negative/`)

What a feed request sometimes gets instead of the feed. The HTTP status
is part of the file name (`…-http403.html` is served with 403).

| File | What it is |
|---|---|
| `cloudflare-challenge-http403.html` | Cloudflare "Just a moment…" bot challenge |
| `siteground-captcha-http202.html` | SiteGround captcha redirect (the client address is replaced by `192.0.2.1`) |

## Synthetic feeds (`synthetic/`)

Made-up shows for cases no real feed shows in three items.

| File | What it covers |
|---|---|
| `locked-show.xml` | `podcast:locked` + `podcast:guid`, a duplicate GUID, `itunes:block`, an undated item, plain-text notes, inline and JSON chapters, HTML/WebVTT/SRT transcripts (`extras/`), a measurement-prefixed enclosure, `audio/mp3` and `length="0"`. Apple's lookup API answers ID `1000000001` with this feed |
| `paged-1.xml`, `paged-2.xml` | two pages linked with `rel="next"`; page 2 links back to page 1 (the importer must stop) |
| `paged-broken-1.xml` | page 1 of a paged feed whose relative `rel="next"` (`paged-broken-2.xml`) answers 404: the catalog is incomplete |
| `broken-markup.xml` | BOM, leading whitespace, bare `&`, HTML named entities, control characters |
| `atom.xml` | an Atom feed (rejected: podcast apps need RSS) |
| `missing-audio.xml` | two episodes; the first one's audio answers 404, so "copy media" must list it as not copied |
| `rate-limited.xml` | one episode whose audio answers 429 with `Retry-After: 120` (`/media/<name>-http429.mp3`), so "copy media" must wait |

Audio (`/media/<name>.mp3|m4a`) and images (`/media/<name>.png`,
`<name>-<w>x<h>.png`) are generated on request by the HTTP fixture server,
with a Content-Length like a real server; `/media/<name>-http<code>.mp3`
answers that status (429 and 503 with `Retry-After: 120`).
