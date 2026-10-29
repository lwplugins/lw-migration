#!/usr/bin/env bash
# Refreshes includes/Vendor/: the SFTP subset of phpseclib and constant_time_encoding,
# namespace-prefixed (dev only, plan D30). The result is committed; running this again
# must leave `git status --porcelain includes/Vendor` empty (gate G11).
# Usage: bin/vendor-sftp.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PHPSECLIB_VERSION="3.0.57"
PHPSECLIB_SHA256="18023e70ca9257e66e289d201d4af4da5d2c91d1de8adc68c23a58dca4f29761"
CTE_VERSION="3.1.3"
CTE_SHA256="7778902689bb4c047a1b9b9c6c86014bf0684a7c4a1de13ff5c9cac4d6525d7e"

for tool in curl php shasum tar; do
	if ! command -v "$tool" >/dev/null 2>&1; then
		echo "$tool is required." >&2
		exit 1
	fi
done

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

fetch() { # url, sha256, file
	curl -fsSL -o "$3" "$1"
	if ! echo "$2  $3" | shasum -a 256 -c - >/dev/null; then
		echo "Checksum mismatch for $1. If GitHub re-packed the archive, compare the tag's commit (see includes/Vendor/SOURCE.txt) before updating the checksum." >&2
		exit 1
	fi
}

fetch "https://github.com/phpseclib/phpseclib/archive/refs/tags/${PHPSECLIB_VERSION}.tar.gz" "$PHPSECLIB_SHA256" "$WORK/phpseclib.tar.gz"
fetch "https://github.com/paragonie/constant_time_encoding/archive/refs/tags/v${CTE_VERSION}.tar.gz" "$CTE_SHA256" "$WORK/cte.tar.gz"
mkdir "$WORK/p" "$WORK/c"
tar xzf "$WORK/phpseclib.tar.gz" -C "$WORK/p"
tar xzf "$WORK/cte.tar.gz" -C "$WORK/c"

rm -rf "$ROOT/includes/Vendor"
php "$ROOT/bin/vendor-sftp.php" "$WORK/p/phpseclib-${PHPSECLIB_VERSION}" "$WORK/c/constant_time_encoding-${CTE_VERSION}" "$ROOT/includes/Vendor"
