#!/usr/bin/env bash
#
# HTTP tests against a running site seeded with tests/fixtures/seed.php.
#
# Usage: WP_URL=http://localhost:8889 WP_CLI=/tmp/epm-wp/wp tests/http/run.sh
#
set -uo pipefail

URL="${WP_URL:-http://localhost:8889}"
WP="${WP_CLI:-wp}"
FAILED=0
PASSED=0

pass() { PASSED=$((PASSED + 1)); echo "  ✓ $1"; }
fail() { FAILED=$((FAILED + 1)); echo "  ✗ $1"; }
# No pipefail inside checks: `curl … | grep -q` would fail when grep exits
# early and curl gets SIGPIPE.
check() { if ( set +o pipefail; eval "$2" ); then pass "$1"; else fail "$1"; fi; }

# Only the JSON line: plugins may print notices to stdout under WP-CLI.
FIXTURES="$($WP option get epm_test_fixtures --format=json 2>/dev/null | grep '^{' | head -1)"
fx() { php -r '$f = json_decode($argv[1], true); echo $f[$argv[2]] ?? "";' "$FIXTURES" "$1"; }
EP1="$(fx ep1)"; DRAFT="$(fx draft)"; PASSWORD="$(fx password)"; FUTURE="$(fx future)"; EP2="$(fx ep2)"
[ -n "$EP1" ] || { echo "Run tests/fixtures/seed.php first."; exit 1; }

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

echo "Feed routing"
code=$(curl -s -o "$TMP/feed.xml" -D "$TMP/feed.h" -w '%{http_code}' "$URL/podcast/feed/")
check "/podcast/feed/ returns 200" '[ "$code" = 200 ]'
check "served as application/rss+xml" 'grep -qi "^content-type: application/rss+xml" "$TMP/feed.h"'
check "is the podcast feed (itunes namespace + enclosures)" 'grep -q "xmlns:itunes" "$TMP/feed.xml" && grep -q "<enclosure " "$TMP/feed.xml"'
check "is well-formed XML" 'php -r "exit(simplexml_load_file(\$argv[1]) ? 0 : 1);" "$TMP/feed.xml"'
check "never lists restricted episodes" '! grep -Eq "Draft Secret|Private Secret|Password Secret|Future Secret|No Audio Yet|WAV only" "$TMP/feed.xml"'
for alt in /podcast/rss2/ /podcast/feed/atom/ "/?epm_podcast_feed=1"; do
	check "$alt serves the podcast feed" 'curl -s "$URL$alt" | grep -q "<itunes:owner>"'
done

echo "Conditional requests"
etag=$(grep -i '^etag:' "$TMP/feed.h" | cut -d' ' -f2 | tr -d '\r')
lastmod=$(grep -i '^last-modified:' "$TMP/feed.h" | cut -d' ' -f2- | tr -d '\r')
check "ETag header present" '[ -n "$etag" ]'
check "If-None-Match answers 304" '[ "$(curl -s -o /dev/null -w "%{http_code}" -H "If-None-Match: $etag" "$URL/podcast/feed/")" = 304 ]'
check "If-Modified-Since answers 304" '[ "$(curl -s -o /dev/null -w "%{http_code}" -H "If-Modified-Since: $lastmod" "$URL/podcast/feed/")" = 304 ]'
check "stale ETag gets the full feed" '[ "$(curl -s -o /dev/null -w "%{http_code}" -H "If-None-Match: \"stale\"" "$URL/podcast/feed/")" = 200 ]'
# A channel change without a new episode moves Last-Modified too, so a
# client that only sends If-Modified-Since gets the new feed.
SETTINGS_BEFORE="$($WP option get epm_podcast_settings --format=json 2>/dev/null | grep '^{' | head -1)"
sleep 1
$WP eval 'update_option( "epm_podcast_settings", array_merge( (array) get_option( "epm_podcast_settings" ), [ "copyright" => "Changed by the HTTP tests" ] ) );' > /dev/null 2>&1
check "a channel change answers If-Modified-Since with the full feed" '[ "$(curl -s -o /dev/null -w "%{http_code}" -H "If-Modified-Since: $lastmod" "$URL/podcast/feed/")" = 200 ]'
check "and names its new build time" '[ "$(curl -s -D - -o /dev/null "$URL/podcast/feed/" | grep -i "^last-modified:" | cut -d" " -f2- | tr -d "\r")" != "$lastmod" ]'
[ -n "$SETTINGS_BEFORE" ] && $WP option update epm_podcast_settings "$SETTINGS_BEFORE" --format=json > /dev/null 2>&1

echo "Companion documents"
check "chapters JSON for a public episode" 'curl -s "$URL/?epm_chapters=$EP1" | php -r "\$d = json_decode(stream_get_contents(STDIN), true); exit(isset(\$d[\"chapters\"][1][\"startTime\"]) && 30 === \$d[\"chapters\"][1][\"startTime\"] ? 0 : 1);"'
check "transcript HTML for a public episode" 'curl -s "$URL/?epm_transcript=$EP1" | grep -q "Hi there."'
for id in "$DRAFT" "$PASSWORD" "$FUTURE"; do
	check "restricted episode $id: chapters 404" '[ "$(curl -s -o /dev/null -w "%{http_code}" "$URL/?epm_chapters=$id")" = 404 ]'
	check "restricted episode $id: transcript 404" '[ "$(curl -s -o /dev/null -w "%{http_code}" "$URL/?epm_transcript=$id")" = 404 ]'
done
check "password transcript text never served" '! curl -s "$URL/?epm_transcript=$PASSWORD" | grep -q "secret words"'

echo "Pages"
EP1_URL=$($WP post list --post_type=podcast_episode --post__in="$EP1" --field=url 2>/dev/null | grep '^http' | head -1)
curl -s "$EP1_URL" -o "$TMP/episode.html"
check "episode page has one player" '[ "$(grep -o "data-epm-player" "$TMP/episode.html" | wc -l)" -eq 1 ]'
check "episode page has chapters, show notes and transcript" 'grep -q "epm-chapters" "$TMP/episode.html" && grep -q "epm-show-notes" "$TMP/episode.html" && grep -q "epm-transcript" "$TMP/episode.html"'
check "feed discovery link on pages" 'curl -s "$URL/" | grep -q "rel=\"alternate\" type=\"application/rss+xml\" title=\"Test &amp; Talk Podcast\""'
check "design tokens printed once" '[ "$(curl -s "$EP1_URL" | grep -o "id=\"epm-design-tokens\"" | wc -l)" -eq 1 ]'
check "shortcode page renders player and list" 'curl -s "$URL/epm-shortcodes/" | grep -q "epm-episode-list" '
if [ -n "$(fx elementor_page)" ]; then
	check "Elementor page renders all widgets" '[ "$(curl -s "$URL/epm-elementor/" | grep -o "data-widget_type=\"epm-" | wc -l)" -eq 11 ]'
fi

echo "REST"
check "episode meta exposed in REST" 'curl -s "$URL/wp-json/wp/v2/podcast_episode/$EP1" | grep -q "\"_epm_episode_number\":1"'
check "password-protected episode meta hidden in REST" 'curl -s "$URL/wp-json/wp/v2/podcast_episode/$PASSWORD" | php -r "\$d = json_decode(stream_get_contents(STDIN), true); exit(empty(\$d[\"meta\"]) ? 0 : 1);"'

echo "Media"
AUDIO=$(grep -o '<enclosure url="[^"]*"' "$TMP/feed.xml" | head -1 | sed 's/.*url="//; s/"$//')
check "enclosure answers byte-range requests" '[ "$(curl -s -o /dev/null -w "%{http_code}" -r 0-99 "$AUDIO")" = 206 ]'

echo "Import data is never public"
# A feed check through the import screen's AJAX, as an administrator.
COOKIES="$TMP/cookies"
curl -s -o /dev/null -c "$COOKIES" -b "wordpress_test_cookie=WP%20Cookie%20check" \
	-d "log=admin&pwd=admin&wp-submit=Log+In&testcookie=1" "$URL/wp-login.php"
NONCE="$(curl -s -b "$COOKIES" "$URL/wp-admin/admin.php?page=epm-hosting" | grep -o '"importNonce":"[a-z0-9]*"' | head -1 | cut -d'"' -f4)"
check "the import screen gives an administrator a nonce" '[ -n "$NONCE" ]'
curl -s -b "$COOKIES" -o "$TMP/preview.json" \
	-d "action=epm_import_preview&nonce=$NONCE&url=https://feeds.example.test/synthetic/locked-show.xml" "$URL/wp-admin/admin-ajax.php"
TOKEN="$(php -r '$d = json_decode((string) file_get_contents($argv[1]), true); echo $d["data"]["token"] ?? "";' "$TMP/preview.json")"
check "a feed check creates an import job" '[ -n "$TOKEN" ]'
UPLOADS="$($WP eval 'echo wp_upload_dir( null, false )["basedir"], "\n";' 2>/dev/null | head -1)"
# (Before the requests below, which name the token themselves.)
SERVER_LOG="$(dirname "$WP")/server.log"
DEBUG_LOG="$(dirname "$UPLOADS")/debug.log"
check "the job token is in no URL or log" '! grep -qsF "$TOKEN" "$SERVER_LOG" "$DEBUG_LOG"'
STORED="$($WP eval 'global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value LIKE %s", "epm\\_import\\_chunk\\_%", "%synthetic-undated%" ) ), "\n";' 2>/dev/null | head -1)"
check "the parsed feed is stored in the database" '[ "${STORED:-0}" -gt 0 ]'
check "and in no file under the uploads folder" '[ -d "$UPLOADS" ] && ! grep -rqs "synthetic-undated" "$UPLOADS"'
check "no import folder exists to be served or listed" '[ "$(curl -s -o /dev/null -w "%{http_code}" "$URL/wp-content/uploads/epm-import/")" = 404 ]'
check "the address a 1.3.0 job file had serves no feed data" '! curl -s "$URL/wp-content/uploads/epm-import/job-$TOKEN.json" | grep -q "synthetic-undated"'
curl -s -b "$COOKIES" -o /dev/null -d "action=epm_import_cancel&nonce=$NONCE" "$URL/wp-admin/admin-ajax.php"
STORED="$($WP eval 'global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", "epm\\_import\\_chunk\\_%" ) ), "\n";' 2>/dev/null | head -1)"
check "cancelling removes the stored feed" '[ "${STORED:-1}" = 0 ]'

echo "Hosted elsewhere"
# The hosting option is saved (JSON, empty when it does not exist) and put
# back afterwards, also when the script is interrupted.
HOST_FEED="https://feeds.example.test/synthetic/locked-show.xml"
HOSTING_BEFORE="$($WP option get epm_hosting --format=json 2>/dev/null | grep '^{' | head -1)"
restore_hosting() {
	if [ -n "$HOSTING_BEFORE" ]; then
		$WP option update epm_hosting "$HOSTING_BEFORE" --format=json > /dev/null 2>&1
	else
		$WP option delete epm_hosting > /dev/null 2>&1
	fi
}
trap 'restore_hosting; rm -rf "$TMP"' EXIT
# Through the settings sanitizer, like the Hosting screen.
set_hosting() {
	$WP eval "update_option( EPM\\Hosting::OPTION, EPM\\Hosting::sanitize( array_merge( EPM\\Hosting::all(), [ 'mode' => '$1', 'feed_url' => '$HOST_FEED', 'redirect' => '$2', 'sync' => '' ] ) ) );" > /dev/null 2>&1
}
status_and_location() { curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$URL$1"; }

set_hosting external 1
for path in /podcast/feed/ "/?epm_podcast_feed=1" /podcast/rss2/ /podcast/feed/atom/; do
	check "$path answers 301 to the host's feed" '[ "$(status_and_location "$path")" = "301 $HOST_FEED" ]'
done
check "the redirect names the plugin" 'curl -s -D - -o /dev/null "$URL/podcast/feed/" | grep -qi "^x-redirect-by: Elementor Podcast Manager"'
check "pages point feed readers at the host's feed" 'curl -s "$URL/" | grep -q "type=\"application/rss+xml\"[^>]*href=\"$HOST_FEED\""'
check "the blog feed is not redirected" '[ "$(curl -s -o /dev/null -w "%{http_code}" "$URL/feed/")" = 200 ]'

set_hosting external ""
check "redirect turned off: the site's feed answers 200" '[ "$(status_and_location /podcast/feed/)" = "200 " ]'

restore_hosting
for path in /podcast/feed/ "/?epm_podcast_feed=1"; do
	check "self-hosted again: $path answers 200 with the podcast feed" '[ "$(status_and_location "$path")" = "200 " ] && curl -s "$URL$path" | grep -q "<itunes:owner>"'
done

echo
echo "$PASSED passed, $FAILED failed."
[ "$FAILED" -eq 0 ]
