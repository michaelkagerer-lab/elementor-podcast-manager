#!/usr/bin/env bash
# QA-N1 / QA-N3: exercise the fixture's own guard and reset every post status.
set -uo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WP_DIR="${WP_DIR:-/tmp/epm-wp}"
WP_CLI="${WP_CLI:-$WP_DIR/wp}"
if [ ! -f "$WP_DIR/.epm-test-site" ]; then
 echo 'Refusing fixture safety test on an unmarked site.' >&2
 exit 2
fi
FAILED=0
trash="$("$WP_CLI" post create --post_type=podcast_episode --post_status=trash --post_title='Disposable trash probe' --porcelain | awk '!found && /^[0-9]+$/ {print; found=1}')" || exit 1
draft="$("$WP_CLI" post create --post_type=podcast_episode --post_status=auto-draft --post_title='Disposable auto-draft probe' --porcelain | awk '!found && /^[0-9]+$/ {print; found=1}')" || exit 1
if [[ ! "$trash" =~ ^[0-9]+$ || ! "$draft" =~ ^[0-9]+$ ]]; then
 echo 'FAIL: WP-CLI did not return valid probe IDs.' >&2
 exit 1
fi
if env -u EPM_ALLOW_TEST_SEED "$WP_CLI" eval-file "$ROOT/tests/fixtures/seed.php" > "$WP_DIR/seed-refusal.log" 2>&1; then
 echo 'FAIL: seeding without the explicit runner flag was accepted.' >&2
 FAILED=1
fi
for id in "$trash" "$draft"; do
 if ! "$WP_CLI" post get "$id" --field=ID > /dev/null 2>&1; then
  echo "FAIL: the refused seed removed probe $id." >&2
  FAILED=1
 fi
done
EPM_ALLOW_TEST_SEED=1 "$WP_CLI" eval-file "$ROOT/tests/fixtures/seed.php" > /dev/null || exit 1
for id in "$trash" "$draft"; do
 if "$WP_CLI" post get "$id" --field=ID > /dev/null 2>&1; then
  echo "FAIL: authorized reset left probe $id behind." >&2
  FAILED=1
 fi
done
[ "$FAILED" = 0 ] && echo 'Fixture safety: unauthorized reset refused; trash and auto-drafts removed by authorized reset.'
exit "$FAILED"
