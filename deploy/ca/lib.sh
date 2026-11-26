#!/usr/bin/env bash
# Grape Root CA - shared helpers for generate-root-ca.sh / issue-cert.sh / renew-cert.sh
# Sourced, not executed directly.

set -euo pipefail

# Everything secret (root private key, issued leaf keys, serial/index files)
# lives OUTSIDE the repository by default, in $GRAPE_CA_DIR (override the env
# var if you want a different location, e.g. a path on an encrypted volume).
: "${GRAPE_CA_DIR:="$HOME/.grape-ca"}"

CA_DIR="$GRAPE_CA_DIR"
CA_PRIVATE_DIR="$CA_DIR/private"      # root key only - 700/600
CA_CERTS_DIR="$CA_DIR/certs"          # root public cert + serial file
CA_ISSUED_DIR="$CA_DIR/issued"        # one subfolder per leaf cert issued

ROOT_KEY="$CA_PRIVATE_DIR/grape-root.key"
ROOT_CRT="$CA_CERTS_DIR/grape-root.crt"
ROOT_SRL="$CA_PRIVATE_DIR/grape-root.srl"

# Repo-local copy of the PUBLIC root certificate only, meant to be committed
# and/or copied onto guest machines so their browsers trust it.
REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DIST_DIR="$REPO_DIR/dist"

require_openssl() {
    command -v openssl >/dev/null 2>&1 || {
        echo "ERROR: openssl not found in PATH. On Windows, run this from Git Bash" >&2
        echo "       (ships with Git for Windows) or install OpenSSL and add it to PATH." >&2
        exit 1
    }
}

require_root_ca() {
    if [[ ! -f "$ROOT_KEY" || ! -f "$ROOT_CRT" ]]; then
        echo "ERROR: Root CA not found in $CA_DIR" >&2
        echo "       Run deploy/ca/generate-root-ca.sh first." >&2
        exit 1
    fi
}

# build_san_extfile <output_file> <san1> [san2 ...]
# Each san argument is "ip:<value>" or "dns:<value>".
build_san_extfile() {
    local out="$1"; shift
    local ip_i=1 dns_i=1
    {
        echo "basicConstraints = CA:FALSE"
        echo "keyUsage = critical, digitalSignature, keyEncipherment"
        echo "extendedKeyUsage = serverAuth"
        echo "subjectAltName = @alt_names"
        echo ""
        echo "[ alt_names ]"
        for san in "$@"; do
            local type="${san%%:*}"
            local value="${san#*:}"
            case "${type,,}" in
                ip)  echo "IP.${ip_i} = ${value}"; ((ip_i++)) ;;
                dns) echo "DNS.${dns_i} = ${value}"; ((dns_i++)) ;;
                *)
                    echo "ERROR: unrecognized SAN '$san' (expected ip:<value> or dns:<value>)" >&2
                    exit 1
                    ;;
            esac
        done
    } > "$out"
}

# save_san_metadata <name> <san1> [san2 ...]
# Remembers which SANs a cert was issued for, so renew-cert.sh doesn't need
# them typed again.
save_san_metadata() {
    local name="$1"; shift
    printf '%s\n' "$@" > "$CA_ISSUED_DIR/$name/$name.sans"
}

load_san_metadata() {
    local name="$1"
    local file="$CA_ISSUED_DIR/$name/$name.sans"
    if [[ ! -f "$file" ]]; then
        echo "ERROR: no SAN metadata for '$name' at $file" >&2
        echo "       Re-issue it once with issue-cert.sh, passing the SANs explicitly." >&2
        exit 1
    fi
    cat "$file"
}
