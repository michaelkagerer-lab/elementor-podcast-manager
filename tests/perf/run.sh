#!/usr/bin/env bash
#
# Import budget test: a paged feed is checked over several requests, each
# within a memory and time budget, under the memory_limit of a stock
# php-fpm (128M); and an import step costs the same memory whatever the
# size of the catalog. Every request is a PHP process of its own (like one
# admin-ajax request), so each peak is that request's own.
#
# Usage: WP_DIR=/tmp/epm-wp tests/perf/run.sh
#        PERF_HEAVY=1 WP_DIR=/tmp/epm-wp tests/perf/run.sh
#
# Default (CI):  check 10 pages x 100 items from a slow host (300 ms per
#                page); steps on 1,000 vs 4,000 items.
# PERF_HEAVY=1:  check 50 pages x 500 items (25,000 episodes, ~43 MB of XML);
#                steps on 1,000 vs 10,000 items. Takes a few minutes.
# Env: MEMORY_LIMIT (default 128M), BUDGET_MB (peak above the booted
#      WordPress per request, default 48), STEP_GROWTH_MB (default 2),
#      PERF_LATENCY_MS (per page while checking; default 300, heavy 0).
#
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WP_DIR="${WP_DIR:-/tmp/epm-wp}"
LIMIT="${MEMORY_LIMIT:-128M}"
BUDGET="${BUDGET_MB:-48}"
GROWTH="${STEP_GROWTH_MB:-2}"
FAILED=0

if [ -n "${PERF_HEAVY:-}" ]; then
	CHECK="50 500"
	SMALL="2 500"
	LARGE="20 500"
	LATENCY="${PERF_LATENCY_MS:-0}"
else
	CHECK="10 100"
	SMALL="10 100"
	LARGE="20 200"
	# A slow host: the check needs several requests.
	LATENCY="${PERF_LATENCY_MS:-300}"
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
field() { php -r '$d = json_decode($argv[1], true); $v = $d[$argv[2]] ?? ""; echo is_bool($v) ? ($v ? "true" : "false") : $v;' "$1" "$2"; }
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

echo
[ "$FAILED" -eq 0 ] && echo "Import budget passed." || echo "Import budget: $FAILED failed."
[ "$FAILED" -eq 0 ]
