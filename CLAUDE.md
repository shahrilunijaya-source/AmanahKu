# Amanahku

Laravel HR platform. Local dev runs under [Lerd](https://github.com/lerd-env/lerd) (Podman-powered PHP dev environment), with MySQL, Redis, and Mailpit as lerd-managed services.

## Lerd Site

**Reach the app at `http://localhost:9100`**, not `http://amanahku.test`. The nginx
container serves fine on that port, but `.test` DNS is unreliable on this machine
(systemd-resolved picks the router as wlan0's DNS server, which NXDOMAINs `.test` with
no failover to lerd-dns; `lerd dns:repair` cannot win that race, and nsswitch blocks an
`/etc/hosts` workaround). The port is DNS-independent and always works. It is registered
as the `laravel-app` entry in `.claude/launch.json`. Note `.env` still has
`APP_URL=http://amanahku.test`, so APP_URL-derived absolute links (Mailpit emails, etc.)
still emit the `.test` host.

| Site | Access URL | Services | PHP | Node |
|------|-----------|----------|-----|------|
| amanahku (lerd site `amanahku.test`) | http://localhost:9100 | mysql, redis, mailpit | 8.5 FPM | 22 |

**Commands:**
```fish
# open http://localhost:9100 in browser (`lerd open` targets amanahku.test — broken DNS)
lerd status               # overall health (dns, nginx, php-fpm, services)
lerd artisan migrate       # run artisan commands in the container
lerd db:shell               # mysql shell for the amanahku db
lerd logs                    # tail php-fpm/nginx logs
lerd service start mysql      # start a stopped service
```

`.env` is wired to lerd's containers (`DB_HOST=lerd-mysql`, `REDIS_HOST=lerd-redis`, `MAIL_HOST=lerd-mailpit`). Pre-lerd `.env` is backed up at `.env.before_lerd`.

### Dev quick-login (one-click, local only)

To debug behind auth without typing passwords, there is a password-less quick-login route.
The browser (including Claude's browser pane) can sign in as any seeded role by **navigating
to a single URL** — no form, no password:

```
http://localhost:9100/dev/login?email=<account>[&tenant=<slug>]
```

- `GET /dev/login` (route name `dev.login`) calls `Auth::login` by email, then redirects:
  super-admin → provisioning console, `tenant=<slug>` you belong to → that workspace
  dashboard, otherwise → the workspace picker.
- **Local only.** It is `require`d from `routes/web.php` only when `APP_ENV=local`, and the
  route itself `abort_unless(app()->environment('local'), 404)`. Inert on staging/prod.
- Seeded accounts (all password `password`, but the route needs none):
  `superadmin@amanahku.com` (super-admin), `hr@amanahku.test`, `manager@amanahku.test`,
  `management@amanahku.test`, `employee@amanahku.test`. The `unijaya` tenant is the usual
  `tenant` slug.
- Reporting line seeded as employee → manager → management, which is the two-step approval
  chain: the **manager** (`manager@`) *verifies* a request, then **management**
  (`management@`, the only account in `Permissions::MANAGEMENT_TIER`) gives *final approval*.
  Use these three accounts to drive a claim/leave/overtime request through submit → verify →
  approve. No other dev account can approve (hr/employee lack the management-tier role).
- The route file `routes/dev-login.php`, the `dev-login.html` button page, and
  `database/seeders/DevLoginSeeder.php` are **gitignored** (never committed). If the seeded
  accounts are missing on a fresh machine: `lerd artisan db:seed --class=DevLoginSeeder`.

Example — log in as HR onto the unijaya workspace:
```
http://localhost:9100/dev/login?email=hr@amanahku.test&tenant=unijaya
```

## Deploy to staging

Staging: `https://amanahku-staging.myappsonline.net` (Hostinger shared). SSH host alias
`amanahku` → `~/domains/amanahku-staging.myappsonline.net/public_html`, which tracks the
`main` branch of the public GitHub repo. There is no prod host yet.

**The host has no Node**, so assets are built locally and the compiled `public/build` is
committed. Deploy is `git pull && bash deploy.sh` on the server. `deploy.sh` is idempotent
and safe to re-run: maintenance-down, `composer install`, `migrate --force`, skips asset
build (uses committed `public/build`), warms config/route/view caches, restarts the queue,
brings the app back up. View-cache warming is what makes new Blade changes take effect.

Safe sequence (run from local repo root):
```fish
bun run build                                   # rebuild assets if JS/CSS/Blade changed
git add public/build && git commit ...           # commit assets alongside the change
# merge the change into main via PR, then:
ssh amanahku 'cd ~/domains/amanahku-staging.myappsonline.net/public_html && git status -sb'   # LOOK FIRST (read-only)
ssh amanahku 'cd ~/domains/amanahku-staging.myappsonline.net/public_html && git pull origin main && bash deploy.sh'
```

Rules, do not skip:
- **Look before you pull.** Check the server's `git status -sb` first. An untracked
  `.htaccess` lives on the host and is expected — `git pull` leaves it alone. **Never
  `git clean`** on the server, it would delete that `.htaccess`.
- **`public/build/manifest.json` must be committed** before deploying, or CSS/JS 404s.
- **Never `php artisan key:generate` on staging.** `APP_KEY` encrypts NRICs and sessions;
  rotating it makes encrypted columns unrecoverable and logs everyone out.
- **Migrations run automatically** via `migrate --force`. Take a `mysqldump` first if a
  deploy migrates. Rollback = `git revert` on main + redeploy (no release-dir/symlink here).
- Full checklist and hardening gate: [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md).
- Cron (scheduler + queue) lives in hPanel, not SSH crontab; it cannot be verified over SSH.

Staging login credentials are **not** stored in this repo (it is public). They live in the
gitignored `docs/vault/`. Never paste secrets into tracked files.

## Legacy: PM2 (unused)

`ecosystem.config.cjs` is a leftover from the previous maintainer's Windows/Laragon setup (hardcoded `C:/laragon/...` PHP path) and does not run on this machine. Not used, kept only for reference.
