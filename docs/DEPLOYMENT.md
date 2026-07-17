# Amanahku — Deployment & Hardening Checklist

Target: **staging**, then production. Work top to bottom; do not skip the security gate.

---

## 1. Environment

- [ ] Copy `.env.staging.example` → `.env` on the host and fill real secrets.
- [ ] `php artisan key:generate` (unless APP_KEY already provisioned).
- [ ] `APP_ENV=staging` (or `production`) and **`APP_DEBUG=false`**.
- [ ] `APP_URL` set to the real HTTPS URL.
- [ ] DB credentials point at the staging database (not local).

## 2. Build & install

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan config:cache route:cache view:cache
```

- [ ] Assets built (`public/build/manifest.json` present).
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

## 4. Queue worker (required for email)

Invite + verification emails are queued. Without a worker they never send.

```bash
php artisan queue:work --tries=3 --max-time=3600
```

- [ ] Worker supervised by systemd or Supervisor (auto-restart on crash/deploy).
- [ ] Test an invite → confirm the email arrives via real SMTP.
- [ ] Scheduler running (`php artisan schedule:work` or a cron `* * * * * php artisan schedule:run`)
      + queue worker up, so the weekly HR digest (`digest:weekly`, Mon 08:00) sends via real SMTP.

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

- [ ] Scheduler running if used: `php artisan schedule:work` (or cron `schedule:run`).
- [ ] Backups: nightly DB dump + APP_KEY stored in a separate secret store.
- [ ] Log level `warning` or higher in prod; centralised log shipping if available.

---

### Rollback

```bash
# revert to previous release dir / git tag, then:
php artisan migrate:rollback --step=1 --force   # only if the bad deploy migrated
php artisan config:cache route:cache
```
Keep the previous release available for an instant symlink swap.
