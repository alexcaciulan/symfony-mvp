---
description: Rulează manual cei doi reviewers (legal + code) în paralel pe modificările necomise (sare peste debounce-ul hook-ului)
---

Invocă **ÎN PARALEL** (un singur mesaj cu două tool calls `Agent`) cei doi agenți de review pe modificările necomise curente:

1. `subagent_type='lexrecovery-legal-reviewer'` — validare juridică (formule dobândă OG 13/2011, taxă timbru OUG 80/2013, jurisdicție teritorială, ANAF/BPI, workflow legal, deadline-uri procedurale, GDPR/CNP)
2. `subagent_type='lexrecovery-code-reviewer'` — calitate cod + security (PHP/Symfony patterns, teste, deprecation gating, OWASP top 10, Doctrine, autowiring, infra)

După ce primești **ambele rapoarte**, prezintă-le user-ului consolidate:
- **Raport juridic** integral (BLOCKERS + WARNINGS + NOTES + verdict `LEGAL-CLEAN`/`LEGAL-NEEDS-FIX`)
- **Raport tehnic** integral (BLOCKERS + WARNINGS + NOTES + Test coverage + verdict `COMMIT-READY`/`NEEDS-FIX`)
- **Verdict global** propriu: dacă oricare agent are BLOCKERS → `OVERALL-NEEDS-FIX`; altfel → `OVERALL-READY`

Apoi scrie hash-ul curent în `.claude/.last-review-hash` (debounce stamp), folosind ALGORITMUL identic cu hook-ul:

```bash
PATHS="src tests config migrations assets templates translations docker data Dockerfile Makefile compose.yaml compose.override.yaml composer.json importmap.php phpunit.dist.xml docker-entrypoint.sh"
{
  git diff HEAD -- $PATHS 2>/dev/null
  git ls-files --others --exclude-standard -- $PATHS 2>/dev/null | sort | while IFS= read -r f; do
    [ -f "$f" ] && printf '\n--- new file: %s ---\n' "$f" && cat "$f" 2>/dev/null
  done
} | shasum | awk '{print $1}' > .claude/.last-review-hash
```

Dacă verdict-ul global e `OVERALL-NEEDS-FIX`, propune fix-urile concrete ÎNAINTE de a cere user-ului confirmare pentru `git commit`.

Această comandă se folosește când:
- Vrei review intermediar fără să închei tura (hook-ul rulează doar pe Stop)
- Vrei re-review pe un diff pe care l-ai mai revizuit (debounce-ul ar sări)
- Vrei feedback explicit înainte de a încheia un Pas mai mare
