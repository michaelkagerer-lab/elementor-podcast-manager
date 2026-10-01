#!/usr/bin/env bash
# Static checks: PHP syntax (every file) and JavaScript syntax.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.."

status=0

# A destructive suite must never reuse an arbitrary or production WordPress
# directory. run-all.sh checks this marker before seeding; EPM_TEST_SITE=1 is
# the explicit opt-in for a caller that has already verified disposability.
if [ -n "${WP_DIR:-}" ] && [ -d "$WP_DIR/site" ]; then
	if [ ! -f "$WP_DIR/.epm-test-site" ] && [ "${EPM_TEST_SITE:-}" != "1" ]; then
		echo "Refusing to run against unmarked WP_DIR=$WP_DIR. Use tests/run-all.sh or mark a disposable test directory with EPM_TEST_SITE=1." >&2
		exit 2
	fi
fi
PHP_BIN="${PHP_BIN:-php}"
NODE_BIN="${NODE_BIN:-node}"
if ! command -v "$PHP_BIN" >/dev/null 2>&1; then
	echo "PHP executable not found: $PHP_BIN" >&2
	exit 2
fi
if ! command -v "$NODE_BIN" >/dev/null 2>&1; then
	echo "Node executable not found: $NODE_BIN" >&2
	exit 2
fi
while IFS= read -r -d '' file; do
	if ! out=$("$PHP_BIN" -l "$file" 2>&1); then
		echo "$out"
		status=1
	fi
done < <(find . -name '*.php' -not -path './tests/e2e/node_modules/*' -not -path './.git/*' -print0)

for file in assets/js/*.js admin/js/*.js tests/e2e/*.mjs; do
	[ -f "$file" ] || continue
	"$NODE_BIN" --check "$file" || status=1
done

[ "$status" -eq 0 ] && echo "Lint passed."
exit "$status"
