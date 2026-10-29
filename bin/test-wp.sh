#!/usr/bin/env bash
# End-to-end tests: two throwaway WordPress sites on a Docker database (dev only).
# Usage: [LWM_DB_IMAGE=mysql:8.0] [LWM_E2E_DB_PORT=33307] [LWM_PHP=<php 8.x>] [LWM_WP_CLI=<wp-cli phar>]
#        [LWM_WP_VERSION=7.1.2] [LWM_E2E_DIR=<work dir>] [LWM_E2E_SETUP_ONLY=1] [LWM_E2E_KEEP=1]
#        [LWM_E2E_NETWORK=1] bin/test-wp.sh [phpunit args]
# Site r (http://site-r.test) is the throwaway site the reset tests empty.
# LWM_E2E_NETWORK=1 also builds three networks: n (subdirectory, http://net-n.test), s (subdomain,
# http://net-s.test) and m (subdirectory, http://net-m.test, the one the reset tests empty), each with
# the subsites b2 (id 2) and b3 (id 3); without it the network tests skip.
# A test run removes the sites, their databases and the database container when it ends
# (LWM_E2E_KEEP=1 keeps them for debugging). LWM_E2E_SETUP_ONLY=1 builds the sites, prints
# `export …` lines for the environment and leaves everything running.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
IMAGE="${LWM_DB_IMAGE:-mysql:8.0}"
NAME="lwm-e2e-db"
PORT="${LWM_E2E_DB_PORT:-33307}"
WORK="${LWM_E2E_DIR:-${TMPDIR:-/tmp}/lwm-e2e}"
WP_VERSION="${LWM_WP_VERSION:-7.1.2}"
WORK="${WORK%/}"
if [ -z "$WORK" ] || [ "$WORK" = "/" ] || [ "$WORK" = "$HOME" ] || [ "$WORK" = "$ROOT" ]; then
	echo "Refusing to use '$WORK' as the E2E work folder." >&2
	exit 1
fi
# The work folder is wiped in parts: only a new or empty folder, or one this script marked.
MARKER="$WORK/.lwm-e2e"
if [ -e "$WORK" ] && { [ ! -d "$WORK" ] || { [ ! -f "$MARKER" ] && [ -n "$(ls -A "$WORK")" ]; }; }; then
	echo "Refusing to use '$WORK' as the E2E work folder: it is not an empty folder and has no $(basename "$MARKER") marker." >&2
	exit 1
fi

for tool in php docker rsync; do
	if ! command -v "$tool" >/dev/null 2>&1; then
		echo "$tool is required." >&2
		exit 1
	fi
done
if [ -z "${LWM_WP_CLI:-}" ] && ! command -v wp >/dev/null 2>&1; then
	echo "wp is required (or set LWM_WP_CLI to a WP-CLI phar)." >&2
	exit 1
fi

if [ -z "${LWM_PHP:-}" ]; then
	if [ -x /opt/homebrew/opt/php@8.3/bin/php ]; then
		LWM_PHP=/opt/homebrew/opt/php@8.3/bin/php
	else
		LWM_PHP="$(command -v php)"
	fi
fi
if [ -z "${LWM_WP_CLI:-}" ]; then
	LWM_WP_CLI="$("$LWM_PHP" -r 'echo realpath($argv[1]);' "$(command -v wp)")"
fi

# Docker calls can hang (the local daemon once did): never wait on one for ever.
docker_t() {
	if command -v timeout >/dev/null 2>&1; then
		timeout "${LWM_DOCKER_TIMEOUT:-120}" docker "$@"
	else
		docker "$@"
	fi
}

cleanup() {
	local code=$?
	if [ "${LWM_E2E_KEEP:-}" != "1" ]; then
		rm -rf "$WORK/a" "$WORK/b" "$WORK/r" "$WORK/n" "$WORK/s" "$WORK/m" "$WORK/r-shared" "$WORK/r-themes-aside" "$WORK/core"
		rm -f "$MARKER"
		rmdir "$WORK" 2>/dev/null || true
		docker_t rm -f "$NAME" >/dev/null 2>&1 || true
	fi
	exit "$code"
}
if [ "${LWM_E2E_SETUP_ONLY:-}" != "1" ]; then
	trap cleanup EXIT
fi

current="$(docker_t inspect --format '{{.Config.Image}}' "$NAME" 2>/dev/null || true)"
published="$(docker_t port "$NAME" 3306/tcp 2>/dev/null | head -n1 || true)"
if [ "$current" != "$IMAGE" ] || [ "$published" != "127.0.0.1:${PORT}" ]; then
	docker_t rm -f "$NAME" >/dev/null 2>&1 || true
	# The first run may pull the image: a longer limit.
	LWM_DOCKER_TIMEOUT=600 docker_t run -d --rm --name "$NAME" -p "127.0.0.1:${PORT}:3306" \
		-e MYSQL_ROOT_PASSWORD=lwm -e MARIADB_ROOT_PASSWORD=lwm "$IMAGE" >/dev/null
fi

"$LWM_PHP" -r '
mysqli_report( MYSQLI_REPORT_OFF );
for ( $i = 0; $i < 120; $i++ ) {
	$l = @mysqli_connect( "127.0.0.1", "root", "lwm", "", (int) $argv[1] );
	if ( $l ) { exit( 0 ); }
	usleep( 500000 );
}
fwrite( STDERR, "Database did not become ready.\n" );
exit( 1 );
' "$PORT"

wp_cli() {
	local path="$1"
	shift
	"$LWM_PHP" -d memory_limit=512M "$LWM_WP_CLI" --path="$path" "$@"
}

mkdir -p "$WORK"
touch "$MARKER"
if [ ! -f "$WORK/core/wp-load.php" ]; then
	rm -rf "$WORK/core"
	"$LWM_PHP" "$LWM_WP_CLI" core download --path="$WORK/core" --version="$WP_VERSION" --locale=en_US --quiet
fi

make_site() {
	local name="$1" url="$2"
	local path="$WORK/$name" db="lwm_e2e_$1"
	rm -rf "$path"
	cp -R "$WORK/core" "$path"
	"$LWM_PHP" -r '
	$l = mysqli_connect( "127.0.0.1", "root", "lwm", "", (int) $argv[1] );
	mysqli_query( $l, "DROP DATABASE IF EXISTS `" . $argv[2] . "`" );
	mysqli_query( $l, "CREATE DATABASE `" . $argv[2] . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci" );
	' "$PORT" "$db"
	wp_cli "$path" config create --dbname="$db" --dbuser=root --dbpass=lwm --dbhost="127.0.0.1:$PORT" --skip-check --quiet
	wp_cli "$path" core install --url="$url" --title="LWM $name" --admin_user=admin --admin_password=admin-pass \
		--admin_email="admin@site-$name.test" --skip-email --quiet
	rsync -a --delete --exclude=.git --exclude=/tests --exclude=/docs --exclude=/.superpowers --exclude=/vendor \
		--exclude=/bin --exclude=/node_modules --exclude=/.phpunit.cache --exclude=/.playwright-results "$ROOT/" "$path/wp-content/plugins/lw-migration/"
	wp_cli "$path" plugin activate lw-migration --quiet
}

make_site a http://site-a.test
make_site b http://site-b.test
make_site r http://site-r.test

make_network() {
	local name="$1" url="$2" kind="$3"
	local path="$WORK/$name" db="lwm_e2e_$1" host="${2#http://}"
	rm -rf "$path"
	cp -R "$WORK/core" "$path"
	"$LWM_PHP" -r '
	$l = mysqli_connect( "127.0.0.1", "root", "lwm", "", (int) $argv[1] );
	mysqli_query( $l, "DROP DATABASE IF EXISTS `" . $argv[2] . "`" );
	mysqli_query( $l, "CREATE DATABASE `" . $argv[2] . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci" );
	' "$PORT" "$db"
	wp_cli "$path" config create --dbname="$db" --dbuser=root --dbpass=lwm --dbhost="127.0.0.1:$PORT" --skip-check --quiet
	local flags=()
	if [ "$kind" = "subdomain" ]; then flags+=(--subdomains); fi
	wp_cli "$path" core multisite-install --url="$url" --title="LWM $name" --admin_user=admin --admin_password=admin-pass \
		--admin_email="admin@$host" --skip-email --skip-config --quiet ${flags[@]+"${flags[@]}"}
	# --skip-config: the network constants are written here, so the file stays reproducible.
	wp_cli "$path" config set MULTISITE true --raw --quiet
	wp_cli "$path" config set SUBDOMAIN_INSTALL "$([ "$kind" = subdomain ] && echo true || echo false)" --raw --quiet
	wp_cli "$path" config set DOMAIN_CURRENT_SITE "$host" --quiet
	wp_cli "$path" config set PATH_CURRENT_SITE / --quiet
	wp_cli "$path" config set SITE_ID_CURRENT_SITE 1 --raw --quiet
	wp_cli "$path" config set BLOG_ID_CURRENT_SITE 1 --raw --quiet
	wp_cli "$path" site create --slug=b2 --title="LWM $name b2" --email="admin@$host" --quiet
	wp_cli "$path" site create --slug=b3 --title="LWM $name b3" --email="admin@$host" --quiet
	rsync -a --delete --exclude=.git --exclude=/tests --exclude=/docs --exclude=/.superpowers --exclude=/vendor \
		--exclude=/bin --exclude=/node_modules --exclude=/.phpunit.cache --exclude=/.playwright-results "$ROOT/" "$path/wp-content/plugins/lw-migration/"
	wp_cli "$path" plugin activate lw-migration --network --quiet
}

networks=""
if [ "${LWM_E2E_NETWORK:-}" = "1" ]; then
	make_network n http://net-n.test subdirectory
	make_network s http://net-s.test subdomain
	make_network m http://net-m.test subdirectory
	networks="$(printf ' LWM_E2E_N=%q LWM_E2E_S=%q LWM_E2E_M=%q' "$WORK/n" "$WORK/s" "$WORK/m")"
	export LWM_E2E_N="$WORK/n" LWM_E2E_S="$WORK/s" LWM_E2E_M="$WORK/m"
else
	# An unset variable makes the network tests skip themselves.
	unset LWM_E2E_N LWM_E2E_S LWM_E2E_M
fi

export LWM_E2E_PHP="$LWM_PHP" LWM_E2E_WP_CLI="$LWM_WP_CLI" LWM_E2E_A="$WORK/a" LWM_E2E_B="$WORK/b" LWM_E2E_R="$WORK/r" LWM_E2E_DB_PORT="$PORT"
if [ "${LWM_E2E_SETUP_ONLY:-}" = "1" ]; then
	printf 'export LWM_E2E_PHP=%q LWM_E2E_WP_CLI=%q LWM_E2E_A=%q LWM_E2E_B=%q LWM_E2E_R=%q LWM_E2E_DB_PORT=%q%s\n' \
		"$LWM_PHP" "$LWM_WP_CLI" "$WORK/a" "$WORK/b" "$WORK/r" "$PORT" "$networks"
	exit 0
fi

cd "$ROOT"
php -d auto_prepend_file=tests/prepend.php vendor/phpunit/phpunit/phpunit --testsuite e2e "$@"
