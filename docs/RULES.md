# Amanahku — Rules

Invariants. Two kinds: **domain rules** the application enforces, and **operational rules**
the operator must not break. Breaking either loses data or money.

---

# Part 1 — Domain rules

## Approval chain

Leave, claims and overtime all follow the same two-step chain:

```
employee submits  →  manager VERIFIES  →  management / director / HR gives FINAL APPROVAL
```

- The requester's **line manager verifies only**. Verifying is not approving.
- Final approval comes from `Permissions::FINAL_APPROVAL_ROLES` — `management`, `director`,
  `hr`. HR sits directly under the directors, so those three together are the last gate.
- `director` is a strict super-set of `management`. Never assign it expecting *less* access.
- Approval actions are role-gated **and** tenant-asserted, and the balance decrement runs
  inside a database transaction.
- Every approve and reject writes an `AuditLog` row.

## Leave

- Accrual is monthly: a leave type with `monthly_accrual_days > 0` grants that many days to
  every active employee, on the 1st.
- Accrual is **capped** at the leave type's `max_balance` and is **idempotent within a
  month** — running it twice does not double-grant.
- Mid-year joiners are prorated by omission: no grant for a month that ended before they
  joined. There is no partial-month arithmetic.
- Carry-forward runs once, on 1 January.

## Payroll

Payroll is shipped **off** for the current scope. These rules apply if it is switched on.

- Run state machine: `draft → approved → finalized`. Payslips are editable only while
  `draft` or `approved`.
- On finalize: payslips lock, each employee is notified, and any claims pulled into the run
  are marked `paid`.
- Finalizing without approval is allowed by default — a deliberate single-operator shortcut.
  Set `payroll.four_eyes` to require approval first.
- **Statutory rates are editable per company and must be verified against the official
  KWSP / PERKESO tables before a real run.** The app warns in-app. Seeded values (confirmed
  2026-06-24): EPF employee 11%, employer 13% at wage ≤ RM5,000 or 12% above; SOCSO employer
  1.75% / employee 0.5%; EIS 0.2% each; SOCSO and EIS wage ceiling RM6,000.
- SOCSO and EIS use a **flat percentage on the capped wage**, not the PERKESO stepped
  bracket table. Close enough for estimates, not exact. See [ROADMAP.md](ROADMAP.md) I-013.
- PCB / MTD is **manual entry per payslip**. No auto-calculation. Deliberate — the LHDN
  progressive table plus reliefs changes yearly and is error-prone to encode.
- Calculation constants (`PayrollCalculator`): 26 working days per month, 8 hours per day,
  overtime at 1.5× the ordinary rate of pay. Overtime = `hours × (basic / 26 / 8) × 1.5`;
  unpaid deduction = `unpaid_days × (basic / 26)`. Statutory is computed on gross earnings
  after the unpaid deduction.
- Payroll administration is `management` + `hr` only. **Managers do not get payroll access** —
  compensation is sensitive. Every employee sees their own payslips read-only.

## Data protection

- **NRIC is encrypted at rest** — `'nric' => 'encrypted'` cast on both `Employee` and
  `SalaryStructure`. Stored as ciphertext, decrypted only on read.
- Statutory and bank exports are HR/management-only, tenant-asserted, and finalized-runs-only.
- Medical claims are capped per employee per calendar year by the `claims.medical_cap`
  setting (default RM500).

## Tenant safety

- **Every query is scoped to the active tenant.** No exceptions.
- **A route-model-bound model is not tenant-safe.** `SubstituteBindings` runs before
  `ResolveTenant`, so the global scope is not active at bind time and a bound `{model}`
  resolves across all tenants. Assert ownership explicitly in the controller:
  `abort_unless($m->tenant_id === CurrentTenant::id(), 403)`. Forgetting this **fails open**.
- **Authorization gates the data load, not the render.** Never load data a role may not see
  and hide it in the template. Return an empty collection instead.
- This repo is **public**. Demo emails use `@unijaya.example`, never `@unijaya.com`. No
  secrets in tracked files — they live in the gitignored `docs/vault/`.

## Code

- Every change ships with tests: happy path, ACL 403s, validation, and tenant isolation.
- Screens handle empty, populated, validation-error and success-flash states.
- `php artisan test` green, `vendor/bin/pint` clean, and `bun run build` clean before commit.
- Tests run with **every module on** (`Tests\TestCase::setUp` unlocks each `Features::OFF`
  key), so parked modules keep their coverage. Tests asserting the shipped default call
  `useShippedModuleDefaults()`.

---

# Part 2 — Operational rules

## Topology

| Env | Branch | Host | URL |
|-----|--------|------|-----|
| **local** | `dev` | [Lerd](https://github.com/lerd-env/lerd) (Podman: PHP 8.5 FPM, MySQL, Redis, Mailpit) | **http://localhost:9100** |
| **staging** | `main` | Hostinger Business, `ssh amanahku`, `~/domains/amanahku-staging.myappsonline.net/public_html` | https://amanahku-staging.myappsonline.net |
| **production** | GitLab `main` | **Devops-owned. No developer shell.** | https://amanahku.unijaya.com |

Local access is `http://localhost:9100`, **not** `amanahku.test`. `.test` resolution is
unreliable on the dev machine: systemd-resolved picks the router as wlan0's DNS server,
which NXDOMAINs `.test` and never fails over to lerd-dns. `lerd dns:repair` cannot win that
race and nsswitch blocks an `/etc/hosts` workaround. `APP_URL` is still `http://amanahku.test`,
so APP_URL-derived links (Mailpit mail) keep emitting the `.test` host.

`github.com/shahrilunijaya-source/AmanahKu` is public and canonical for development since
2026-07-17. The old private `amanahku-app` repo is retired — do not push to it.

A second repo now exists: `gitlab.com/developer-unijaya/claudecode/amanahku` (the `gitlab`
remote), which the devops team releases production from. It is **not a mirror**. Its `main`
is a separate lineage — a URSB governance template plus one squashed
`feat: import Amanahku application onto the governed baseline` commit — so it shares no
ancestor with GitHub `main`, and 260-odd development commits do not appear in it. Every
release so far reached it as a fresh file upload, not a push. Unifying the two histories is
open work; see [ROADMAP.md](ROADMAP.md).

## The five rules that lose data

1. **Never run `php artisan key:generate` on a host that already has data.** `APP_KEY`
   encrypts NRICs and sessions. Rotating it makes the encrypted payroll columns permanently
   unrecoverable and logs every user out. Fresh installs only. Back the key up in a secret
   store separate from the database.
2. **Never run `git clean` on the server.** An untracked `.htaccess` lives there and must
   survive. `git pull` leaves it alone; `git clean` deletes it.
3. **Take a `mysqldump` before any deploy that migrates.** Migrations are forward-only and
   run automatically under `--force`.
4. **`public/build` is committed on purpose.** Do not "clean up" that gitignore exception.
   The host builds nothing.
5. **Run `php artisan view:cache` before `bun run build`.** Tailwind scans the compiled Blade
   cache (`@source` in `resources/css/app.css`), so building against a partial cache silently
   drops utilities from the stylesheet. This is how staging lost `.animate-spin` and its
   focus-visible rings. CI enforces the CSS match in the `Committed assets match sources` job;
   JS is out of scope there because Rolldown emits different chunk bytes per machine.

## Release flow

```
dev ── merge → main ── push (from your own authenticated machine)
                          │
        ┌─────────────────┴─────────────────────────┐
        │ staging (yours)                            │ production (devops)
   ssh amanahku → …/public_html                 GitHub main → GitLab main
        │                                            │
   git pull && bash deploy.sh                   released by devops
```

Staging deploy is a manual pull over SSH. No webhook, no deploy key. `deploy.sh` auto-detects the
tier from `APP_ENV` and refuses to run against `APP_ENV=local`. It runs: maintenance mode →
`composer install` → `migrate --force` → storage symlink (via `ln`, because `exec()` is
disabled on the host) → skip asset build → config/route/view caches → queue restart →
`artisan up`.

**Look before you pull.** Check the server's `git status -sb` first (read-only).

Assets are built locally:

```fish
php artisan view:cache
bun run build
git add -f public/build && git commit -m "build: compile assets"
```

The host *does* have Node at `/opt/alt/alt-nodejs{18,20,22,24}/root/usr/bin/node`, just not
on `PATH`. It is still useless: Vite 8 bundles with Rolldown, which asks rayon for one thread
per core (the box reports 64) and the account's thread cap refuses them —
`ThreadPoolBuildError … WouldBlock`. `RAYON_NUM_THREADS=4` does make it build, but a build
that dies mid-deploy strands the app in maintenance mode. The host stays build-free on purpose.

## Production handoff

Production went live on `amanahku.unijaya.com` on 2026-07-31, provisioned by the devops
team. A developer's access to it is an app-level super-admin login and nothing more: no SSH,
no database, no log files, no cron console. Every operational rule below still applies to
prod, but **devops is the one who applies it** — you can only ask, and you cannot verify the
answer yourself.

What follows from that:

- Nothing in this document has been confirmed *on the prod host*. The five data-losing rules,
  the cron jobs, the mail configuration and the security gate are all written from the
  staging host. Treat them as the handover request to devops, not as a description of what
  is running.
- Prod carries one seeded super-admin account. Its password lives with devops, never in this
  repo, and never in a tracked file.
- A prod bug you cannot reproduce on staging is a devops ticket, not a debugging session.
  You have no log to read.

## Cron — mandatory

Hostinger shared allows no long-running workers and no SSH crontab. Both jobs live in
**hPanel → Advanced → Cron Jobs**, so their state cannot be verified from the shell. This is
the staging arrangement; the prod host is a different machine and its cron setup is
unverified from here — confirm with devops that both jobs exist, because the same silent
failures apply.

```
* * * * *   cd ~/domains/… && php artisan schedule:run >> /dev/null 2>&1
*/5 * * * * cd ~/domains/… && php artisan queue:work --stop-when-empty --max-time=280 >> /dev/null 2>&1
```

- **Scheduler is not optional.** Leave accrual, carry-forward, the weekly HR digest,
  timesheet reminders, attendance reminders and staff auto-archive all fail silently without it.
- **Queue worker is not optional.** Invite and verification mail is queued. Without the
  drain cron a new user never receives their activation link and has no recovery path.

## Mail

Port 587 (STARTTLS) needs `MAIL_SCHEME=smtp`. Port 465 (implicit TLS) needs
`MAIL_SCHEME=smtps`. **`MAIL_SCHEME=tls` is not valid** — it is the old `MAIL_ENCRYPTION`
value and Symfony Mailer rejects it before attempting any connection. Omitting the key also
works; Symfony infers from the port.

Staging sends through Hostinger Business Email on the dedicated subdomain
`amanahku.myappsonline.net`, mailbox `noreply@amanahku.myappsonline.net`, which keeps
Amanahku's sending reputation separate from the other apps on `myappsonline.net`.

**DKIM needs manual DNS work.** Hostinger writes MX and SPF automatically but not DKIM. Add
all three CNAMEs — `hostingermail-{a,b,c}._domainkey.amanahku` →
`hostingermail-{a,b,c}.dkim.mail.hostinger.com`. `-b` and `-c` look like empty placeholders
but Hostinger uses them for key rotation; omitting them breaks signing at the next rotation.
DMARC TXT at `_dmarc.amanahku` (`v=DMARC1; p=none`).

**Do not trust `php artisan about`'s "Mail deliverability: OK".** That check cannot trip on a
misconfigured SMTP. Verify by sending a real mail and reading the log.

`LOG_CHANNEL=daily` with 14-day retention. Failed jobs surface as a banner on the super-admin
provisioning console — it cannot be mail (mail is what breaks) and cannot be the in-app bell
(that is tenant-scoped; a super-admin is not).

## Security gate — do not deploy public without these

Prod is already public, so this list is now a **verification** list, not a pre-flight one.
None of it has been checked on `amanahku.unijaya.com`. The two you can test from a browser
without any host access are the security headers and the HTTPS redirect; the rest needs
devops.

- [ ] TLS terminating in front of the app; HTTP redirects to HTTPS.
- [ ] `APP_ENV=production`, **`APP_DEBUG=false`**, `APP_URL` set to the real HTTPS URL.
- [ ] `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true`, `SESSION_DOMAIN` set.
- [ ] Behind a proxy: `TrustProxies` configured so `$request->secure()` is true — HSTS and
      secure cookies depend on it.
- [ ] Security headers verified in devtools: `Content-Security-Policy`, `X-Frame-Options: DENY`,
      `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, `Strict-Transport-Security`.
- [ ] Rate limits active: login 5/min, 2FA 5/min, passkeys 10/min, register 5/min.
- [ ] Dedicated MySQL user, not `root`, with limited grants.
- [ ] `APP_KEY` backed up in a secret store separate from the database.
- [ ] `composer audit` clean.
- [ ] Real super-admin created; the demo seeder **not** run.

CSP still allows `'unsafe-inline'` for scripts and styles because the UI has inline styles
and a few inline scripts. `frame-ancestors`, `base-uri`, `object-src` and `form-action` are
already enforced. Nonce hardening is tracked in [ROADMAP.md](ROADMAP.md).

## Smoke test before calling a release done

- [ ] `php artisan test` green locally.
- [ ] Log in per role, open several modules, no 500s.
- [ ] Register → lands on "no workspace", verification mail **arrives in a real inbox**.
- [ ] Provision a company as super-admin → first admin receives the credentials mail.
- [ ] Approval round trip: apply leave → manager verifies → management approves.

## Rollback

```bash
git checkout main && git revert <bad-sha> && git push
# on the server:
git pull && bash deploy.sh
php artisan migrate:rollback --step=1 --force   # only if the bad deploy migrated
```

That is the staging path. A prod rollback is a devops action; your part is the revert commit
on `main` and a clear note of whether the bad deploy migrated.

No release-dir or symlink setup on this host — rollback is a revert plus redeploy. The
server keeps a local `staging` branch pinned at `f2cf804` as a rollback pointer, and
full-history bundles exist at `~/amanahku-server-backup-2026-07-17.bundle`.

## Env template map

| Template | Copied to | Key differences |
|----------|-----------|-----------------|
| `.env.example` | local (then rewired for lerd; pre-lerd backup at `.env.before_lerd`) | `APP_ENV=local`, `APP_DEBUG=true` |
| `.env.staging.example` | staging | `APP_ENV=staging`, real SMTP, secure cookies |
| `.env.production.example` | production (devops holds the real file) | `APP_ENV=production`, `LOG_LEVEL=error` |
