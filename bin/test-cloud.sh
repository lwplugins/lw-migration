#!/usr/bin/env bash
# Cloud destination end-to-end tests against throwaway storage servers (dev only, spec 21.9).
# Usage: [LWM_CLOUD_SERVICES="minio sftp ftp nextcloud relay"] [LWM_MINIO_PORT=39000] [LWM_MINIO_IMAGE=…]
#        [LWM_MC_IMAGE=…] [LWM_SFTP_IMAGE=…] [LWM_FTP_IMAGE=…] [LWM_NEXTCLOUD_IMAGE=…]
#        [LWM_PHP_IMAGE=…] [LWM_NGINX_IMAGE=…]
#        [LWM_DOCKER_TIMEOUT=120] [LWM_DOCKER_PULL_TIMEOUT=1800] bin/test-cloud.sh [phpunit args]
# Starts the services named in LWM_CLOUD_SERVICES (default: all five), then runs bin/test-wp.sh
# with --filter 'CloudE2ETest|CloudProtocol|RelayE2ETest'; a test whose server is not running skips itself.
# - minio: MinIO on 127.0.0.1:39000 with a bucket "lwm-e2e".
# - sftp: two OpenSSH servers with different host keys, 127.0.0.1:39022 and :39023, a user with a
#   password and an Ed25519 key with a passphrase (generated for the run).
# - ftp: vsftpd with forced FTPS (a self-signed certificate, confirmed once) on :39021 (passive ports 39100-39109), plain FTP on :39121
#   (39110-39114) and plain FTP that refuses REST on :39122 (39115-39119), so uploads there go the
#   APPE way. Every vsftpd announces the unroutable passive address 10.255.255.1: a transfer works
#   only when the client ignores it and opens the data connection to the control address.
# - nextcloud: Nextcloud (SQLite) on :39080.
# - relay: a fake LW sign-in service and a fake Dropbox API (tests/E2E/relay/fake-relay.php on
#   php -S) behind an nginx TLS front on 127.0.0.1:443 (the port must be free), with a CA made for
#   the run; the sites reach it only as lwm-relay.invalid, api.dropboxapi.com and
#   content.dropboxapi.com through the relay probe (tests/E2E/relay-probe.php), which also makes
#   them trust that CA for those names only.
# The sites reach every server by a name under .invalid (lwm-minio.invalid, lwm-sftp.invalid,
# lwm-sftp2.invalid, lwm-ftp.invalid, lwm-nextcloud.invalid), which only the test probe resolves
# (tests/E2E/cloud-probe.php), so every connection must go to the address the plugin checked:
# HTTP through the plugin's pinned cURL path, FTP and SFTP to the checked IP address.
# The containers, their network and the generated keys are removed when it ends
# (LWM_E2E_KEEP=1 keeps them, like bin/test-wp.sh keeps its sites).
# The images are pinned by tag and digest (checked against the local Docker cache on 2026-10-13;
# the minio/minio and minio/mc repositories no longer answer anonymous pulls on Docker Hub, and
# Docker Hub may refuse anonymous pulls of the others too, so a machine without them in its cache
# must log in and pull them, or point the LWM_*_IMAGE variables elsewhere). The Nextcloud image
# is about 1.3 GB to download.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SERVICES="${LWM_CLOUD_SERVICES:-minio sftp ftp nextcloud relay}"
IMAGE="${LWM_MINIO_IMAGE:-minio/minio:RELEASE.2025-09-07T16-13-09Z@sha256:14cea493d9a34af32f524e538b8346cf79f3321eff8e708c1e2960462bd8936e}"
MC="${LWM_MC_IMAGE:-minio/mc:RELEASE.2025-08-13T08-35-41Z@sha256:a7fe349ef4bd8521fb8497f55c6042871b2ae640607cf99d9bede5e9bdf11727}"
SFTP_IMAGE="${LWM_SFTP_IMAGE:-linuxserver/openssh-server:10.3_p1-r1-ls239@sha256:0f535b0faa7448511b23528165e9fbd4f1fe563b861b3dcaa5ddb0ddbfe2254c}"
FTP_IMAGE="${LWM_FTP_IMAGE:-delfer/alpine-ftp-server@sha256:60bb774d8408d9d4d5c74d05d1c086a34ce192c6c1a142ffac268cac0dbc6fac}"
NC_IMAGE="${LWM_NEXTCLOUD_IMAGE:-nextcloud:35.0.1-apache@sha256:8f2f0e93aca5e9457019933936dea1f313052e72c696b4aecb31580a208b859f}"
PHP_IMAGE="${LWM_PHP_IMAGE:-php:8.3-cli@sha256:aafe21201943a8a6e497ddfb471e2ce68ada76adede38c7d61fede8918b24319}"
NGINX_IMAGE="${LWM_NGINX_IMAGE:-nginx:1.30-alpine@sha256:dc5069ad14f19660b141b21236140b91656bf89bbc3e2417c70ae650cd66104c}"
PORT="${LWM_MINIO_PORT:-39000}"
NAME="lwm-e2e-minio"
NET="lwm-e2e-cloud"
USER_NAME="lwmadmin"
SECRET="lwm-e2e-$(od -An -N8 -tx1 /dev/urandom | tr -d ' \n')"
KEYS=""
NAMES=()

for tool in docker curl timeout php ssh-keygen ssh-keyscan openssl; do
	if ! command -v "$tool" >/dev/null 2>&1; then
		echo "$tool is required." >&2
		exit 1
	fi
done

# Docker calls can hang: never wait on one for ever.
docker_t() {
	timeout "${LWM_DOCKER_TIMEOUT:-120}" docker "$@"
}

# A call that may first download its image (Nextcloud is about 1.3 GB) gets longer.
docker_pull_t() {
	timeout "${LWM_DOCKER_PULL_TIMEOUT:-1800}" docker "$@"
}

cleanup() {
	local code=$?
	if [ "${LWM_E2E_KEEP:-}" != "1" ]; then
		for name in ${NAMES[@]+"${NAMES[@]}"}; do
			docker_t rm -f "$name" >/dev/null 2>&1 || true
		done
		docker_t network rm "$NET" >/dev/null 2>&1 || true
		if [ -n "$KEYS" ]; then
			rm -rf "$KEYS"
		fi
	fi
	exit "$code"
}
trap cleanup EXIT
KEYS="$(mktemp -d "${TMPDIR:-/tmp}/lwm-e2e-keys.XXXXXX")"

has() {
	case " $SERVICES " in
	*" $1 "*) return 0 ;;
	esac
	return 1
}

# wait_for <seconds> <command…>: retries every half second until the deadline, then runs it once
# more so its error shows. Each check below limits its own try to a few seconds.
wait_for() {
	local deadline=$((SECONDS + $1))
	shift
	while [ "$SECONDS" -lt "$deadline" ]; do
		if "$@" >/dev/null 2>&1; then
			return 0
		fi
		sleep 0.5
	done
	"$@"
}

keyscan() {
	timeout 10 ssh-keyscan -T 5 -p "$1" -t ed25519 127.0.0.1 2>/dev/null | grep -q ssh-ed25519
}

# An FTP server is ready when it greets (a forwarded port may accept before anything listens).
ftp_ready() {
	php -r '$s = @fsockopen( "127.0.0.1", (int) $argv[1], $e, $m, 2 ); if ( ! $s ) { exit( 1 ); } stream_set_timeout( $s, 2 ); exit( str_starts_with( (string) fgets( $s ), "220" ) ? 0 : 1 );' "$1"
}

nextcloud_ready() {
	curl -fsS --max-time 5 "http://127.0.0.1:$1/status.php" | grep -q '"installed":true'
}

relay_ready() {
	curl -fsS --max-time 5 --cacert "$KEYS/relay/ca.pem" --resolve lwm-relay.invalid:443:127.0.0.1 \
		https://lwm-relay.invalid/_e2e/stats | grep -q refreshes
}

docker_t network inspect "$NET" >/dev/null 2>&1 || docker_t network create "$NET" >/dev/null

if has minio; then
	docker_t rm -f "$NAME" >/dev/null 2>&1 || true
	NAMES+=("$NAME")
	docker_pull_t run -d --rm --name "$NAME" --network "$NET" -p "127.0.0.1:${PORT}:9000" \
		-e MINIO_ROOT_USER="$USER_NAME" -e MINIO_ROOT_PASSWORD="$SECRET" \
		"$IMAGE" server /data >/dev/null

	for _ in $(seq 1 60); do
		if curl -fsS --max-time 2 "http://127.0.0.1:${PORT}/minio/health/live" >/dev/null 2>&1; then
			break
		fi
		sleep 0.5
	done
	curl -fsS --max-time 5 "http://127.0.0.1:${PORT}/minio/health/live" >/dev/null

	docker_pull_t run --rm --network "$NET" -e "MC_HOST_e2e=http://${USER_NAME}:${SECRET}@${NAME}:9000" "$MC" mb --ignore-existing e2e/lwm-e2e >/dev/null

	export LWM_E2E_MINIO="http://127.0.0.1:${PORT}" LWM_E2E_MINIO_USER="$USER_NAME" LWM_E2E_MINIO_SECRET="$SECRET"
fi

if has sftp; then
	SFTP_PORT="${LWM_SFTP_PORT:-39022}"
	SFTP2_PORT="${LWM_SFTP2_PORT:-39023}"
	mkdir -p "$KEYS/pub"
	ssh-keygen -q -t ed25519 -N "$SECRET" -C lwm-e2e -f "$KEYS/id_ed25519"
	cp "$KEYS/id_ed25519.pub" "$KEYS/pub/authorized"
	for pair in "lwm-e2e-sftp:$SFTP_PORT" "lwm-e2e-sftp2:$SFTP2_PORT"; do
		name="${pair%%:*}"
		port="${pair##*:}"
		docker_t rm -f "$name" >/dev/null 2>&1 || true
		NAMES+=("$name")
		docker_pull_t run -d --rm --name "$name" --network "$NET" -p "127.0.0.1:${port}:2222" \
			-e PUID=1000 -e PGID=1000 -e USER_NAME=lwm -e USER_PASSWORD="$SECRET" -e PASSWORD_ACCESS=true \
			-e PUBLIC_KEY_FILE=/keys/authorized -v "$KEYS/pub:/keys:ro" "$SFTP_IMAGE" >/dev/null
	done
	wait_for 90 keyscan "$SFTP_PORT"
	wait_for 90 keyscan "$SFTP2_PORT"
	SFTP_FP="$(timeout 10 ssh-keyscan -T 5 -p "$SFTP_PORT" -t ed25519 127.0.0.1 2>/dev/null | ssh-keygen -lf - | awk '{print $2}')"
	export LWM_E2E_SFTP="lwm-sftp.invalid:${SFTP_PORT}" LWM_E2E_SFTP2="lwm-sftp2.invalid:${SFTP2_PORT}" LWM_E2E_SFTP_USER=lwm \
		LWM_E2E_SFTP_SECRET="$SECRET" LWM_E2E_SFTP_KEY="$KEYS/id_ed25519" LWM_E2E_SFTP_FP="$SFTP_FP"
fi

if has ftp; then
	FTPS_PORT="${LWM_FTPS_PORT:-39021}"
	FTP_PORT="${LWM_FTP_PORT:-39121}"
	FTP_APPE_PORT="${LWM_FTP_APPE_PORT:-39122}"
	mkdir -p "$KEYS/tls" "$KEYS/ftp"
	openssl req -x509 -newkey rsa:2048 -nodes -keyout "$KEYS/tls/key.pem" -out "$KEYS/tls/cert.pem" -days 2 -subj /CN=localhost 2>/dev/null
	chmod 644 "$KEYS/tls/key.pem"
	# The image's own configuration with vsftpd in the foreground: its start script otherwise
	# records the server's process id with a race, and the container sometimes ends right away.
	docker_pull_t run --rm --entrypoint cat "$FTP_IMAGE" /etc/vsftpd/vsftpd.conf | sed 's/^background=YES$/background=NO/' >"$KEYS/ftp/vsftpd.conf"
	# The third server also refuses REST (uploads append) and logs the commands.
	cp "$KEYS/ftp/vsftpd.conf" "$KEYS/ftp/appe.conf"
	printf 'cmds_denied=REST\nlog_ftp_protocol=YES\n' >>"$KEYS/ftp/appe.conf"
	TLS_OPTS=(-orsa_cert_file=/tls/cert.pem -orsa_private_key_file=/tls/key.pem -ossl_enable=YES -oallow_anon_ssl=NO
		-oforce_local_data_ssl=YES -oforce_local_logins_ssl=YES -ossl_tlsv1=NO -ossl_sslv2=NO -ossl_sslv3=NO -ossl_ciphers=HIGH)
	for spec in "lwm-e2e-ftps:$FTPS_PORT:39100:39109:vsftpd.conf" "lwm-e2e-ftp:$FTP_PORT:39110:39114:vsftpd.conf" \
		"lwm-e2e-ftp-appe:$FTP_APPE_PORT:39115:39119:appe.conf"; do
		IFS=: read -r name port low high conf <<<"$spec"
		tls=()
		if [ "$name" = lwm-e2e-ftps ]; then
			tls=("${TLS_OPTS[@]}")
		fi
		docker_t rm -f "$name" >/dev/null 2>&1 || true
		NAMES+=("$name")
		# Arguments after the image replace the start script's own vsftpd call (it still adds the user).
		docker_t run -d --rm --name "$name" --network "$NET" -p "127.0.0.1:${port}:21" -p "127.0.0.1:${low}-${high}:${low}-${high}" \
			-e USERS="lwm|$SECRET" -v "$KEYS/tls:/tls:ro" -v "$KEYS/ftp/$conf:/etc/lwm-vsftpd.conf:ro" "$FTP_IMAGE" \
			vsftpd -opasv_min_port="$low" -opasv_max_port="$high" -opasv_address=10.255.255.1 ${tls[@]+"${tls[@]}"} /etc/lwm-vsftpd.conf >/dev/null
	done
	for port in "$FTPS_PORT" "$FTP_PORT" "$FTP_APPE_PORT"; do
		wait_for 30 ftp_ready "$port"
	done
	FTPS_FP="$(openssl x509 -in "$KEYS/tls/cert.pem" -noout -fingerprint -sha256 | cut -d= -f2)"
	export LWM_E2E_FTPS="lwm-ftp.invalid:${FTPS_PORT}" LWM_E2E_FTP="lwm-ftp.invalid:${FTP_PORT}" LWM_E2E_FTPS_FP="$FTPS_FP" \
		LWM_E2E_FTP_APPE="lwm-ftp.invalid:${FTP_APPE_PORT}" LWM_E2E_FTP_USER=lwm LWM_E2E_FTP_SECRET="$SECRET"
fi

if has nextcloud; then
	NC_PORT="${LWM_NEXTCLOUD_PORT:-39080}"
	docker_t rm -f lwm-e2e-nextcloud >/dev/null 2>&1 || true
	NAMES+=(lwm-e2e-nextcloud)
	docker_pull_t run -d --rm --name lwm-e2e-nextcloud --network "$NET" -p "127.0.0.1:${NC_PORT}:80" \
		-e SQLITE_DATABASE=nextcloud -e NEXTCLOUD_ADMIN_USER=lwm -e NEXTCLOUD_ADMIN_PASSWORD="$SECRET" \
		-e NEXTCLOUD_TRUSTED_DOMAINS="127.0.0.1 localhost lwm-nextcloud.invalid" "$NC_IMAGE" >/dev/null
	wait_for 300 nextcloud_ready "$NC_PORT"
	export LWM_E2E_NEXTCLOUD="http://lwm-nextcloud.invalid:${NC_PORT}/remote.php/dav/files/lwm/" LWM_E2E_NEXTCLOUD_USER=lwm \
		LWM_E2E_NEXTCLOUD_SECRET="$SECRET"
fi

if has relay; then
	R="$KEYS/relay"
	mkdir -p "$R"
	openssl req -new -newkey rsa:2048 -nodes -keyout "$R/ca.key" -out "$R/ca.csr" -subj /CN=lwm-e2e-relay-ca 2>/dev/null
	printf 'basicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\n' >"$R/ca.ext"
	openssl x509 -req -in "$R/ca.csr" -signkey "$R/ca.key" -out "$R/ca.pem" -days 2 -extfile "$R/ca.ext" 2>/dev/null
	openssl req -new -newkey rsa:2048 -nodes -keyout "$R/leaf.key" -out "$R/leaf.csr" -subj /CN=lwm-relay.invalid 2>/dev/null
	printf 'basicConstraints=CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\nsubjectAltName=DNS:lwm-relay.invalid,DNS:api.dropboxapi.com,DNS:content.dropboxapi.com\n' >"$R/leaf.ext"
	openssl x509 -req -in "$R/leaf.csr" -CA "$R/ca.pem" -CAkey "$R/ca.key" -CAcreateserial -out "$R/leaf.pem" -days 2 -extfile "$R/leaf.ext" 2>/dev/null
	chmod 644 "$R/leaf.key"
	for name in lwm-e2e-relay lwm-e2e-relay-php; do
		docker_t rm -f "$name" >/dev/null 2>&1 || true
		NAMES+=("$name")
	done
	docker_pull_t run -d --rm --name lwm-e2e-relay-php --network "$NET" -e RELAY_ALLOW_HTTP_HOSTS=site-a.test \
		-v "$ROOT/tests/E2E/relay:/srv:ro" "$PHP_IMAGE" php -S 0.0.0.0:8080 /srv/fake-relay.php >/dev/null
	docker_pull_t run -d --rm --name lwm-e2e-relay --network "$NET" -p 127.0.0.1:443:443 \
		-v "$R:/tls:ro" -v "$ROOT/tests/E2E/relay/nginx.conf:/etc/nginx/nginx.conf:ro" "$NGINX_IMAGE" >/dev/null
	wait_for 60 relay_ready
	export LWM_E2E_RELAY="https://lwm-relay.invalid" LWM_E2E_RELAY_CA="$R/ca.pem"
fi

cd "$ROOT"
# A --filter of the caller (e.g. one test method) replaces the default one: PHPUnit takes only one.
case " $* " in
*" --filter"*) bash bin/test-wp.sh "$@" ;;
*) bash bin/test-wp.sh --filter 'CloudE2ETest|CloudProtocol|RelayE2ETest' "$@" ;;
esac
