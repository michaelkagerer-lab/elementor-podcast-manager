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

echo "Stylesheet placement (WID-N4) and pages without podcast content (WID-N9)"
# Line of the first match of a pattern in a page (empty when absent).
line_of() { grep -n -m1 -- "$2" "$1" | cut -d: -f1; }
in_head() {
	local file="$1" css head
	css=$(line_of "$file" "epm-frontend.css")
	head=$(line_of "$file" "</head>")
	[ -n "$css" ] && [ -n "$head" ] && [ "$css" -lt "$head" ]
}
curl -s "$URL/epm-shortcodes/" -o "$TMP/shortcodes.html"
check "automatic episode page: stylesheet in <head>" 'in_head "$TMP/episode.html"'
check "automatic episode page: tokens in <head>" '[ "$(line_of "$TMP/episode.html" "epm-design-tokens")" -lt "$(line_of "$TMP/episode.html" "</head>")" ]'
check "shortcode page: stylesheet in <head>" 'in_head "$TMP/shortcodes.html"'
check "shortcode page: stylesheet loaded once" '[ "$(grep -o "epm-frontend-css" "$TMP/shortcodes.html" | wc -l)" -eq 1 ]'
PLAIN_ID=$($WP post create --post_type=page --post_status=publish --post_title="EPM plain page" --post_content="Nothing about podcasts." --porcelain 2>/dev/null | grep -E '^[0-9]+$' | head -1)
NOTHING_ID=$($WP post create --post_type=page --post_status=publish --post_title="EPM nothing to show" --post_content='[podcast_player source="current"] [podcast_chapters] [podcast_video]' --porcelain 2>/dev/null | grep -E '^[0-9]+$' | head -1)
curl -sL "$URL/?page_id=$PLAIN_ID" -o "$TMP/plain.html"
curl -sL "$URL/?page_id=$NOTHING_ID" -o "$TMP/nothing.html"
check "a page without podcast content prints no tokens" '! grep -q "epm-design-tokens" "$TMP/plain.html"'
check "and loads no podcast assets" '! grep -Eq "epm-frontend|epm-player\.js" "$TMP/plain.html"'
check "shortcodes that render nothing load no assets and no tokens" '! grep -Eq "epm-frontend|epm-player\.js|epm-design-tokens" "$TMP/nothing.html"'
# An Elementor page whose only widget shows nothing (a "current episode"
# player on a page that is not an episode): Elementor still prints the
# widget's stylesheet in <head> (its page-asset list does not know what a
# widget will show), and the tokens come with it; the player script goes.
EMPTY_EL_ID=$($WP eval '
	$id = wp_insert_post( [ "post_type" => "page", "post_status" => "publish", "post_title" => "EPM empty widget" ] );
	update_post_meta( $id, "_elementor_edit_mode", "builder" );
	update_post_meta( $id, "_elementor_template_type", "wp-page" );
	update_post_meta( $id, "_elementor_version", ELEMENTOR_VERSION );
	update_post_meta( $id, "_elementor_data", wp_slash( wp_json_encode( [ [ "id" => "ee00001", "elType" => "container", "settings" => [], "isInner" => false, "elements" => [ [ "id" => "ee00002", "elType" => "widget", "widgetType" => "epm-podcast-player", "settings" => [ "source" => "current" ], "elements" => [] ] ] ] ] ) ) );
	echo "EPMID:" . $id . "\n";' 2>/dev/null | grep '^EPMID:' | cut -d: -f2)
# Twice: the first view builds Elementor's page-asset list.
curl -sL "$URL/?page_id=$EMPTY_EL_ID" -o "$TMP/empty-widget.html"
curl -sL "$URL/?page_id=$EMPTY_EL_ID" -o "$TMP/empty-widget.html"
check "Elementor page whose podcast widget shows nothing: no player script" '! grep -q "epm-player\.js" "$TMP/empty-widget.html" && grep -q "elementor-element-ee00001" "$TMP/empty-widget.html"'
check "tokens only together with Elementor's stylesheet (Elementor keeps a widget's stylesheet: known limit)" '! grep -q "epm-design-tokens" "$TMP/empty-widget.html" || grep -q "epm-frontend-css" "$TMP/empty-widget.html"'
$WP post delete "$PLAIN_ID" "$NOTHING_ID" "$EMPTY_EL_ID" --force > /dev/null 2>&1

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
# Also to clients that only send If-Modified-Since (WordPress used to
# answer the archive feeds with its own 304 first).
for path in /podcast/rss2/ "/?post_type=podcast_episode&feed=rss2"; do
	check "$path answers 301 also to If-Modified-Since" '[ "$(curl -s -o /dev/null -w "%{http_code}" -H "If-Modified-Since: $(date -u -d "+1 day" "+%a, %d %b %Y %H:%M:%S GMT")" "$URL$path")" = 301 ]'
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

echo "Conditional requests on every feed address"
header_of() { curl -s -D - -o /dev/null "${@:2}" "$URL$1" | grep -i "^$3:" | cut -d' ' -f2- | tr -d '\r'; }
main_etag="$(curl -s -D - -o /dev/null "$URL/podcast/feed/" | grep -i '^etag:' | cut -d' ' -f2 | tr -d '\r')"
main_lastmod="$(curl -s -D - -o /dev/null "$URL/podcast/feed/" | grep -i '^last-modified:' | cut -d' ' -f2- | tr -d '\r')"
ARCHIVE_FEEDS=(/podcast/rss2/ /podcast/feed/atom/ "/?post_type=podcast_episode&feed=rss2")
for path in "${ARCHIVE_FEEDS[@]}"; do
	check "$path carries the podcast feed's ETag" '[ "$(curl -s -D - -o /dev/null "$URL$path" | grep -i "^etag:" | cut -d" " -f2 | tr -d "\r")" = "$main_etag" ]'
done
sleep 1
SETTINGS_BEFORE="$($WP option get epm_podcast_settings --format=json 2>/dev/null | grep '^{' | head -1)"
$WP eval 'update_option( "epm_podcast_settings", array_merge( (array) get_option( "epm_podcast_settings" ), [ "copyright" => "Changed again by the HTTP tests" ] ) );' > /dev/null 2>&1
for path in "${ARCHIVE_FEEDS[@]}"; do
	# WordPress would answer If-Modified-Since on these itself (304, from
	# the last post change) before the plugin ran.
	check "$path: If-Modified-Since after a channel change gets the new feed" '[ "$(curl -s -o /dev/null -w "%{http_code}" -H "If-Modified-Since: $main_lastmod" "$URL$path")" = 200 ]'
done
check "If-None-Match: * answers 304" '[ "$(curl -s -o /dev/null -w "%{http_code}" -H "If-None-Match: *" "$URL/podcast/feed/")" = 304 ]'
new_etag="$(curl -s -D - -o /dev/null "$URL/podcast/feed/" | grep -i '^etag:' | cut -d' ' -f2 | tr -d '\r')"
check "a list of tags with the current one answers 304" '[ "$(curl -s -o /dev/null -w "%{http_code}" -H "If-None-Match: \"abc\", W/$new_etag" "$URL/podcast/feed/")" = 304 ]'
check "a tag that merely contains the ETag gets the full feed" '[ "$(curl -s -o /dev/null -w "%{http_code}" -H "If-None-Match: \"xx${new_etag//\"/}yy\"" "$URL/podcast/feed/")" = 200 ]'
check "HEAD sends the headers without a body" '[ "$(curl -s -I -o /dev/null -w "%{http_code} %{size_download}" "$URL/podcast/feed/")" = "200 0" ] && curl -s -I "$URL/podcast/feed/" | grep -qi "^etag: $new_etag"'
[ -n "$SETTINGS_BEFORE" ] && $WP option update epm_podcast_settings "$SETTINGS_BEFORE" --format=json > /dev/null 2>&1

echo "Build time never in the future"
FUTURE_ID="$($WP eval '$id = wp_insert_post( [ "post_type" => "podcast_episode", "post_status" => "publish", "post_title" => "Dated next year", "post_date" => gmdate( "Y-m-d H:i:s", time() + YEAR_IN_SECONDS ) ] ); update_post_meta( $id, "_epm_audio_url", "https://feeds.example.test/media/next-year.mp3" ); wp_publish_post( $id ); echo "ID:", $id, "\n";' 2>/dev/null | grep '^ID:' | cut -c4-)"
future_lastmod="$(curl -s -D - -o /dev/null "$URL/podcast/feed/" | grep -i '^last-modified:' | cut -d' ' -f2- | tr -d '\r')"
check "an episode published with a date next year does not move Last-Modified past now" '[ -n "$future_lastmod" ] && [ "$(date -d "$future_lastmod" +%s)" -le "$(date +%s)" ]'
check "and the episode is in the feed" 'curl -s "$URL/podcast/feed/" | grep -q "Dated next year"'
[ -n "$FUTURE_ID" ] && $WP post delete "$FUTURE_ID" --force > /dev/null 2>&1

echo "The previous address of a WordPress podcast plugin"
alias_setting() { $WP eval '$s = (array) get_option( "epm_podcast_settings" ); $s["feed_alias"] = '"$1"'; update_option( "epm_podcast_settings", $s );' > /dev/null 2>&1; }
check "off: /feed/podcast/ is not found" '[ "$(curl -s -o /dev/null -w "%{http_code}" "$URL/feed/podcast/")" = 404 ]'
alias_setting true
for path in /feed/podcast/ "/?feed=podcast"; do
	check "on: $path answers 301 to the feed" '[ "$(status_and_location "$path")" = "301 $URL/podcast/feed/" ]'
	check "on: $path answers 301 also to If-Modified-Since" '[ "$(curl -s -o /dev/null -w "%{http_code}" -H "If-Modified-Since: $(date -u -d "+1 day" "+%a, %d %b %Y %H:%M:%S GMT")" "$URL$path")" = 301 ]'
done
check "the blog feed is unchanged" '[ "$(curl -s -o /dev/null -w "%{http_code}" "$URL/feed/")" = 200 ]'
alias_setting false
check "off again: /feed/podcast/ is not found" '[ "$(curl -s -o /dev/null -w "%{http_code}" "$URL/feed/podcast/")" = 404 ]'

echo "Plain permalinks"
$WP rewrite structure '' --hard > /dev/null 2>&1
check "the old address /podcast/feed/ still serves the feed" 'curl -s "$URL/podcast/feed/" | grep -q "<itunes:owner>"'
check "?epm_podcast_feed=1 serves it" 'curl -s "$URL/?epm_podcast_feed=1" | grep -q "<itunes:owner>"'
check "?post_type=podcast_episode&feed=rss2 serves it" 'curl -s "$URL/?post_type=podcast_episode&feed=rss2" | grep -q "<itunes:owner>"'
$WP rewrite structure '/%postname%/' --hard > /dev/null 2>&1
$WP rewrite flush --hard > /dev/null 2>&1

echo
echo "$PASSED passed, $FAILED failed."
[ "$FAILED" -eq 0 ]
