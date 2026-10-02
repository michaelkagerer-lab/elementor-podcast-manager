#!/usr/bin/env bash
# Converts only a marked disposable MySQL test site, then tests network lifecycle.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WP_DIR="${WP_DIR:-/tmp/epm-wp}"
WP_CLI="${WP_CLI:-$WP_DIR/wp}"
if [ ! -f "$WP_DIR/.epm-test-site" ] || [ ! -f "$WP_DIR/site/wp-config.php" ]; then
 echo "Refusing multisite tests: WP_DIR must contain a marked disposable test site." >&2
 exit 2
fi
if [ -f "$WP_DIR/site/wp-content/db.php" ]; then
 echo "Multisite lifecycle tests require a disposable MySQL/MariaDB database." >&2
 exit 2
fi
if [ "$("$WP_CLI" eval 'echo is_multisite() ? "yes" : "no";')" != yes ]; then
 "$WP_CLI" core multisite-convert --title='EPM disposable test network'
fi
EPM_TEST_SITE=1 "$WP_CLI" eval-file "$ROOT/tests/multisite/lifecycle.php"
