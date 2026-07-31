# Amanahku — Environments & Deploy (local · staging)

> **History:** rewritten 2026-07-20. The original version described the previous
> maintainer's setup (Windows/Laragon, PM2 on :8888, a separate PRIVATE deploy repo
> `amanahku-app` with `staging`/`main` branches). That topology was retired on
> **2026-07-17**, when this public repo became canonical and the server was switched
> to track it. The old version is preserved in git history (`git log -p docs/ENVIRONMENTS.md`,
> import commit `be23ac6`).

---

## 1. Topology (current, verified 2026-07-17)

| Env | Branch | Host | URL | Notes |
|-----|--------|------|-----|-------|
| **local** | `dev` (working branch) | [Lerd](https://github.com/lerd-dev/lerd) site `amanahku.test` (Podman: PHP 8.5 FPM, MySQL, Redis, Mailpit) | **http://localhost:9100** (not `amanahku.test` — see note) | `.env` wired to lerd containers (`DB_HOST=lerd-mysql` etc.) |

> **Local access — use `http://localhost:9100`, not `amanahku.test`.** The nginx container
> serves on that port regardless of DNS. `.test` name resolution is unreliable on the dev
> machine: systemd-resolved selects the router as wlan0's DNS server, which answers `.test`
> with authoritative NXDOMAIN and never fails over to lerd-dns (`127.0.0.1:5300`).
> `lerd dns:repair` re-adds the same shared-link config and cannot fix it, and the nsswitch
> order (`resolve [!UNAVAIL=return]` before `files`) blocks an `/etc/hosts` workaround.
> The port is registered as the `laravel-app` entry in `.claude/launch.json`. `APP_URL` is
> still `http://amanahku.test`, so APP_URL-derived links (Mailpit emails, etc.) keep emitting
> the `.test` host.
| **staging** | `main` | Hostinger Business (shared-style, hPanel), `ssh amanahku`, `~/domains/amanahku-staging.myappsonline.net/public_html` | https://amanahku-staging.myappsonline.net | `APP_ENV=staging`. The **only deployed instance** |
| **production** | — | **Does not exist yet.** Planned: fold the legacy `unijayahr`/`petron`/`shell` PHP sites in as tenants | — | Cutover not scheduled; see DEPLOYMENT.md security gate first |

- **One repo:** `github.com/shahrilunijaya-source/AmanahKu` (public, canonical since 2026-07-17).
  The old private `amanahku-app` repo is retired — do not push to it. The server keeps a local
  `staging` branch pinned at `f2cf804` purely as a rollback pointer.
- **Sanitization rule for this public repo:** demo emails use `@unijaya.example`, never `@unijaya.com`.
- `APP_DEBUG=false` on staging. **Never run `php artisan key:generate` on the server** —
  `APP_KEY` encrypts NRICs and sessions; staging already holds encrypted rows.

## 2. Release flow

```
dev branch ── merge → main ── push (from your own authenticated machine)
                                │
        ssh amanahku → cd ~/domains/amanahku-staging.myappsonline.net/public_html
                                │
                     git pull && bash deploy.sh
```

- Deploy is a **manual pull over SSH** (anonymous HTTPS remote, no deploy key). There is
  no auto-deploy webhook wired up.
- **Never run `git clean` on the server** — the untracked `.htaccess` there must survive.
- `deploy.sh` auto-detects the tier from `APP_ENV` in the host `.env` and refuses to run
  against `APP_ENV=local`. Sequence: maintenance mode → composer install → migrate `--force`
  → storage symlink (`ln`, because `exec()` is disabled on the host) → skip asset build if no
  Node → config/route/view caches → queue restart → `artisan up`.

## 3. Assets — build locally, commit `public/build`

Hostinger shared SSH has **no Node**, so `deploy.sh` skips the asset build and serves the
committed bundle. Before pushing a release that changed CSS/JS:

```fish
bun run build          # or npm run build; package-lock.json is the shared lockfile
git add -f public/build
git commit -m "build: compile assets"
```

`public/build` is committed **on purpose** — do not "clean up" that gitignore exception.

## 4. Scheduler + queue (mandatory on the host)

A cron running the scheduler **every minute is mandatory** — leave accrual/carry-forward,
the weekly HR digest, timesheet reminders, and staff auto-archive all depend on it and fail
silently without it. On Hostinger shared there is no `crontab` over SSH; cron lives only in
**hPanel → Advanced → Cron Jobs** (so its state cannot be verified from the shell):

```
* * * * *   cd ~/domains/amanahku-staging.myappsonline.net/public_html && php artisan schedule:run >> /dev/null 2>&1
*/5 * * * * cd ~/domains/amanahku-staging.myappsonline.net/public_html && php artisan queue:work --stop-when-empty --max-time=280 >> /dev/null 2>&1
```

(Long-running queue workers are not possible on this shared plan; the cron drain pattern
above is the substitute. Invite/verification emails are queued — without it they never send.)

## 5. Rollback

```bash
# preferred: revert on main and redeploy
git checkout main && git revert <bad-sha> && git push
# on the server: git pull && bash deploy.sh

# last resort: the server's local `staging` branch still points at the pre-switch
# tree (f2cf804). Full-history bundles exist at ~/amanahku-server-backup-2026-07-17.bundle
# (server) and in the local Projects/Unijaya directory.
```

Keep a `mysqldump` before any risky deploy — migrations are forward-only.

## 6. Env template map

| Committed template | Copied to `.env` on | Key differences |
|--------------------|---------------------|-----------------|
| `.env.example` | local (then rewired for lerd; pre-lerd backup at `.env.before_lerd`) | `APP_ENV=local`, `APP_DEBUG=true`, mail=log |
| `.env.staging.example` | staging host | `APP_ENV=staging`, real SMTP, secure cookies |
| `.env.production.example` | future production host | `APP_ENV=production`, `LOG_LEVEL=error` |
