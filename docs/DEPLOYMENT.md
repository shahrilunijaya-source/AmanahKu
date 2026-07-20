# Amanahku — Deployment & Hardening Checklist

Target: **staging**, then production. Work top to bottom; do not skip the security gate.

> **History note (2026-07-20):** commands below corrected for the current single-repo /
> Hostinger-shared reality (see `ENVIRONMENTS.md`). Staging is already deployed at
> `amanahku-staging.myappsonline.net`; §1–§3 are for a **fresh host** (i.e. the future
> production cutover), not for the existing staging box.

---

## 1. Environment (fresh host only)

- [ ] Copy `.env.staging.example` (or `.env.production.example`) → `.env` on the host and fill real secrets.
- [ ] `php artisan key:generate` — **FRESH INSTALLS ONLY. Never run this on a host that
      already has data.** `APP_KEY` encrypts NRICs and sessions; regenerating it makes the
      encrypted columns unrecoverable and logs everyone out. Staging already has a key —
      back it up, don't rotate it.
- [ ] `APP_ENV=staging` (or `production`) and **`APP_DEBUG=false`**.
- [ ] `APP_URL` set to the real HTTPS URL.
- [ ] DB credentials point at the staging database (not local).

## 2. Build & install

`bash deploy.sh` does all of this (composer install, migrate `--force`, caches, queue
restart), auto-detecting the tier from `APP_ENV`. Assets are the exception: the Hostinger
host has **no Node**, so build them **locally** and commit `public/build` before pushing
(see `ENVIRONMENTS.md` §3).

- [ ] Assets built and committed (`public/build/manifest.json` present in the repo).
- [ ] `git pull && bash deploy.sh` ran clean on the host.
- [ ] Migrations applied with `--force` (non-interactive).
- [ ] Config/route/view caches warmed.

## 3. First super-admin

The seeder's demo super-admin (`superadmin@amanahku.com`) is for local only. On a
real environment, create one explicitly and do **not** run the demo seeder:

```bash
php artisan tinker --execute="\$u=App\Models\User::create(['name'=>'Platform Admin','email'=>'admin@yourco.com','password'=>bcrypt(env('SEED_ADMIN_PW'))]); \$u->forceFill(['is_super_admin'=>true,'email_verified_at'=>now()])->save();"
```

- [ ] Real super-admin created, demo seeder NOT run on staging/prod.
- [ ] Sign in → provision the first real company at `/admin/companies/new`.

## 4. Queue worker + scheduler (required for email; cron-only on this host)

Invite + verification emails are queued. Without a worker they never send. Hostinger shared
allows **no long-running workers and no SSH crontab** — both jobs live in
**hPanel → Advanced → Cron Jobs** (cron state cannot be checked over SSH):

```
* * * * *   … && php artisan schedule:run          # scheduler — MANDATORY (accrual, digest, archiving fail silently without it)
*/5 * * * * … && php artisan queue:work --stop-when-empty --max-time=280
```

- [ ] Both cron jobs present in hPanel.
- [ ] Test an invite → confirm the email arrives via real SMTP.
- [ ] **Do not trust `php artisan about`'s "Mail deliverability: OK"** — that check cannot
      trip on staging and never trips on a misconfigured SMTP. Verify by sending a real
      email and reading `storage/logs/laravel.log`.

## 5. Security gate (do not deploy public without these)

- [ ] TLS terminating in front of the app; HTTP redirects to HTTPS.
- [ ] `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true`.
- [ ] If behind a proxy/LB: `TrustProxies` configured so `$request->secure()` is true
      (HSTS + secure cookies depend on it).
- [ ] Security headers present on responses (verify in browser devtools):
      `Content-Security-Policy`, `X-Frame-Options: DENY`, `X-Content-Type-Options`,
      `Referrer-Policy`, `Permissions-Policy`, and `Strict-Transport-Security` over HTTPS.
- [ ] Rate limits active: login (5/min), 2FA (5/min), passkeys (10/min), register (5/min).
- [ ] NRIC encryption key (APP_KEY) backed up securely — losing it makes encrypted
      payroll NRICs unrecoverable.
- [ ] `composer audit` clean.

> **CSP note:** the current policy allows `'unsafe-inline'` for scripts/styles because
> the UI still has inline styles + a few inline scripts. Nonce-based hardening is the
> next step (tracked in `docs/ISSUES.md`). `frame-ancestors`, `base-uri`, `object-src`
> and `form-action` are already enforced.

## 6. Smoke test on staging

- [ ] `php artisan test` green locally before shipping.
- [ ] Log in as demo/HR → open several modules, confirm no 500s.
- [ ] Register a new account → lands on "no workspace" + verification email sent.
- [ ] Provision a company as super-admin → first admin receives credentials email.
- [ ] Approval round-trip (leave: apply → approve) works.
- [ ] Payroll run → bank file + statutory report download.

## 7. Operational

- [ ] Scheduler cron running — **not optional** (see §4).
- [ ] Backups: nightly DB dump + APP_KEY stored in a separate secret store.
- [ ] Log level `warning` or higher in prod; centralised log shipping if available.

---

### Rollback

```bash
# git revert on main, then on the host: git pull && bash deploy.sh   (see ENVIRONMENTS.md §5)
php artisan migrate:rollback --step=1 --force   # only if the bad deploy migrated
```
There is no release-dir/symlink setup on this host — rollback is a git revert plus redeploy.
Keep a `mysqldump` from before the deploy.
