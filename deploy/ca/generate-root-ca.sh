#!/usr/bin/env bash
# Grape Root CA - one-time setup.
#
# Generates:
#   $GRAPE_CA_DIR/private/grape-root.key   (4096-bit RSA, AES-256 encrypted, 600) - SECRET, stays out of the repo
#   $GRAPE_CA_DIR/certs/grape-root.crt     (public root certificate)
#   deploy/ca/dist/grape-root.crt          (copy of the public cert, safe to commit / hand to guests)
#
# Usage:
#   deploy/ca/generate-root-ca.sh
#   GRAPE_CA_DIR=/path/on/encrypted/volume deploy/ca/generate-root-ca.sh   # custom location
#
# You will be prompted to set a passphrase for the root key. Do not skip it
# and do not store the passphrase next to the key (see deploy/ca/README.md).

cd "$(dirname "${BASH_SOURCE[0]}")"
source ./lib.sh
require_openssl

if [[ -f "$ROOT_KEY" || -f "$ROOT_CRT" ]]; then
    echo "ERROR: a root CA already exists in $CA_DIR" >&2
    echo "       Remove it manually first if you really want to regenerate it -" >&2
    echo "       doing so invalidates every certificate it has signed and every" >&2
    echo "       guest machine that installed the old grape-root.crt." >&2
    exit 1
fi

umask 077
mkdir -p "$CA_PRIVATE_DIR" "$CA_CERTS_DIR" "$CA_ISSUED_DIR" "$DIST_DIR"
chmod 700 "$CA_DIR" "$CA_PRIVATE_DIR"

echo "==> Generating 4096-bit RSA root key (you'll be asked for a passphrase)"
openssl genrsa -aes256 -out "$ROOT_KEY" 4096
chmod 600 "$ROOT_KEY"

echo "==> Self-signing the root certificate (valid 10 years)"
openssl req -config root.cnf -key "$ROOT_KEY" -new -x509 \
    -days 3650 -extensions v3_ca -out "$ROOT_CRT"
chmod 644 "$ROOT_CRT"

cp "$ROOT_CRT" "$DIST_DIR/grape-root.crt"

echo
echo "Root CA created:"
echo "  Private key (SECRET): $ROOT_KEY"
echo "  Public cert:          $ROOT_CRT"
echo "  Distributable copy:   $DIST_DIR/grape-root.crt"
echo
echo "Next steps:"
echo "  1. Read deploy/ca/README.md for where to move the private key for long-term storage."
echo "  2. Install $DIST_DIR/grape-root.crt as a trusted root on your machine and each guest's."
echo "  3. Issue a server certificate with deploy/ca/issue-cert.sh."
