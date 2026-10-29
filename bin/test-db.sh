#!/usr/bin/env bash
# Runs the integration test suite against a throwaway Docker database.
# Usage: [LWM_DB_IMAGE=mariadb:10.11] [LWM_DB_PORT=33306] bin/test-db.sh [phpunit args]
# Image defaults to mysql:8.0. For backward compatibility a first argument that
# does not start with '-' and contains ':' is treated as the image.
set -euo pipefail

IMAGE="${LWM_DB_IMAGE:-mysql:8.0}"
if [ $# -gt 0 ] && [[ "$1" != -* && "$1" == *:* ]]; then
	IMAGE="$1"
	shift
fi
NAME="lwm-test-db"
PORT="${LWM_DB_PORT:-33306}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"

current="$(docker inspect --format '{{.Config.Image}}' "$NAME" 2>/dev/null || true)"
published="$(docker port "$NAME" 3306/tcp 2>/dev/null | head -n1 || true)"
if [ "$current" != "$IMAGE" ] || [ "$published" != "127.0.0.1:${PORT}" ]; then
	docker rm -f "$NAME" >/dev/null 2>&1 || true
	docker run -d --rm --name "$NAME" -p "127.0.0.1:${PORT}:3306" \
		-e MYSQL_ROOT_PASSWORD=lwm -e MARIADB_ROOT_PASSWORD=lwm \
		-e MYSQL_DATABASE=lwm_test -e MARIADB_DATABASE=lwm_test \
		"$IMAGE" >/dev/null
fi

export LWM_DB_HOST=127.0.0.1 LWM_DB_PORT="$PORT" LWM_DB_USER=root LWM_DB_PASS=lwm LWM_DB_NAME=lwm_test

php -r '
mysqli_report( MYSQLI_REPORT_OFF );
for ( $i = 0; $i < 120; $i++ ) {
	$l = @mysqli_connect( getenv( "LWM_DB_HOST" ), "root", "lwm", "lwm_test", (int) getenv( "LWM_DB_PORT" ) );
	if ( $l ) { exit( 0 ); }
	usleep( 500000 );
}
fwrite( STDERR, "Database did not become ready.\n" );
exit( 1 );
'

cd "$ROOT"
# Brain Monkey (group "monkey") loads Patchwork, whose file stream wrapper stays active for
# the rest of the process: those tests get their own PHPUnit process. Both runs must pass.
php -d auto_prepend_file=tests/prepend.php vendor/phpunit/phpunit/phpunit --testsuite integration --exclude-group monkey "$@"
php -d auto_prepend_file=tests/prepend.php vendor/phpunit/phpunit/phpunit --testsuite integration --group monkey "$@"
