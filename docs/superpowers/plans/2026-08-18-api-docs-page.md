# Public API reference at /docs/api — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a public, unauthenticated API reference at `/docs/api` that a developer can hand to a coding agent in one click, and remove `payslips:read` from the set of scopes an application key can be granted.

**Architecture:** The six endpoints become one PHP array, `App\Support\ApiReference::ENDPOINTS`. The page's cards render from it and the copy-for-your-agent text is generated from it, so the human view and the machine view cannot disagree. A drift test compares that array against the routes actually registered in `routes/api.php`, in both directions.

**Tech Stack:** Laravel 13, PHP 8.5, PHPUnit 12, Blade, Larastan 3, Pint.

**Spec:** `docs/superpowers/specs/2026-08-18-api-docs-page-design.md`

## Global Constraints

- **Base branch:** this worktree's branch with `dev` merged in. `dev` moved after the last round (the Projects register and changelog 1.4 landed). **Run before Task 1:**

  ```bash
  git merge dev --no-edit
  grep -c "Route::get" routes/api.php          # must print 6
  test -f app/Models/ApiClient.php && echo ok  # must print ok
  ```

- **No new dependencies.** No markdown package, no OpenAPI generator, no CSS framework.
- **Additive to the API itself.** No endpoint is added, removed or changed in behaviour. `ApiController` is touched in this plan only if a test proves it must be — it should not be.
- **Every pre-existing test must stay green and unedited except where a task says otherwise:** `ApiTokenTest` (17), `TimesheetEffortApiTest` (11), `ApiClientKeyTest` (9), `ApiDocsTest` (2), `SuperAdminApiKeyTest` (4).
- **After any PHP edit:** `vendor/bin/pint --dirty --format agent` must report `passed`.
- **After any PHP edit:** `vendor/bin/phpstan analyse --memory-limit=1G --no-progress` must report `0` errors.
- **One source for endpoint facts.** After Task 2, no file may hardcode the endpoint list again. If you find yourself typing `/timesheet-effort` into the Blade view, loop over `ApiReference::ENDPOINTS` instead.
- **English only** on the page. Do not add `_ms` copy or `__()` calls; the spec's decision table explains why.
- **Blade:** the view uses inline styles and existing CSS variables only. If a new Tailwind utility class appears, run `lerd artisan view:cache && bun run build` and commit `public/build` alongside.

---

### Task 1: Take `payslips:read` off the key picker

**Files:**
- Modify: `app/Models/ApiClient.php` (the `SCOPES` constant)
- Modify: `docs/API.md`
- Modify: `public/openapi.json`
- Test: `tests/Feature/SuperAdminApiKeyTest.php` (append)

**Interfaces:**
- Consumes: nothing new.
- Produces: `ApiClient::SCOPES` shrinks from six entries to five. Task 3's checkbox list and Task 2's brief both read it, so this must land first.

**Why:** `GET /api/v1/payslips` returns every employee's name, `gross`, `net_pay` and `total_deductions`. None of DevStage 01, Track or SupportOS has a reason to read that. The endpoint and its guard are deliberately left alone — a staff token is minted `['*']`, still satisfies `tokenCan($request, 'payslips:read')`, and behaves exactly as it does today. Only the ability to *issue an application* that scope goes away.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/SuperAdminApiKeyTest.php`:

```php
    public function test_payslips_is_not_offered_as_a_grantable_scope(): void
    {
        $response = $this->actingAs($this->superAdmin())
            ->get(route('superadmin.companies.api-keys', $this->tenant));

        $response->assertOk();
        $response->assertDontSee('payslips:read');

        // The other five must still be offered — this test must fail if someone
        // empties the list rather than removing one entry.
        foreach (['projects:read', 'employees:read', 'positions:read', 'effort:read', 'leave:read'] as $scope) {
            $response->assertSee($scope);
        }
    }

    public function test_a_hand_crafted_request_cannot_mint_a_payslips_key(): void
    {
        $this->actingAs($this->superAdmin())
            ->post(route('superadmin.companies.api-keys.store', $this->tenant), [
                'name' => 'Sneaky',
                'scopes' => ['payslips:read'],
            ])
            ->assertStatus(302)
            ->assertSessionHasErrors('scopes.0');

        $this->assertSame(0, ApiClient::count());
        $this->assertSame(0, PersonalAccessToken::count());
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter='payslips' tests/Feature/SuperAdminApiKeyTest.php`
Expected: FAIL. The first sees `payslips:read` on the page; the second mints a key instead of rejecting it.

- [ ] **Step 3: Remove the scope**

In `app/Models/ApiClient.php`, delete this line from the `SCOPES` constant:

```php
        'payslips:read' => 'Finalized payslips',
```

Then extend the constant's docblock so the omission is deliberate rather than looking like an oversight. Append this paragraph to it:

```
     * `payslips:read` is deliberately absent. GET /api/v1/payslips still exists and
     * still checks that ability, so a staff token minted ['*'] reaches it exactly as
     * before — but no application can be issued a key carrying it. None of the apps
     * that read this API has a reason to see anyone's pay, and a checkbox that should
     * never be ticked is better removed than labelled.
```

- [ ] **Step 4: Correct the two documentation files**

In `docs/API.md`:
- Remove the `| payslips:read | Finalized payslips |` row from the Scopes table.
- Under that table, add a short paragraph: `payslips:read` cannot be granted to an application key. The endpoint remains reachable by a staff token, which carries every ability, and is documented below for that reason.
- At the `### GET /payslips` heading, add a line immediately under it stating the same thing, so a reader who jumps straight to that section is not misled.

In `public/openapi.json`, amend the `/payslips` operation's `description` so it reads (keeping the existing sentence about finalized runs):

```
Requires the payslips:read scope, which is held only by staff tokens — it cannot be granted to an application key. Returns only finalized payslips — draft or in-progress payroll runs never appear here, regardless of scope.
```

`public/openapi.json` must remain valid JSON. Verify with:

```bash
php -r 'json_decode(file_get_contents("public/openapi.json"), true); echo json_last_error_msg(), PHP_EOL;'
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/SuperAdminApiKeyTest.php tests/Feature/Api tests/Feature/ApiTokenTest.php`
Expected: PASS, all of them. `ApiTokenTest`'s payslip cases prove the endpoint still works for staff tokens; if one fails, the guard was removed by mistake.

Note that `ApiDocsTest::test_every_scope_is_documented_in_both_files` iterates `ApiClient::SCOPES`, so dropping the entry means it simply stops requiring `payslips:read` in the docs. It must still pass untouched.

- [ ] **Step 6: Pint and PHPStan**

Run: `vendor/bin/pint --dirty --format agent`
Run: `vendor/bin/phpstan analyse --memory-limit=1G --no-progress`

- [ ] **Step 7: Commit**

```bash
git add app/Models/ApiClient.php docs/API.md public/openapi.json tests/Feature/SuperAdminApiKeyTest.php
git commit -m "feat(api): stop offering payslips as an app-key scope"
```

---

### Task 2: `App\Support\ApiReference` — the endpoint table and the agent brief

**Files:**
- Create: `app/Support/ApiReference.php`
- Test: `tests/Feature/Api/ApiDocsTest.php` (append)

**Interfaces:**
- Consumes: `ApiClient::SCOPES` (five entries after Task 1).
- Produces:
  - `ApiReference::ENDPOINTS` — `list<array{...}>`, six rows. Task 3's cards loop over this.
  - `ApiReference::agentBrief(): string` — the text the page's primary copy button puts on the clipboard.
  - `ApiReference::BASE_PATH` — `'/api/v1'`, so nothing hardcodes it twice.

**Pattern to follow:** `app/Support/Changelog.php` — a small support class holding structured content, handed to a screen by a controller. Same idea, but a constant rather than a parsed file, because this content is code-adjacent and must be type-checked.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Api/ApiDocsTest.php` (add `use App\Support\ApiReference;` and `use Illuminate\Support\Facades\Route;` to the imports):

```php
    public function test_every_registered_api_route_has_a_reference_entry_and_the_reverse(): void
    {
        $registered = collect(Route::getRoutes())
            ->map(fn ($route) => '/'.$route->uri())
            ->filter(fn (string $uri) => str_starts_with($uri, '/api/v1/'))
            ->map(fn (string $uri) => str_replace('/api/v1', '', $uri))
            ->unique()->sort()->values()->all();

        $documented = collect(ApiReference::ENDPOINTS)
            ->pluck('path')->unique()->sort()->values()->all();

        // Both directions on purpose. One catches an endpoint shipped without a card;
        // the other catches a card left behind after an endpoint was deleted.
        $this->assertSame($registered, $documented);
    }

    public function test_the_agent_brief_carries_every_grantable_scope(): void
    {
        $brief = ApiReference::agentBrief();

        foreach (array_keys(ApiClient::SCOPES) as $scope) {
            $this->assertStringContainsString($scope, $brief, "The agent brief never mentions {$scope}.");
        }
    }

    public function test_the_agent_brief_pins_the_facts_agents_get_wrong(): void
    {
        $brief = ApiReference::agentBrief();

        // Both 401 shapes. An agent told about only one writes a client that throws
        // on the other, and which one it meets depends on how the key failed.
        $this->assertStringContainsString('{"message": "Unauthenticated."}', $brief);
        $this->assertStringContainsString('{"data": null, "error": "Unauthenticated."}', $brief);

        // The silent-empty trap: a non-Monday week_start returns 200 with no rows.
        $this->assertStringContainsString('week_start', $brief);
        $this->assertStringContainsString('Monday', $brief);

        // The four constraints an agent will otherwise invent.
        $this->assertStringContainsString('Read-only', $brief);
        $this->assertStringContainsString('No webhooks', $brief);
        $this->assertStringContainsString('No pagination', $brief);
        $this->assertStringContainsString('No rate limit', $brief);
    }

    public function test_payslips_is_documented_but_marked_unavailable_to_app_keys(): void
    {
        $payslips = collect(ApiReference::ENDPOINTS)->firstWhere('path', '/payslips');

        $this->assertNotNull($payslips, 'The reference must still describe /payslips — staff tokens reach it.');
        $this->assertFalse($payslips['app_key'], 'An application key must not be able to request payslips.');
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Api/ApiDocsTest.php`
Expected: FAIL with `Class "App\Support\ApiReference" not found`.

- [ ] **Step 3: Write the class**

Create `app/Support/ApiReference.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ApiClient;

/**
 * The API's six endpoints, as one table.
 *
 * The public reference page renders its cards from this, and the block its "copy for
 * your AI" button produces is generated from it too — the two are the same array read
 * twice, so they cannot drift apart. ApiDocsTest compares this list against the routes
 * actually registered in routes/api.php, in both directions.
 */
class ApiReference
{
    /** Every endpoint hangs off this. Kept here so nothing spells it out twice. */
    public const BASE_PATH = '/api/v1';

    /**
     * One row per endpoint, in the order the page presents them.
     *
     * `app_key` is false where the scope exists but cannot be granted to an
     * application — the endpoint is still real and still documented, because a staff
     * token reaches it.
     *
     * @var list<array{path: string, scope: string, app_key: bool, title: string,
     *                 blurb: string, fields: string, query: ?string, note: ?string}>
     */
    public const ENDPOINTS = [
        [
            'path' => '/projects',
            'scope' => 'projects:read',
            'app_key' => true,
            'title' => 'Projects',
            'blurb' => 'Every active project, with its code and its category tags.',
            'fields' => 'id, code (nullable), name, categories (string[], sorted A-Z, [] when untagged)',
            'query' => null,
            'note' => 'Key your records on id. code is frequently null.',
        ],
        [
            'path' => '/employees',
            'scope' => 'employees:read',
            'app_key' => true,
            'title' => 'Employee directory',
            'blurb' => 'Active staff, with where they sit in the company.',
            'fields' => 'id, name, email, position, status, department, branch',
            'query' => null,
            'note' => 'Active staff only. Archived people are still named on historical leave records.',
        ],
        [
            'path' => '/positions',
            'scope' => 'positions:read',
            'app_key' => true,
            'title' => 'Position bands',
            'blurb' => 'Job bands, including retired ones. No salary figures.',
            'fields' => 'id, title, code, department, level, status',
            'query' => null,
            'note' => 'Retired bands are included so effort booked under a closed band still resolves to a title.',
        ],
        [
            'path' => '/timesheet-effort',
            'scope' => 'effort:read',
            'app_key' => true,
            'title' => 'Weekly effort',
            'blurb' => 'One week of effort per project, aggregated per position band.',
            'fields' => 'week_start, projects[].project_id, projects[].positions[] { position_id, position_title, headcount, person_days, days_present, alloc_pct }',
            'query' => 'week_start=YYYY-MM-DD (required, must be a Monday)',
            'note' => 'Aggregated server-side: no employee name, id or salary ever crosses the wire.',
        ],
        [
            'path' => '/leave-requests',
            'scope' => 'leave:read',
            'app_key' => true,
            'title' => 'Leave requests',
            'blurb' => 'Who is away and when, with the state of each request.',
            'fields' => 'id, employee, leave_type, date_from, date_to, days, status',
            'query' => null,
            'note' => null,
        ],
        [
            'path' => '/payslips',
            'scope' => 'payslips:read',
            'app_key' => false,
            'title' => 'Finalized payslips',
            'blurb' => 'Finalized payroll runs only. Not available to application keys.',
            'fields' => 'id, employee, period, run_status, gross, net_pay, total_deductions',
            'query' => null,
            'note' => 'Staff tokens only. No application can be issued this scope; see the key screen.',
        ],
    ];

    /**
     * The instruction block the page's primary button copies.
     *
     * Written as a brief for a coding agent working in another repository, not as
     * prose for a person: constraints first, then the endpoint table, then the two
     * error shapes and the traps that have already cost somebody an afternoon.
     */
    public static function agentBrief(): string
    {
        $root = rtrim((string) config('app.url'), '/');
        $base = $root.self::BASE_PATH;
        $specUrl = $root.'/openapi.json';

        $endpoints = collect(self::ENDPOINTS)
            ->map(function (array $e): string {
                $line = sprintf('  GET %-18s [%s]%s', $e['path'], $e['scope'], $e['app_key'] ? '' : ' (staff tokens only — cannot be granted to an app)');
                $line .= "\n      returns: ".$e['fields'];
                if ($e['query'] !== null) {
                    $line .= "\n      query:   ".$e['query'];
                }
                if ($e['note'] !== null) {
                    $line .= "\n      note:    ".$e['note'];
                }

                return $line;
            })
            ->implode("\n");

        $scopes = collect(ApiClient::SCOPES)
            ->map(fn (string $label, string $key): string => "  {$key} — {$label}")
            ->implode("\n");

        return <<<BRIEF
        You are wiring up a client for the AmanahKu API.

        BASE URL   {$base}
        AUTH       Authorization: Bearer <key>   (one key per application, issued by an AmanahKu super-admin)
        ENVELOPE   success -> {"data": ..., "error": null}
                   failure -> {"data": null, "error": "message"}

        WHAT IT IS
        AmanahKu is the source of truth for which projects exist across Unijaya and what
        kind of work each one is. It does NOT hold budget, phases or delivery cadence —
        those live in Track. Do not try to read project status from here.

        HARD CONSTRAINTS — violating any of these will break the integration:
        - Read-only. Every route is GET. There is no write endpoint and none is planned.
        - No webhooks. AmanahKu never calls you. Poll on your own schedule.
        - No pagination. Every list returns the whole company in one response.
        - No rate limit today. Do not build retry logic around 429 or X-RateLimit-*.

        ENDPOINTS
        {$endpoints}

        SCOPES A KEY CAN BE GRANTED
        {$scopes}

        ERROR SHAPES — there are two different 401 bodies, handle both:
          401  {"message": "Unauthenticated."}               key missing or unrecognised (framework default, NOT the envelope)
          401  {"data": null, "error": "Unauthenticated."}   key real but its company binding no longer holds
          403  {"data": null, "error": "This token lacks the <scope> scope."}
          5xx  {"message": "...", "reference": "..."}        reference is present for reported faults only

        TRAPS
        1. week_start MUST be an exact Monday. Any other day returns 200 with an empty
           projects array and no error at all. Validate before sending.
        2. Read both "error" and "message" when handling failures, per the two 401 shapes.
        3. A project's "code" is frequently null. Key your records on "id", never "code".
        4. A key is displayed once at creation and cannot be recovered. Store it as a secret.

        Machine-readable spec: {$specUrl} (OpenAPI 3.1)

        Now: ask me which endpoints I need, then write the client in my project's existing
        HTTP style, with the two 401 shapes and the Monday validation handled.
        BRIEF;
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Api/ApiDocsTest.php`
Expected: PASS, 6 tests (2 existing + 4 new).

If `test_every_registered_api_route_has_a_reference_entry_and_the_reverse` fails, read the diff it prints before touching `ENDPOINTS` — a mismatch may mean an endpoint really is undocumented, which is the test doing its job.

- [ ] **Step 5: Pint and PHPStan**

Run: `vendor/bin/pint --dirty --format agent`
Run: `vendor/bin/phpstan analyse --memory-limit=1G --no-progress`

Larastan may want a type on the `collect()` chains. Annotate rather than loosening the array shape.

- [ ] **Step 6: Commit**

```bash
git add app/Support/ApiReference.php tests/Feature/Api/ApiDocsTest.php
git commit -m "feat(api): hold the endpoint reference in one array the docs render from"
```

---

### Task 3: The public page at `/docs/api`

**Files:**
- Create: `app/Http/Controllers/ApiDocsController.php`
- Create: `resources/views/docs/api.blade.php`
- Modify: `routes/web.php` (public block, after the `/activate/{user}` routes at `:94-95`, before the `auth` group at `:97`)
- Modify: `resources/views/superadmin/companies/api-keys.blade.php` (one link)
- Test: `tests/Feature/ApiDocsPageTest.php`

**Interfaces:**
- Consumes: `ApiReference::ENDPOINTS`, `ApiReference::agentBrief()`, `ApiReference::BASE_PATH`, `ApiClient::SCOPES`.
- Produces: route name `docs.api` at path `/docs/api`.

**A reviewed mockup of this page exists** at `.superpowers/mockups/api-docs.html` in this worktree. Open it and port it — the layout, the palette, the section order and the copy are all settled. Two things must change on the way in: the six endpoint cards become a loop over `ApiReference::ENDPOINTS`, and the agent block comes from `ApiReference::agentBrief()` rather than being written inline in JavaScript. If that file is missing, the spec's "Sections, in order" list is the fallback.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/ApiDocsPageTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApiClient;
use App\Support\ApiReference;
use Tests\TestCase;

/**
 * The public API reference. It has no auth by design — it documents shapes, not data,
 * and openapi.json is already served unauthenticated.
 */
class ApiDocsPageTest extends TestCase
{
    public function test_a_logged_out_visitor_can_read_it(): void
    {
        $response = $this->get('/docs/api');

        $response->assertOk();
        $response->assertSee('AmanahKu API', escape: false);
    }

    public function test_it_lists_every_endpoint(): void
    {
        $response = $this->get('/docs/api');

        foreach (ApiReference::ENDPOINTS as $endpoint) {
            $response->assertSee($endpoint['path'], escape: false);
        }
    }

    public function test_it_offers_the_five_grantable_scopes_and_marks_payslips_otherwise(): void
    {
        $response = $this->get('/docs/api');

        foreach (array_keys(ApiClient::SCOPES) as $scope) {
            $response->assertSee($scope, escape: false);
        }

        // /payslips is still documented, but must not read as something you can ask for.
        $response->assertSee('/payslips', escape: false);
        $response->assertSee('Staff tokens only', escape: false);
    }

    public function test_the_agent_brief_is_embedded_so_the_copy_button_has_something_to_copy(): void
    {
        $response = $this->get('/docs/api');

        // A distinctive line from the brief, proving it was rendered into the page
        // rather than the button being wired to an empty string.
        $response->assertSee('HARD CONSTRAINTS', escape: false);
        $response->assertSee('week_start MUST be an exact Monday', escape: false);
    }

    public function test_it_does_not_leak_a_key_or_invite_anyone_to_paste_one(): void
    {
        $response = $this->get('/docs/api');

        // A public page must never hold a credential, and must not teach the habit of
        // pasting one into a public page.
        $response->assertDontSee('amk_live_', escape: false);
        $response->assertDontSee('<input type="password"', escape: false);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/ApiDocsPageTest.php`
Expected: FAIL with 404 — the route does not exist.

- [ ] **Step 3: Add the route**

In `routes/web.php`, immediately after the two `/activate/{user}` routes and before the `Route::middleware('auth')` group:

```php
// Public API reference for the apps that consume AmanahKu (Track, DevStage 01,
// SupportOS). No auth on purpose: it documents shapes, not data, and public/openapi.json
// is already served unauthenticated. Deliberately not under /api/*, where the exception
// handler renders every error as JSON — a typo'd URL there would hand a developer a raw
// JSON body instead of a 404 page.
Route::get('/docs/api', [ApiDocsController::class, 'show'])->name('docs.api');
```

Add the import alongside the other controller imports at the top of the file:

```php
use App\Http\Controllers\ApiDocsController;
```

- [ ] **Step 4: Write the controller**

Create `app/Http/Controllers/ApiDocsController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ApiClient;
use App\Support\ApiReference;
use Illuminate\View\View as ViewContract;

/**
 * The public API reference at /docs/api.
 *
 * Unauthenticated by design. Everything it renders comes from ApiReference, so the
 * page and the block its copy button produces are generated from one array and cannot
 * disagree with each other or with routes/api.php.
 */
class ApiDocsController extends Controller
{
    public function show(): ViewContract
    {
        return view('docs.api', [
            'endpoints' => ApiReference::ENDPOINTS,
            'scopes' => ApiClient::SCOPES,
            'brief' => ApiReference::agentBrief(),
            'baseUrl' => rtrim((string) config('app.url'), '/').ApiReference::BASE_PATH,
        ]);
    }
}
```

- [ ] **Step 5: Write the view**

Create `resources/views/docs/api.blade.php`, porting `.superpowers/mockups/api-docs.html`.

Structural requirements, all of which the mockup already satisfies:

- Standalone page: its own `<!DOCTYPE html>`, `{{ Vite::fonts() }}`, `@vite(['resources/css/app.css', 'resources/js/app.js'])`, `@include('partials.pwa-head')`. It is not an app-shell child — there is no user and no tenant.
- Styling via inline styles and the existing CSS variables (`--ink`, `--muted`, `--red`, `--red-tint`, `--canvas`, `--card`, `--hairline`, `--hairline-soft`, `--shelf`, `--sidebar`, `--amber-ink`, `--success`). No new Tailwind utilities.
- The base URL is printed from `$baseUrl`, never hardcoded.
- **The endpoint cards loop over `$endpoints`.** Each card shows the path, `title`, `blurb`, and either its `scope` as a chip when `app_key` is true, or the exact text `Staff tokens only` when it is false.
- **The agent brief is rendered into the page** inside `<script type="text/plain" id="agent-brief">{{ $brief }}</script>`, and the copy button reads `textContent` from that element. Do not rebuild the brief in JavaScript.
- Three buttons: *Copy everything for your AI* (the brief), *Copy OpenAPI JSON* (the absolute URL of `/openapi.json`), *Copy a test call* (a `curl` against `/projects`).
- Copy uses `navigator.clipboard.writeText`. `SecurityHeaders` already allows `script-src 'self' 'unsafe-inline'`, so an inline handler needs no CSP change. Clipboard needs a secure context, which `https://` in production and `localhost` in dev both satisfy.
- No credential appears anywhere, and there is no input inviting one. The tests assert both.

- [ ] **Step 6: Link it from the key screen**

In `resources/views/superadmin/companies/api-keys.blade.php`, after the intro paragraph at line 34, add:

```blade
        <p style="font-size:13px;color:var(--muted);max-width:680px;margin:-16px 0 24px;">The developer reference for these keys lives at <a href="{{ route('docs.api') }}" style="color:var(--red);">{{ route('docs.api') }}</a> — send it along with the key.</p>
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/ApiDocsPageTest.php tests/Feature/Api tests/Feature/SuperAdminApiKeyTest.php tests/Feature/ApiTokenTest.php`
Expected: PASS, all of them.

- [ ] **Step 8: Pint, PHPStan, and check the build**

Run: `vendor/bin/pint --dirty --format agent`
Run: `vendor/bin/phpstan analyse --memory-limit=1G --no-progress`
Run: `git status -s public/build` — if anything changed, run `lerd artisan view:cache && bun run build` and stage `public/build`.

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/ApiDocsController.php resources/views/docs/api.blade.php routes/web.php resources/views/superadmin/companies/api-keys.blade.php tests/Feature/ApiDocsPageTest.php
git commit -m "feat(docs): publish the API reference at /docs/api"
```

---

## Final verification

- [ ] **Full suite:** `php artisan test --compact` — everything green.
- [ ] **Static analysis:** `vendor/bin/phpstan analyse --memory-limit=1G --no-progress` → 0 errors.
- [ ] **Formatting:** `vendor/bin/pint --dirty --format agent` → passed.
- [ ] **Look at the page** at `http://localhost:9100/docs/api` **in a logged-out browser** (a private window, or clear the session first). Confirm it renders without redirecting to login, the language tabs switch, and all three copy buttons put the right thing on the clipboard. The agent-brief button is the one worth actually pasting somewhere to read.
- [ ] **Confirm the key screen no longer offers payslips** at `/admin/companies/{slug}/api-keys`, and that the link to the reference works.
- [ ] **Delete the scratch mockup** once the real page is up: `rm -f public/api-docs-mockup.html .superpowers/mockups/api-docs.html`.

## Out of scope, deliberately

- **A "try it" console.** It would need a key, and a public page must never hold one or teach the habit of pasting one in.
- **Search.** Six endpoints on one page; Ctrl+F is search.
- **Malay translation.** The spec's decision table explains why.
- **Retiring `docs/API.md`.** It stays as the long-form reference.
- **Versioned docs.** There is one version.
- **Removing the `payslips:read` guard from `ApiController`.** Task 1 explains why it stays.
- **Rendering `docs/API.md` into the page.** The built page is the decision; the drift test is what makes it safe.
