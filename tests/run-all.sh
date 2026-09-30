#!/usr/bin/env bash
#
# Provision a disposable WordPress + Elementor site and run every suite:
# lint, integration (WP-CLI), HTTP and browser (Playwright) tests.
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

"$ROOT/tests/bin/lint.sh"
"$ROOT/tests/bin/setup-wp.sh"

echo; echo "== Fixtures"
EPM_ALLOW_TEST_SEED=1 "$WP_CLI" eval-file "$ROOT/tests/fixtures/seed.php"

echo; echo "== Integration tests"
"$WP_CLI" eval-file "$ROOT/tests/integration/run.php"

echo; echo "== HTTP tests"
"$ROOT/tests/http/run.sh"

if [ -z "${SKIP_E2E:-}" ]; then
	echo; echo "== Browser tests"
	cd "$ROOT/tests/e2e"
	[ -d node_modules/playwright ] || npm install --no-audit --no-fund
	node run.mjs
fi

echo; echo "== PHP notices from the plugin"
# Only notices raised in the plugin's own files (not WordPress, Elementor
# or WP-CLI), or _doing_it_wrong calls naming the plugin.
if grep -E "PHP (Warning|Notice|Fatal error|Deprecated)" "$WP_DIR/site/wp-content/debug.log" 2>/dev/null \
	| grep -F -e "$ROOT/" -e "plugins/elementor-podcast-manager/" -e "epm_" -e "EPM\\"; then
	echo "The plugin produced PHP notices (see above)."
	exit 1
fi
echo "None."
