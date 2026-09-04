# Amanahku

Laravel HR platform. Local dev under [Lerd](https://github.com/lerd-env/lerd) (Podman PHP dev environment), with MySQL, Redis, Mailpit as lerd-managed services.

## Lerd Site

**Reach app at `http://localhost:9100`**, `.test` DNS is off entirely now (see below), not worth chasing back on. `.env` `APP_URL` follows whatever domain lerd currently assigns the site — check it if absolute links (Mailpit emails) look wrong.

`lerd dns:disable` was run on this machine: `.test` was unreliable (systemd-resolved picks the router as wlan0's DNS server, NXDOMAINs `.test`, no failover to lerd-dns; `lerd dns:repair` couldn't win that race; nsswitch's `resolve [!UNAVAIL=return]` blocks an `/etc/hosts` workaround too since a failed-but-answered `resolve` lookup never falls through to `files`) and it also made `lerd-watcher` sudo-retry the resolver setup every few minutes. With DNS management off, lerd falls back to the `.localhost` TLD, which glibc resolves natively (RFC 6761 special-use domain, no resolver config needed) — so `amanahku.test` is now `amanahku.localhost`. The numeric port below is still the fastest path in; it's DNS-independent by construction either way.

| Site | Access URL | Services | PHP | Node |
|------|-----------|----------|-----|------|
| amanahku (lerd site `amanahku.localhost`) | http://localhost:9100 | mysql, redis, mailpit | 8.5 FPM | 22 |

**Commands:**
```fish
# open http://localhost:9100 in browser (`lerd open` targets amanahku.test — broken DNS)
lerd status               # overall health (dns, nginx, php-fpm, services)
lerd artisan migrate       # run artisan commands in the container
lerd db:shell               # mysql shell for the amanahku db
lerd logs                    # tail php-fpm/nginx logs
lerd service start mysql      # start a stopped service
```

`.env` wired to lerd containers (`DB_HOST=lerd-mysql`, `REDIS_HOST=lerd-redis`, `MAIL_HOST=lerd-mailpit`). Pre-lerd `.env` backed up at `.env.before_lerd`.

### Dev quick-login (one-click, local only)

`resources/views/auth/login.blade.php` renders quick-login buttons when `app()->isLocal()`, tracked in git (not gitignored). Each button fills the normal login form and submits with password `password` — no `/dev/login` route, no gitignored route file (removed, was redundant with this).

- Buttons: `hidayahsuffya.unijaya@gmail.com` (HR), `kussairi.unijaya@gmail.com` (Manager), `haryati.unijaya@gmail.com` (Sr Manager, Kussairi's own manager, `branch` data scope), `shahril.unijaya@gmail.com` (Director), `shazwanshah.unijaya@gmail.com` (Employee), `superadmin@amanahku.com` (Super Admin).
- Reporting line Shazwan → Kussairi → Haryati → Shahrilnizam = the two-step approval chain: **manager** *verifies* a request, then a **director** gives *final approval* (`Permissions::MANAGEMENT_TIER` = `management`, `director`; the real data only has directors, no `management` role). Use these three to drive a claim/leave/overtime request through submit → verify → approve. No other quick-login account can approve (hr/employee lack the management-tier role).
- The local DB is a copy of the production dump (`amanahku20260828.sql`, imported 2026-08-28) with **every** password reset to `password`, 2FA cleared, and the encrypted `nric` columns nulled — prod ciphertext can't be decrypted under the local `APP_KEY`. `database/seeders/DevLoginSeeder.php` is now obsolete: it creates `@amanahku.test` accounts that don't exist in this data and collides with `superadmin@amanahku.com`. Don't run it.
- Re-importing a fresh dump: back the local DB up, `DROP DATABASE amanahku; CREATE DATABASE amanahku`, load the dump, null `employees.nric` and `salary_structures.nric` (otherwise `2026_08_25_200300_reconcile_nric...` dies with `The MAC is invalid.`), then `lerd artisan migrate` and reset the passwords.

### Git worktrees (Claude-created branches included)

`lerd-watcher` (`systemctl --user status lerd-watcher`) auto-registers **any** git worktree — plain `git worktree add`, Claude's own worktree tooling, whatever — no `lerd worktree add` required. It must be running; it was previously disabled here because it kept sudo-retrying broken `.test` DNS setup (fixed by `lerd dns:disable`, see above — if it starts sudo-looping again, that's DNS management having come back on, not the watcher itself).

Each worktree gets its own vhost at `worktree-<branch>.amanahku.localhost` (branch name slugified), with `vendor/` seeded and `.env` synced automatically — takes a few seconds after the worktree is created. Reachable directly, no numeric port needed (`.localhost` resolves natively). Check `~/.local/share/lerd/nginx/conf.d/` if a worktree isn't resolving yet.

`database/seeders/DevLoginSeeder.php` doesn't carry over automatically and needs a manual copy from the main checkout before dev-login accounts exist in a worktree:
```fish
set wt .claude/worktrees/<name>
cp database/seeders/DevLoginSeeder.php $wt/database/seeders/DevLoginSeeder.php
```
It's gitignored (never committed, see above), so `git worktree add` — by lerd or anyone — never brings it along. The worktree shares the parent's database by default, so the seeded dev accounts already exist; no need to re-run `DevLoginSeeder`.

## Deploy to staging

Staging: `https://amanahku-staging.myappsonline.net` (Hostinger shared). SSH alias `amanahku` → `~/domains/amanahku-staging.myappsonline.net/public_html`, tracking **`staging`**. Still the test target — deploy here first.

**Production is live at `https://amanahku.unijaya.com`, and it is not yours to deploy.** The devops team owns the host (DigitalOcean, not Hostinger — the shared-hosting limits below are staging's, not prod's) and releases from GitLab (`https://gitlab.com/developer-unijaya/claudecode/amanahku.git`, the `gitlab` remote). You have no shell, no database and no cron visibility on prod — only an app-level super-admin login. Anything operational on prod goes through devops. Prod carries one seeded super-admin account; its password is not in this repo.

**GitLab is where you push. GitHub is a copy.** Since 2026-08-22 a GitLab push mirror sends `main`, `dev` and `staging` to GitHub within a few minutes of every change (Settings → Repository → Mirroring repositories). Push to GitHub directly and that branch diverges: the mirror keeps divergent refs rather than forcing over them, so it just stops updating and reports an error on the mirror row. `git config remote.pushDefault gitlab` makes the safe thing the default. Unprotected branches — feature branches, `supportos/*` — are not mirrored and live only where you push them.

Release order: commit on `dev` → `git push gitlab dev:release/<version>` → `glab mr create` from that branch into `staging`, merge it → the pipeline deploys staging by itself → **test** → MR `staging` into `main` **on GitLab**, merge there, and the mirror carries `main` to GitHub. Since 2026-09-02 a group security policy (devops-owned, `developer-unijaya/claudecode/security-policy`) blocks every direct push to `main`, `dev` and `staging` — `git push gitlab dev:staging` is refused with `Push is blocked by settings overridden by a security policy`, so staging is reached by MR from an unprotected branch. Local `dev` can no longer be pushed to GitLab `dev` either; it lives locally and reaches GitLab only through those release branches. It's `staging` (not `dev`) that gets MR'd into `main`, so `main` only ever holds the exact commit that was actually tested, not whatever `dev` has moved on to since. Merging `main` on GitHub instead is what diverges the two repos now that the mirror runs GitLab → GitHub. The two repos have shared one history since 2026-07-31; the 29 devops-owned files at the repo root (`STATE.md`, `PROJECT.md`, `CODEOWNERS`, `.gitlab-ci.yml`, `.planning/`, `DECISIONS/`, …) belong to them, do not tidy them away. `.gitlab-ci.yml` carries our staging and SupportOS jobs as of 2026-08-22, added on top of the devops skeleton — still their file, still CODEOWNERS'd to them, so changes there go through them rather than being assumed.

Staging deploys itself. A merge into `staging` runs the GitLab pipeline in `.gitlab-ci.yml`: lint, phpstan, the suite with an 80% coverage floor, committed-asset freshness, then `deploy-staging` — which waits for the mirror to reach GitHub (the server's `origin` is GitHub), SSHes in, pulls, runs `deploy.sh`, and loads the login page. A failed deploy or a staging that stops answering is rolled back to the previous commit on the server automatically. The manual SSH commands below are the fallback for when the pipeline itself is broken.

SupportOS (the Unijaya OS helpdesk agent) uses that same path unattended. It pushes a fix as `supportos/<severity>/<ticket>-<slug>` with GitLab push options that open the MR and merge it when the pipeline succeeds; the `supportos-severity` job fails anything above `low`, so those stop at a green MR for a human. Verified end to end on 2026-08-22. Nothing in that loop can reach `main` or production.

Assets built locally, `public/build` committed; host builds nothing.

```fish
lerd artisan view:cache                          # REQUIRED: makes the Tailwind scan complete
bun run build                                    # rebuild assets if JS/CSS/Blade changed
git add public/build && git commit ...           # commit assets alongside the change
git push gitlab dev:release/<version>            # direct push to staging is policy-blocked
glab mr create --source-branch release/<version> --target-branch staging --remove-source-branch --yes
glab mr merge <iid> --auto-merge --yes           # merges when the MR pipeline is green; staging deploys itself

# Fallback only, when the pipeline itself is broken:
ssh amanahku 'cd ~/domains/amanahku-staging.myappsonline.net/public_html && git status -sb'   # LOOK FIRST (read-only)
ssh amanahku 'cd ~/domains/amanahku-staging.myappsonline.net/public_html && git pull origin staging && bash deploy.sh'
# staging passed? merge the staging → main MR on GitLab; the mirror carries it to GitHub.
```

The four that lose data: never run `key:generate` on a host that already has data; never run `git clean` on the server; take a `mysqldump` before a deploy that migrates; run `view:cache` before `bun run build`. The reasoning behind each, the security gate, the mandatory hPanel cron jobs, the mail configuration and the rollback path are all in **[docs/RULES.md](docs/RULES.md#part-2--operational-rules)**. Read it before any release; do not restate it here, a second copy is how the last one rotted.

Staging and production login credentials are **not** in this repo (it is public), and never go into a tracked file. The prod super-admin password is held by devops.

## Legacy: PM2 (unused)

`ecosystem.config.cjs` is a leftover from the previous maintainer's Windows/Laragon setup (hardcoded `C:/laragon/...` PHP path) and does not run on this machine. Unused, kept for reference.

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.5
- laravel/fortify (FORTIFY) - v1
- laravel/framework (LARAVEL) - v13
- laravel/prompts (PROMPTS) - v0
- laravel/sanctum (SANCTUM) - v4
- larastan/larastan (LARASTAN) - v3
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- phpunit/phpunit (PHPUNIT) - v12
- alpinejs (ALPINEJS) - v3
- tailwindcss (TAILWINDCSS) - v4

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This application uses PHPUnit for testing. All tests must be written as PHPUnit classes. Use `php artisan make:test --phpunit {name}` to create a new test.
- If you see a test using "Pest", convert it to PHPUnit.
- Every time a test has been updated, run that singular test.
- When the tests relating to your feature are passing, ask the user if they would like to also run the entire test suite to make sure everything is still passing.
- Tests should cover all happy paths, failure paths, and edge cases.
- You must not remove any tests or test files from the tests directory without approval. These are not temporary or helper files; these are core to the application.

## Running Tests

- Run the minimal number of tests, using an appropriate filter, before finalizing.
- To run all tests: `php artisan test --compact`.
- To run all tests in a file: `php artisan test --compact tests/Feature/ExampleTest.php`.
- To filter on a particular test name: `php artisan test --compact --filter=testName` (recommended after making a change to a related file).

</laravel-boost-guidelines>
