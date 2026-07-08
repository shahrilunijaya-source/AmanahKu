# Amanahku

Laravel HR platform. Runs under PM2 so the dev server survives sessions, sleep, and crashes.

## PM2 Services

| Port | Name | Type |
|------|------|------|
| 8888 | amanahku-8888 | Laravel (`php artisan serve`) |

**Commands:**
```bash
pm2 start ecosystem.config.cjs   # first time (from project root)
pm2 restart amanahku-8888        # after code/env change
pm2 stop amanahku-8888           # stop
pm2 logs amanahku-8888           # tail logs
pm2 status                       # process table
pm2 save                         # persist process list
pm2 resurrect                    # restore saved list (after reboot)
```

If PHP is upgraded, update the `interpreter` path in `ecosystem.config.cjs`.
