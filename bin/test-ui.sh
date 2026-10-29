#!/usr/bin/env bash
# Browser tests of the admin screen (dev only, export-ignored): the two throwaway sites of
# bin/test-wp.sh, each served by PHP's built-in web server (several workers: the page, its
# scripts and the REST calls run at once), Playwright (Chromium) driving wp-admin.
# Site A at http://127.0.0.1:$LWM_UI_PORT (default 8894), site B one port higher.
# LWM_E2E_NETWORK=1 also serves the subdirectory network n two ports higher (moved to that
# address with search-replace + DOMAIN_CURRENT_SITE, the verified test bed); without it the
# network tests skip themselves. Site r (the Reset tests' throwaway site) is served three
# ports higher.
# LWM_UI_OUTSIDE: an empty folder beside the site roots for the backups folder tests.
# Usage: [LWM_UI_PORT=8894] [LWM_E2E_DIR=<work dir>] [LWM_E2E_KEEP=1] [LWM_E2E_NETWORK=1] bin/test-ui.sh [playwright args]
# The browser is the Chromium build of the pinned @playwright/test; when the run says it is
# missing: npx playwright install chromium
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PORT="${LWM_UI_PORT:-8894}"
PORT_B="$((PORT + 1))"
PORT_N="$((PORT + 2))"
PORT_R="$((PORT + 3))"
cd "$ROOT"

# Docker calls can hang (the local daemon once did): never wait on one for ever.
docker_t() {
	if command -v timeout >/dev/null 2>&1; then
		timeout "${LWM_DOCKER_TIMEOUT:-120}" docker "$@"
	else
		docker "$@"
	fi
}

for tool in nc docker rsync; do
	if ! command -v "$tool" >/dev/null 2>&1; then
		echo "$tool is required." >&2
		exit 1
	fi
done
if [ ! -f build/index.js ]; then
	echo "Run npm run build first." >&2
	exit 1
fi
if [ ! -x node_modules/.bin/playwright ]; then
	echo "Run npm install first." >&2
	exit 1
fi
PORTS=("$PORT" "$PORT_B" "$PORT_R")
if [ "${LWM_E2E_NETWORK:-}" = "1" ]; then
	PORTS+=("$PORT_N")
fi
for port in "${PORTS[@]}"; do
	if nc -z 127.0.0.1 "$port" 2>/dev/null; then
		echo "Port $port is in use." >&2
		exit 1
	fi
done

# The work folder of bin/test-wp.sh (same default), known before the setup runs, so a
# setup that fails half-way is cleaned up too.
WORK="${LWM_E2E_DIR:-${TMPDIR:-/tmp}/lwm-e2e}"
WORK="${WORK%/}"
SERVERS=()

cleanup() {
	local code=$?
	for pid in ${SERVERS[@]+"${SERVERS[@]}"}; do
		pkill -P "$pid" 2>/dev/null || true
		kill "$pid" 2>/dev/null || true
	done
	if [ "${LWM_E2E_KEEP:-}" != "1" ]; then
		# Only a folder bin/test-wp.sh marked as its own is emptied.
		if [ -n "$WORK" ] && [ -f "$WORK/.lwm-e2e" ]; then
			rm -rf "$WORK/a" "$WORK/b" "$WORK/r" "$WORK/n" "$WORK/s" "$WORK/m" "$WORK/r-shared" "$WORK/r-themes-aside" \
				"$WORK/core" "$WORK/ui-backups" "$WORK/.lwm-e2e"
			rmdir "$WORK" 2>/dev/null || true
		fi
		docker_t rm -f lwm-e2e-db >/dev/null 2>&1 || true
	fi
	exit "$code"
}
trap cleanup EXIT

# A failed setup stops here (set -e), not inside an empty eval.
SETUP="$(LWM_E2E_SETUP_ONLY=1 bin/test-wp.sh)"
eval "$(printf '%s\n' "$SETUP" | grep '^export ')"
WORK="$(dirname "$LWM_E2E_A")"

wp_cli() {
	local path="$1"
	shift
	"$LWM_E2E_PHP" -d memory_limit=256M "$LWM_E2E_WP_CLI" --path="$path" "$@"
}

serve() {
	local path="$1" port="$2" url="http://127.0.0.1:$2"
	wp_cli "$path" option update home "$url" --quiet
	wp_cli "$path" option update siteurl "$url" --quiet
	listen "$path" "$port"
}

# The network keeps its sites' addresses in wp_blogs and every site's options: all of them
# move, and WordPress finds its main site by DOMAIN_CURRENT_SITE (a port included).
serve_network() {
	local path="$1" port="$2" host
	host="$(wp_cli "$path" eval 'echo DOMAIN_CURRENT_SITE;')"
	wp_cli "$path" search-replace "$host" "127.0.0.1:$port" --network --all-tables --quiet
	wp_cli "$path" config set DOMAIN_CURRENT_SITE "127.0.0.1:$port" --quiet
	listen "$path" "$port"
}

listen() {
	local path="$1" port="$2"
	mkdir -p "$path/wp-content/mu-plugins"
	cp tests/E2E/browser/mu-plugin/lwm-e2e.php "$path/wp-content/mu-plugins/lwm-e2e.php"
	PHP_CLI_SERVER_WORKERS=4 "$LWM_E2E_PHP" -d post_max_size=64M -d upload_max_filesize=64M -d memory_limit=256M \
		-S "127.0.0.1:$port" -t "$path" >/dev/null 2>&1 &
	SERVERS+=("$!")
	for _ in $(seq 1 50); do
		if nc -z 127.0.0.1 "$port" 2>/dev/null; then
			return 0
		fi
		sleep 0.1
	done
	echo "The web server on port $port did not start." >&2
	return 1
}

# The mu-plugin lets the two sites connect on these ports (Connections tests).
export LWM_UI_PORT="$PORT"
serve "$LWM_E2E_A" "$PORT"
serve "$LWM_E2E_B" "$PORT_B"
serve "$LWM_E2E_R" "$PORT_R"
unset LWM_UI_URL_N
if [ "${LWM_E2E_NETWORK:-}" = "1" ]; then
	serve_network "$LWM_E2E_N" "$PORT_N"
	export LWM_UI_URL_N="http://127.0.0.1:$PORT_N"
fi

# A backups folder outside the web root, beside the site roots (never under them, never in
# /tmp: a system tree for the folder rules); new and empty for every run.
rm -rf "$WORK/ui-backups"
mkdir -m 0750 "$WORK/ui-backups"
export LWM_UI_OUTSIDE="$WORK/ui-backups"

export LWM_UI_URL="http://127.0.0.1:$PORT" LWM_UI_URL_B="http://127.0.0.1:$PORT_B" LWM_UI_URL_R="http://127.0.0.1:$PORT_R"
node_modules/.bin/playwright test -c tests/E2E/browser/playwright.config.mjs "$@"
