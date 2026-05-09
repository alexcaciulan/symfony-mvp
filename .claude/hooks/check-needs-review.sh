#!/bin/bash
# LexRecovery — Stop hook
# Forces invocation of `lexrecovery-code-reviewer` subagent when src/, tests/,
# config/ or migrations/ contain uncommitted changes that haven't been reviewed
# yet (debounced via .claude/.last-review-hash).
#
# Behavior contract:
#  - Exit 0 (silent)        → allow Claude to stop normally.
#  - Exit 0 with JSON stdout → influence Claude per JSON contract.
#    `{"decision": "block", "reason": "..."}` blocks stop and continues turn
#    with `reason` injected as additional context.
#  - We never exit non-zero — Stop hook errors would surface to user as red.

set -e

INPUT=$(cat)

# Avoid infinite loops: if Claude is already responding to a previous Stop-hook
# block, the harness sets stop_hook_active=true. Yield in that case.
STOP_HOOK_ACTIVE=$(printf '%s' "$INPUT" | jq -r '.stop_hook_active // false' 2>/dev/null || echo "false")
if [ "$STOP_HOOK_ACTIVE" = "true" ]; then
  exit 0
fi

# Project dir from harness; fall back to script location's parent.
PROJECT_DIR="${CLAUDE_PROJECT_DIR:-}"
if [ -z "$PROJECT_DIR" ]; then
  PROJECT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
fi

cd "$PROJECT_DIR" 2>/dev/null || exit 0

# Only fire inside a git repo.
git rev-parse --git-dir >/dev/null 2>&1 || exit 0

# Compute fingerprint of uncommitted changes in code paths.
# Includes BOTH tracked-modified files (via `git diff HEAD`) AND untracked
# files (via `git ls-files --others`). Pas-urile LexRecovery adaugă des
# fișiere noi — am rata review-ul pe ele dacă foloseam doar `git diff HEAD`.
#
# Watched dirs: tot ce conține cod review-abil — src, tests, config, migrations,
# assets (Stimulus/CSS), templates (Twig), translations (i18n YAML), docker
# (nginx/php), data (seed JSON). Watched root files: infra config (Dockerfile,
# Makefile, compose*, composer.json, importmap.php, phpunit.dist.xml,
# docker-entrypoint.sh).
# Excluse intenționat: vendor/, var/, public/, docs/, bin/ (binare), node_modules/,
# .claude/ (self), composer.lock/symfony.lock (auto-generate).
PATHS="src tests config migrations assets templates translations docker data Dockerfile Makefile compose.yaml compose.override.yaml composer.json importmap.php phpunit.dist.xml docker-entrypoint.sh"

# Quick existence check — bail out cheaply if nothing changed.
HAS_DIFF=$(git diff HEAD --name-only -- $PATHS 2>/dev/null | head -1)
HAS_UNTRACKED=$(git ls-files --others --exclude-standard -- $PATHS 2>/dev/null | head -1)
if [ -z "$HAS_DIFF" ] && [ -z "$HAS_UNTRACKED" ]; then
  exit 0
fi

# Build fingerprint: tracked diff + untracked file contents (sorted, deterministic).
FINGERPRINT=$(
  git diff HEAD -- $PATHS 2>/dev/null || true
  git ls-files --others --exclude-standard -- $PATHS 2>/dev/null | sort | while IFS= read -r f; do
    if [ -f "$f" ]; then
      printf '\n--- new file: %s ---\n' "$f"
      cat "$f" 2>/dev/null || true
    fi
  done
)

DIFF_HASH=$(printf '%s' "$FINGERPRINT" | shasum | awk '{print $1}')
STAMP_FILE=".claude/.last-review-hash"

LAST_HASH=""
if [ -f "$STAMP_FILE" ]; then
  LAST_HASH=$(cat "$STAMP_FILE" 2>/dev/null || echo "")
fi

# If diff hasn't changed since last review, skip — already reviewed.
if [ "$DIFF_HASH" = "$LAST_HASH" ]; then
  exit 0
fi

# Block stop and instruct Claude to invoke the reviewer.
# NOTE: keep `reason` on a single line — embedded newlines complicate JSON.
REASON="Modificări necomise detectate în paths review-abile (src/, tests/, config/, migrations/, assets/, templates/, translations/, docker/, data/ sau fișiere root infra) cu diff hash $DIFF_HASH, încă nerevizuite. Înainte de a încheia pasul, invocă ÎN PARALEL (un singur mesaj cu DOUĂ tool calls Agent) agenții: (1) subagent_type='lexrecovery-legal-reviewer' — validare juridică (formule, taxe, jurisdicție, ANAF/BPI, workflow legal, deadline-uri, GDPR); (2) subagent_type='lexrecovery-code-reviewer' — calitate cod + security (PHP/Symfony, teste, OWASP, Doctrine, infra). După ce primești AMBELE rapoarte, prezintă-le user-ului consolidate (verdict per agent + verdict global), apoi scrie hash-ul $DIFF_HASH în .claude/.last-review-hash pentru debounce. Dacă oricare agent găsește BLOCKERS, repară-le ÎNAINTE de a cere confirmare pentru git commit. Algoritm stamp identic cu hook-ul (vezi agent definitions §6)."

printf '{"decision":"block","reason":%s}\n' "$(printf '%s' "$REASON" | jq -Rs .)"

exit 0
