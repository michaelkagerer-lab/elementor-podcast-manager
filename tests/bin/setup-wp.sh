#!/usr/bin/env bash
#
# Provision a disposable WordPress + Elementor site for the test suites.
#
# Uses SQLite (no database server) and PHP's built-in web server with a
# router that emulates pretty permalinks and HTTP Range requests (needed
# for audio seeking). The plugin under test is symlinked, so edits apply
# immediately.
#
# Usage: tests/bin/setup-wp.sh
# Env:   WP_DIR   (default: /tmp/epm-wp)      where to install
#        WP_PORT  (default: 8889)             local port
#        WP_VERSION (default: latest)         WordPress version
#        ELEMENTOR_VERSION (default: latest-stable)
#
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WP_DIR="${WP_DIR:-/tmp/epm-wp}"
WP_PORT="${WP_PORT:-8889}"
WP_VERSION="${WP_VERSION:-latest}"
ELEMENTOR_VERSION="${ELEMENTOR_VERSION:-latest-stable}"
SITE="$WP_DIR/site"
URL="http://localhost:$WP_PORT"

mkdir -p "$WP_DIR"
cd "$WP_DIR"

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

if [ ! -f wp-cli.phar ]; then
	retry curl -fsSL -o wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
fi

cat > wp <<EOF
#!/usr/bin/env bash
exec php -d memory_limit=512M "$WP_DIR/wp-cli.phar" --path="$SITE" --allow-root "\$@"
EOF
chmod +x wp
WP="$WP_DIR/wp"

if [ ! -f "$SITE/wp-load.php" ]; then
	retry "$WP" core download --version="$WP_VERSION" --skip-content --force --quiet
	mkdir -p "$SITE/wp-content/plugins" "$SITE/wp-content/themes" "$SITE/wp-content/uploads"
fi

# SQLite database drop-in.
if [ ! -f "$SITE/wp-content/db.php" ]; then
	retry curl -fsSL -o sqlite.zip https://downloads.wordpress.org/plugin/sqlite-database-integration.latest-stable.zip
	unzip -q -o sqlite.zip -d "$SITE/wp-content/plugins/"
	sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$SITE/wp-content/plugins/sqlite-database-integration#" \
		-e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" \
		"$SITE/wp-content/plugins/sqlite-database-integration/db.copy" > "$SITE/wp-content/db.php"
fi

if [ ! -f "$SITE/wp-config.php" ]; then
	"$WP" config create --dbname=wp --dbuser=wp --dbpass=wp --skip-check --quiet --extra-php <<'PHP'
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

# Themes: a block theme (default) plus the theme most Elementor sites use.
"$WP" theme is-installed twentytwentyfive || retry "$WP" theme install twentytwentyfive --quiet
"$WP" theme is-installed hello-elementor || retry "$WP" theme install hello-elementor --quiet
"$WP" theme activate "${WP_THEME:-hello-elementor}" --quiet

if ! "$WP" plugin is-installed elementor; then
	retry curl -fsSL -o elementor.zip "https://downloads.wordpress.org/plugin/elementor.$ELEMENTOR_VERSION.zip"
	unzip -q -o elementor.zip -d "$SITE/wp-content/plugins/"
fi
"$WP" plugin activate elementor --quiet

ln -sfn "$PLUGIN_DIR" "$SITE/wp-content/plugins/elementor-podcast-manager"
"$WP" plugin activate elementor-podcast-manager --quiet

# Test-only HTTP fixtures: requests to https://feeds.example.test/… (and
# Apple's podcast lookup) are answered from tests/fixtures/feeds/, so feed
# imports and syncs run offline. Installed on this disposable site only.
mkdir -p "$SITE/wp-content/mu-plugins"
ln -sfn "$PLUGIN_DIR/tests/fixtures/mu-plugins/epm-test-http.php" "$SITE/wp-content/mu-plugins/epm-test-http.php"

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
	EPM_WP_SITE="$SITE" PHP_CLI_SERVER_WORKERS=4 nohup php -d memory_limit=512M -d upload_max_filesize=64M -d post_max_size=64M \
		-S "localhost:$WP_PORT" -t "$SITE" "$WP_DIR/router.php" > "$WP_DIR/server.log" 2>&1 &
	echo $! > "$WP_DIR/server.pid"
	for _ in $(seq 1 30); do
		curl -fs -o /dev/null "$URL/wp-login.php" && break
		sleep 1
	done
fi

echo "WordPress ready at $URL (admin/admin). wp-cli: $WP"
