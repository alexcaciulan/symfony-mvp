#!/usr/bin/env bash
# Expose the demo stack over a Cloudflare tunnel.
#
# Two modes, picked from .env.demo:
#   - DEMO_TUNNEL_NAME empty  -> quick tunnel, random *.trycloudflare.com URL.
#     The URL is discovered at runtime, written back into .env.demo and the
#     affected containers are recreated so APP_BASE_URL, MERCURE_PUBLIC_URL and
#     the Mercure CORS origin match the public URL.
#   - DEMO_TUNNEL_NAME set    -> named tunnel, stable URL from DEMO_PUBLIC_URL.
#     Requires a domain in your Cloudflare account; setup steps are in
#     docs/LexRecovery/DEMO-STACK.md.
#
# Ctrl+C stops the tunnel. The Mac is kept awake while it runs.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="${ROOT}/.env.demo"
LOG_DIR="${ROOT}/var"

command -v cloudflared >/dev/null || { echo "cloudflared lipsește. Rulează: brew install cloudflared"; exit 1; }
[ -f "${ENV_FILE}" ] || { echo "Lipsește ${ENV_FILE} (cp .env.demo.dist .env.demo)"; exit 1; }

set -a
# shellcheck disable=SC1090
. "${ENV_FILE}"
set +a

APP_PORT="${DEMO_APP_PORT:-8090}"
MAIL_PORT="${DEMO_MAIL_PORT:-8035}"
mkdir -p "${LOG_DIR}"

if ! curl -sf -o /dev/null "http://127.0.0.1:${APP_PORT}/"; then
  echo "ATENȚIE: nimic nu răspunde pe http://127.0.0.1:${APP_PORT}/ (pornește stack-ul demo: make demo-up)"
fi

caffeinate -dimsu &
CAFFEINATE_PID=$!
PIDS=("${CAFFEINATE_PID}")
cleanup() {
  echo ""
  echo "Opresc tunelul și caffeinate..."
  kill "${PIDS[@]}" 2>/dev/null || true
}
trap cleanup EXIT INT TERM

# Block until the demo app answers locally. Recreating php runs the entrypoint
# again (migrations, assets, Tailwind), so nginx answers 502 for up to a minute.
# Do not announce the public link before this returns.
wait_for_demo() {
  echo -n "Aștept ca demo-ul să răspundă"
  for _ in $(seq 1 60); do
    if curl -sf -o /dev/null "http://127.0.0.1:${APP_PORT}/login"; then
      echo " gata."
      return 0
    fi
    echo -n "."
    sleep 2
  done
  echo ""
  echo "ATENȚIE: demo-ul nu răspunde local pe portul ${APP_PORT}. Verifică: make demo-logs"
  return 1
}

# Point the demo containers at the public URL. Compose only recreates the
# services whose environment actually changed (php, worker, mercure).
apply_public_url() {
  local url="$1"
  if [ "${DEMO_PUBLIC_URL:-}" = "${url}" ]; then
    return
  fi
  echo "Aliniez stack-ul demo la ${url} ..."
  # BSD sed (macOS) needs the empty suffix for in-place editing.
  sed -i '' "s|^DEMO_PUBLIC_URL=.*|DEMO_PUBLIC_URL=${url}|" "${ENV_FILE}"
  DEMO_PUBLIC_URL="${url}"
  "${ROOT}/scripts/demo.sh" up -d >/dev/null
  wait_for_demo || true
}

# --- Named tunnel: stable hostname, nothing to discover at runtime. ---------
if [ -n "${DEMO_TUNNEL_NAME:-}" ]; then
  APP_HOST="${DEMO_PUBLIC_URL#https://}"
  APP_HOST="${APP_HOST#http://}"
  [ -n "${APP_HOST}" ] || { echo "DEMO_PUBLIC_URL nu e setat în ${ENV_FILE}"; exit 1; }

  CONFIG="${LOG_DIR}/cloudflared-demo.yml"
  {
    echo "tunnel: ${DEMO_TUNNEL_NAME}"
    echo "ingress:"
    echo "  - hostname: ${APP_HOST}"
    echo "    service: http://127.0.0.1:${APP_PORT}"
    if [ -n "${DEMO_MAIL_HOST:-}" ]; then
      echo "  - hostname: ${DEMO_MAIL_HOST}"
      echo "    service: http://127.0.0.1:${MAIL_PORT}"
    fi
    echo "  - service: http_status:404"
  } > "${CONFIG}"

  # Ensure the containers were built with the current DEMO_PUBLIC_URL before the
  # tunnel goes live (e.g. after switching away from a previous quick-tunnel URL).
  # Idempotent: compose only recreates services whose environment changed.
  echo "Aliniez stack-ul demo la https://${APP_HOST} ..."
  "${ROOT}/scripts/demo.sh" up -d >/dev/null
  wait_for_demo || true

  echo "Tunel numit '${DEMO_TUNNEL_NAME}'. URL stabil: https://${APP_HOST}"
  [ -n "${DEMO_MAIL_HOST:-}" ] && echo "Mailpit: https://${DEMO_MAIL_HOST}"
  echo ""
  while true; do
    cloudflared tunnel --config "${CONFIG}" run "${DEMO_TUNNEL_NAME}" || true
    echo "Tunelul s-a oprit. Repornesc în 3 secunde (URL-ul rămâne același)..."
    sleep 3
  done
fi

# --- Quick tunnel: random URL, rediscovered on every (re)start. -------------
APP_LOG="${LOG_DIR}/cloudflared-demo-app.log"
MAIL_LOG="${LOG_DIR}/cloudflared-demo-mail.log"
APP_PID=""
MAIL_PID=""
APP_URL=""
MAIL_URL=""

# Starts one quick tunnel and writes the pid and public URL into the caller's
# variables. Must NOT be called through $(...): a subshell would swallow the pid
# and the supervisor loop below would then restart a tunnel that is still alive.
start_quick_tunnel() {
  local port="$1" log="$2" pid_var="$3" url_var="$4"
  : > "${log}"
  cloudflared tunnel --url "http://127.0.0.1:${port}" >"${log}" 2>&1 &
  local pid=$!
  printf -v "${pid_var}" '%s' "${pid}"
  PIDS+=("${pid}")

  local url=""
  for _ in $(seq 1 60); do
    url="$(grep -oE 'https://[a-z0-9-]+\.trycloudflare\.com' "${log}" | head -1 || true)"
    [ -n "${url}" ] && break
    kill -0 "${pid}" 2>/dev/null || break
    sleep 1
  done
  printf -v "${url_var}" '%s' "${url}"
}

print_links() {
  echo ""
  echo "======================================================================"
  echo "  APLICAȚIE:  ${APP_URL}"
  if [ -n "${MAIL_URL}" ]; then
    echo "  EMAILURI:   ${MAIL_URL}"
    [ -n "${DEMO_MAIL_AUTH:-}" ] && echo "              (user/parolă: ${DEMO_MAIL_AUTH})"
  else
    echo "  EMAILURI:   http://localhost:${MAIL_PORT} (doar local)"
  fi
  echo "======================================================================"
  echo "Ține terminalul deschis. Ctrl+C oprește demo-ul public."
  echo ""
}

echo "Pornesc quick tunnel peste stack-ul demo (port ${APP_PORT})..."
start_quick_tunnel "${APP_PORT}" "${APP_LOG}" APP_PID APP_URL
[ -n "${APP_URL}" ] || { echo "Nu am putut citi URL-ul tunelului. Vezi ${APP_LOG}"; exit 1; }
apply_public_url "${APP_URL}"

if [ "${DEMO_EXPOSE_MAIL:-false}" = "true" ]; then
  start_quick_tunnel "${MAIL_PORT}" "${MAIL_LOG}" MAIL_PID MAIL_URL
  [ -n "${MAIL_URL}" ] || echo "Nu am putut expune Mailpit. Vezi ${MAIL_LOG}"
fi

print_links

# Cloudflare quick tunnels get a new hostname on every restart, so a dead
# tunnel means both restarting it and re-announcing the links.
while true; do
  sleep 5
  if ! kill -0 "${APP_PID}" 2>/dev/null; then
    echo "Tunelul aplicației a picat. Repornesc (URL nou)..."
    start_quick_tunnel "${APP_PORT}" "${APP_LOG}" APP_PID APP_URL
    [ -n "${APP_URL}" ] && apply_public_url "${APP_URL}"
    print_links
  fi
  if [ -n "${MAIL_PID}" ] && ! kill -0 "${MAIL_PID}" 2>/dev/null; then
    echo "Tunelul Mailpit a picat. Repornesc (URL nou)..."
    start_quick_tunnel "${MAIL_PORT}" "${MAIL_LOG}" MAIL_PID MAIL_URL
    print_links
  fi
done
