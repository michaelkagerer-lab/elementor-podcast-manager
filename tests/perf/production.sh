#!/usr/bin/env bash
#
# Production-like checks on a stock nginx + php-fpm site (memory_limit
# 128M), through real HTTP requests:
#
#   upgrade  after a plugin update (an older epm_version stored) on a
#            catalog of 1,000 episodes with 40 KB transcripts, the home
#            page, the feed, wp-login.php and wp-admin answer 200, the new
#            version is stored by the first request, and the per-episode
#            upgrade work finishes in bounded steps.
#   feed     cold, warm and conditional (304) feed requests for the feed
#            limits 20, 500 and 0 (unlimited) on catalogs of 5,000 and
#            10,000 episodes and 1,000 episodes with 40 KB transcripts:
#            200, well-formed, every expected item, within the memory
#            budget, and a 304 that stays cheap.
#   move     "Move my podcast here" with a 5,000-episode feed through the
#            import screen's AJAX requests; afterwards the feed (now
#            unlimited) answers 200 with every episode, and again cheaply.
#
# The site must be a disposable MySQL/MariaDB test site (setup-wp.sh with
# WP_DB=mysql) that holds no other episodes (do not seed it). The script serves it through tests/perf/fpm.sh and stops
# nginx and php-fpm at the end (KEEP_SERVER=1 keeps them). It deletes
# episodes. Takes a few minutes.
#
# Usage: WP_DIR=/tmp/epm-wp-fpm WP_PORT=8963 tests/perf/production.sh [upgrade] [feed] [move]
# Env:   FPM_PORT (default WP_PORT+1), FEED_BUDGET_MB (default 64: peak of
#        one feed request, whole request), NOT_MODIFIED_MS (default 300),
#        SIZES (default "5000 10000 1000x40"), MOVE_EPISODES (default 5000)
#
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
export WP_DIR="${WP_DIR:?WP_DIR is required}"
export WP_PORT="${WP_PORT:-8963}"
export FPM_PORT="${FPM_PORT:-$((WP_PORT + 1))}"
URL="http://localhost:$WP_PORT"
WP="$WP_DIR/wp"
LOG="$WP_DIR/fpm/requests.jsonl"
BUDGET="${FEED_BUDGET_MB:-64}"
NOT_MODIFIED_MS="${NOT_MODIFIED_MS:-300}"
SIZES="${SIZES:-5000 10000 1000x40}"
MOVE_EPISODES="${MOVE_EPISODES:-5000}"
SECTIONS=("$@")
[ "${#SECTIONS[@]}" -gt 0 ] || SECTIONS=(upgrade feed move)
FAILED=0
PASSED=0
TMP="$(mktemp -d)"

pass() { PASSED=$((PASSED + 1)); echo "  ✓ $1"; }
fail() { FAILED=$((FAILED + 1)); echo "  ✗ $1"; }
cleanup() {
	rm -rf "$TMP"
	[ -n "${KEEP_SERVER:-}" ] || "$HERE/fpm.sh" stop > /dev/null
}
trap cleanup EXIT

# WP-CLI without the deprecation notices some Elementor versions print.
wpq() {
	"$WP" "$@" 2>&1 | awk '
		/^PHP: .*Elementor/ { skip = 1; next }
		skip && /^\)\]$/ { skip = 0; next }
		skip { next }
		/^Deprecated: .*[Ee]lementor/ { next }
		{ print }'
}
# One SQL statement through $wpdb (prints a SELECT's first value).
sql() {
	EPM_SQL="$1" wpq eval 'global $wpdb; $q = str_replace( "{prefix}", $wpdb->prefix, (string) getenv( "EPM_SQL" ) ); $v = 0 === stripos( ltrim( $q ), "select" ) ? $wpdb->get_var( $q ) : $wpdb->query( $q ); echo "SQL:", $v, "\n";' | grep '^SQL:' | head -1 | cut -c5-
}
# The same without loading WordPress (which would run the upgrade itself).
rawsql() {
	local prefix
	prefix="$(wpq config get table_prefix | grep -E '^[A-Za-z0-9_]+$' | head -1)"
	wpq db query "${1//\{prefix\}/$prefix}" --skip-column-names | grep -v '^$' | grep -v '^Success' | head -1
}
budget() {
	php "$WP_DIR/wp-cli.phar" --path="$WP_DIR/site" --allow-root eval-file "$HERE/catalog-budget.php" "$@" 2>&1 | grep '^EPM_PERF ' | head -1 | cut -c10-
}
field() { php -r '$d = json_decode($argv[1], true); $v = $d[$argv[2]] ?? ""; echo is_bool($v) ? ($v ? "true" : "false") : $v;' "$1" "$2"; }
# The probe's line for a request label: "<ms> <peak_mb> <fatal>".
probe() {
	local line
	line="$(grep -F "\"label\":\"$1\"" "$LOG" | tail -1)"
	php -r '$d = json_decode($argv[1], true) ?: []; echo ($d["ms"] ?? "?"), " ", ($d["peak_mb"] ?? "?"), " ", ($d["fatal"] ?? "");' "$line"
}
# GET with a probe label: prints "<status> <bytes>".
get() {
	local label="$1" path="$2"
	shift 2
	curl -s -o "$TMP/body" -w '%{http_code} %{size_download}' -H "X-EPM-Perf: $label" "$@" "$URL$path"
}
# Well-formed RSS: prints "<items> <well-formed true|false>".
items() {
	php -r '
		$r = new XMLReader(); libxml_use_internal_errors(true);
		$n = 0; $ok = @$r->open($argv[1], null, LIBXML_NONET);
		while ($ok && ($ok = $r->read())) { if (XMLReader::ELEMENT === $r->nodeType && "item" === $r->name) { $n++; } }
		echo $n, " ", (empty(libxml_get_errors()) && $n > 0) ? "true" : "false";' "$TMP/body"
}
limit() {
	wpq eval 'update_option( EPM\PodcastSettings::OPTION, epm()->settings->sanitize( array_merge( epm()->settings->all(), [ "feed_limit" => '"$1"' ] ) ) );' > /dev/null
}
login() {
	curl -s -o /dev/null -c "$TMP/cookies" -b "wordpress_test_cookie=WP%20Cookie%20check" \
		-d "log=admin&pwd=admin&wp-submit=Log+In&testcookie=1" "$URL/wp-login.php"
}

# Episodes a "move" section left behind (GUIDs gen-*).
drop_generated() {
	wpq eval 'global $wpdb; foreach ( $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = \"_epm_guid\" AND meta_value LIKE \"gen-%\"" ) as $id ) { $wpdb->delete( $wpdb->postmeta, [ "post_id" => $id ] ); $wpdb->delete( $wpdb->posts, [ "ID" => $id ] ); } wp_cache_flush(); EPM\Feed::flush_cache();' > /dev/null
}

"$HERE/fpm.sh" start || exit 1
wpq eval 'global $wpdb; echo "database: ", $wpdb->db_server_info(), "\n";' | grep '^database' || true
login

for section in "${SECTIONS[@]}"; do
case "$section" in
upgrade)
	echo "Upgrade: the first requests after a plugin update (1,000 episodes with 40 KB transcripts)"
	drop_generated
	limit 500
	budget catalog 1000 40 0 > /dev/null
	current="$(wpq eval 'echo "V:", EPM_VERSION, "\n";' | grep '^V:' | cut -c3-)"
	# Simulate the update without loading WordPress (WP-CLI would run the
	# upgrade itself, without a memory limit).
	rawsql "UPDATE {prefix}postmeta SET meta_value = '0' WHERE meta_key = '_epm_duration_seconds'" > /dev/null
	rawsql "UPDATE {prefix}options SET option_value = '1.2.0' WHERE option_name = 'epm_version'" > /dev/null
	ok=1
	for round in 1 2 3; do
		for path in / /podcast/feed/ /wp-login.php /wp-admin/; do
			read -r code _ <<< "$(get "upgrade-$round-$path" "$path" -b "$TMP/cookies")"
			read -r ms peak fatal <<< "$(probe "upgrade-$round-$path")"
			echo "    round $round $path: HTTP $code, ${ms} ms, ${peak} MB $fatal"
			[ "$code" = 200 ] || ok=0
		done
		if [ "$round" = 1 ]; then
			stored="$(rawsql "SELECT option_value FROM {prefix}options WHERE option_name = 'epm_version'")"
			[ "$stored" = "$current" ] && pass "the first request stored the new version ($current)" || fail "stored version after the first requests: $stored"
		fi
	done
	[ "$ok" = 1 ] && pass "home page, feed, wp-login.php and wp-admin answer 200 after the update" || fail "a request failed after the update"
	steps=0
	while [ "$steps" -lt 50 ]; do
		json="$(budget upgrade-step)"
		steps=$((steps + 1))
		php -r '$d = json_decode($argv[1], true); exit(empty($d["pending"]) ? 0 : 1);' "$json" && break
	done
	echo "    upgrade steps: $steps, last: $json"
	synced="$(sql "SELECT COUNT(*) FROM {prefix}postmeta WHERE meta_key = '_epm_duration_seconds' AND meta_value = '600'")"
	[ "${synced:-0}" -ge 1000 ] && pass "the per-episode upgrade finished in $steps step(s) of at most $(field "$json" peak_mb) MB" || fail "durations synced: $synced of 1000"
	;;
feed)
	drop_generated
	for spec in $SIZES; do
		n="${spec%x*}"
		kb=0
		[[ "$spec" == *x* ]] && kb="${spec#*x}"
		json="$(budget catalog "$n" "$kb" 1)"
		# The site holds no other episodes (it is not seeded).
		expected_all="$(field "$json" distributable)"
		echo "Feed: $n episodes$([ "$kb" != 0 ] && echo " with $kb KB transcripts") ($(field "$json" distributable) with MP3 audio)"
		for lim in 20 500 0; do
			limit "$lim"
			wpq eval 'EPM\Feed::flush_cache();' > /dev/null
			want="$lim"
			[ "$lim" = 0 ] || [ "$lim" -gt "$expected_all" ] && want="$expected_all"
			read -r code bytes <<< "$(get "feed-$spec-$lim-cold" /podcast/feed/)"
			read -r count wf <<< "$(items)"
			read -r ms peak fatal <<< "$(probe "feed-$spec-$lim-cold")"
			etag="$(curl -s -D - -o /dev/null "$URL/podcast/feed/" | grep -i '^etag:' | cut -d' ' -f2 | tr -d '\r')"
			read -r wcode _ <<< "$(get "feed-$spec-$lim-warm" /podcast/feed/)"
			read -r wms wpeak _ <<< "$(probe "feed-$spec-$lim-warm")"
			read -r ncode _ <<< "$(get "feed-$spec-$lim-304" /podcast/feed/ -H "If-None-Match: $etag")"
			read -r nms npeak _ <<< "$(probe "feed-$spec-$lim-304")"
			echo "    limit $lim: cold HTTP $code ${bytes} B ${ms} ms ${peak} MB, $count items, well-formed $wf; warm $wcode ${wms} ms ${wpeak} MB; 304? $ncode ${nms} ms ${npeak} MB $fatal"
			[ "$code" = 200 ] && [ "$wf" = true ] && [ "$count" = "$want" ] \
				&& pass "$spec, limit $lim: 200, well-formed, $count items" || fail "$spec, limit $lim: HTTP $code, $count items (want $want), well-formed $wf"
			php -r "exit(is_numeric('$peak') && $peak <= $BUDGET ? 0 : 1);" && pass "$spec, limit $lim: cold request ${peak} MB (budget $BUDGET MB)" || fail "$spec, limit $lim: cold request ${peak} MB (budget $BUDGET MB)"
			[ "$ncode" = 304 ] && php -r "exit($nms <= $NOT_MODIFIED_MS ? 0 : 1);" && pass "$spec, limit $lim: 304 in ${nms} ms" || fail "$spec, limit $lim: conditional request HTTP $ncode in ${nms} ms"
		done
	done
	limit 500
	budget reset > /dev/null
	;;
move)
	echo "Move: a show of $MOVE_EPISODES episodes through the import screen, then its feed"
	budget reset > /dev/null
	drop_generated
	wpq eval '$s = epm()->settings; update_option( EPM\PodcastSettings::OPTION, $s->sanitize( array_merge( $s->all(), [ "feed_limit" => 500, "moved_in" => false, "locked" => false ] ) ) ); EPM\ImportJob::cancel(); wp_cache_flush();' > /dev/null
	nonce="$(curl -s -b "$TMP/cookies" "$URL/wp-admin/admin.php?page=epm-hosting" | grep -o '"importNonce":"[a-z0-9]*"' | head -1 | cut -d'"' -f4)"
	feed="https://feeds.example.test/generated/$MOVE_EPISODES.xml"
	curl -s -b "$TMP/cookies" -o "$TMP/preview.json" -d "action=epm_import_preview&nonce=$nonce&url=$feed" "$URL/wp-admin/admin-ajax.php"
	token="$(php -r '$d = json_decode((string) file_get_contents($argv[1]), true); echo $d["data"]["token"] ?? "";' "$TMP/preview.json")"
	curl -s -b "$TMP/cookies" -o "$TMP/start.json" -d "action=epm_import_start&nonce=$nonce&token=$token&status=publish&purpose=move&confirm_owner=1" "$URL/wp-admin/admin-ajax.php"
	status="$(php -r '$d = json_decode((string) file_get_contents($argv[1]), true); echo $d["data"]["status"] ?? ("error: " . ($d["data"]["message"] ?? "?"));' "$TMP/start.json")"
	steps=0
	while [ "$status" = running ] && [ "$steps" -lt 3000 ]; do
		steps=$((steps + 1))
		curl -s -b "$TMP/cookies" -o "$TMP/step.json" -d "action=epm_import_step&nonce=$nonce" "$URL/wp-admin/admin-ajax.php"
		status="$(php -r '$d = json_decode((string) file_get_contents($argv[1]), true); echo $d["data"]["status"] ?? "running";' "$TMP/step.json")"
	done
	echo "    import: $status after $steps steps"
	[ "$status" = done ] && pass "the move import finished" || fail "the move import ended as '$status'"
	state="$(wpq eval 'echo "S:", epm()->settings->get( "feed_limit" ), " ", epm()->settings->get( "moved_in" ) ? "moved_in" : "-", " ", EPM\Hosting::get( "mode" ), "\n";' | grep '^S:' | cut -c3-)"
	echo "    after the move: feed_limit moved_in mode = $state"
	expected="$MOVE_EPISODES"
	for round in 1 2; do
		read -r code bytes <<< "$(get "move-feed-$round" /podcast/feed/)"
		read -r count wf <<< "$(items)"
		read -r ms peak fatal <<< "$(probe "move-feed-$round")"
		echo "    GET /podcast/feed/ #$round: HTTP $code, $bytes bytes, ${ms} ms, ${peak} MB, $count items, well-formed $wf $fatal"
	done
	[ "$code" = 200 ] && [ "$wf" = true ] && [ "$count" -ge "$MOVE_EPISODES" ] && [ "$count" = "$expected" ] \
		&& pass "after the move the feed lists all $count episodes" || fail "after the move: HTTP $code, $count items (expected $expected)"
	etag="$(curl -s -D - -o /dev/null "$URL/podcast/feed/" | grep -i '^etag:' | cut -d' ' -f2 | tr -d '\r')"
	read -r ncode _ <<< "$(get move-304 /podcast/feed/ -H "If-None-Match: $etag")"
	read -r nms npeak _ <<< "$(probe move-304)"
	[ "$ncode" = 304 ] && php -r "exit($nms <= $NOT_MODIFIED_MS ? 0 : 1);" && pass "a conditional request answers 304 in ${nms} ms (${npeak} MB)" || fail "conditional request: HTTP $ncode in ${nms} ms"
	;;
*)
	echo "Unknown section: $section" >&2
	FAILED=$((FAILED + 1))
	;;
esac
done

echo
echo "$PASSED passed, $FAILED failed."
[ "$FAILED" -eq 0 ]
