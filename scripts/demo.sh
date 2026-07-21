#!/usr/bin/env bash
# Driver for the isolated demo stack: a separate git worktree pinned to the
# lexrecovery branch, running its own containers, database and vendor volume on
# separate ports. Day-to-day work in the main checkout (any branch, any schema
# change) cannot affect it.
#
# Usage:
#   scripts/demo.sh setup        # create/refresh the worktree, build, install, migrate, seed
#   scripts/demo.sh sync         # move the demo to the current tip of lexrecovery and reload
#   scripts/demo.sh db-copy      # one-off copy of the dev database into the demo database
#   scripts/demo.sh up|down|logs|restart|ps|exec php sh|...   # passthrough to docker compose
#
# Config (optional, in .env.demo at the repo root):
#   DEMO_PUBLIC_URL, DEMO_APP_PORT, DEMO_MAIL_PORT, DEMO_DB_PORT, DEMO_APP_ENV

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORKTREE="${DEMO_WORKTREE:-$(cd "${ROOT}/.." && pwd)/symfony-mvp-demo}"
PROJECT=lexdemo
DEMO_BRANCH="${DEMO_BRANCH:-lexrecovery}"
ENV_FILE="${ROOT}/.env.demo"

if [ ! -f "${ENV_FILE}" ]; then
  echo "Lipsește ${ENV_FILE}. Copiază șablonul: cp .env.demo.dist .env.demo și completează DEMO_PUBLIC_URL."
  exit 1
fi

compose() {
  ( cd "${WORKTREE}" && docker compose -p "${PROJECT}" \
      -f compose.yaml -f compose.demo.yaml \
      --env-file .env --env-file "${ENV_FILE}" "$@" )
}

ensure_worktree() {
  if [ ! -d "${WORKTREE}" ]; then
    echo "Creez worktree-ul demo în ${WORKTREE} (detached pe ${DEMO_BRANCH})..."
    git -C "${ROOT}" worktree add --detach "${WORKTREE}" "${DEMO_BRANCH}"
  fi
}

# The demo worktree is intentionally detached: it points at whatever commit
# `lexrecovery` is on right now, and only moves when this is called.
sync_worktree() {
  ensure_worktree
  git -C "${WORKTREE}" checkout --detach "${DEMO_BRANCH}"
  echo "Demo e acum pe $(git -C "${WORKTREE}" rev-parse --short HEAD) ($(git -C "${WORKTREE}" log -1 --format=%s))"
}

# Nginx answers 502 for a few seconds while PHP-FPM comes back, so the sync
# should not report success before the app actually serves again.
wait_until_up() {
  local port
  port="$(grep -E '^DEMO_APP_PORT=' "${ENV_FILE}" | cut -d= -f2)"
  port="${port:-8090}"
  for _ in $(seq 1 30); do
    if curl -sf -o /dev/null "http://127.0.0.1:${port}/login"; then
      echo "Demo răspunde pe http://localhost:${port}"
      return 0
    fi
    sleep 1
  done
  echo "ATENȚIE: demo-ul nu răspunde pe http://localhost:${port} după 30s. Verifică: make demo-logs"
  return 1
}

case "${1:-}" in
  setup)
    sync_worktree
    compose build
    compose up -d
    compose exec -T php composer install
    compose exec -T php php bin/console doctrine:migrations:migrate --no-interaction
    compose exec -T php php bin/console app:import-courts
    compose exec -T php php bin/console app:create-test-users
    compose exec -T php php bin/console tailwind:build
    compose exec -T php php bin/console cache:clear
    echo "Demo pornit local pe http://localhost:${DEMO_APP_PORT:-8090} (Mailpit: http://localhost:${DEMO_MAIL_PORT:-8035})"
    ;;

  sync)
    sync_worktree
    compose exec -T php composer install
    compose exec -T php php bin/console doctrine:migrations:migrate --no-interaction
    compose exec -T php php bin/console tailwind:build
    compose exec -T php php bin/console cache:clear
    # PHP-FPM OPcache does not pick up changed files without a restart.
    compose restart php worker
    wait_until_up
    ;;

  db-copy)
    echo "Copiez baza de date dev (symfony-mvp-db) în demo (lexdemo-db)..."
    docker exec symfony-mvp-db mysqldump -u root -proot_password \
      --single-transaction --routines --triggers symfony_mvp \
      | docker exec -i lexdemo-db mysql -u root -proot_password symfony_mvp
    compose exec -T php php bin/console cache:clear
    echo "Gata. Din acest moment cele două baze diverg din nou."
    ;;

  "" | help | -h | --help)
    sed -n '2,20p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
    ;;

  *)
    compose "$@"
    ;;
esac
