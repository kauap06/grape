#!/usr/bin/env bash
# Grape Root CA - issue a server (leaf) certificate signed by grape-root.crt.
#
# Usage:
#   deploy/ca/issue-cert.sh <name> <san> [san ...]
#
# <name> is just a label used for the output folder/files (e.g. "radmin-host").
# Each <san> is "ip:<value>" or "dns:<value>". Pass every address/hostname
# guests will type in the browser - certificates are matched by SAN, not CN.
#
# Example (Radmin IP + localhost, matching deploy/Caddyfile):
#   deploy/ca/issue-cert.sh radmin-host ip:SEU-IP-RADMIN dns:localhost ip:127.0.0.1
#
# Output (valid 397 days, the browser-recommended maximum for server certs):
#   $GRAPE_CA_DIR/issued/<name>/<name>.key   - leaf private key (keep this host-only)
#   $GRAPE_CA_DIR/issued/<name>/<name>.crt   - leaf certificate, signed by grape-root.crt
#
# Point your server (e.g. Caddy's Caddyfile, "tls <crt> <key>") at those two files.
# Re-run this script (or renew-cert.sh) before the 397 days are up.

cd "$(dirname "${BASH_SOURCE[0]}")"
source ./lib.sh
require_openssl
require_root_ca

if [[ $# -lt 2 ]]; then
    echo "Usage: $0 <name> <san> [san ...]" >&2
    echo "  e.g.: $0 radmin-host ip:SEU-IP-RADMIN dns:localhost" >&2
    exit 1
fi

NAME="$1"; shift
SANS=("$@")
OUT_DIR="$CA_ISSUED_DIR/$NAME"

mkdir -p "$OUT_DIR"
EXTFILE="$OUT_DIR/$NAME.extfile.cnf"
build_san_extfile "$EXTFILE" "${SANS[@]}"
save_san_metadata "$NAME" "${SANS[@]}"

echo "==> Generating leaf key for '$NAME'"
openssl genrsa -out "$OUT_DIR/$NAME.key" 2048
chmod 600 "$OUT_DIR/$NAME.key"

echo "==> Creating CSR for '$NAME'"
# Double leading slash: on Git Bash/MSYS, a single "/CN=..." gets mistaken for
# a filesystem path and silently mangled, which makes this command fail
# (no .csr ever gets written). "//CN=..." opts that one argument out of the
# path conversion while leaving -key/-out (which DO need it) unaffected.
openssl req -new -key "$OUT_DIR/$NAME.key" -out "$OUT_DIR/$NAME.csr" \
    -subj "//CN=$NAME/O=Grape"

echo "==> Signing with the Grape Root CA (enter the root key passphrase)"
openssl x509 -req -in "$OUT_DIR/$NAME.csr" \
    -CA "$ROOT_CRT" -CAkey "$ROOT_KEY" -CAcreateserial -CAserial "$ROOT_SRL" \
    -days 397 -sha256 -extfile "$EXTFILE" \
    -out "$OUT_DIR/$NAME.crt"

rm -f "$OUT_DIR/$NAME.csr"

echo
echo "Issued certificate for '$NAME':"
echo "  Key:  $OUT_DIR/$NAME.key"
echo "  Cert: $OUT_DIR/$NAME.crt"
echo "  SANs: ${SANS[*]}"
openssl x509 -in "$OUT_DIR/$NAME.crt" -noout -dates