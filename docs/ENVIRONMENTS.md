# Amanahku — Environments & Deploy (local · staging · production)

Mirrors the **DevStage01** convention: a **private** GitHub repo Hostinger auto-pulls, then
`bash deploy.sh` on the host. `deploy.sh` auto-detects the tier from `APP_ENV` in the host `.env`.

> The **public** repo `github.com/shahrilunijaya-source/AmanahKu` is a sanitized showcase.
> This deploy repo is a **separate PRIVATE** repo. Never push deploy branches to the public one.

---

## 1. Topology

| Env | Branch | Host | URL | DB | `.env` |
|-----|--------|------|-----|----|----|
| **local** | feature branches | your PC (PM2 `:8888`) | http://localhost:8888 | `amanahku` (local MySQL) | `.env` from `.env.example` (`APP_ENV=local`) |
| **staging** | `staging` | Hostinger staging site | `https://staging.DOMAIN` | `amanahku_staging` | `.env` from `.env.staging.example` (`APP_ENV=staging`) |
| **production** | `main` | Hostinger main site | `https://DOMAIN` | `amanahku_prod` | `.env` from `.env.production.example` (`APP_ENV=production`) |

- Branch → tier: **`staging` = staging**, **`main` = production** (same as DevStage01).
- Each tier = its own Hostinger site + own MySQL DB + own `.env`. `.env` is gitignored — created once per host.
- `APP_DEBUG=false` on staging AND production. **Back up production's `APP_KEY`** — it encrypts NRICs.

---

## 2. Release flow

```
feature branch ─ merge → staging ─ push origin → Hostinger staging pulls → deploy.sh → QA on staging.DOMAIN
                              │
                    staging OK ─ merge → main ─ push origin → Hostinger prod pulls → deploy.sh → LIVE on DOMAIN
```

Golden rule: **nothing reaches `main` that didn't pass on `staging` first.**

---

## 3. Assets — build locally, commit `public/build`

Hostinger shared SSH has **no Node**, so `deploy.sh` skips `npm` and serves the committed build.
Before pushing a release that changed CSS/JS:

```bash
npm ci && npm run build
git add -f public/build            # /public/build is gitignored — force-add for the deploy repo
git commit -m "build: compile assets"
```

(If your Hostinger plan DOES have Node, skip this — `deploy.sh` builds on the host automatically.)

---

## 4. One-time setup

### 4a. Private deploy repo (run in your authenticated terminal, from the AmanahKu working dir)
```bash
git branch -m master main                 # match DevStage01 (main = production)
gh repo create amanahku-app --private --source . --remote origin --push   # pushes main
git branch staging && git push -u origin staging
```
`amanahku-app` = PRIVATE. The public showcase keeps the name `AmanahKu`.

### 4b. Hostinger — two sites + two databases
- Sites: `staging.DOMAIN` (subdomain) and `DOMAIN` (main) — each its own `public_html`.
- MySQL: `u_<acct>_amanahku_staging` and `u_<acct>_amanahku_prod`, a user for each. Record credentials.

### 4c. Wire Git auto-deploy (Hostinger panel → Advanced → Git)
- Connect the **private** repo `shahrilunijaya-source/amanahku-app` (add Hostinger's deploy key to the repo).
- Staging site → branch **`staging`**, directory `public_html`.
- Production site → branch **`main`**, directory `public_html`.
- Enable auto-deploy webhook so a push pulls automatically, then run `bash deploy.sh` (via the panel's
  post-deploy command, or SSH — see §6).

### 4d. First boot per host (SSH, once each)
```bash
cd ~/domains/<site>/public_html
cp .env.staging.example .env      # or .env.production.example on prod
php artisan key:generate          # unique per env — store prod's key in your secret vault
nano .env                         # fill DB creds, SMTP, real DOMAIN
bash deploy.sh
```
Then `docs/DEPLOYMENT.md` §3 (create the real super-admin; do NOT run the demo seeder) + §5 (security gate).

### 4e. Queue worker + scheduler (emails & weekly digest)
```bash
# Scheduler — Hostinger panel → Cron Jobs, every minute:
* * * * * cd ~/domains/<site>/public_html && php artisan schedule:run >> /dev/null 2>&1
# Queue — if long-running workers aren't allowed on shared, drain via cron:
*/5 * * * * cd ~/domains/<site>/public_html && php artisan queue:work --stop-when-empty --max-time=280 >> /dev/null 2>&1
```

---

## 5. Ongoing releases

```bash
git checkout staging && git merge <feature> && git push          # → staging deploys
# (rebuild+commit public/build first if assets changed — §3)
# after QA on staging.DOMAIN:
git checkout main && git merge staging && git push               # → production deploys
```

`deploy.sh`: maintenance mode → composer (optimized on prod / dev-deps on staging) → migrate `--force`
→ assets → caches → queue restart → `artisan up`.

---

## 6. If Hostinger won't run a post-pull command

SSH deploy after each push:
```bash
ssh u_acct@host "cd ~/domains/<site>/public_html && git pull && bash deploy.sh"
```

---

## 7. Rollback

```bash
git checkout main && git revert <bad-sha> && git push
# on host: git pull && bash deploy.sh
# Keep a mysqldump before each prod deploy — migrations are forward-only.
```

---

## 8. Env template map

| Committed template | Copied to `.env` on | Key differences |
|--------------------|---------------------|-----------------|
| `.env.example` | local | `APP_ENV=local`, `APP_DEBUG=true`, mail=log |
| `.env.staging.example` | staging host | `APP_ENV=staging`, real SMTP, secure cookies |
| `.env.production.example` | production host | `APP_ENV=production`, `LOG_LEVEL=error` |

Replace every `DOMAIN` placeholder once the real Amanahku domain is set.
