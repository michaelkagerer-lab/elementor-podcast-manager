# Getting a podcast listed

This guide covers **Podcast → Distribution** in version 1.3.0: where to
submit the feed, in which order, what each platform checks, and which
apps list the show without a submission.

The platform list lives in `includes/Directories.php` (filter
`epm_directories`). The steps were taken from each platform's help pages
and checked on 2026-09-30; platforms change their sign-up flows, so the
wording on their sites may differ.

## Which feed address to submit

The Distribution screen shows the address to submit and a copy button:

- **Hosting on this website:** this site's feed, `https://your-site/podcast/feed/`.
  The screen also lists readiness errors (missing artwork, owner email,
  episodes …) that directories would reject, with a link to Podcast
  Settings.
- **Another podcast host:** the host's feed. Most hosts offer their own
  distribution tools; use those or submit the address shown.

Each platform needs the address once. New episodes reach it
automatically after that.

Before submitting:

- **Test feed and audio delivery** (button on the screen) checks what
  directories check when they fetch the show: the feed answers with HTTP
  200 and RSS, the address uses HTTPS, and the newest episode's audio
  answers a `HEAD` request (status 200, file size, audio type) and a
  byte-range request (`206 Partial Content`). Apple Podcasts and the
  Pandora/SiriusXM submission require HEAD and byte-range support.
- Check the feed with an independent validator. The screen links to
  Podbase and Cast Feed Validator with the feed address filled in.

## The owner email

Spotify, YouTube, Amazon Music, iHeartRadio, Deezer and Pandora/SiriusXM
confirm that you own the show by sending a code or a confirmation link to
the owner email in the feed (`<itunes:owner><itunes:email>`, set under
Podcast Settings → Owner email). The address is therefore public in the
feed. Use one you can receive mail at while submitting and are happy to
share.

## Order

The screen groups the platforms. Submit the first group first; together
these reach most listeners.

### Start here (essential)

| Platform | How to submit | What it checks |
|---|---|---|
| Apple Podcasts | Sign in to Podcasts Connect (podcastsconnect.apple.com) with an Apple Account, add a new show, choose "Add a show with an RSS feed", paste the feed address and submit it for review. | Square JPEG or PNG artwork of 1400–3000 px, a category, an owner email and at least one episode. Review usually takes a few days. |
| Spotify | On Spotify for Creators (creators.spotify.com) choose "Find an existing show" → "Somewhere else", paste the feed address, enter the 8-digit code Spotify emails to the feed's owner address, then confirm country, language and category. | Owner email in the feed; artwork exactly square. |
| YouTube & YouTube Music | In YouTube Studio choose Create → New podcast → Submit RSS feed, accept the terms, send the verification code to the feed's email, pick the episodes and publish the podcast once processing is done. It starts as private. | Owner email in the feed. YouTube turns the artwork into still-image videos. Dynamically inserted ads are not allowed. |
| Amazon Music & Audible | On Amazon Music for Podcasters (podcasters.amazon.com) choose "Get started", sign in with an Amazon account, paste the feed address, pick a country and confirm the email sent to the feed's address. One submission lists the show on Amazon Music and Audible. | Owner email in the feed. |
| Podcast Index | Paste the feed address at podcastindex.org/add. The show appears within minutes. | A public feed. Podcast Index supplies Fountain, Podverse, Castamatic, Podcast Guru, AntennaPod's search and other apps. |

### Recommended

| Platform | How to submit | What it checks |
|---|---|---|
| iHeartRadio | At podcasters.iheart.com sign in, choose "Add Your Podcast", paste the feed address, accept the terms and confirm the email sent to the feed's address. | Owner email in the feed. |
| Pocket Casts | Paste the feed address at pocketcasts.com/submit and submit. It can take up to about 12 hours. | A public feed. |
| Deezer | At podcasters.deezer.com choose "Publish my podcast", paste the feed address, verify it with the code emailed to the feed's address and fill in the show details. | Owner email in the feed. |
| Podcast Addict | Search for the show first; if it is missing, paste the feed address at podcastaddict.com/submit. | A public feed. |

### More platforms (optional)

| Platform | How to submit | What it checks |
|---|---|---|
| Pandora & SiriusXM (United States) | Sign up for Simplecast Creator Connect, choose "Add Shows", paste the feed address, accept the SiriusXM/Pandora terms and confirm the email sent to the feed's address. | Owner email in the feed. The server must answer HEAD and byte-range requests for the audio. |
| TuneIn | At broadcasters.tunein.com/podcasts/add check whether the show is listed already, then fill in the "Add a Podcast" form. Review takes days to weeks. TuneIn also supplies Alexa speakers. | A public feed. |
| podcast.de (German-speaking countries) | Fill in the registration form at podcast.de/podcast-anmelden with the feed address; no account needed. | A public feed. |
| Listen Notes | Paste the feed address at listennotes.com/submit. | A public feed. |

### Listed automatically

These apps need no submission. They pick the show up from Apple
Podcasts or Podcast Index. Claim the listing if you want to manage it.

| App | Source | Notes |
|---|---|---|
| Overcast | Apple Podcasts | Appears one or two days after Apple lists the show. |
| Castro | Apple Podcasts | Uses the Apple Podcasts directory. |
| Castbox | Apple Podcasts | Claim the show in Castbox Creator Studio to manage it. |
| Goodpods | Apple Podcasts | Claim it from your Goodpods profile. |
| Player FM | Apple Podcasts | Usually automatic; if missing, paste the feed address into Player FM's search. |
| Fountain | Podcast Index | Appears within minutes of the Podcast Index listing. |

## Tracking progress

Each platform row has a *Track {platform}* section (*Add the listing
link* for apps that list the show automatically):

- *I submitted the feed* marks the platform as **Submitted**.
- *Your show on {platform}*: paste the listing address once the show is
  live. The platform is then marked **Listed**.
- A listing address is also added to Podcast Settings → Platform links,
  unless a link for that service exists already. The subscribe buttons
  (Subscribe Links widget, `[podcast_subscribe]`, the player's platform
  row) then show it.

The header counts how many essential platforms are submitted or listed.
Progress is stored in the option `epm_distribution`.

## Adding platforms

Agencies can add regional directories or change the steps with the
`epm_directories` filter. Each entry has these keys: `name`, `icon` (a
key from `includes/BrandIcons.php`, or empty for a letter icon),
`priority` (`essential`, `recommended` or `optional`), `submit_url`,
`steps`, `needs`, `via` (the key of the directory that lists the show
automatically, empty when a submission is needed), `region` and
`service` (the link service used for the listing address; see the
`epm_link_services` filter).

```php
add_filter( 'epm_directories', function ( array $directories ) {
	$directories['fyyd'] = [
		'name'       => 'fyyd',
		'icon'       => '',
		'priority'   => 'optional',
		'submit_url' => 'https://fyyd.de/add-feed',
		'steps'      => 'Paste your feed address; no account needed.',
		'needs'      => 'A public feed.',
		'via'        => '',
		'region'     => '',
		'service'    => 'custom',
	];
	return $directories;
} );
```

Platform names are trademarks of their owners and only identify the
service.
