#!/usr/bin/env bash
# Grape Root CA - renew a previously issued server certificate using the same
# SANs it was issued with, keeping the same private key.
#
# Usage:
#   deploy/ca/renew-cert.sh <name>
#
# <name> must match a certificate already issued with issue-cert.sh.
# The Radmin VPN IP can change between sessions; if it did, don't use this
# script - run issue-cert.sh again with the new SAN list instead.

cd "$(dirname "${BASH_SOURCE[0]}")"
source ./lib.sh
require_openssl
require_root_ca

if [[ $# -ne 1 ]]; then
    echo "Usage: $0 <name>" >&2
    exit 1
fi

NAME="$1"
OUT_DIR="$CA_ISSUED_DIR/$NAME"
KEY="$OUT_DIR/$NAME.key"
CRT="$OUT_DIR/$NAME.crt"

if [[ ! -f "$KEY" ]]; then
    echo "ERROR: no existing key for '$NAME' at $KEY" >&2
    echo "       Use issue-cert.sh for a first-time certificate." >&2
    exit 1
fi

mapfile -t SANS < <(load_san_metadata "$NAME")

EXTFILE="$OUT_DIR/$NAME.extfile.cnf"
build_san_extfile "$EXTFILE" "${SANS[@]}"

if [[ -f "$CRT" ]]; then
    BACKUP="$OUT_DIR/$NAME.crt.$(date +%Y%m%d%H%M%S).bak"
    cp "$CRT" "$BACKUP"
    echo "==> Backed up previous certificate to $BACKUP"
fi

echo "==> Re-creating CSR for '$NAME' (same key, same SANs: ${SANS[*]})"
# Double leading slash avoids Git Bash/MSYS mangling this value as a path (see issue-cert.sh).
openssl req -new -key "$KEY" -out "$OUT_DIR/$NAME.csr" -subj "//CN=$NAME/O=Grape"

echo "==> Re-signing with the Grape Root CA (enter the root key passphrase)"
openssl x509 -req -in "$OUT_DIR/$NAME.csr" \
    -CA "$ROOT_CRT" -CAkey "$ROOT_KEY" -CAcreateserial -CAserial "$ROOT_SRL" \
    -days 397 -sha256 -extfile "$EXTFILE" \
    -out "$CRT"

rm -f "$OUT_DIR/$NAME.csr"

echo
echo "Renewed certificate for '$NAME':"
openssl x509 -in "$CRT" -noout -dates
echo "Restart Caddy/the webserver so it picks up the new certificate."