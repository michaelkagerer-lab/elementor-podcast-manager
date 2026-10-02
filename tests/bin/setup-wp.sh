#!/usr/bin/env bash
#
# Provision a disposable WordPress + Elementor site for the test suites.
#
# Uses SQLite (no database server) by default, or MySQL/MariaDB with
# WP_DB=mysql, and PHP's built-in web server with a router that emulates
# pretty permalinks and HTTP Range requests (needed for audio seeking).
# The plugin under test is symlinked, so edits apply immediately.
#
# Usage: tests/bin/setup-wp.sh
# Env:   WP_DIR   (default: /tmp/epm-wp)      where to install
#        WP_PORT  (default: 8889)             local port
#        WP_VERSION (default: pinned in tests/versions.json)         WordPress version
#        ELEMENTOR_VERSION (default: pinned in tests/versions.json)
#        WP_DB    (default: sqlite)           sqlite, or mysql for MySQL/MariaDB:
#          DB_NAME (required), DB_USER (default: root), DB_PASSWORD (default: ''),
#          DB_HOST (default: localhost). The database is created when missing;
#          use a database that holds nothing else (the suites delete episodes).
#
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WP_DIR="${WP_DIR:-/tmp/epm-wp}"
WP_PORT="${WP_PORT:-8889}"
EPM_TEST_PROFILE="${EPM_TEST_PROFILE:-current}"
export EPM_TEST_PROFILE
DEPS="$PLUGIN_DIR/tests/bin/dependencies.py"
pinned() { python3 "$DEPS" value "$EPM_TEST_PROFILE" "$1"; }
WP_VERSION="${WP_VERSION:-$(pinned WP_VERSION)}"
ELEMENTOR_VERSION="${ELEMENTOR_VERSION:-$(pinned ELEMENTOR_VERSION)}"
SQLITE_VERSION="$(pinned SQLITE_VERSION)"
WP_CLI_VERSION="$(pinned WP_CLI_VERSION)"
BLOCK_THEME="$(pinned BLOCK_THEME)"
BLOCK_THEME_VERSION="$(pinned BLOCK_THEME_VERSION)"
HELLO_VERSION="$(pinned HELLO_VERSION)"
WP_THEME="${WP_THEME:-$(pinned WP_THEME)}"
WP_DB="${WP_DB:-sqlite}"
PHP_BIN="${PHP_BIN:-php}"
SITE="$WP_DIR/site"
URL="http://localhost:$WP_PORT"

case "$WP_DB" in
	sqlite) ;;
	mysql)
		DB_NAME="${DB_NAME:?WP_DB=mysql needs DB_NAME}"
		DB_USER="${DB_USER:-root}"
		DB_PASSWORD="${DB_PASSWORD:-}"
		DB_HOST="${DB_HOST:-localhost}"
		;;
	*)
		echo "WP_DB must be sqlite or mysql, not '$WP_DB'." >&2
		exit 1
		;;
esac

mkdir -p "$WP_DIR"
cd "$WP_DIR"

# Refuse to mark an existing WordPress installation as disposable just
# because the test setup script was pointed at it.
if [ -f "$SITE/wp-config.php" ] && [ ! -f "$WP_DIR/.epm-test-site" ]; then
	echo "Refusing to provision unmarked WordPress site at $SITE. Use a fresh WP_DIR or mark a disposable test directory explicitly." >&2
	exit 2
fi
touch "$WP_DIR/.epm-test-site"

# Downloads are retried: large transfers occasionally get cut off.
retry() {
	local attempt
	for attempt in 1 2 3 4; do
		"$@" && return 0
		echo "Retrying ($attempt): $*" >&2
		sleep $((attempt * 2))
	done
	return 1
}

python3 "$DEPS" download wp-cli "$WP_CLI_VERSION" "$WP_DIR/wp-cli.phar"

cat > wp <<EOF
#!/usr/bin/env bash
exec "$PHP_BIN" -d memory_limit=512M "$WP_DIR/wp-cli.phar" --path="$SITE" --allow-root "\$@"
EOF
chmod +x wp
WP="$WP_DIR/wp"

if [ ! -f "$SITE/wp-load.php" ]; then
	python3 "$DEPS" download wordpress "$WP_VERSION" "$WP_DIR/wordpress.zip"
	EPM_CORE_TMP="$(mktemp -d "$WP_DIR/core-XXXXXX")"
	unzip -q "$WP_DIR/wordpress.zip" -d "$EPM_CORE_TMP"
	mkdir -p "$SITE"
	cp -a "$EPM_CORE_TMP/wordpress/." "$SITE/"
	python3 -c 'import shutil,sys; shutil.rmtree(sys.argv[1])' "$EPM_CORE_TMP"
	mkdir -p "$SITE/wp-content/plugins" "$SITE/wp-content/themes" "$SITE/wp-content/uploads"
fi

if [ "$("$WP" core version)" != "$WP_VERSION" ]; then
	echo "This marked site has another WordPress version. Use a fresh WP_DIR for the selected pinned profile." >&2
	exit 2
fi

# A site keeps the database it was installed with.
if [ -f "$SITE/wp-config.php" ]; then
	if [ "$WP_DB" = mysql ] && [ -f "$SITE/wp-content/db.php" ]; then
		echo "$WP_DIR is a SQLite site; use another WP_DIR for WP_DB=mysql." >&2
		exit 1
	fi
	if [ "$WP_DB" = sqlite ] && [ ! -f "$SITE/wp-content/db.php" ]; then
		echo "$WP_DIR is a MySQL site; set WP_DB=mysql (and DB_NAME …) or use another WP_DIR." >&2
		exit 1
	fi
fi

# MySQL/MariaDB: create the database when it is missing.
if [ "$WP_DB" = mysql ]; then
	DB_NAME="$DB_NAME" DB_USER="$DB_USER" DB_PASSWORD="$DB_PASSWORD" DB_HOST="$DB_HOST" "$PHP_BIN" -r '
		$host = getenv( "DB_HOST" );
		$port = null;
		$socket = null;
		if ( preg_match( "/^(.*):(\d+)$/", $host, $m ) ) {
			$host = $m[1];
			$port = (int) $m[2];
		} elseif ( preg_match( "/^(.*):(\/.*)$/", $host, $m ) ) {
			$host = $m[1];
			$socket = $m[2];
		}
		mysqli_report( MYSQLI_REPORT_OFF );
		$db = @new mysqli( $host, getenv( "DB_USER" ), getenv( "DB_PASSWORD" ), "", $port ?? 3306, $socket );
		if ( $db->connect_errno ) {
			fwrite( STDERR, "Cannot connect to MySQL: " . $db->connect_error . "\n" );
			exit( 1 );
		}
		$name = str_replace( "`", "``", getenv( "DB_NAME" ) );
		if ( ! $db->query( "CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" ) ) {
			fwrite( STDERR, "Cannot create the database: " . $db->error . "\n" );
			exit( 1 );
		}
	'
fi

# SQLite database drop-in.
if [ "$WP_DB" = sqlite ] && [ ! -f "$SITE/wp-content/db.php" ]; then
	python3 "$DEPS" download sqlite "$SQLITE_VERSION" "$WP_DIR/sqlite.zip"
	unzip -q -o sqlite.zip -d "$SITE/wp-content/plugins/"
	sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$SITE/wp-content/plugins/sqlite-database-integration#" \
		-e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" \
		"$SITE/wp-content/plugins/sqlite-database-integration/db.copy" > "$SITE/wp-content/db.php"
fi

if [ "$WP_DB" = mysql ]; then
	DB_ARGS=(--dbname="$DB_NAME" --dbuser="$DB_USER" --dbpass="$DB_PASSWORD" --dbhost="$DB_HOST")
else
	DB_ARGS=(--dbname=wp --dbuser=wp --dbpass=wp)
fi
if [ ! -f "$SITE/wp-config.php" ]; then
	"$WP" config create "${DB_ARGS[@]}" --skip-check --quiet --extra-php <<'PHP'
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'SCRIPT_DEBUG', true );
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'AUTOMATIC_UPDATER_DISABLED', true );
PHP
fi

if ! "$WP" core is-installed 2>/dev/null; then
	"$WP" core install --url="$URL" --title="EPM Test" --admin_user=admin --admin_password=admin \
		--admin_email=admin@example.com --skip-email --quiet
fi
"$WP" option update home "$URL" --quiet
"$WP" option update siteurl "$URL" --quiet
"$WP" option update blog_public 0 --quiet

# Install exact verified theme/plugin archives, replacing old test copies.
python3 "$DEPS" download "$BLOCK_THEME" "$BLOCK_THEME_VERSION" "$WP_DIR/block-theme.zip"
python3 "$DEPS" download hello-elementor "$HELLO_VERSION" "$WP_DIR/hello-elementor.zip"
"$WP" theme install "$WP_DIR/block-theme.zip" --force --quiet
"$WP" theme install "$WP_DIR/hello-elementor.zip" --force --quiet
"$WP" theme activate "$WP_THEME" --quiet

if [ "${EPM_ELEMENTOR_OFF:-0}" = 1 ]; then
	"$WP" plugin deactivate elementor --quiet 2>/dev/null || true
else
	python3 "$DEPS" download elementor "$ELEMENTOR_VERSION" "$WP_DIR/elementor.zip"
	"$WP" plugin install "$WP_DIR/elementor.zip" --force --quiet
	"$WP" plugin activate elementor --quiet
fi

ln -sfn "$PLUGIN_DIR" "$SITE/wp-content/plugins/elementor-podcast-manager"
"$WP" plugin activate elementor-podcast-manager --quiet

# Test-only HTTP fixtures: requests to https://feeds.example.test/… (and
# Apple's podcast lookup) are answered from tests/fixtures/feeds/, so feed
# imports and syncs run offline. Installed on this disposable site only.
mkdir -p "$SITE/wp-content/mu-plugins"
ln -sfn "$PLUGIN_DIR/tests/fixtures/mu-plugins/epm-test-http.php" "$SITE/wp-content/mu-plugins/epm-test-http.php"
# Test-only: lets the media suite (tests/media/) reach its local media host,
# one loopback port named in EPM_TEST_MEDIA_ORIGIN; inactive without it.
ln -sfn "$PLUGIN_DIR/tests/fixtures/mu-plugins/epm-test-loopback.php" "$SITE/wp-content/mu-plugins/epm-test-loopback.php"

"$WP" rewrite structure '/%postname%/' --hard --quiet
"$WP" rewrite flush --hard --quiet

# Router: pretty permalinks + HTTP Range for media (php -S has neither).
cat > "$WP_DIR/router.php" <<'PHP'
<?php
$root = getenv( 'EPM_WP_SITE' );
$path = urldecode( (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) );
// No realpath(): the plugin under test is symlinked into wp-content.
$file = $root . $path;

if ( '/' !== $path && false === strpos( $path, '..' ) && file_exists( $file ) ) {
	if ( is_file( $file ) && preg_match( '/\.(mp3|m4a|wav|mp4)$/i', $file ) ) {
		$size  = filesize( $file );
		$types = [ 'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'wav' => 'audio/wav', 'mp4' => 'video/mp4' ];
		header( 'Content-Type: ' . $types[ strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ) ] );
		header( 'Accept-Ranges: bytes' );
		$start = 0;
		$end   = $size - 1;
		if ( isset( $_SERVER['HTTP_RANGE'] ) && preg_match( '/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m ) ) {
			if ( '' !== $m[1] ) {
				$start = (int) $m[1];
				if ( '' !== $m[2] ) {
					$end = min( (int) $m[2], $size - 1 );
				}
			} else {
				$start = max( 0, $size - (int) $m[2] );
			}
			http_response_code( 206 );
			header( "Content-Range: bytes $start-$end/$size" );
		}
		header( 'Content-Length: ' . ( $end - $start + 1 ) );
		if ( 'HEAD' !== $_SERVER['REQUEST_METHOD'] ) {
			$fh = fopen( $file, 'rb' );
			fseek( $fh, $start );
			echo fread( $fh, $end - $start + 1 );
			fclose( $fh );
		}
		return true;
	}
	if ( is_file( $file ) ) {
		return false;
	}
	if ( is_dir( $file ) ) {
		if ( '/' !== substr( $path, -1 ) ) {
			header( 'Location: ' . $path . '/', true, 301 );
			return true;
		}
		if ( is_file( $file . '/index.php' ) ) {
			return false;
		}
	}
}

$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['PHP_SELF']        = '/index.php';
chdir( $root );
require $root . '/index.php';
return true;
PHP

if ! curl -fs -o /dev/null "$URL/wp-login.php"; then
	# Browser fixtures use localhost for pages and 127.0.0.1 as a second
	# audio origin. An explicit IPv4 listener serves both consistently;
	# binding localhost may select IPv6 only on some runners.
	EPM_WP_SITE="$SITE" PHP_CLI_SERVER_WORKERS=4 nohup "$PHP_BIN" -d memory_limit=512M -d upload_max_filesize=64M -d post_max_size=64M \
		-S "127.0.0.1:$WP_PORT" -t "$SITE" "$WP_DIR/router.php" > "$WP_DIR/server.log" 2>&1 &
	echo $! > "$WP_DIR/server.pid"
	for _ in $(seq 1 30); do
		curl -fs -o /dev/null "$URL/wp-login.php" && break
		sleep 1
	done
fi

python3 "$DEPS" inventory "$WP" "$WP_DIR/versions-actual.json"
echo "WordPress ready at $URL (admin/admin). wp-cli: $WP"
echo "Pinned dependency versions and checksums: $WP_DIR/versions-actual.json"
