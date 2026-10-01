#!/usr/bin/env bash
#
# Serve a test site through nginx + php-fpm with the distribution's php.ini
# (memory_limit 128M, max_execution_time 30, opcache on): what a stock
# shared or managed host runs, unlike `php -S` (which setup-wp.sh starts
# with 512M). The site's own `php -S` server on the same port is stopped.
#
# Usage: WP_DIR=/tmp/epm-wp-fpm WP_PORT=8963 FPM_PORT=8964 tests/perf/fpm.sh start
#        WP_DIR=/tmp/epm-wp-fpm tests/perf/fpm.sh stop
#
# Needs nginx and php-fpm (PHP 8.1+; PHP_FPM names the binary, default the
# newest php-fpm* found). Configuration, logs and pids live in $WP_DIR/fpm/.
# Requests that carry the header "X-EPM-Perf: <label>" append their wall
# time and peak memory to $WP_DIR/fpm/requests.jsonl (tests/perf/probe.php,
# linked as a must-use plugin while the server runs).
#
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WP_DIR="${WP_DIR:?WP_DIR is required}"
WP_PORT="${WP_PORT:-8963}"
FPM_PORT="${FPM_PORT:-$((WP_PORT + 1))}"
SITE="$WP_DIR/site"
RUN="$WP_DIR/fpm"
PHP_FPM="${PHP_FPM:-$(ls /usr/sbin/php-fpm* 2>/dev/null | sort -V | tail -1)}"
PHP_INI="${PHP_INI:-}"
if [ -z "$PHP_INI" ]; then
	version="$("$PHP_FPM" -v | head -1 | sed -E 's/^PHP ([0-9]+\.[0-9]+).*/\1/')"
	PHP_INI="/etc/php/$version/fpm/php.ini"
fi

stop() {
	for pid in "$RUN/nginx.pid" "$RUN/php-fpm.pid"; do
		[ -f "$pid" ] && kill "$(cat "$pid")" 2> /dev/null || true
		rm -f "$pid"
	done
	rm -f "$SITE/wp-content/mu-plugins/epm-perf-probe.php"
}

case "${1:-}" in
	stop)
		stop
		echo "Stopped nginx and php-fpm for $WP_DIR."
		exit 0
		;;
	start) ;;
	*)
		echo "Usage: $0 start|stop" >&2
		exit 1
		;;
esac

[ -f "$SITE/wp-load.php" ] || { echo "No site in $WP_DIR (run tests/bin/setup-wp.sh first)." >&2; exit 1; }
stop
mkdir -p "$RUN"/logs "$RUN"/tmp
# The php -S server setup-wp.sh started on this port.
if [ -f "$WP_DIR/server.pid" ]; then
	kill "$(cat "$WP_DIR/server.pid")" 2> /dev/null || true
	rm -f "$WP_DIR/server.pid"
	sleep 1
fi

cat > "$RUN/php-fpm.conf" <<EOF
[global]
pid = $RUN/php-fpm.pid
error_log = $RUN/logs/php-fpm.log
daemonize = yes
[epm]
listen = 127.0.0.1:$FPM_PORT
user = $(id -un)
group = $(id -gn)
pm = static
pm.max_children = 4
catch_workers_output = yes
php_admin_value[error_log] = $RUN/logs/php-error.log
php_admin_value[opcache.enable] = 1
clear_env = no
env[EPM_PERF_LOG] = $RUN/requests.jsonl
EOF

cat > "$RUN/nginx.conf" <<EOF
worker_processes 2;
pid $RUN/nginx.pid;
error_log $RUN/logs/nginx-error.log;
events { worker_connections 256; }
http {
	include /etc/nginx/mime.types;
	access_log $RUN/logs/access.log;
	client_body_temp_path $RUN/tmp/body;
	proxy_temp_path $RUN/tmp/proxy;
	fastcgi_temp_path $RUN/tmp/fcgi;
	uwsgi_temp_path $RUN/tmp/uwsgi;
	scgi_temp_path $RUN/tmp/scgi;
	client_max_body_size 64m;
	server {
		listen 127.0.0.1:$WP_PORT;
		server_name localhost;
		root $SITE;
		index index.php;
		location / { try_files \$uri \$uri/ /index.php?\$args; }
		location ~ \.php\$ {
			try_files \$uri =404;
			include /etc/nginx/fastcgi_params;
			fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
			fastcgi_pass 127.0.0.1:$FPM_PORT;
		}
	}
}
EOF

mkdir -p "$SITE/wp-content/mu-plugins"
ln -sfn "$HERE/probe.php" "$SITE/wp-content/mu-plugins/epm-perf-probe.php"
: > "$RUN/requests.jsonl"
"$PHP_FPM" -R -y "$RUN/php-fpm.conf" -c "$PHP_INI"
nginx -c "$RUN/nginx.conf" -p "$RUN/" 2> /dev/null
for _ in $(seq 1 20); do
	curl -fs -o /dev/null "http://localhost:$WP_PORT/wp-login.php" && break
	sleep 0.5
done
echo "nginx + php-fpm ($(grep -E '^memory_limit' "$PHP_INI" | tr -d ' ')) serve $SITE at http://localhost:$WP_PORT"
