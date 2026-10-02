#!/usr/bin/env bash
#
# Two-process race tests for the import/sync lock, the import job, the
# upgrade after a plugin update and new episode GUIDs.
#
# Each scenario in race.php runs its roles as separate `wp eval-file`
# processes at the same time; they meet at barrier files. See race.php for
# the scenarios and lib.php for the barriers.
#
# Usage: tests/concurrency/run.sh [scenario …]
# Env:   WP_CLI   (default: $WP_DIR/wp, WP_DIR default /tmp/epm-wp)
#        STRESS=1 also run the barrier-free stress scenario (STRESS_ITEMS, default 200)
#        RACE_KEEP=1 keep the barrier directories (events.log, role output)
#
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WP_DIR="${WP_DIR:-/tmp/epm-wp}"
WP_CLI="${WP_CLI:-$WP_DIR/wp}"
RACE="$HERE/race.php"

# WP-CLI output without the deprecation notices some Elementor versions
# print at shutdown.
wpq() {
	"$WP_CLI" "$@" 2>&1 | awk '
		/^PHP: .*Elementor/ { skip = 1; next }
		skip && /^\)\]$/ { skip = 0; next }
		skip { next }
		/^Deprecated: .*[Ee]lementor/ { next }
		{ print }'
	return "${PIPESTATUS[0]}"
}

mapfile -t LIST < <(wpq eval-file "$RACE" list)
FAILED=()
RAN=0

for line in "${LIST[@]}"; do
	read -r name roles flag <<< "$line"
	[ -n "$name" ] || continue
	if [ "$#" -gt 0 ]; then
		wanted=""
		for arg in "$@"; do [ "$arg" = "$name" ] && wanted=1; done
		[ -n "$wanted" ] || continue
	elif [ "${flag:-}" = stress ] && [ -z "${STRESS:-}" ]; then
		continue
	fi

	dir="$(mktemp -d "${TMPDIR:-/tmp}/epm-race-$name-XXXXXX")"
	echo "-- $name"
	if ! EPM_RACE_DIR="$dir" wpq --require="$HERE/early.php" eval-file "$RACE" "$name" setup > "$dir/setup.out"; then
		cat "$dir/setup.out"
		FAILED+=("$name (setup)")
		continue
	fi

	pids=()
	IFS=',' read -r -a role_list <<< "$roles"
	for role in "${role_list[@]}"; do
		EPM_RACE_DIR="$dir" EPM_RACE_ROLE="$role" EPM_RACE_ROLES="$roles" \
			wpq --require="$HERE/early.php" eval-file "$RACE" "$name" "$role" > "$dir/$role.out" &
		pids+=($!)
	done
	for pid in "${pids[@]}"; do
		wait "$pid"
	done

	RAN=$((RAN + 1))
	if EPM_RACE_DIR="$dir" EPM_RACE_ROLE=check EPM_RACE_ROLES="$roles" wpq --require="$HERE/early.php" eval-file "$RACE" "$name" check > "$dir/check.out"; then
		grep -E '✓|✗' "$dir/check.out"
		[ -n "${RACE_KEEP:-}" ] || rm -rf "$dir"
	else
		cat "$dir/check.out"
		for role in "${role_list[@]}"; do
			if [ -s "$dir/$role.out" ]; then
				echo "   [$role output]"
				sed 's/^/   /' "$dir/$role.out" | head -20
			fi
		done
		echo "   [events: $dir/events.log]"
		[ -f "$dir/events.log" ] && sed 's/^/   /' "$dir/events.log" | tail -40
		FAILED+=("$name")
	fi
done

echo
if [ "${#FAILED[@]}" -gt 0 ]; then
	echo "Race scenarios failed: ${FAILED[*]}"
	exit 1
fi
echo "All $RAN race scenarios passed."
