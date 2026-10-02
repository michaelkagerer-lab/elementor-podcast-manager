#!/usr/bin/env bash
#
# Provision a disposable WordPress + Elementor site and run every suite:
# lint, integration (WP-CLI), two-process races, the import budget, media
# downloads over real sockets, HTTP and browser (Playwright) tests.
#
# Suites are discovered, so a new file is picked up without editing this
# script:
#   integration  tests/integration/*.php  (run.php first; lib.php is shared code)
#   browser      tests/e2e/*.mjs          (run.mjs first; lib.mjs, helpers.mjs
#                                          and _*.mjs are shared code)
#
# The fixtures are seeded again before every suite, so each one starts from
# the same site. Every suite runs even when an earlier one failed; the exit
# code is non-zero when any suite failed or the plugin raised a PHP notice.
#
# Usage: tests/run-all.sh            (all suites)
#        SKIP_E2E=1 tests/run-all.sh (no browser)
#
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
export WP_DIR="${WP_DIR:-/tmp/epm-wp}"
export WP_PORT="${WP_PORT:-8889}"
export WP_URL="http://localhost:$WP_PORT"
export WP_CLI="$WP_DIR/wp"
export PHP_BIN="${PHP_BIN:-php}"
export NODE_BIN="${NODE_BIN:-node}"
DEBUG_LOG="$WP_DIR/site/wp-content/debug.log"

FAILED=()

if [ -d "$WP_DIR/site" ] && [ ! -f "$WP_DIR/.epm-test-site" ]; then
	echo "Refusing to run against unmarked WP_DIR=$WP_DIR. Use a fresh disposable directory; tests delete episodes and alter site settings." >&2
	exit 2
fi
export EPM_TEST_SITE=1

# Seed the fixtures (deletes every episode on the test site).
seed() {
	EPM_ALLOW_TEST_SEED=1 "$WP_CLI" eval-file "$ROOT/tests/fixtures/seed.php" > /dev/null
}

# Suite files in a directory: the main suite first, then the rest in
# alphabetical order, without the shared helper files.
suites() {
	local dir="$1" ext="$2" first="$3" file name
	shift 3
	[ -f "$dir/$first" ] && echo "$dir/$first"
	for file in "$dir"/*."$ext"; do
		[ -f "$file" ] || continue
		name="$(basename "$file")"
		case "$name" in
			"$first" | _*) continue ;;
		esac
		local helper skip=""
		for helper in "$@"; do
			[ "$name" = "$helper" ] && skip=1
		done
		[ -z "$skip" ] && echo "$file"
	done
	return 0
}

"$ROOT/tests/bin/lint.sh"
python3 "$ROOT/tests/packaging/test_package.py"
python3 -m unittest discover -s "$ROOT/tests/safety" -p "test_*.py"

# Only this run's notices count.
[ -f "$DEBUG_LOG" ] && : > "$DEBUG_LOG"
"$ROOT/tests/bin/setup-wp.sh"
WP_DIR="$WP_DIR" WP_CLI="$WP_CLI" "$ROOT/tests/safety/seed.sh"

echo; echo "== Integration tests"
while IFS= read -r suite; do
	echo; echo "-- integration/$(basename "$suite")"
	seed
	"$WP_CLI" eval-file "$suite" || FAILED+=("integration/$(basename "$suite")")
done < <(suites "$ROOT/tests/integration" php run.php lib.php)

echo; echo "== Race tests (two processes)"
seed
WP_CLI="$WP_CLI" "$ROOT/tests/concurrency/run.sh" || FAILED+=("concurrency/run.sh")

echo; echo "== Import budget (memory and time per request)"
WP_DIR="$WP_DIR" "$ROOT/tests/perf/run.sh" || FAILED+=("perf/run.sh")

echo; echo "== Media downloads (local media host, real sockets)"
seed
WP_DIR="$WP_DIR" WP_CLI="$WP_CLI" "$ROOT/tests/media/run.sh" || FAILED+=("media/run.sh")

echo; echo "== HTTP tests"
seed
"$ROOT/tests/http/run.sh" || FAILED+=("http/run.sh")

if [ -z "${SKIP_E2E:-}" ]; then
	echo; echo "== Browser tests"
	cd "$ROOT/tests/e2e"
	[ -d node_modules/playwright ] || npm ci --no-audit --no-fund
	while IFS= read -r suite; do
		echo; echo "-- e2e/$(basename "$suite")"
		seed
		node "$suite" || FAILED+=("e2e/$(basename "$suite")")
	done < <(suites "$ROOT/tests/e2e" mjs run.mjs lib.mjs helpers.mjs)
	cd "$ROOT"
fi

# Leave the site seeded for manual checks.
seed

echo; echo "== PHP notices from the plugin"
# Only notices raised in the plugin's own files (not WordPress, Elementor
# or WP-CLI), or _doing_it_wrong calls naming the plugin.
if grep -E "PHP (Warning|Notice|Fatal error|Deprecated)" "$DEBUG_LOG" 2>/dev/null \
	| grep -F -e "$ROOT/" -e "plugins/elementor-podcast-manager/" -e "epm_" -e "EPM\\"; then
	echo "The plugin produced PHP notices (see above)."
	FAILED+=("PHP notices")
else
	echo "None."
fi

echo
if [ "${#FAILED[@]}" -gt 0 ]; then
	echo "Failed: ${FAILED[*]}"
	exit 1
fi
echo "All suites passed."
