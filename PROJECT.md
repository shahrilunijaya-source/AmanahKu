# Amanahku

- **System:** Multi-tenant HR + weekly work-tracking + AI workforce-intelligence platform
  (Laravel 13 · Blade · Tailwind v4 · Alpine.js · Vite · MySQL · Fortify)
- **Tenants:** Unijaya (primary), Shell S2, Petron TL
- **PM / Product Owner:** _TBC — assign before the next requirement gate_
- **Project Initialiser:** Developer Unijaya (`developer.unijaya`)
- **Repo:** https://gitlab.com/developer-unijaya/claudecode/amanahku
- **Status:** baseline-imported — existing application brought under URSB governance

## Environments

| Environment | URL / host | Notes |
|-------------|-----------|-------|
| Local | http://localhost:9100 | Lerd (Podman) site `amanahku.test`; `.test` DNS unreliable, use the port |
| Staging | https://amanahku-staging.myappsonline.net | Hostinger shared, SSH alias `amanahku`, deploys by `git pull && bash deploy.sh` |
| Production | none yet | To be provisioned |

Assets are built locally — the compiled `public/build` is committed because the staging host
cannot run `npm run build` (Vite/Rolldown thread cap). See `CLAUDE.md` → Deploy to staging.

## Reference docs (pre-existing, carried into this repo)

- `README.md` — setup, architecture notes, production checklist
- `CLAUDE.md` — governance + local dev, deploy, and Laravel Boost guidelines
- `docs/MASTER_PLAN.md`, `docs/PROGRESS.md`, `docs/FEATURE_STATUS.json` — build plan and status
- `docs/DECISIONS.md`, `docs/ISSUES.md` — decision log and open issues (pre-URSB format)
- `docs/DEPLOYMENT.md`, `docs/ENVIRONMENTS.md` — deploy runbooks
- `docs/MULTI_TENANT_ONBOARDING.md` — tenant provisioning
