# Unijaya OS API: per-app keys, scoped reads, and agent-first docs

**Date:** 2026-08-17
**Status:** design proposed, awaiting approval, not implemented

## Problem

Amanahku's Create Project screen is meant to be the single place a Unijaya project
comes into existence. Everything downstream — DevStage 01 (requirement gathering),
Track (delivery), SupportOS (helpdesk plus a low-severity auto-fix agent) — should
reference an Amanahku project by its Amanahku id rather than keep a private copy.

The API that makes this possible already half exists, and the way it is wired today
does not survive three consumers.

**A key belongs to a person, not an app.** `User::mintApiToken()`
(`app/Models/User.php:184`) mints a Sanctum token against a `User`. Authorization is
then the user's own tenant role: `ApiTenant` puts `tenantRole` on the request
(`app/Http/Middleware/ApiTenant.php`), and `ApiController::isPrivileged()`
(`app/Http/Controllers/Api/V1/ApiController.php:131`) treats `management|hr` as
"see the whole tenant", everyone else as "see only your own rows". So Track's key is
somebody's staff identity. When that person leaves, `EnsureNotArchived`'s API twin in
`ApiTenant` 401s the token and Track stops working. And a token minted for an HR user
to read projects can also read every payslip in the tenant, because the role, not the
key, decides.

**There is no way to hand a key out.** Minting is `php artisan api:token`
(`app/Console/Commands/MintApiToken.php`) and nothing else. There is no listing, no
revoke, no rotation, no "when was this last used". Production offers no shell, so
every key for every new app is a devops request, and a leaked key has no kill switch
short of one.

**The consumer end is equally hand-wired.** Track reads
`SystemSetting::get('amanahku_api_url')` and `amanahku_api_token`
(`Track/app/Services/AmanahkuClient.php:71-72`). Its docblock says those come from
admin Settings, but `Track/app/Http/Controllers/Admin/SettingsController.php` only
handles `anthropic_api_key` and `voyage_api_key` — there is no form field for either
Amanahku key, so today they are set by hand directly in the database.

**And there are no docs.** Nothing describes the endpoints, the envelope, the auth
header or the error codes. A developer (or a coding agent) in the DevStage or
SupportOS repo has nothing to read.

## Decisions taken

Decisions 1–5 came from the user directly. The rest are implementation choices made
inside those, recorded here so they are not re-litigated mid-build.

| Question | Decision |
|---|---|
| 1. Do consumer apps write to Amanahku, or only read? | **Read only.** Write is roadmap, not this build. Nothing outside Amanahku creates or mutates a project. |
| 2. DevStage 01 sits upstream of a project existing — who creates it? | A person, in Amanahku's Create Project screen. Falls out of decision 1: DevStage links to a project that already exists. |
| 3. How does a calling app prove it is allowed in? | **Its own key, carrying an explicit list of what it may read.** Not a borrowed staff identity. This is the industry-standard shape (Stripe restricted keys, GitHub fine-grained tokens); OAuth client-credentials is the same idea with expiry machinery that three internal first-party apps do not need. |
| 4. How are keys handed out and revoked? | **A super-admin screen in Amanahku.** Super-admin only — the director does not get it. Rationale: the production super-admin login is the only access path that exists without devops, and a key that reads projects is one mis-tick away from a key that reads payslips. |
| 5. How does an app learn something changed? | **It asks (polling).** No webhooks. Nothing here is urgent to the second, and Amanahku's queue on production is currently failing, so a push path would silently not run. Push stays on the roadmap for a single named event if SupportOS later needs it. |
| Where does an app key attach, given Sanctum tokens are polymorphic? | **A new `ApiClient` model**, one row per consuming app per tenant, used as the token's `tokenable`. Not `Tenant` (a tenant is not a caller and one tenant may have several apps), not a fake staff `User` (that reintroduces exactly the person-shaped identity being removed). |
| Do existing person-tokens get removed? | **No.** This is additive. `api:token`, `User::mintApiToken()`, the role-gated behavior and all 14 tests in `tests/Feature/ApiTokenTest.php` stay exactly as they are. Removing them was not asked for and would break passing tests. |
| What does an app key see? | **Tenant-wide read for every scope it was granted.** There is no "own records" concept for a machine — a super-admin ticking `employees:read` at mint time *is* the authorization decision. |
| Do the Track endpoints (`positions`, weekly effort) come back in this build? | **They are not gone.** They are live on `staging` and were held out of the 1.3 release to `main` only. They are absent from `dev`, which is the live problem; see Open items. This spec's four-endpoint scope list becomes six once they are back on `dev`. |

## Architecture

### 1. `ApiClient` — the caller identity

New model and table. One row per consuming app, per tenant.

```
api_clients
  id
  tenant_id      foreignId, constrained, cascadeOnDelete
  name           string           -- "Track", "DevStage 01", "SupportOS"
  created_by     foreignId users  -- which super-admin issued it
  timestamps
```

`ApiClient` uses `Laravel\Sanctum\HasApiTokens`. It deliberately does **not** use
`BelongsToTenant`, for the same reason `PersonalAccessToken` doesn't: the row is
resolved by Sanctum during authentication, before any tenant context exists, so a
global scope keyed on `CurrentTenant` would be inert exactly where isolation matters.
`tenant_id` is set explicitly by the screen (which is always operating on one named
company) and matched explicitly by the middleware. Relying on `BelongsToTenant` here
would look like protection while providing none — reads fail open when no tenant is
active (`app/Models/Concerns/BelongsToTenant.php`), which is precisely the state an
API request is in when the token is being resolved.

A key is minted through `ApiClient::mintKey(array $scopes): NewAccessToken`, which
sets `tenant_id` on the token row exactly as `User::mintApiToken()` does — that column
already exists (`database/migrations/2026_06_24_080101_create_personal_access_tokens_table.php`)
and is what `ApiTenant` reads.

Deleting an `ApiClient` deletes its tokens. Revoking a single key deletes the token
row and leaves the client.

### 2. `ApiTenant` branches on tokenable type

This is the only middleware change. `app/Http/Middleware/ApiTenant.php` currently
assumes `$request->user()` is a `User` and derives tenant membership, role and
employee record from it.

- **`User` tokenable** — unchanged. Membership check, archived check, `tenantRole`
  and `employee` on the request. Existing behavior, existing tests.
- **`ApiClient` tokenable** — tenant comes from the client row (cross-checked against
  the token's `tenant_id`; a mismatch is a 401, not a silent preference). No employee,
  no `tenantRole`. Abilities are the entire authorization story.

Nothing else on the `api` stack assumes a `User`: `bootstrap/app.php` appends no
middleware to the API group, so `auth:sanctum` plus `api.tenant` is the whole chain
(`routes/api.php`).

`ApiClient` must implement `Illuminate\Contracts\Auth\Authenticatable` (the
`AuthenticatableTrait` `User` already uses is the shortest route), because
`$request->user()` is typed to that contract and this is what the guard returns for a
machine caller. Worth settling up front: this repo has already reverted a round of
API work over static-analysis typing (`bf3a16d`, `6939900`), so larastan should not
be the thing that discovers it.

### 3. Scopes are Sanctum abilities

Named `<resource>:read`, one per endpoint. Initial set, matching today's surface:

| Scope | Endpoint | Grants |
|---|---|---|
| `projects:read` | `GET /api/v1/projects` | Active projects: id, code, name, category tags |
| `employees:read` | `GET /api/v1/employees` | Directory: name, email, position, status, department, branch |
| `leave:read` | `GET /api/v1/leave-requests` | All leave requests in the tenant |
| `payslips:read` | `GET /api/v1/payslips` | Finalized payslips only, tenant-wide |

Each action gains one guard:

```php
if (! $request->user()->tokenCan('projects:read')) {
    return $this->error('This token lacks the projects:read scope.', 403);
}
```

**Why this is backward compatible:** every existing token is minted `['*']`
(`User::mintApiToken()`'s default) and Sanctum's `tokenCan()` returns true for `*`.
So adding these guards changes nothing for person-tokens. To be verified against
`tests/Feature/ApiTokenTest.php` during implementation, not assumed.

**The scope guard alone is not enough — the role checks must also learn about machine
callers.** A scope guard that passes still lands in `isPrivileged()`
(`ApiController.php:131`), which reads `tenantRole` off the request. `ApiTenant` sets
no role for a machine caller, so the default `'employee'` applies and an app key with
`employees:read` would get a 403, while `leave:read` and `payslips:read` would fall to
the own-records branch, find no employee, and return an empty array. Granted scopes
would silently return nothing. `projects` would work only because it has no role
logic at all.

The fix is one line. `ApiTenant` puts the resolved client on the request as
`apiClient`, and `isPrivileged()` treats its presence as already-authorized:

```php
private function isPrivileged(Request $request): bool
{
    return $request->attributes->get('apiClient') !== null
        || in_array($request->attributes->get('tenantRole', 'employee'), self::PRIVILEGED, true);
}
```

A machine caller that cleared the scope guard is tenant-wide by definition — the
super-admin ticking the scope was the authorization act. A person-token never sets
`apiClient`, so the role expression it evaluates is byte-for-byte what it evaluates
today. That is what keeps all 14 existing tests untouched.

`payslips:read` exists for completeness and should be tickable, but no planned
consumer needs it. The screen shows it unticked by default.

### 4. The super-admin screen

Placement mirrors the feature matrix, which already hangs off a company:

- `GET  /admin/companies/{tenant:slug}/api-keys` — `superadmin.companies.api-keys`
- `POST /admin/companies/{tenant:slug}/api-keys` — create
- `POST /admin/companies/{tenant:slug}/api-keys/{token}/revoke` — revoke

All three inside the existing `super.admin` group (`routes/web.php:108`). Entry point
is a button beside "Feature matrix →" on `resources/views/superadmin/companies/show.blade.php:40`.
New controller `App\Http\Controllers\SuperAdmin\ApiKeyController`; view
`resources/views/superadmin/companies/api-keys.blade.php`, styled off
`features.blade.php`.

**Screen contents:**

- Table of issued keys: app name, scopes, issued by, last used, created. `last_used_at`
  already exists on the token row and Sanctum maintains it — it is the only signal
  that tells a super-admin whether a key is live or forgotten.
- Create form: app name (text), scopes (checkboxes). Submit mints.
- The plaintext key is rendered **once**, immediately after creation, with a copy
  button and a line stating it cannot be shown again. This is not a UX preference:
  only the sha256 hash is stored, so nobody including the issuer can recover it.
  Passed via a one-shot flash, never persisted, never re-rendered on refresh.
- Revoke per row, with a confirm. Immediate.

There is no rotation flow. Revoke, create, repaste is the whole recovery path, and a
lost key is handled the same way as a leaked one.

**Token prefix.** Set `SANCTUM_TOKEN_PREFIX` (already supported, `config/sanctum.php:68`)
so keys are greppable and secret-scanners can spot one committed by accident.

### 5. Agent-first docs

Two artifacts, both generated from the same source of truth and both committed:

- **`docs/API.md`** — hand-written, the thing a developer or a coding agent reads.
  Base URL, the `Authorization: Bearer` header, the `{data, error}` envelope, every
  endpoint with a real response body, every error code (`401` unauthenticated or
  wrong tenant, `403` missing scope), and one copy-pasteable `curl` per endpoint.
  Written so an agent in another repo can wire up a client without a human
  translating: explicit about what is *not* there (no write, no push, no pagination).
- **`public/openapi.json`** — the machine-readable twin, served statically. Lets an
  agent generate a typed client rather than hand-roll one.

Both are committed files, not a generated route. Amanahku commits `public/build`
already; a doc endpoint would be one more moving part on a host that builds nothing.
Keeping the two in step is a checklist item on the endpoint's test, not a build step.

## Scope

**In:**

1. `ApiClient` model, migration, `mintKey()`.
2. `ApiTenant` tokenable branch.
3. Scope guard on the four existing endpoints, plus category tags added to the
   projects payload (eager-loaded via `with('categories')`, or the list is one query
   per project).
4. Super-admin key screen: list, create, show-once, revoke.
5. `docs/API.md` and `public/openapi.json` for the existing four endpoints.
6. Tests (below).

**Out, and deliberately so:**

- **Write endpoints.** Roadmap, per decision 1.
- **Webhooks / push.** Roadmap, per decision 5, and blocked behind the production
  queue failures regardless.
- **Restoring `positions` and the weekly effort endpoint.** See Open items.
- **Removing person-tokens or the `api:token` command.** Additive build.
- **Track's Settings form fields.** Different repository. Track needs two fields
  added to `Admin/SettingsController` and its settings view so `amanahku_api_url`
  and `amanahku_api_token` stop being hand-set database rows. Best done as its own
  session against the Track repo.
- **Rate limiting per key.** Three first-party apps on internal infrastructure. Add
  when there is a fourth consumer or an untrusted one.
- **Key expiry.** `expires_at` exists on the token row and is left null. Revocation
  is the control that matters here; expiry without a rotation flow just breaks
  integrations on a timer.

## Testing

New file `tests/Feature/Api/ApiClientKeyTest.php`:

- An app key reads a granted scope and gets `200`.
- An app key with `leave:read` sees **all** the tenant's leave requests, not an empty
  own-records list. Same for `employees:read` returning the directory rather than a
  403. This is the regression test for the role-check branch above: without it, a
  granted scope silently returns nothing and every status code still looks fine.
- An app key hitting an endpoint it lacks the scope for gets `403`, with the data
  absent from the body — not merely a status check.
- An app key for tenant A cannot read tenant B, mirroring
  `test_token_for_tenant_a_cannot_read_tenant_b_data`.
- Revoking a key makes the next call `401`.
- Deleting an `ApiClient` kills its tokens.
- An app key is unaffected by any employee being archived — the regression that
  motivates the whole design.
- The projects payload carries category tags, and an untagged project carries `[]`.
  Existing `test_any_valid_token_lists_tenant_active_projects` must still pass, which
  is the check that the addition stayed additive.

New file `tests/Feature/SuperAdmin/ApiKeyScreenTest.php`:

- Non-super-admin cannot reach any of the three routes.
- Creating shows the plaintext exactly once; a subsequent `GET` of the screen does
  not contain it.
- Revoke removes the row.

Existing `tests/Feature/ApiTokenTest.php` (14 tests) must pass untouched. If a scope
guard breaks one, the guard is wrong, not the test.

## Relationship to the Projects screen redesign

A parallel session is rebuilding Amanahku's project surface into a single register
(`docs/superpowers/specs/2026-08-17-projects-source-of-truth-design.md`, branch
`claude/project-screen-redesign-521aef`): one Projects screen every employee can view,
editable by manager/management/hr, with Timesheet Setup's project block and the
category tags folded in. That spec's non-goals say it plainly — *"No change to how
projects are consumed. Timesheets, board cards and Track's API read the same table,
unchanged."* So it does not block this build, and this build does not block it.

Two consequences that are real anyway:

**The projects payload gains category tags.** Category is already a many-to-many on
`Project` as of `23e97c8` (`Project::categories()`, pivot `project_timesheet_category`,
values `Development`, `Maintenance`, `InHouse Project`, `Sales` from
`TimesheetCategory::PROJECT_LINKABLE`). Those tags are exactly the
development-versus-maintenance distinction that makes a Unijaya project what it is, so
a consumer that can't see them can't tell two projects apart in any way that matters.
`GET /api/v1/projects` returns them as a flat array of names:

```json
{"id": 12, "code": "UJ-014", "name": "AmanahKu", "categories": ["Development", "Maintenance"]}
```

Additive, so Track's current call is unaffected. An untagged project returns `[]`,
matching the screen's rule that untagged means "shows under every category".

**Where the line falls between Amanahku and Track.** The Projects spec deliberately
holds project *identity* only — code, name, active, sort, categories, sub-pillars — and
leaves budget, phases and cadence to Track, on the grounds that duplicating them
creates two records to keep current. That division belongs in `docs/API.md` in as many
words, because it is the first question a coding agent in the DevStage or SupportOS
repo will have: Amanahku answers *which projects exist and what kind they are*, not
*how they are going*.

**Sub-pillars are not exposed.** They become a visible part of the register on that
screen, but no consumer has asked for them and the endpoint stays flat. Add
`subpillars` to the payload when something needs it, not before.

**Merge order.** Both branches touch `routes/web.php` in different blocks (theirs the
tenant `/app/*` routes, this one the `super.admin` group), so a rebase is the whole
coordination cost. Whichever lands second rebases onto dev.

## Rollout

Amanahku's release path is dev → staging → PR staging into main → GitLab, and staging
is the gate. Nothing here reaches production until it has been exercised on staging.

1. Ship the mechanism to staging. No existing token's behavior differs, on either
   host — this is additive throughout.
2. On **staging**: super-admin creates a `Track` client with `projects:read`, points a
   Track instance at it, confirms the call works and that revoking kills it. That
   round trip is the thing staging is for; it is the first time the screen, the key
   and a real consumer meet.
3. Only then does the release move on to production, by the normal PR route.
4. On **production**, once the code is live: super-admin creates the `Track` client,
   Track's `amanahku_api_token` row is updated to the new key, and the old hand-minted
   person-token is revoked once Track is confirmed working.
5. DevStage 01 and SupportOS get their own clients when they are ready to consume,
   with only the scopes each actually needs.

Step 4 is the point of the screen: it is doable with the production super-admin login
alone, no shell, no devops ticket.

## Open items

### The Track endpoints were on staging, missing from dev — RESOLVED

**Resolved 2026-08-17 by `b78e180` on `dev`**, a revert of the two reverts. `dev` and
`origin/staging` now agree on `routes/api.php` and `ApiController`, so the fast-forward
described below is a no-op for the API surface. 28 tests, 88 assertions green; Pint and
phpstan clean. The scope list for this spec is therefore **six endpoints, not four** —
see the consequence note at the end of this section. The history below is kept because
it explains why the two endpoints exist and how the trap formed.

Branch state before the fix:

| Branch | `positions` + `timesheet-effort` |
|---|---|
| `origin/staging` (tip `2494e27`) | **present** |
| `dev` | absent |
| `main` / production | absent |

The two reverts (`6939900`, `3d74337`) were applied on `release-20260814` *on top of*
`2494e27` and merged to `main`. Staging was never advanced past `2494e27`, so it kept
the endpoints. This was a release decision — hold the Track work back from production —
not a deletion.

**The problem: `dev` carries both revert commits, and `origin/staging` is an ancestor
of `dev`.** So the ordinary release step in CLAUDE.md,

```fish
git push origin dev:staging
```

is a clean fast-forward that removes `Route::get('/positions', …)`,
`Route::get('/timesheet-effort', …)`, `ApiController::positions()`,
`timesheetEffort()`, `effortByPosition()` and `COUNTED_TIMESHEET_STATUSES` from
staging. No conflict, no warning, no failing test — the tests that covered them
(`tests/Feature/Api/TimesheetEffortApiTest.php`) left with the revert too.
`Track/app/Services/AmanahkuClient.php` calls both, so Track's positions screen and
nightly effort sync start 404ing against staging the next time anything at all is
deployed.

The fix taken was to restore on `dev`, which matches "Amanahku is the source of truth
for Unijaya projects": those two endpoints are how Track reads effort back, and the
integration was demoed end to end on 2026-08-12 against exactly this code
(`Track/docs/DEMO-AMANAHKU-LOCAL.md`: TTMS week of 2026-08-03 costing RM 7,065.00 from
AmanahKu timesheets, twenty position bands mapped). The alternative — accepting the
loss and changing Track's client to stop calling them — was available but nobody had
chosen it; doing nothing would have picked it by accident, on an unrelated deploy.

**Consequence for this spec: the scope list is six, not four.**

| Scope | Endpoint | Grants |
|---|---|---|
| `positions:read` | `GET /api/v1/positions` | Position bands including retired ones: id, title, code, department, level, status. No salary. |
| `effort:read` | `GET /api/v1/timesheet-effort?week_start=` | One week's effort per project per band: headcount, person-days, days present, average allocation. No employee name, id or salary. |

Both are `isPrivileged()`-gated today, so they hit the same machine-caller trap
described in section 3 and are fixed by the same one-line change. Both belong in
`docs/API.md` and `public/openapi.json`.

`effort:read` is the one scope no consumer but Track should be granted. The endpoint
deliberately aggregates server-side so that no name, employee id or salary leaves
Amanahku, but per-band person-days across a company this size is still the closest
thing in the API to payroll, and it should be ticked only for the app that costs
against it.
