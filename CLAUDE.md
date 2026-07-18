# Amanahku

Laravel HR platform. Local dev runs under [Lerd](https://github.com/) (Podman-powered PHP dev environment) as the site `amanahku.test`, with MySQL, Redis, and Mailpit as lerd-managed services.

## Lerd Site

| Domain | Services | PHP | Node |
|--------|----------|-----|------|
| amanahku.test | mysql, redis, mailpit | 8.5 FPM | 22 |

**Commands:**
```fish
lerd open                # open amanahku.test in browser
lerd status               # overall health (dns, nginx, php-fpm, services)
lerd artisan migrate       # run artisan commands in the container
lerd db:shell               # mysql shell for the amanahku db
lerd logs                    # tail php-fpm/nginx logs
lerd service start mysql      # start a stopped service
```

`.env` is wired to lerd's containers (`DB_HOST=lerd-mysql`, `REDIS_HOST=lerd-redis`, `MAIL_HOST=lerd-mailpit`). Pre-lerd `.env` is backed up at `.env.before_lerd`.

## Legacy: PM2 (unused)

`ecosystem.config.cjs` is a leftover from the previous maintainer's Windows/Laragon setup (hardcoded `C:/laragon/...` PHP path) and does not run on this machine. Not used, kept only for reference.
