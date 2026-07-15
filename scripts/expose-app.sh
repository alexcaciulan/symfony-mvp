#!/usr/bin/env bash
# Expose the running app (http://localhost:8080) and Mailpit (http://localhost:8025)
# over public Cloudflare quick tunnels.
# Keeps the Mac awake (caffeinate) and auto-restarts each tunnel if it drops.
# Stop everything with Ctrl+C.
#
# Prerequisites:
#   - Docker stack running (make up) so 8080 and 8025 answer locally.
#   - brew install cloudflared

set -euo pipefail

APP_PORT=8080
MAIL_PORT=8025

command -v cloudflared >/dev/null || { echo "cloudflared lipsește. Rulează: brew install cloudflared"; exit 1; }

# Warn early if the local services are not answering.
for port in "${APP_PORT}" "${MAIL_PORT}"; do
  if ! curl -sf -o /dev/null "http://127.0.0.1:${port}/"; then
    echo "ATENȚIE: nimic nu răspunde pe http://127.0.0.1:${port}/ (ai pornit stack-ul? make up)"
  fi
done

# Keep the machine awake for the whole session (background, killed on exit).
caffeinate -dimsu &
CAFFEINATE_PID=$!

PIDS=("${CAFFEINATE_PID}")

cleanup() {
  echo ""
  echo "Opresc tunelurile și caffeinate..."
  kill "${PIDS[@]}" 2>/dev/null || true
}
trap cleanup EXIT INT TERM

# Run one auto-restarting tunnel for a given local port. Label prefixes the log
# so the two URLs are easy to tell apart in the interleaved output.
run_tunnel() {
  local label="$1" port="$2"
  while true; do
    cloudflared tunnel --url "http://127.0.0.1:${port}" 2>&1 | sed "s/^/[${label}] /" || true
    echo "[${label}] Tunelul s-a oprit. Repornesc în 3 secunde..."
    sleep 3
  done
}

echo "Pornesc tunelurile publice (Mac ținut treaz). Linkurile *.trycloudflare.com apar mai jos."
echo "  [APP]  -> http://127.0.0.1:${APP_PORT}"
echo "  [MAIL] -> http://127.0.0.1:${MAIL_PORT}"
echo "ATENȚIE: la fiecare repornire a unui tunel, URL-ul lui se schimbă. Ctrl+C pentru oprire."
echo ""

run_tunnel "APP" "${APP_PORT}" &
PIDS+=("$!")
run_tunnel "MAIL" "${MAIL_PORT}" &
PIDS+=("$!")

wait
