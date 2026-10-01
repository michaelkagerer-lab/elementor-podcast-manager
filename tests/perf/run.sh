#!/usr/bin/env bash
#
# Budget tests: what one request costs, each request a PHP process of its
# own with the memory_limit of a stock php-fpm (128M).
#
# Import:  a paged feed is checked over several requests, each within a
#          memory and time budget; an import step costs the same memory
#          whatever the size of the catalog.
# Feed:    the feed (limits 20, 500 and unlimited) builds within a memory
#          budget, and for limits 20 and 500 needs the same memory on a
#          small and a large catalog; a conditional request (304) is cheap.
# Readiness: the report needs the same memory on both catalogs.
# Upgrade: the first request after a plugin update stores the version and
#          does no per-episode work, even with long transcripts; the
#          queued batches finish it, each within the budget.
#
# Usage: WP_DIR=/tmp/epm-wp tests/perf/run.sh
#        PERF_HEAVY=1 WP_DIR=/tmp/epm-wp tests/perf/run.sh
#
# Default (CI):  check 10 pages x 100 items from a slow host (300 ms per
#                page); steps on 1,000 vs 4,000 items; feed and readiness
#                on 300 vs 1,500 episodes; upgrade with 300 episodes of
#                200 KB transcripts (60 MB).
# PERF_HEAVY=1:  check 50 pages x 500 items (25,000 episodes, ~43 MB of XML);
#                steps on 1,000 vs 10,000 items; feed and readiness on
#                1,000 vs 10,000 episodes plus 1,000 episodes with 40 KB
#                transcripts; upgrade with 1,000 episodes of 200 KB
#                transcripts (200 MB). Takes several minutes.
# Env: MEMORY_LIMIT (default 128M), BUDGET_MB (peak above the booted
#      WordPress per import request, default 48), STEP_GROWTH_MB (default 2),
#      PERF_LATENCY_MS (per page while checking; default 300, heavy 0),
#      FEED_BUDGET_MB (peak of a feed, readiness or upgrade request above
#      the booted WordPress, default 24), FEED_GROWTH_MB (default 2),
#      PERF_ONLY (import, feed, readiness or upgrade: run one section).
#
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WP_DIR="${WP_DIR:-/tmp/epm-wp}"
LIMIT="${MEMORY_LIMIT:-128M}"
BUDGET="${BUDGET_MB:-48}"
GROWTH="${STEP_GROWTH_MB:-2}"
FEED_BUDGET="${FEED_BUDGET_MB:-24}"
FEED_GROWTH="${FEED_GROWTH_MB:-2}"
ONLY="${PERF_ONLY:-}"
FAILED=0

if [ -n "${PERF_HEAVY:-}" ]; then
	CHECK="50 500"
	SMALL="2 500"
	LARGE="20 500"
	LATENCY="${PERF_LATENCY_MS:-0}"
	CATALOGS="1000 10000"
	HEAVY_CATALOG="1000x40"
	UPGRADE_CATALOG="1000 200"
else
	CHECK="10 100"
	SMALL="10 100"
	LARGE="20 200"
	# A slow host: the check needs several requests.
	LATENCY="${PERF_LATENCY_MS:-300}"
	CATALOGS="300 1500"
	HEAVY_CATALOG=""
	UPGRADE_CATALOG="300 200"
fi

pass() { echo "  ✓ $1"; }
fail() { FAILED=$((FAILED + 1)); echo "  ✗ $1"; }

# One request: prints its EPM_PERF JSON (or the error output).
request() {
	local out
	out="$(EPM_PERF_MEMORY_LIMIT="$LIMIT" php "$WP_DIR/wp-cli.phar" --path="$WP_DIR/site" --allow-root \
		eval-file "$HERE/import-budget.php" "$@" 2>&1)"
	if ! grep -q '^EPM_PERF ' <<< "$out"; then
		echo "REQUEST FAILED ($*): $(grep -E 'Fatal|Error|error' <<< "$out" | head -3)" >&2
		echo '{}'
		return 1
	fi
	grep '^EPM_PERF ' <<< "$out" | head -1 | cut -c10-
}
field() { php -r '$d = json_decode($argv[1], true); $v = $d[$argv[2]] ?? ""; echo is_bool($v) ? ($v ? "true" : "false") : (is_array($v) ? implode(",", $v) : $v);' "$1" "$2"; }
max() { php -r 'echo max(array_map("floatval", array_slice($argv, 1)));' "$@"; }

# Check a feed the way the import screen does; prints the max request peak.
check_feed() {
	local pages="$1" per="$2" json peaks=() secs=() n=1
	request reset "$pages" "$per" > /dev/null
	json="$(request preview "$pages" "$per")" || return 1
	peaks+=("$(field "$json" peak_mb)"); secs+=("$(field "$json" seconds)")
	while [ "$(field "$json" loading)" = true ] && [ "$n" -lt 500 ]; do
		json="$(request more "$pages" "$per")" || return 1
		peaks+=("$(field "$json" peak_mb)"); secs+=("$(field "$json" seconds)")
		n=$((n + 1))
	done
	echo "$(field "$json" complete) $(field "$json" episodes) $(field "$json" pages) $n $(max "${peaks[@]}") $(max "${secs[@]}") $(field "$json" exact)"
}

# Start the checked import and run three steps; prints the max step peak.
steps() {
	local pages="$1" per="$2" json peaks=()
	request start "$pages" "$per" > /dev/null || return 1
	for _ in 1 2 3; do
		json="$(request step "$pages" "$per")" || return 1
		peaks+=("$(field "$json" peak_mb)")
	done
	echo "$(max "${peaks[@]}") $(field "$json" done)"
	request reset "$pages" "$per" > /dev/null
}

if [ -z "$ONLY" ] || [ "$ONLY" = import ]; then
read -r pages per <<< "$CHECK"
echo "Checking a paged feed: $pages pages x $per items, ${LATENCY} ms per page, memory_limit $LIMIT"
if result="$(EPM_PERF_LATENCY_MS="$LATENCY" check_feed "$pages" "$per")"; then
	read -r complete episodes read_pages requests peak secs exact <<< "$result"
	echo "  $requests requests, peak ${peak} MB above the booted site per request, longest request ${secs} s"
	[ "$complete" = true ] && [ "$episodes" = $((pages * per)) ] && [ "$read_pages" = "$pages" ] \
		&& pass "every page read, every episode found ($episodes)" || fail "complete=$complete episodes=$episodes pages=$read_pages"
	[ "$requests" -gt 1 ] && pass "read over several requests" || fail "read in one request"
	if [ "$exact" = true ]; then
		php -r "exit($peak <= $BUDGET ? 0 : 1);" && pass "no request above $BUDGET MB" || fail "a request used $peak MB (budget $BUDGET MB)"
	else
		echo "  (PHP < 8.2: no per-request peak, memory budget not checked)"
	fi
	php -r "exit($secs <= 15 ? 0 : 1);" && pass "no request longer than 15 s" || fail "a request took $secs s"
else
	fail "a request failed (memory_limit $LIMIT)"
fi
request reset "$pages" "$per" > /dev/null

echo "An import step does not cost more on a larger catalog"
read -r sp sper <<< "$SMALL"
read -r lp lper <<< "$LARGE"
small=""
large=""
if check_feed "$sp" "$sper" > /dev/null && small="$(steps "$sp" "$sper")" \
	&& check_feed "$lp" "$lper" > /dev/null && large="$(steps "$lp" "$lper")"; then
	read -r small_peak small_done <<< "$small"
	read -r large_peak large_done <<< "$large"
	echo "  $((sp * sper)) items: ${small_peak} MB per step; $((lp * lper)) items: ${large_peak} MB per step"
	[ "$small_done" = 30 ] && [ "$large_done" = 30 ] && pass "three steps of ten episodes each" || fail "steps imported $small_done / $large_done"
	php -r "exit($large_peak - $small_peak <= $GROWTH ? 0 : 1);" && pass "step memory grows by at most $GROWTH MB" || fail "step memory grew from $small_peak to $large_peak MB"
else
	fail "a request failed (memory_limit $LIMIT)"
fi

fi

# One request of the catalog budget test: prints its EPM_PERF JSON.
catalog() {
	local out
	out="$(EPM_PERF_MEMORY_LIMIT="$LIMIT" php "$WP_DIR/wp-cli.phar" --path="$WP_DIR/site" --allow-root \
		eval-file "$HERE/catalog-budget.php" "$@" 2>&1)"
	if ! grep -q '^EPM_PERF ' <<< "$out"; then
		echo "REQUEST FAILED ($*): $(grep -E 'Fatal|Error|error' <<< "$out" | head -3)" >&2
		echo '{}'
		return 1
	fi
	grep '^EPM_PERF ' <<< "$out" | head -1 | cut -c10-
}
within() { php -r "exit(is_numeric('$1') && $1 <= $2 ? 0 : 1);"; }

if [ -z "$ONLY" ] || [ "$ONLY" = feed ] || [ "$ONLY" = readiness ]; then
	declare -A FEED_PEAK=()
	declare -A READY_PEAK=()
	for spec in $CATALOGS $HEAVY_CATALOG; do
		n="${spec%x*}"
		kb=0
		[[ "$spec" == *x* ]] && kb="${spec#*x}"
		json="$(catalog catalog "$n" "$kb" 1)" || { fail "catalog $spec"; continue; }
		distributable="$(field "$json" distributable)"
		# The seeded site's own episodes are in the feed too.
		extra="$(catalog feed 0 cold | php -r '$d = json_decode(stream_get_contents(STDIN), true); echo (int) ($d["items"] ?? 0);')"
		extra=$((extra - distributable))
		if [ -z "$ONLY" ] || [ "$ONLY" = feed ]; then
			echo "Feed: $n episodes$([ "$kb" != 0 ] && echo " with $kb KB transcripts") ($distributable with MP3 audio), memory_limit $LIMIT"
			for lim in 20 500 0; do
				json="$(catalog feed "$lim" cold)" || { fail "feed $spec limit $lim: request failed (memory_limit $LIMIT)"; continue; }
				want=$((distributable + extra))
				[ "$lim" != 0 ] && [ "$lim" -lt "$want" ] && want="$lim"
				peak="$(field "$json" peak_mb)"
				FEED_PEAK["$spec-$lim"]="$peak"
				echo "  limit $lim: $(field "$json" items) items, $(field "$json" bytes) bytes, ${peak} MB, $(field "$json" seconds) s, $(field "$json" queries) queries"
				[ "$(field "$json" status)" = 200 ] && [ "$(field "$json" well_formed)" = true ] && [ "$(field "$json" items)" = "$want" ] && [ "$(field "$json" unique)" = true ] \
					&& pass "limit $lim: 200, well-formed, $want items, no GUID twice" || fail "limit $lim: status $(field "$json" status), $(field "$json" items) items (want $want), well-formed $(field "$json" well_formed)"
				[ "$(field "$json" exact)" = true ] && { within "$peak" "$FEED_BUDGET" && pass "limit $lim: ${peak} MB (budget $FEED_BUDGET MB)" || fail "limit $lim: ${peak} MB (budget $FEED_BUDGET MB)"; }
				etag="$(field "$json" etag)"
			done
			json="$(catalog feed 0 304 "$etag")" || fail "304 request failed"
			[ "$(field "$json" status)" = 304 ] && within "$(field "$json" peak_mb)" 2 \
				&& pass "a conditional request answers 304 with $(field "$json" peak_mb) MB, $(field "$json" queries) queries" || fail "conditional request: status $(field "$json" status), $(field "$json" peak_mb) MB"
		fi
		if [ -z "$ONLY" ] || [ "$ONLY" = readiness ]; then
			json="$(catalog readiness)" || { fail "readiness $spec: request failed (memory_limit $LIMIT)"; continue; }
			READY_PEAK["$spec"]="$(field "$json" peak_mb)"
			echo "Readiness: $spec episodes: $(field "$json" checks) checks ($(field "$json" errors) errors, $(field "$json" warnings) warnings), $(field "$json" peak_mb) MB, $(field "$json" seconds) s"
			[ "$(field "$json" exact)" = true ] && { within "$(field "$json" peak_mb)" "$FEED_BUDGET" && pass "readiness within $FEED_BUDGET MB" || fail "readiness: $(field "$json" peak_mb) MB (budget $FEED_BUDGET MB)"; }
		fi
	done
	read -r small large <<< "$CATALOGS"
	if [ -n "${FEED_PEAK[$small-20]:-}" ] && [ -n "${FEED_PEAK[$large-20]:-}" ]; then
		for lim in 20 500; do
			php -r "exit(${FEED_PEAK[$large-$lim]:-999} - ${FEED_PEAK[$small-$lim]:-0} <= $FEED_GROWTH ? 0 : 1);" \
				&& pass "feed limit $lim: ${FEED_PEAK[$small-$lim]} MB on $small, ${FEED_PEAK[$large-$lim]} MB on $large episodes (at most $FEED_GROWTH MB more)" \
				|| fail "feed limit $lim grows from ${FEED_PEAK[$small-$lim]} MB ($small) to ${FEED_PEAK[$large-$lim]} MB ($large)"
		done
	fi
	if [ -n "${READY_PEAK[$small]:-}" ] && [ -n "${READY_PEAK[$large]:-}" ]; then
		php -r "exit(${READY_PEAK[$large]} - ${READY_PEAK[$small]} <= $FEED_GROWTH ? 0 : 1);" \
			&& pass "readiness: ${READY_PEAK[$small]} MB on $small, ${READY_PEAK[$large]} MB on $large episodes" \
			|| fail "readiness grows from ${READY_PEAK[$small]} MB ($small) to ${READY_PEAK[$large]} MB ($large)"
	fi
	catalog reset > /dev/null
fi

if [ -z "$ONLY" ] || [ "$ONLY" = upgrade ]; then
	read -r n kb <<< "$UPGRADE_CATALOG"
	echo "Upgrade: the first request after a plugin update, $n episodes with $kb KB transcripts, memory_limit $LIMIT"
	catalog catalog "$n" "$kb" 0 > /dev/null || fail "catalog"
	if json="$(catalog upgrade)"; then
		echo "  first request: $(field "$json" peak_mb) MB, $(field "$json" seconds) s, $(field "$json" queries) queries; pending: $(field "$json" pending)"
		[ "$(field "$json" version)" != "1.2.0" ] && pass "the new version is stored by the first request" || fail "the version is still $(field "$json" version)"
		[ "$(field "$json" stale)" = "$n" ] && pass "and it did no per-episode work" || fail "$(field "$json" stale) of $n episodes still stale after the first request"
		[ "$(field "$json" exact)" = true ] && { within "$(field "$json" peak_mb)" "$FEED_BUDGET" && pass "within $FEED_BUDGET MB" || fail "$(field "$json" peak_mb) MB (budget $FEED_BUDGET MB)"; }
		steps=0
		peaks=()
		while [ "$steps" -lt 30 ]; do
			json="$(catalog upgrade-step)" || { fail "an upgrade step failed (memory_limit $LIMIT)"; break; }
			steps=$((steps + 1))
			peaks+=("$(field "$json" peak_mb)")
			php -r '$d = json_decode($argv[1], true); exit(empty($d["pending"]) ? 0 : 1);' "$json" && break
		done
		echo "  $steps step(s), at most $(max "${peaks[@]:-0}") MB each"
		[ "$(field "$json" stale)" = 0 ] && pass "the queued batches synced every episode" || fail "$(field "$json" stale) episodes still stale after $steps steps"
		[ "$(field "$json" exact)" = true ] && { within "$(max "${peaks[@]:-0}")" "$FEED_BUDGET" && pass "each step within $FEED_BUDGET MB" || fail "a step used $(max "${peaks[@]:-0}") MB"; }
	else
		fail "the first request after the update failed (memory_limit $LIMIT)"
	fi
	catalog reset > /dev/null
fi

echo
[ "$FAILED" -eq 0 ] && echo "Budget tests passed." || echo "Budget tests: $FAILED failed."
[ "$FAILED" -eq 0 ]
