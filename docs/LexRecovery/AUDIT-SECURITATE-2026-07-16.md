# LexRecovery Security Audit: Consolidated Report

**Scope:** Authentication, session management, password lifecycle, CSRF, access control, and the `User` entity. Read-only audit against OWASP Top 10 (A01, A02, A05, A07) with lawyer/CNP/case-data sensitivity weighting.

---

## 1. Executive Summary

The application's **core security primitives are sound**: passwords are hashed with the `auto` hasher with rehash-on-login, login is CSRF-protected and enumeration-safe, object-level authorization is uniformly enforced through Voters (no live IDOR found), and the highest-risk IDOR surface (`/api/table/{key}`) is correctly user-scoped. The material risk is concentrated in **configuration and trust-boundary gaps**, not broken crypto: email verification is decorative, production reverse-proxy trust is unconfigured (breaking rate limiters and the Secure cookie flag), two password-reset endpoints have no CSRF protection, and no security response headers exist anywhere.

**CONFIRMED gaps by severity:** **4 High** · **8 Medium** · **10 Low**

---

## 2. Confirmed Findings

| # | Title | Sev | Evidence (file:line) | Impact | Recommended fix | Effort |
|---|-------|-----|----------------------|--------|-----------------|--------|
| 1 | Email verification (`isVerified`) never enforced at authentication | **High** | `config/packages/security.yaml` (no `user_checker`; access_control 43-48); `src/Entity/User.php:40,174-181`; `src/Controller/RegistrationController.php:63` auto-login before verification | Anyone who registers (even with an email they do not own) gets a full `IS_AUTHENTICATED_FULLY` session and can create cases / view stored CNP + debtor data. Verification is cosmetic. | Add `App\Security\UserChecker` **or** a `kernel.request` listener enforcing `isVerified()` on `/case`,`/dashboard`,`/profile`,`/subscription`, allowlisting `app_check_email`/`app_verify_email`/`app_resend_verification`/`app_logout`; redirect unverified users to `app_check_email`. Do **not** blanket-throw in `checkPostAuth` (breaks the resend-email flow). | **M** |
| 2 | `trusted_proxies` set only under `when@dev`; prod has no equivalent | **High** | `config/packages/framework.yaml:11-18` (dev-only); no `TRUSTED_PROXIES` env; `docker/nginx/default.conf` has no `real_ip`; `RegistrationController.php:40`, `ResetPasswordController.php:37` key limiters on `getClientIp()` | Behind Coolify/nginx, `getClientIp()` returns the ingress IP for everyone. The `register` (3/h) and `forgot_password` (3/h) limiters collapse into one global bucket → **self-DoS of signup + password-reset funnels** after 3 total uses/hour. Also degrades `login_throttling` and `payment_webhook` per-IP keying, and prevents HTTPS detection (see #5). | Add non-dev `framework.trusted_proxies: '%env(TRUSTED_PROXIES)%'` + `trusted_headers`; set `TRUSTED_PROXIES` to the Coolify ingress CIDR in prod env. Keep the dev block. | **S** |
| 3 | No security response headers anywhere (HSTS, CSP, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy) | **High** | `docker/nginx/default.conf:1-30` (no `add_header`); no `kernel.response` listener in `src/`; no `nelmio/security` in `composer.lock`; `templates/base.html.twig` no meta | No HSTS (TLS-strip → session-cookie interception of CNP data), no frame-ancestors (clickjacking of `/admin` + `/case`), no nosniff (MIME-sniffing of uploaded docs), no CSP to blunt XSS. | Add a `kernel.response` listener or NelmioSecurityBundle (preferred over nginx so it survives infra changes): HSTS `max-age=31536000; includeSubDomains`, `X-Frame-Options: DENY` / CSP `frame-ancestors 'none'`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, Permissions-Policy, and a CSP tuned for Asset Mapper/Stimulus/Mercure. If via nginx, use `always`. | **M** |
| 4 | Password-reset POST endpoints have no CSRF protection | **High** | `templates/reset_password/reset.html.twig:10`, `request.html.twig:11` (bare `<form method="post">`, no token); `src/Controller/ResetPasswordController.php:114-142` reads raw `getPayload()`, `setPassword()`+flush with no `isCsrfTokenValid()` | A victim holding an active reset token in-session can be lured to an attacker page that auto-submits a password of the attacker's choosing → **account takeover**. This is the only user-facing state-changing flow lacking the `isCsrfTokenValid` pattern used everywhere else. | Convert both forms to Symfony `FormType` (auto-CSRF) **or** add `csrf_token('reset_password')` + `isCsrfTokenValid()`. Reuse the FormType to centralize length/strength rules (#6). | **M** |
| 5 | `cookie_secure: auto` undermined in prod (Secure flag may never be set) | **Med** | `framework.yaml:6` `session: true` only; `cookie_secure: auto` (runtime); no prod `trusted_proxies` (#2); `docker/nginx/default.conf:2` plain HTTP, no `fastcgi_param HTTPS` | Behind TLS-terminating ingress, `Request::isSecure()` is false → session + CSRF cookies emitted **without Secure**, interceptable on a plaintext downgrade; can also break the stateless same-origin CSRF check. | Fix #2 (trusted_proxies), or set `cookie_secure: true` explicitly. Pair with HSTS (#3). | **S** |
| 6 | Weak password policy (min 6, no breach/complexity check) at all 3 entry points | **Med** | `RegistrationFormType.php:47`, `ChangePasswordType.php:32` (`Length(min:6)`); `ResetPasswordController.php:116` (`mb_strlen<6`); no `NotCompromisedPassword`/`PasswordStrength` anywhere | 6-char floor guarding CNP/case data is weak against dictionary/credential-stuffing, especially with only a 5/min throttle. NIST 800-63B → min 8 + breach screen. | Raise min to 8-12, add `NotCompromisedPassword()` (+ optionally `PasswordStrength()`) on registration + change forms; enforce server-side in reset via shared FormType. Keep RO/EN keys in lockstep. | **S** |
| 7 | Session cookie flags / idle-absolute timeout not pinned | **Med** | `framework.yaml:6` only `session: true`; no `cookie_secure`/`cookie_samesite`/`cookie_lifetime`/`gc_maxlifetime` (test-only block at 20-24) | Session persists until browser close with only PHP's probabilistic `gc_maxlifetime`; no reliable idle or absolute cap → long hijack window on shared office machines. `httponly:true` + `samesite:lax` defaults are OK but unpinned. | Add explicit `session: { cookie_secure: true, cookie_httponly: true, cookie_samesite: lax, cookie_lifetime: <n>, gc_maxlifetime: <n> }`; consider an absolute-timeout check (login timestamp in session). | **S** |
| 8 | Password change/reset does not proactively invalidate other sessions | **Med** | `ProfileController.php:70-72`, `ResetPasswordController.php:134-137` (setPassword+flush only); relies on lazy `User::__serialize` crc32c (`User.php:158-167`) | After a reset (the exact "account suspected compromised" flow), other devices are only deauthenticated **lazily on their next request**. An attacker's open session stays live until it acts again. Silent; no "signed out other devices" UX. Would fail entirely if the token/provider is ever changed. | Make revocation explicit: bump a `passwordVersion`/`securityStamp` compared in the token, or purge the user's other sessions server-side; add user-facing confirmation. | **M** |
| 9 | CNP (Romanian national ID) stored in plaintext on the user row | **Med** | `src/Entity/User.php:51-52` (`Column(length:13)`, plain get/set); `migrations/Version20260508102445.php:38` `cnp VARCHAR(13)`; `PiiMasker` used elsewhere but not here | Special-category-adjacent PII (OUG 97/2005, GDPR). DB/backup leak or over-broad query exposes it directly; inconsistent with the app's own data-minimization posture. | Encrypt at rest (Doctrine encrypted string / envelope encryption); ensure CNP never hits logs. If natural-person CNP billing is not actually offered (`hasCompleteFiscalData`, `User.php:384-392`), consider not storing it on `User`. | **M** |
| 10 | Real `APP_SECRET` committed to the repo | **Med** | `.env:19` `APP_SECRET=dcaced…4d4c5` (git-tracked, also `.env.dev:3`); `framework.yaml:3` `secret: '%env(APP_SECRET)%'`; `.gitignore` only excludes `.env.local*` | `APP_SECRET` keys CSRF token signing, signed verify-email/reset URIs (HMAC), and cookie/secret derivation. If reused in prod, repo-readers can forge CSRF tokens + signed links. | Treat committed value as compromised; generate a fresh prod secret via untracked env / Symfony secrets vault; keep `.env` values as obvious dev placeholders. Also rotate the placeholder `MERCURE_JWT_SECRET` (`.env:62`). | **S** |
| 11 | No deny-by-default `access_control` catch-all | **Med** | `security.yaml:39-48` explicit prefixes, no trailing `{ path: ^/, roles: IS_AUTHENTICATED_FULLY }`; `/creditors`,`/notifications`,`/invoices` protected only by class `#[IsGranted]` | Firewall default posture is **public** (fail-open). Every un-prefixed sensitive route depends on a dev remembering `#[IsGranted]`. No live exposure today, but one forgotten attribute on a future `/reports`/`/export` silently leaks CNP/case data. | Add a final `{ path: ^/, roles: IS_AUTHENTICATED_FULLY }` after enumerating genuinely public routes (`/`,`/login`,`/register*`,`/forgot-password*`,`/reset-password*`,`/verify/email`,`/switch-locale`,`/webhook`) as explicit `PUBLIC_ACCESS`. | **S** |
| 12 | Registration reveals whether an email already exists | **Med** | `User.php:18` `#[UniqueEntity(...email_already_exists)]`; `RegistrationController.php:52-79` re-renders form with the "already exists" error | Attacker confirms registered lawyer emails → target list for credential-stuffing/phishing. Only partially blunted by the 3/h limit (itself weakened by #2). | Return the same "check your email" outcome for existing emails and instead email the existing account a "someone tried to register" notice; keep the response indistinguishable from the success path. | **M** |
| 13 | Forgot-password request form has no CSRF token | **Low** | `ResetPasswordController.php:43` raw `getPayload()`; `templates/reset_password/request.html.twig:11` bare form | Cross-site-forced reset-email dispatch + consumption of the target's 3/h bucket. Spam/recon. | Add CSRF token + validate (or move to FormType). | **S** |
| 14 | Forgot-password timing side-channel (synchronous email send) | **Low** | `config/packages/messenger.yaml` routes `SendEmailMessage` → sync; `ResetPasswordController.php:48-61` sends inline only when user exists; both branches redirect identically (72) | Response body is safe, but the existing-user branch is measurably slower (token gen + SMTP) → timing enumeration of registered lawyer emails. | Route `SendEmailMessage` to the async transport, or do equivalent dummy work on the not-found branch. | **S** |
| 15 | Logout is a CSRF-unprotected GET link | **Low** | `security.yaml:26-27` logout has no `enable_csrf`; GET anchors at `_topbar.html.twig:122`, `_sidebar.html.twig:160`, `login.html.twig:41` | `<img src="/logout">` force-logs-out a lawyer mid-session. Nuisance/DoS. | Set `enable_csrf: true` on the firewall logout key; switch triggers to POST forms with `csrf_token('logout')` (id already registered). | **S** |
| 16 | Login brute-force throttling is generous with no account lockout | **Low** | `security.yaml:23-25` `max_attempts:5 / '1 minute'`; no per-account limiter, CAPTCHA, or backoff in `src/` | ~300 guesses/hour per key; IP-rotating attackers largely bypass per-IP keying (worsened by #2). Slows but never locks. Amplified by the 6-char floor (#6). | Add a second per-username hourly/daily limiter with backoff, or temporary account lock + owner notification; alert via `AuditLogService`. | **M** |
| 17 | Reset flow duplicates ad-hoc validation, no max-length guard | **Low** | `ResetPasswordController.php:114-137` hand-rolled `mb_strlen<6` + match, no `max` cap (reg/change cap at 4096) | Divergent validation drifts from policy (already true for #6); unbounded string to argon2 is a minor DoS consideration. | Replace manual checks with the shared password FormType. | **S** |
| 18 | `User::__toString()` leaks email into logs/admin labels | **Low** | `User.php:442-445` returns `getFullName() ?: $this->email`; used by EasyAdmin labels + string interpolation | Email PII can surface in admin dropdowns, traces, logs. | Return a non-PII label (name/id); avoid logging `User` via string cast. | **S** |
| 19 | Inconsistent authorization mechanism / role level | **Low** | `security.yaml:44-48` firewall gating vs class `#[IsGranted]` on `NotificationsController:16`/`InvoiceListController:16`/`CreditorLibraryController:25`; `ROLE_USER` on `CaseOverviewController:17`/`CaseWizardController:76` vs `IS_AUTHENTICATED_FULLY` peers | Two overlapping mechanisms at different strengths make coverage hard to reason about; `ROLE_USER` is weaker (only safe because `^/case` forces full auth and no remember_me). | Standardize on `IS_AUTHENTICATED_FULLY`, prefer firewall access_control as primary gate (with #11), keep `#[IsGranted]` as defense-in-depth. | **S** |
| 20 | Session storage left to PHP native file default in prod | **Low** | `framework.yaml` no `handler_id`/`save_path`; only `when@test` overrides | Fine at single-node, but stores security tokens as files on the app container and blocks clean cross-node invalidation (weakens any future "log out other devices"). | Define an explicit store (e.g. Redis via `handler_id`) for prod. | **M** |
| 21 | Soft-deleted `User` has no auth-time enforcement | **Low** *(downgraded)* | `User.php:91,396-409` `deletedAt`/`isDeleted()`; no `UserChecker`, provider has no deleted filter. **Adversarial: currently unreachable**: `setDeletedAt()` never called on `User` in `src/`; `UserCrudController` disables `Action::DELETE` | Latent A01: if a "deactivate lawyer" feature ships, off-boarded accounts would keep full access. Not exploitable today (no path sets the field). | Either remove the dead field until needed, **or** add the isDeleted check to the same UserChecker as #1 and wire an admin deactivate action. Hardening item, not a blocker. | **S** |

---

## 3. Suggested Hardening Plan

### Phase 0: Config quick wins (hours; do first)
High value / near-zero risk, mostly YAML + env:
- **Prod trusted_proxies** (#2, #5): add non-dev `trusted_proxies`/`trusted_headers` in `framework.yaml` + `TRUSTED_PROXIES` env in Coolify. Unblocks rate limiters **and** the Secure cookie flag in one change.
- **Session cookie hardening** (#7, #5): explicit `framework.session` block with `cookie_secure: true`, `cookie_httponly: true`, `cookie_samesite: lax`, bounded `cookie_lifetime` + `gc_maxlifetime`.
- **Deny-by-default access_control** (#11): trailing `^/` catch-all + explicit public routes in `security.yaml`.
- **Rotate/segregate `APP_SECRET`** (#10) + `MERCURE_JWT_SECRET`: fresh prod secret via untracked env; downgrade committed `.env` values to placeholders.
- **Async email** (#14): route `SendEmailMessage` to async transport in `messenger.yaml` (closes the timing channel and speeds up requests).

### Phase 1: Security response headers (M)
- Add NelmioSecurityBundle or a `kernel.response` listener (#3): HSTS, frame-ancestors/X-Frame-Options, nosniff, Referrer-Policy, Permissions-Policy, CSP tuned for Asset Mapper/Stimulus/Mercure. App-level so it survives nginx/infra changes.

### Phase 2: Email-verification enforcement (M)
- Implement the verification gate (#1) via a `kernel.request` listener or Voter enforcing `isVerified()` on sensitive prefixes, allowlisting the check-email/resend/verify/logout routes. Fold the (currently-dead) `isDeleted()` check into the same `UserChecker` for future-proofing (#21). Close registration enumeration (#12) in the same slice.

### Phase 3: Password flow consolidation + CSRF (M)
- Convert `reset_password/reset` + `request` forms to Symfony `FormType`. This single refactor closes **#4 (reset CSRF)**, **#13 (request CSRF)**, **#17 (ad-hoc validation)** and provides the injection point for **#6 (min length + `NotCompromisedPassword`)** across registration/change/reset uniformly.
- Add logout CSRF + POST (#15) alongside.

### Phase 4: Session revocation + brute-force depth (M)
- Proactive multi-session invalidation on password change/reset via `securityStamp`/`passwordVersion` (#8), with "signed out other devices" UX.
- Per-username hourly limiter with backoff + audit alerting (#16).
- Consider Redis session store (#20) to make revocation clean cross-node.

### Phase 5: Data-at-rest + PII hygiene (M)
- Encrypt CNP at rest or drop the column if unused (#9).
- Non-PII `__toString()` (#18); standardize authz level/mechanism (#19).

**Quick-win call-out:** Phase 0 (all S) plus logout CSRF (#15) and async email (#14) remove the two High config gaps and several Mediums/Lows with minimal code and low regression risk.

---

## 4. Already Correct: Do Not Touch

- **Login CSRF** enabled end-to-end (`security.yaml:19-22` `enable_csrf:true`; `login.html.twig:84` `csrf_token('authenticate')`).
- **Enumeration-safe login**: generic `Invalid credentials`, `hide_user_not_found` at default `true`.
- **No open redirect** post-login (no `_target_path`/`use_referer`/`TargetPathTrait`).
- **Impersonation, remember_me, custom authenticators all absent**. Minimal surface. `switch_user` commented out.
- **Logout invalidates the session** (default `invalidate_session:true`); **session fixation** protected (default `migrate` strategy).
- **Password storage**: `auto` hasher, hash-only column, `eraseCredentials` no-op, `__serialize` excludes raw password (crc32c) → session invalidation on credential change; **rehash-on-login** via `UserRepository` `PasswordUpgraderInterface`.
- **Change-password re-authenticates** with the current password (`ProfileController.php:62-67`).
- **Reset tokens single-use**, request/check-email responses enumeration-safe in the body; **verify-email** uses signed, expiring, user-bound URLs behind `IS_AUTHENTICATED_FULLY`.
- **Object-level authz**: `CaseVoter`/`FiscalInvoiceVoter` owner-or-admin on every case sub-resource; cross-entity scoping on documents/invoices/creditors verified.
- **`/api/table/{key}`** user-scoped in all four `TableDefinition`s with whitelisted sort/filter fields + escaped LIKE metacharacters.
- **Admin area** behind `^/admin ROLE_ADMIN` including the one custom admin route; no privileged role mass-assignment.
- **IPN webhook** correctly `PUBLIC_ACCESS` + JWT/signature-authenticated (not session/CSRF).
- **`FiscalInvoiceController`** open-redirect allowlist on provider PDF URLs (`TRUSTED_PDF_HOSTS`).

---

## 5. Open Questions / Product Decisions

1. **Unverified login** (#1): is auto-login-before-verification an intentional UX (to render "check your email")? Confirms whether we gate access or block login outright. Recommended: gate access, keep the check-email page reachable.
2. **Idle / absolute session timeout** (#7): what duration for a lawyer audience? Proposed 30-60 min idle + absolute re-auth ceiling; needs product sign-off (impacts daily UX on shared office machines).
3. **Remember-me** (#4 context): confirm the omission is deliberate. If ever added, require Secure+HttpOnly+SameSite signed tokens with rotation.
4. **CSP strictness** (#3): report-only rollout first, or enforce immediately? Asset Mapper/Stimulus/Mercure inline needs (nonce vs `unsafe-inline`) must be decided.
5. **CNP at rest** (#9): is natural-person (CNP) fiscal billing actually offered? If not, drop the column rather than encrypt.
6. **Account lockout policy** (#16): temporary lock + owner email, or throttle-only? Lockout introduces a DoS-by-lockout tradeoff to weigh.
7. **Session store** (#20): commit to Redis now (enables clean multi-session revocation for #8) or stay single-node file sessions?
8. **`TRUSTED_PROXIES` value** (#2): exact Coolify/Traefik ingress CIDR to trust (do not blindly trust `private_ranges` if the docker bridge overlaps untrusted ranges).