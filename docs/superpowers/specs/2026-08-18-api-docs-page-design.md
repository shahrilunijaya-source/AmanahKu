# A public API reference at /docs/api, and payslips off the key picker

**Date:** 2026-08-18
**Status:** design proposed, awaiting approval, not implemented
**Follows:** `docs/superpowers/specs/2026-08-17-unijaya-os-api-keys-design.md`

## Problem

The API is documented twice and discoverable neither time.

`docs/API.md` is a good reference, but it lives in a repository that DevStage 01 and
SupportOS developers have no reason to clone. `public/openapi.json` is already served
publicly and unauthenticated, but nothing anywhere links to it, so knowing it exists is
folklore. A developer starting an integration today is told the URL by a person.

There is also no path from "I have the docs" to "my coding agent has the docs". The
realistic workflow in this org is a developer opening Claude Code or Cursor inside the
consuming repo and asking it to wire up a client. That agent needs the constraints
(read-only, no webhooks, no pagination), the two different `401` shapes, and the
exact-Monday rule on `week_start`, or it will invent plausible wrong behaviour. Today
getting that in front of an agent means finding a file and pasting it by hand.

Separately, the key-issuing screen offers a `payslips:read` checkbox. `GET /api/v1/payslips`
returns, for every employee in the company, their **name**, the pay period, and three
money figures: `gross`, `net_pay`, `total_deductions` (`ApiController::payslips()`).
None of the three planned consumers has a reason to read anyone's salary. DevStage
gathers requirements, Track costs projects from its own rates, SupportOS handles
tickets. Offering the checkbox creates a mis-tick with no upside.

## Decisions taken

| Question | Decision |
|---|---|
| Path | **`/docs/api`**, public, no login. Deliberately not `/api/docs`: `bootstrap/app.php` renders every error under `api/*` as JSON, so a typo'd URL there would hand a developer a raw JSON body instead of a 404 page. `/docs/api` is free — `php artisan route:list --path=docs` matches nothing today. |
| Who can read it | **Anyone.** No auth, no tenant. It documents shapes, not data, and `public/openapi.json` is already unauthenticated, so this exposes nothing new. |
| Rendered markdown, or a built page? | **A built page.** Cards, language tabs and the agent block are structure that markdown cannot express, and they are the reason to build this at all. Accepted with a hard condition: the drift guard below, without which this is just a second copy that rots. |
| Where the endpoint facts live | **One PHP array**, `App\Support\ApiReference::ENDPOINTS`. Both the visible cards and the copy-to-agent text render from it. Duplicating the endpoint list inside the page — once for humans, once for the agent block — is the exact failure this spec exists to avoid. |
| What the copy button copies | A written **instruction block**, not the page. Base URL, auth header, envelope, the four hard constraints, every endpoint with its scope and fields, both `401` shapes, the four traps, and a closing instruction. |
| `payslips:read` | **Removed from `ApiClient::SCOPES`.** The endpoint keeps working exactly as today for staff tokens; it simply becomes impossible to issue an *application* a key that reads payroll. |
| Language | **English only.** The app is bilingual (en/ms) and every staff-facing screen carries both. This one is developer-facing and its audience reads English API docs all day; a half-translated reference is worse than an untranslated one. |
| `docs/API.md` | **Stays.** It is the long-form reference and the thing the agent block is derived from in spirit. The page is the front door, not a replacement. |

## Part 1 — drop `payslips:read` from the key picker

One line removed from `App\Models\ApiClient::SCOPES`:

```php
'payslips:read' => 'Finalized payslips',   // deleted
```

Everything downstream follows from that, with no other code change:

- **The screen** renders its checkboxes from `ApiClient::SCOPES`, so the box disappears.
- **The validation** is `Rule::in(array_keys(ApiClient::SCOPES))`, so a hand-crafted POST
  carrying `payslips:read` now fails with a 422 rather than minting the key.
- **The endpoint is untouched.** `ApiController::payslips()` keeps its
  `tokenCan($request, 'payslips:read')` guard. A staff token is minted `['*']`, which
  still satisfies it, so `php artisan api:token` and every one of the 17 tests in
  `ApiTokenTest` behave exactly as before. An application key can no longer carry that
  ability, so it can never pass the guard.

The guard stays deliberately. Removing it would make the endpoint depend on the role
check alone, which is the arrangement this whole design moved away from.

**Documentation still describes the endpoint,** because staff tokens reach it and a
reference that hides a live endpoint is a reference that lies. Both `docs/API.md` and
the new page must say plainly that it cannot be granted to an application key.

## Part 2 — `App\Support\ApiReference`

New class, modelled on `App\Support\Changelog` (which already does exactly this job for
`resources/changelog.yaml`: a support class holds the parsed content, a controller hands
it to a screen).

```php
/**
 * The API's six endpoints, as one table. The public reference page renders its cards
 * from this, and the copy-for-your-agent block is generated from it too, so the two
 * cannot disagree — they are the same array read twice.
 *
 * @var list<array{path: string, scope: string, app_key: bool, title: string,
 *                 blurb: string, fields: string, query: ?string, note: ?string}>
 */
public const ENDPOINTS = [...];
```

One row per endpoint, six rows, in the order the page shows them: `/projects`,
`/employees`, `/positions`, `/timesheet-effort`, `/leave-requests`, `/payslips`.

`scope` is the ability string a machine key needs. `/payslips` carries
`'payslips:read'` here but is flagged `app_key: false`, so the card renders with a
"staff tokens only" marker instead of a grantable scope chip. That keeps the endpoint
honest on the page without implying you can ask for it.

`ApiReference::agentBrief(): string` builds the instruction block from the same rows,
plus the fixed preamble (base URL, auth, envelope), the constraints, the error shapes
and the traps. One method, one return value, testable.

## Part 3 — the page

- **Route**, in `routes/web.php` beside the other public routes (the block at `:80-95`,
  above the `auth` group at `:97`):

  ```php
  // Public API reference. No auth: it documents shapes, not data, and openapi.json is
  // already served unauthenticated. Deliberately not under /api/*, where the exception
  // handler renders every error as JSON.
  Route::get('/docs/api', [ApiDocsController::class, 'show'])->name('docs.api');
  ```

- **Controller** `app/Http/Controllers/ApiDocsController.php`: one `show()` method,
  passing `ApiReference::ENDPOINTS`, `ApiReference::agentBrief()`, and the API base URL
  derived from `config('app.url')` so the page never hardcodes a hostname.

- **View** `resources/views/docs/api.blade.php`: standalone page in the same shape as
  `superadmin/companies/api-keys.blade.php` — its own `<!DOCTYPE>`, `Vite::fonts()`,
  `@vite([...])`, `@include('partials.pwa-head')`. Not an app-shell child; there is no
  logged-in user and no tenant.

**Sections, in order.** A working mockup of all of this exists and was reviewed:

1. **Hero** — title, one-line summary, base URL, and three buttons: *Copy everything for
   your AI* (primary), *Copy OpenAPI JSON*, *Copy a test call*.
2. **What this is** — AmanahKu answers which projects exist and what kind they are; Track
   holds budget, phases and cadence. This is the first question another team's agent asks.
3. **Quick start** — three steps: get a key, send the header, read the envelope.
4. **Sample code** — tabbed: cURL, PHP (Laravel `Http`, because Track is Laravel),
   JavaScript, Python. Each shows the same call, with error handling that covers both
   failure shapes.
5. **What it can do** — six cards from `ENDPOINTS`, each showing its scope chip.
6. **What it won't do** — no writes, no webhooks, no pagination, no rate limit.
7. **Endpoints** — expandable, with real response bodies.
8. **Errors** — the four shapes in a table, including both `401`s.
9. **Known traps** — exact-Monday `week_start`, the two `401` shapes, keys shown once.

**Styling** reuses the app's existing CSS variables (`--ink`, `--muted`, `--red`,
`--canvas`, `--hairline`, `--sidebar`) and inline styles, as `api-keys.blade.php` does.
No new Tailwind utilities, so no asset rebuild.

**The copy buttons** use `navigator.clipboard.writeText`. The `SecurityHeaders` CSP
already allows `script-src 'self' 'unsafe-inline'`, so the inline handler works without
a CSP change. Clipboard access requires a secure context: fine on `https://` in
production and on `localhost` in dev, and this is worth knowing rather than discovering.

**Link from the key screen.** `superadmin/companies/api-keys.blade.php` gains one line
pointing at `/docs/api`, so the person issuing a key can hand over the URL to go with it.

## The drift guard

This is the condition the built page was accepted on. `tests/Feature/Api/ApiDocsTest.php`
is extended:

- Every path registered in `routes/api.php` appears in `ApiReference::ENDPOINTS`, and
  every row in `ENDPOINTS` maps to a registered route. Adding an endpoint without
  documenting it fails. Deleting one without removing its card fails too.
- Every scope key in `ApiClient::SCOPES` appears in `ApiReference::agentBrief()`.
- `GET /docs/api` returns 200 **without authentication**, and its body contains every
  endpoint path.
- The agent brief names both `401` shapes and the exact-Monday rule. These are the two
  facts an agent gets wrong when they are missing, and they were both found empirically
  rather than assumed, so they are worth pinning.

The existing markdown assertions stay.

## Testing

New `tests/Feature/ApiDocsPageTest.php`:

- A logged-out request to `/docs/api` returns 200 and renders all six endpoint paths.
- The page does not render a `payslips:read` scope chip, and does mark `/payslips` as
  staff-token-only.
- The agent brief contains the base URL, `Authorization: Bearer`, all five grantable
  scopes, both `401` bodies, and `week_start`.

Extended `tests/Feature/SuperAdminApiKeyTest.php`:

- The issuing screen renders five checkboxes, not six, and none of them is `payslips:read`.
- Posting `scopes[]=payslips:read` returns 422 and mints nothing.

Everything already green must stay green: `ApiTokenTest` (17), `TimesheetEffortApiTest`
(11), `ApiClientKeyTest` (9), `ApiDocsTest` (2), `SuperAdminApiKeyTest` (4).

## Scope

**In:** the `payslips:read` removal and its tests; `ApiReference`; the route, controller
and view; the three copy buttons; the extended drift guard; the link from the key screen.

**Out, deliberately:**

- **A "try it" console.** Making a live call from the page needs a key, and a public page
  must never hold one. Asking the reader to paste their key into a public page is a habit
  worth not teaching.
- **Search.** Six endpoints on one page. Ctrl+F is search.
- **Malay translation.** Named in the decisions table.
- **Retiring `docs/API.md`.** It stays as the long-form reference.
- **Versioned docs.** There is one version, `v1`. A version switcher for a single version
  is furniture.
- **Removing `payslips:read` from the controller guard.** Part 1 explains why it stays.

## Open items

**`docs/API.md` and `public/openapi.json` still list `payslips:read` as a grantable
scope.** Both need a line saying it cannot be issued to an application key. Small, but it
is exactly the kind of half-update that makes a reference untrustworthy, so it belongs in
the same change rather than after it.

**The page will be the first public surface this app has had** other than the login
screen. Nothing on it is sensitive, but it does advertise that AmanahKu serves an API and
that payroll is among the things behind it. That is normal for a developer portal and the
control is the key, not the secrecy — recorded here so the decision is on purpose.
