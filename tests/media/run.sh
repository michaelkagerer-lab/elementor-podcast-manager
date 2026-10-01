#!/usr/bin/env bash
#
# Media downloads over real sockets (IMP-04, IMPB-N1, IMPB-N2): starts the
# local media host (tests/fixtures/mediaserver.py) on a free port, lets the
# test site reach exactly that host (EPM_TEST_MEDIA_ORIGIN, read by the
# test-only mu-plugin epm-test-loopback.php) and runs:
#
# - tests/media/downloads.php: size limit while streaming (with and without
#   Content-Length), a stalled host (low-speed limit), a slow file continued
#   with Range requests over several steps (byte-identical), a host without
#   Range support, wrong content, HTTP errors, a move from a slow host;
# - a 60 MB MP3 (an ID3 tag and silent frames without a newline byte, which
#   getimagesize() read into memory whole) copied under a 128M memory limit
#   (PERF: peak memory and time); MEDIA_HEAVY=1 adds a 300 MB file;
# - when run as root with tmpfs: a temp folder and an uploads folder that
#   are too small (otherwise skipped, and said so).
#
# Usage: WP_DIR=/tmp/epm-wp tests/media/run.sh
# Env:   MEDIA_HEAVY=1  also the 300 MB file
#        MEDIA_MEMORY_BUDGET_MB (default 32): peak above the booted site
#
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
WP_DIR="${WP_DIR:-/tmp/epm-wp}"
WP_CLI="${WP_CLI:-$WP_DIR/wp}"
BUDGET="${MEDIA_MEMORY_BUDGET_MB:-32}"
FAILED=()

# WP-CLI output without the deprecation notices some Elementor versions
# print at shutdown.
wpq() {
	"$@" 2>&1 | awk '
		/^PHP: .*Elementor/ { skip = 1; next }
		skip && /^\)\]$/ { skip = 0; next }
		skip { next }
		/^Deprecated: .*[Ee]lementor/ { next }
		{ print }'
	return "${PIPESTATUS[0]}"
}

LOG="$(mktemp "${TMPDIR:-/tmp}/epm-mediahost-XXXXXX")"
python3 -u "$ROOT/tests/fixtures/mediaserver.py" 0 "$LOG" > "$LOG.out" 2>&1 &
SERVER=$!
cleanup() {
	kill "$SERVER" 2> /dev/null
	wait "$SERVER" 2> /dev/null
	rm -f "$LOG" "$LOG.out"
}
trap cleanup EXIT
PORT=""
for _ in $(seq 1 50); do
	PORT="$(sed -n 's/^listening on 127\.0\.0\.1:\([0-9]*\)$/\1/p' "$LOG.out")"
	[ -n "$PORT" ] && break
	sleep 0.1
done
if [ -z "$PORT" ]; then
	echo "The media host did not start:"; cat "$LOG.out"
	exit 1
fi
export EPM_TEST_MEDIA_ORIGIN="127.0.0.1:$PORT" EPM_TEST_MEDIA_LOG="$LOG"
echo "Media host on $EPM_TEST_MEDIA_ORIGIN"

echo "-- downloads"
wpq "$WP_CLI" eval-file "$HERE/downloads.php" || FAILED+=("downloads")

# One request copies a large file under a stock memory limit.
memory() {
	local bytes="$1" out json
	out="$(EPM_MEDIA_MEMORY_LIMIT=128M wpq php "$WP_DIR/wp-cli.phar" --path="$WP_DIR/site" --allow-root eval-file "$HERE/downloads.php" memory "$bytes")"
	json="$(grep '^EPM_MEDIA ' <<< "$out" | head -1 | cut -c11-)"
	if [ -z "$json" ]; then
		echo "  ✗ $((bytes / 1048576)) MB: the request failed: $(grep -E 'Fatal|Error|error' <<< "$out" | head -3)"
		FAILED+=("memory $bytes")
		return
	fi
	php -r '
		$d = json_decode( $argv[1], true );
		$mb = (int) $argv[2] / 1048576;
		$ok = $d["copied"] && "done" === $d["status"] && 0 === strpos( $d["mime"], "audio/" ) && ( ! $d["exact"] || $d["peak_mb"] <= (float) $argv[3] );
		printf( "  %s %d MB audio copied under memory_limit %s: %s, peak %s MB above the booted site%s, %s s, length %s\n", $ok ? "✓" : "✗", $mb, $d["limit"], $d["copied"] ? "stored" : "NOT stored (" . $d["reason"] . ")", $d["peak_mb"], $d["exact"] ? " (budget " . $argv[3] . " MB)" : " (PHP < 8.2: not exact)", $d["seconds"], $d["length"] );
		exit( $ok ? 0 : 1 );
	' "$json" "$bytes" "$BUDGET" || FAILED+=("memory $bytes")
}
echo "-- memory"
memory 62914560
[ -n "${MEDIA_HEAVY:-}" ] && memory 314572800

# Disks that are too small: tmpfs mounts (root only).
echo "-- full disks"
if [ "$(id -u)" = 0 ] && SMALL="$(mktemp -d "${TMPDIR:-/tmp}/epm-small-XXXXXX")" && mount -t tmpfs -o size=20m tmpfs "$SMALL" 2> /dev/null; then
	TMPDIR="$SMALL" wpq "$WP_CLI" eval-file "$HERE/downloads.php" disk-full || FAILED+=("disk-full")
	umount "$SMALL"
	rmdir "$SMALL"

	UPLOADS="$WP_DIR/site/wp-content/uploads/2026/04"
	mkdir -p "$UPLOADS"
	if [ -z "$(ls -A "$UPLOADS")" ] && mount -t tmpfs -o size=1m tmpfs "$UPLOADS"; then
		wpq "$WP_CLI" eval-file "$HERE/downloads.php" uploads-full "$UPLOADS" || FAILED+=("uploads-full")
		umount "$UPLOADS"
	else
		echo "  (skipped: $UPLOADS is not empty or cannot be mounted)"
	fi
else
	[ -n "${SMALL:-}" ] && rmdir "$SMALL" 2> /dev/null
	echo "  (skipped: needs root and tmpfs)"
fi

echo
if [ "${#FAILED[@]}" -gt 0 ]; then
	echo "Media downloads failed: ${FAILED[*]}"
	exit 1
fi
echo "Media downloads passed."
