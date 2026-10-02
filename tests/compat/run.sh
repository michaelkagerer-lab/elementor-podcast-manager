#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WP_DIR="${WP_DIR:-/tmp/epm-wp}"
WP_CLI="${WP_CLI:-$WP_DIR/wp}"
if [ ! -f "$WP_DIR/.epm-test-site" ]; then
 echo 'Refusing compatibility tests on an unmarked site.' >&2
 exit 2
fi
export EPM_TEST_SITE=1
EPM_ALLOW_TEST_SEED=1 "$WP_CLI" eval-file "$ROOT/tests/fixtures/seed.php" > /dev/null
if [ "${EPM_ELEMENTOR_OFF:-0}" = 1 ]; then
 "$WP_CLI" eval-file "$ROOT/tests/compat/elementor-off.php"
else
 for suite in run admin design feed frontend hosting import media widgets; do
  EPM_ALLOW_TEST_SEED=1 "$WP_CLI" eval-file "$ROOT/tests/fixtures/seed.php" > /dev/null
  timeout 180 "$WP_CLI" eval-file "$ROOT/tests/integration/$suite.php"
 done
fi
WP_DIR="$WP_DIR" WP_CLI="$WP_CLI" "$ROOT/tests/safety/seed.sh"
