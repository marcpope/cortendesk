#!/bin/sh
# CortenDesk Agent installer for Linux and macOS, served by @SERVER@.
# Usage: curl -fsSL @SERVER@/agent/v1/install.sh | sudo sh -s -- --token <token> [--ca-file <pem>]
set -eu

SERVER='@SERVER@'
VERSION='@VERSION@'
TOKEN=''
CA_FILE=''

while [ $# -gt 0 ]; do
    case "$1" in
        --token) TOKEN="${2:-}"; shift 2 ;;
        --ca-file) CA_FILE="${2:-}"; shift 2 ;;
        *) echo "Unknown option: $1" >&2; exit 2 ;;
    esac
done

[ -n "$TOKEN" ] || { echo "Missing --token. Copy the command from Monitoring > Enrollment in the console." >&2; exit 2; }
[ "$(id -u)" -eq 0 ] || { echo "Run this as root (with sudo)." >&2; exit 1; }

os=$(uname -s)
arch=$(uname -m)
case "$os" in
    Linux) os=linux ;;
    Darwin) os=darwin ;;
    *) echo "Unsupported OS: $os" >&2; exit 1 ;;
esac
case "$arch" in
    x86_64|amd64) arch=amd64 ;;
    aarch64|arm64) arch=arm64 ;;
    *) echo "Unsupported CPU: $arch" >&2; exit 1 ;;
esac
[ "$os" = darwin ] && arch=universal

URL=''
SHA=''
case "$os-$arch" in
@CASES@
esac

[ -n "$URL" ] && [ -n "$SHA" ] || { echo "No CortenDesk Agent $VERSION build for $os-$arch is published for this console yet." >&2; exit 1; }

tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT

echo "Downloading CortenDesk Agent $VERSION for $os-$arch"
curl -fsSL "$URL" -o "$tmp/cortendesk-agent"

if command -v sha256sum >/dev/null 2>&1; then
    got=$(sha256sum "$tmp/cortendesk-agent" | cut -d' ' -f1)
else
    got=$(shasum -a 256 "$tmp/cortendesk-agent" | cut -d' ' -f1)
fi
[ "$got" = "$SHA" ] || { echo "Checksum mismatch: expected $SHA, got $got" >&2; exit 1; }

chmod 0755 "$tmp/cortendesk-agent"
if [ -n "$CA_FILE" ]; then
    "$tmp/cortendesk-agent" install --server "$SERVER" --token "$TOKEN" --ca-file "$CA_FILE"
else
    "$tmp/cortendesk-agent" install --server "$SERVER" --token "$TOKEN"
fi
