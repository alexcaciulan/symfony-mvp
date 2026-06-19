#!/usr/bin/env bash
# Serve the v2 mockups over a public Cloudflare quick tunnel.
# Keeps the Mac awake (caffeinate) and auto-restarts the tunnel if it drops.
# Stop everything with Ctrl+C.
#
# One-time prerequisite:  brew install cloudflared

set -euo pipefail

MOCKUPS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PORT=8888

command -v cloudflared >/dev/null || { echo "cloudflared lipsește. Rulează: brew install cloudflared"; exit 1; }

# Keep the machine awake for the whole session (background, killed on exit).
caffeinate -dimsu &
CAFFEINATE_PID=$!

# Static server for the mockups (background).
php -S "127.0.0.1:${PORT}" -t "${MOCKUPS_DIR}" >/dev/null 2>&1 &
PHP_PID=$!

cleanup() {
  echo ""
  echo "Opresc serverul și caffeinate..."
  kill "${PHP_PID}" "${CAFFEINATE_PID}" 2>/dev/null || true
}
trap cleanup EXIT INT TERM

echo "Server pornit pe http://127.0.0.1:${PORT}  (Mac ținut treaz)"
echo "Pornesc tunelul public. Linkul apare mai jos. Ctrl+C pentru oprire."
echo "ATENȚIE: la fiecare repornire a tunelului, URL-ul se schimbă."
echo ""

# Tunnel with auto-restart. Note: a new URL is issued on each restart.
while true; do
  cloudflared tunnel --url "http://127.0.0.1:${PORT}" || true
  echo "Tunelul s-a oprit. Repornesc în 3 secunde..."
  sleep 3
done
