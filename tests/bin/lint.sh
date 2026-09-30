#!/usr/bin/env bash
# Static checks: PHP syntax (every file) and JavaScript syntax.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.."

status=0
while IFS= read -r -d '' file; do
	if ! out=$(php -l "$file" 2>&1); then
		echo "$out"
		status=1
	fi
done < <(find . -name '*.php' -not -path './tests/e2e/node_modules/*' -not -path './.git/*' -print0)

for file in assets/js/*.js admin/js/*.js; do
	node --check "$file" || status=1
done

[ "$status" -eq 0 ] && echo "Lint passed."
exit "$status"
