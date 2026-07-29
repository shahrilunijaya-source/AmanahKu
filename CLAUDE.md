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

**Assets are built locally and the compiled `public/build` is committed**; the host builds
nothing. Deploy is `git pull && bash deploy.sh` on the server. `deploy.sh` is idempotent
and safe to re-run: maintenance-down, `composer install`, `migrate --force`, skips asset
build (uses committed `public/build`), warms config/route/view caches, restarts the queue,
brings the app back up. View-cache warming is what makes new Blade changes take effect.

The host *does* have Node, contrary to what this file said before 2026-07-30: CloudLinux
ships it at `/opt/alt/alt-nodejs{18,20,22,24}/root/usr/bin/node`, just not on `PATH`. It is
still useless for building. Vite 8 bundles with Rolldown, which asks rayon for one thread
per core (the box reports 64), and the account's thread cap refuses them:
`ThreadPoolBuildError ... WouldBlock`. `RAYON_NUM_THREADS=4` does make it build, but the
server-side build was rejected deliberately — a build that dies mid-deploy leaves the app
in maintenance mode. Do not revive the idea without that tradeoff in mind. Under bun the
same panic surfaces as a bare `SIGABRT` with no message at all.

**Always `view:cache` before building.** `resources/css/app.css` has
`@source '../../storage/framework/views/*.php'`, so Tailwind scans the compiled Blade
cache. Build against a partial cache and the CSS silently omits whatever was not compiled —
this is exactly how staging came to be missing `.animate-spin`, the `focus-visible:` ring
utilities and the `disabled:` states. Compiling every view first makes the cache a pure
function of the Blade sources, and the CSS then reproduces byte for byte on any machine.
CI enforces the CSS match in the `Committed assets match sources` job. It checks CSS only:
rolldown's JS chunk bytes differ per machine even with an identical lockfile, bun and
rolldown version, so JS cannot be compared this way. Stale JS is the louder failure anyway
(missing feature, console error), while a dropped Tailwind utility fails silently.

Safe sequence (run from local repo root):
```fish
lerd artisan view:cache                          # REQUIRED: makes the Tailwind scan complete
bun run build                                    # rebuild assets if JS/CSS/Blade changed
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
