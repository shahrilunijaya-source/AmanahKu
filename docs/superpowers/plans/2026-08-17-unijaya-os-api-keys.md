# Unijaya OS API keys — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give each Unijaya app its own API key carrying an explicit list of read scopes, a super-admin screen to issue and revoke those keys, and committed docs an agent in another repo can wire up from.

**Architecture:** Additive throughout. A new `ApiClient` model becomes the Sanctum token's `tokenable` for app keys, so a key belongs to an app rather than to a staff member. `ApiTenant` branches on the token's `tokenable_type` and hands the controllers a plain abilities array, so no controller has to reason about what kind of caller it is serving. Every existing person-token is minted `['*']` and keeps its current role-gated behaviour byte for byte.

**Tech Stack:** Laravel 13, PHP 8.5, Sanctum 4, PHPUnit 12, Blade, Larastan 3, Pint.

**Spec:** `docs/superpowers/specs/2026-08-17-unijaya-os-api-keys-design.md`

## Global Constraints

- **Base branch:** must contain `b78e180` (which restored `GET /api/v1/positions` and `GET /api/v1/timesheet-effort`) and `23e97c8` (which added `Project::categories()`). Both are on `dev`. A branch cut from `main` has neither, and the failure is late and confusing: Task 3 finds no `positions()` to guard and Task 4 calls a relation that does not exist. **Check before Task 1:**

  ```bash
  grep -c "Route::get" routes/api.php          # must print 6
  grep -c "function categories" app/Models/Project.php   # must print 1
  ```

  If either is wrong, `git merge dev` first.
- **No new dependencies.** Everything here uses Sanctum and Laravel as already installed.
- **Read-only API.** No endpoint added, changed or planned here writes. Write access is roadmap, not this build.
- **Additive only.** `php artisan api:token`, `User::mintApiToken()`, the `isPrivileged()` role rules for person-tokens, and all 14 tests in `tests/Feature/ApiTokenTest.php` must still pass untouched. If a change breaks one of those tests, the change is wrong, not the test.
- **After any PHP edit:** `vendor/bin/pint --dirty --format agent` must report `passed`.
- **After any PHP edit:** `vendor/bin/phpstan analyse --memory-limit=1G --no-progress` must report `0` errors. This repo has already reverted a round of API work purely over static-analysis typing (`bf3a16d`, `6939900`), so run it per task, not at the end.
- **Scope vocabulary** is defined once, on `ApiClient::SCOPES`, and read from there by both the controller guards and the screen. Never duplicate the list.
- **Blade:** if a new Tailwind utility class appears, run `lerd artisan view:cache && bun run build` and commit `public/build` alongside, per CLAUDE.md.
- **Route-model binding is not tenant-scoped** in this app (`SubstituteBindings` runs before tenant resolution). Any bound model reached from a super-admin route must have its ownership checked explicitly in the controller.

---

### Task 1: `ApiClient` — the caller identity

**Files:**
- Create: `database/migrations/<timestamp>_create_api_clients_table.php`
- Create: `app/Models/ApiClient.php`
- Test: `tests/Feature/Api/ApiClientKeyTest.php`

**Interfaces:**
- Consumes: `App\Models\PersonalAccessToken` (already carries a `tenant_id` column), `App\Models\Tenant`.
- Produces:
  - `ApiClient::SCOPES` — `array<string, string>`, scope key => human label. Read by Task 3's guards and Task 5's screen.
  - `ApiClient::mintKey(array $scopes): \Laravel\Sanctum\NewAccessToken` — mints a token stamped with the client's `tenant_id`.
  - Columns: `id`, `tenant_id`, `name`, `created_by`, timestamps.

**Background the implementer needs:** Sanctum's guard only rejects a non-`User` tokenable when a provider is configured for the `sanctum` guard. This app defines no `sanctum` entry under `auth.guards` (`config/auth.php:40-45` has `web` only), so Sanctum's own default of `'provider' => null` applies and `Guard::hasValidProvider()` returns true for any tokenable that uses `HasApiTokens`. An `ApiClient` therefore authenticates through `auth:sanctum` normally. There is no morph map in `AppServiceProvider`, so `personal_access_tokens.tokenable_type` stores the fully-qualified class name.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Api/ApiClientKeyTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\ApiClient;
use App\Models\PersonalAccessToken;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Coverage for machine API keys: a key belongs to an app, not to a person, and
 * carries an explicit list of scopes rather than inheriting a staff member's role.
 */
class ApiClientKeyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'alpha', 'name' => 'Alpha', 'initials' => 'AL']);
    }

    public function test_minting_stamps_the_clients_tenant_on_the_token(): void
    {
        $client = ApiClient::create(['tenant_id' => $this->tenant->id, 'name' => 'Track']);

        $plain = $client->mintKey(['projects:read'])->plainTextToken;

        // findToken() takes the whole "{id}|{secret}" string and splits it itself.
        $token = PersonalAccessToken::findToken($plain);

        $this->assertNotNull($token);
        $this->assertSame($this->tenant->id, $token->tenant_id);
        $this->assertSame(ApiClient::class, $token->tokenable_type);
        $this->assertSame(['projects:read'], $token->abilities);
    }

    public function test_deleting_a_client_deletes_its_keys(): void
    {
        $client = ApiClient::create(['tenant_id' => $this->tenant->id, 'name' => 'Track']);
        $client->mintKey(['projects:read']);

        $this->assertSame(1, PersonalAccessToken::count());

        $client->delete();

        $this->assertSame(0, PersonalAccessToken::count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/Api/ApiClientKeyTest.php`
Expected: FAIL with `Class "App\Models\ApiClient" not found`.

- [ ] **Step 3: Create the migration**

Run: `php artisan make:migration create_api_clients_table --no-interaction`

Then replace the generated file's body with:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A consuming application, as an API caller in its own right — Track, DevStage 01,
 * SupportOS. Sanctum tokens hang off this row instead of a User, so a key survives
 * the person who issued it leaving, and carries only the scopes it was granted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // The super-admin who issued it. Nullable so removing a staff account never
            // takes a live integration's key with it.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_clients');
    }
};
```

- [ ] **Step 4: Write the model**

Create `app/Models/ApiClient.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\NewAccessToken;

/**
 * An application that reads AmanahKu's API — Track, DevStage 01, SupportOS.
 *
 * Deliberately not a User. A key minted against a staff account inherits that
 * person's role (so it sees far more than the app needs) and dies when they are
 * archived. A client row has neither problem: its authorization is entirely the
 * scope list on its token, and nothing about a person can revoke it by accident.
 *
 * Implements Authenticatable because the token guard returns the tokenable as the
 * authenticated party. It has no password and no session; bearer tokens are the
 * only way in.
 *
 * Not BelongsToTenant: the row is resolved by Sanctum during authentication, before
 * any tenant context exists, so a global scope keyed on CurrentTenant would be inert
 * exactly where isolation matters. tenant_id is set and matched explicitly instead —
 * the same choice PersonalAccessToken documents.
 */
class ApiClient extends Model implements AuthenticatableContract
{
    use Authenticatable, HasApiTokens;

    /**
     * Every scope the API understands, in the order the issuing screen lists them.
     * The single source of truth: ApiController guards against these keys and the
     * super-admin screen renders its checkboxes from them.
     *
     * @var array<string, string>
     */
    public const SCOPES = [
        'projects:read' => 'Projects and their categories',
        'employees:read' => 'Employee directory (names, emails, positions)',
        'positions:read' => 'Position bands (no salary)',
        'effort:read' => 'Weekly timesheet effort per band (no names, no salary)',
        'leave:read' => 'Leave requests',
        'payslips:read' => 'Finalized payslips',
    ];

    protected $fillable = ['tenant_id', 'name', 'created_by'];

    protected static function booted(): void
    {
        // A client without its keys is a row nobody can use; leaving orphaned tokens
        // behind would leave a live key with no screen showing it.
        static::deleting(function (ApiClient $client): void {
            $client->tokens()->delete();
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Mint a key for this client. The plaintext is returned once via
     * NewAccessToken::plainTextToken and never persisted — only its sha256 hash is
     * stored. tenant_id is stamped on the token row so ApiTenant can activate the
     * right tenant without loading the client first.
     *
     * @param  list<string>  $scopes
     */
    public function mintKey(array $scopes): NewAccessToken
    {
        $token = $this->createToken($this->name, $scopes);
        $token->accessToken->forceFill(['tenant_id' => $this->tenant_id])->save();

        return $token;
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --compact tests/Feature/Api/ApiClientKeyTest.php`
Expected: PASS, 2 tests.

- [ ] **Step 6: Run Pint and PHPStan**

Run: `vendor/bin/pint --dirty --format agent`
Expected: `{"tool":"pint","result":"passed"}`

Run: `vendor/bin/phpstan analyse --memory-limit=1G --no-progress`
Expected: `{"tool":"phpstan","result":"passed","errors":0}`

If PHPStan reports an unknown property on `$token->tenant_id` or `$token->abilities`, add `@property` annotations to `app/Models/PersonalAccessToken.php` rather than casting at the call site.

- [ ] **Step 7: Commit**

```bash
git add database/migrations app/Models/ApiClient.php tests/Feature/Api/ApiClientKeyTest.php
git commit -m "feat(api): add ApiClient, so a key belongs to an app not a person"
```

---

### Task 2: `ApiTenant` resolves machine callers

**Files:**
- Modify: `app/Http/Middleware/ApiTenant.php`
- Test: `tests/Feature/Api/ApiClientKeyTest.php` (append)

**Interfaces:**
- Consumes: `ApiClient::mintKey()` from Task 1.
- Produces, on the request attributes, for every `/api/v1` request:
  - `tokenAbilities` — `list<string>`, the token's abilities. `['*']` for every person-token.
  - `apiClient` — `ApiClient|null`. Non-null only for machine callers. Task 3 reads it.
  - `tenantRole` and `employee` — unchanged, and set **only** for person callers.

**Why abilities go on the request rather than being read from `$request->user()`:** Larastan resolves `$request->user()` to `App\Models\User` from the `web` guard's provider, so calling `ApiClient`-shaped methods on it, or narrowing it with `instanceof ApiClient`, invites static-analysis errors of exactly the kind that got the last round of API work reverted. The middleware is the one place that legitimately knows which kind of caller this is; it resolves that once and passes plain values on. Branch on the `tokenable_type` **string column**, never on `instanceof`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Api/ApiClientKeyTest.php` (and add `use App\Models\Project;` to the imports):

```php
    public function test_an_app_key_reads_a_granted_scope(): void
    {
        Project::create(['tenant_id' => $this->tenant->id, 'name' => 'iLPF', 'code' => 'UJ-1', 'is_active' => true, 'sort' => 1]);

        $client = ApiClient::create(['tenant_id' => $this->tenant->id, 'name' => 'Track']);
        $plain = $client->mintKey(['projects:read'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$plain)->getJson('/api/v1/projects');

        $response->assertOk();
        $this->assertSame('iLPF', $response->json('data.0.name'));
    }

    public function test_a_key_whose_client_moved_tenant_is_rejected(): void
    {
        $other = Tenant::create(['slug' => 'beta', 'name' => 'Beta', 'initials' => 'BE']);

        $client = ApiClient::create(['tenant_id' => $this->tenant->id, 'name' => 'Track']);
        $plain = $client->mintKey(['projects:read'])->plainTextToken;

        // The token still carries tenant A; the client now says tenant B. The two must
        // agree or the key is dead — otherwise re-homing a client silently leaves a
        // working key pointed at the tenant it used to belong to.
        $client->forceFill(['tenant_id' => $other->id])->save();

        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->getJson('/api/v1/projects')
            ->assertStatus(401);
    }

    public function test_an_app_key_cannot_see_another_companys_projects(): void
    {
        $other = Tenant::create(['slug' => 'beta', 'name' => 'Beta', 'initials' => 'BE']);
        Project::create(['tenant_id' => $other->id, 'name' => 'Beta Secret', 'code' => 'B-1', 'is_active' => true, 'sort' => 1]);
        Project::create(['tenant_id' => $this->tenant->id, 'name' => 'iLPF', 'code' => 'UJ-1', 'is_active' => true, 'sort' => 1]);

        $client = ApiClient::create(['tenant_id' => $this->tenant->id, 'name' => 'Track']);
        $plain = $client->mintKey(['projects:read'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$plain)->getJson('/api/v1/projects');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertStringNotContainsString('Beta Secret', $response->getContent() ?: '');
    }

    public function test_an_app_key_survives_every_employee_being_archived(): void
    {
        // The regression the whole design exists for. A key minted against a staff
        // account dies the moment that person is archived (ApiTenant treats it as
        // revoked membership); a client key has no person to lose.
        Project::create(['tenant_id' => $this->tenant->id, 'name' => 'iLPF', 'code' => 'UJ-1', 'is_active' => true, 'sort' => 1]);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Ali', 'status' => 'active', 'workload' => 'green',
        ]);

        $client = ApiClient::create(['tenant_id' => $this->tenant->id, 'name' => 'Track']);
        $plain = $client->mintKey(['projects:read'])->plainTextToken;

        // Archive every last one, so the update below is not a no-op against an empty
        // table — the point is that a real archived roster changes nothing for an app key.
        $archived = Employee::query()->update(['status' => 'archived']);
        $this->assertSame(1, $archived);

        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->getJson('/api/v1/projects')
            ->assertOk();
    }
```

Add `use App\Models\Employee;` to the imports for the last one.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Api/ApiClientKeyTest.php`
Expected: FAIL. `test_an_app_key_reads_a_granted_scope` returns 401, because `ApiTenant` calls `$tokenable->tenants` and an `ApiClient` has no `tenants` relation.

- [ ] **Step 3: Rewrite the middleware's `handle()`**

In `app/Http/Middleware/ApiTenant.php`, add `use App\Models\ApiClient;` to the imports and replace the whole `handle()` method with:

```php
    public function handle(Request $request, Closure $next): Response
    {
        $tokenable = $request->user();
        $token = $tokenable?->currentAccessToken();
        $tenantId = $token?->tenant_id ?? null;

        if (! $tokenable || ! $token || ! $tenantId) {
            return $this->unauthenticated();
        }

        $tenant = Tenant::find($tenantId);

        if (! $tenant) {
            return $this->unauthenticated();
        }

        // Read once here so the controllers never have to ask what kind of caller this
        // is. A person-token carries ['*'], which every scope check treats as "all".
        $request->attributes->set('tokenAbilities', array_values((array) ($token->abilities ?? [])));

        // Machine caller: an app, not a person. Branch on the string column, not on
        // instanceof — see the plan's note about static analysis.
        if ($token->tokenable_type === ApiClient::class) {
            $client = ApiClient::find($token->tokenable_id);

            // The client row and the token must agree on the tenant. Without this, moving
            // a client to another company leaves its old keys reading the old company.
            if (! $client || $client->tenant_id !== $tenant->id) {
                return $this->unauthenticated();
            }

            app(CurrentTenant::class)->set($tenant);
            $request->attributes->set('apiClient', $client);

            return $next($request);
        }

        // Person caller — unchanged from here down.
        if (! $tokenable->tenants->contains('id', $tenant->id)) {
            return $this->unauthenticated();
        }

        app(CurrentTenant::class)->set($tenant);

        // An archived staff record must not act through a lingering API token — the API
        // equivalent of EnsureNotArchived on the web stack. Treated as revoked membership (401).
        $employee = $tokenable->employeeFor($tenant);
        if ($employee && $employee->isArchived()) {
            return $this->unauthenticated();
        }

        $request->attributes->set('tenantRole', $tokenable->roleIn($tenant));
        $request->attributes->set('employee', $employee);

        return $next($request);
    }
```

Also update the class docblock's first line to read:

```php
 * Activates the tenant a Sanctum API token is bound to, for /api/v1 requests.
 *
 * Two kinds of caller arrive here. A person-token resolves to a User, and keeps the
 * membership, archived and role checks the web stack applies. An app key resolves to
 * an ApiClient, which has no role and no employee record — its scope list is the whole
 * of its authorization. Either way the token's tenant_id activates exactly one tenant,
 * so the BelongsToTenant global scope isolates every subsequent query.
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Api/ApiClientKeyTest.php tests/Feature/ApiTokenTest.php`
Expected: PASS. 6 in `ApiClientKeyTest` (2 from Task 1, 4 from this task) plus the 17 existing (the dev merge that restored the Track endpoints added three).

- [ ] **Step 5: Run Pint and PHPStan**

Run: `vendor/bin/pint --dirty --format agent`
Run: `vendor/bin/phpstan analyse --memory-limit=1G --no-progress`
Expected: both pass, 0 errors.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Middleware/ApiTenant.php tests/Feature/Api/ApiClientKeyTest.php
git commit -m "feat(api): let ApiTenant resolve an app key, not just a person's"
```

---

### Task 3: Scope guards on all six endpoints

**Files:**
- Modify: `app/Http/Controllers/Api/V1/ApiController.php`
- Test: `tests/Feature/Api/ApiClientKeyTest.php` (append)

**Interfaces:**
- Consumes: `tokenAbilities` and `apiClient` request attributes from Task 2; `ApiClient::SCOPES` from Task 1.
- Produces: a `403` with body `{"data": null, "error": "This token lacks the <scope> scope."}` when a scope is missing.

**The trap this task exists to avoid:** adding the scope guard alone is not enough. A machine caller that clears the guard still reaches `isPrivileged()`, which reads `tenantRole` off the request. `ApiTenant` sets no role for a machine caller, so the default `'employee'` applies: `employees`, `positions` and `timesheet-effort` would 403 despite a granted scope, and `leave-requests` and `payslips` would fall to the own-records branch, find no employee, and return an empty array. A granted scope would silently return nothing behind a healthy 200. `isPrivileged()` must learn about machine callers in the same task.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Api/ApiClientKeyTest.php` (`App\Models\Employee` is already imported from Task 2):

```php
    public function test_an_app_key_without_the_scope_is_refused_and_returns_no_data(): void
    {
        Project::create(['tenant_id' => $this->tenant->id, 'name' => 'iLPF', 'code' => 'UJ-1', 'is_active' => true, 'sort' => 1]);

        $client = ApiClient::create(['tenant_id' => $this->tenant->id, 'name' => 'SupportOS']);
        $plain = $client->mintKey(['employees:read'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$plain)->getJson('/api/v1/projects');

        $response->assertStatus(403);
        $this->assertNull($response->json('data'));
        $this->assertStringNotContainsString('iLPF', $response->getContent() ?: '');
    }

    public function test_an_app_key_sees_the_whole_tenant_not_an_empty_own_records_list(): void
    {
        // The regression this whole task exists for. A machine caller has no employee
        // record, so the own-records branch would hand back [] behind a healthy 200,
        // and a granted scope would look like a working integration returning nothing.
        Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Ali', 'status' => 'active', 'workload' => 'green',
        ]);

        $client = ApiClient::create(['tenant_id' => $this->tenant->id, 'name' => 'Track']);
        $plain = $client->mintKey(['employees:read'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$plain)->getJson('/api/v1/employees');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Ali', $response->json('data.0.name'));
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter='without_the_scope|whole_tenant' tests/Feature/Api/ApiClientKeyTest.php`
Expected: FAIL. The first returns 200 (no guard exists yet); the second returns 403 (`isPrivileged()` sees the default `'employee'`).

- [ ] **Step 3: Add the scope helpers and teach `isPrivileged()` about machine callers**

In `app/Http/Controllers/Api/V1/ApiController.php`, replace the existing `isPrivileged()` method with these three:

```php
    /**
     * Whether the caller's token carries a scope. A person-token is minted ['*'],
     * which Sanctum treats as every ability, so this is true for all of them and the
     * guards below are invisible to the existing token stack.
     */
    private function tokenCan(Request $request, string $scope): bool
    {
        /** @var list<string> $abilities */
        $abilities = $request->attributes->get('tokenAbilities', []);

        return in_array('*', $abilities, true) || in_array($scope, $abilities, true);
    }

    private function denyScope(string $scope): JsonResponse
    {
        return $this->error("This token lacks the {$scope} scope.", 403);
    }

    /**
     * Whether the caller may see the whole tenant rather than only its own records.
     *
     * A machine caller always may: it cleared its scope guard to get here, and the
     * super-admin who ticked that scope was the authorization act. There is no "own
     * records" for an app — it has no employee record to own any. A person-token is
     * judged exactly as before, on its tenant role.
     */
    private function isPrivileged(Request $request): bool
    {
        return $request->attributes->get('apiClient') !== null
            || in_array($request->attributes->get('tenantRole', 'employee'), self::PRIVILEGED, true);
    }
```

- [ ] **Step 4: Add one guard to each of the six actions**

At the very top of each action body, **before** any existing role check, insert the matching guard:

```php
// employees()
if (! $this->tokenCan($request, 'employees:read')) {
    return $this->denyScope('employees:read');
}

// leaveRequests()
if (! $this->tokenCan($request, 'leave:read')) {
    return $this->denyScope('leave:read');
}

// payslips()
if (! $this->tokenCan($request, 'payslips:read')) {
    return $this->denyScope('payslips:read');
}

// projects()
if (! $this->tokenCan($request, 'projects:read')) {
    return $this->denyScope('projects:read');
}

// positions()
if (! $this->tokenCan($request, 'positions:read')) {
    return $this->denyScope('positions:read');
}

// timesheetEffort()
if (! $this->tokenCan($request, 'effort:read')) {
    return $this->denyScope('effort:read');
}
```

`projects()` currently takes `Request $request` but never uses it — it does now, so no signature change is needed. Confirm every one of the six is guarded: `grep -c 'denyScope(' app/Http/Controllers/Api/V1/ApiController.php` must print `7` (six call sites plus the method definition).

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Api/ApiClientKeyTest.php tests/Feature/Api/TimesheetEffortApiTest.php tests/Feature/ApiTokenTest.php`
Expected: PASS, all of them. The `ApiTokenTest` cases (17 of them) and the `TimesheetEffortApiTest` cases must be untouched — they are the proof that `['*']` still means "everything".

- [ ] **Step 6: Run Pint and PHPStan**

Run: `vendor/bin/pint --dirty --format agent`
Run: `vendor/bin/phpstan analyse --memory-limit=1G --no-progress`
Expected: both pass, 0 errors.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Api/V1/ApiController.php tests/Feature/Api/ApiClientKeyTest.php
git commit -m "feat(api): gate every endpoint on a token scope, tenant-wide for app keys"
```

---

### Task 4: Category tags on the projects payload

**Files:**
- Modify: `app/Http/Controllers/Api/V1/ApiController.php` (the `projects()` action)
- Test: `tests/Feature/Api/ApiClientKeyTest.php` (append)

**Interfaces:**
- Consumes: `Project::categories()` — the `belongsToMany(TimesheetCategory::class, 'project_timesheet_category')` relation added in `23e97c8`.
- Produces: each project row gains `categories`, a `list<string>` of category names. Existing keys `id`, `code`, `name` are unchanged.

**Why:** the category tags (`Development`, `Maintenance`, `InHouse Project`, `Sales`) are the development-versus-maintenance distinction that makes a Unijaya project what it is. A consumer that cannot see them cannot tell two projects apart in any way that matters. Additive, so Track's current call is unaffected.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Api/ApiClientKeyTest.php` (add `use App\Models\TimesheetCategory;` to the imports):

```php
    public function test_projects_carry_their_category_tags_and_an_untagged_project_carries_none(): void
    {
        $dev = TimesheetCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Development', 'is_active' => true, 'sort' => 1]);
        $maint = TimesheetCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Maintenance', 'is_active' => true, 'sort' => 2]);

        $tagged = Project::create(['tenant_id' => $this->tenant->id, 'name' => 'iLPF', 'code' => 'UJ-1', 'is_active' => true, 'sort' => 1]);
        $tagged->categories()->sync([$dev->id, $maint->id]);

        Project::create(['tenant_id' => $this->tenant->id, 'name' => 'Untagged', 'code' => 'UJ-2', 'is_active' => true, 'sort' => 2]);

        $client = ApiClient::create(['tenant_id' => $this->tenant->id, 'name' => 'Track']);
        $plain = $client->mintKey(['projects:read'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$plain)->getJson('/api/v1/projects');

        $response->assertOk();
        $this->assertSame(['Development', 'Maintenance'], $response->json('data.0.categories'));
        $this->assertSame([], $response->json('data.1.categories'));
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=category_tags tests/Feature/Api/ApiClientKeyTest.php`
Expected: FAIL — `data.0.categories` is null, the key does not exist yet.

- [ ] **Step 3: Add the tags to the payload**

In `projects()`, replace the query and map with:

```php
        // Eager-loaded: without it the map below fires one query per project.
        $projects = Project::where('is_active', true)
            ->with('categories:id,name')
            ->orderBy('sort')
            ->orderBy('name')
            ->get()
            ->map(fn (Project $p) => [
                'id' => $p->id,
                'code' => $p->code,
                'name' => $p->name,
                // Names, not ids: a category id means nothing outside AmanahKu, and a
                // consumer matching on "Development" needs no second lookup.
                'categories' => $p->categories->pluck('name')->values()->all(),
            ]);
```

Update the action's docblock to:

```php
    /** GET /api/v1/projects — the tenant's active projects and their category tags. */
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Api/ApiClientKeyTest.php tests/Feature/ApiTokenTest.php`
Expected: PASS. `test_any_valid_token_lists_tenant_active_projects` must still pass untouched — that is the check that the addition stayed additive.

- [ ] **Step 5: Run Pint and PHPStan**

Run: `vendor/bin/pint --dirty --format agent`
Run: `vendor/bin/phpstan analyse --memory-limit=1G --no-progress`
Expected: both pass, 0 errors.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/V1/ApiController.php tests/Feature/Api/ApiClientKeyTest.php
git commit -m "feat(api): return each project's category tags"
```

---

### Task 5: The super-admin key screen

**Files:**
- Create: `app/Http/Controllers/SuperAdmin/ApiKeyController.php`
- Create: `resources/views/superadmin/companies/api-keys.blade.php`
- Modify: `routes/web.php` (inside the `super.admin` group, after the features routes at `:118-119`)
- Modify: `resources/views/superadmin/companies/show.blade.php:40` (add the entry button)
- Test: `tests/Feature/SuperAdminApiKeyTest.php`

**Interfaces:**
- Consumes: `ApiClient::SCOPES` and `ApiClient::mintKey()` from Task 1.
- Produces route names `superadmin.companies.api-keys`, `superadmin.companies.api-keys.store`, `superadmin.companies.api-keys.revoke`.

**Two things that will bite:** route-model binding is not tenant-scoped in this app, so the bound `PersonalAccessToken` on the revoke route can be *any* tenant's token and the controller must check ownership itself. And the plaintext key exists for exactly one response — it is flashed, never stored, and a refresh must not show it again.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/SuperAdminApiKeyTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApiClient;
use App\Models\PersonalAccessToken;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Covers issuing and revoking machine API keys from the super-admin console.
 */
class SuperAdminApiKeyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'alpha', 'name' => 'Alpha', 'initials' => 'AL']);
    }

    private function superAdmin(): User
    {
        $u = User::create(['name' => 'Platform', 'email' => 'super@example.com', 'password' => Hash::make('password')]);
        $u->forceFill(['is_super_admin' => true])->save();

        return $u;
    }

    private function ordinaryUser(): User
    {
        $u = User::create(['name' => 'Joe', 'email' => 'joe@example.com', 'password' => Hash::make('password')]);
        $u->tenants()->attach($this->tenant->id, ['role' => 'hr']);

        return $u;
    }

    public function test_an_ordinary_user_cannot_reach_the_screen(): void
    {
        $this->actingAs($this->ordinaryUser())
            ->get(route('superadmin.companies.api-keys', $this->tenant))
            ->assertStatus(403);
    }

    public function test_creating_shows_the_key_once_and_never_again(): void
    {
        $admin = $this->superAdmin();

        $response = $this->actingAs($admin)->post(route('superadmin.companies.api-keys.store', $this->tenant), [
            'name' => 'Track',
            'scopes' => ['projects:read'],
        ]);

        $plain = session('newKey');
        $this->assertIsString($plain);
        $this->assertNotEmpty($plain);
        $response->assertRedirect();

        // The follow-up render must not carry it — only the sha256 hash is stored, so
        // there is nothing to show a second time even if the screen wanted to.
        $this->actingAs($admin)
            ->get(route('superadmin.companies.api-keys', $this->tenant))
            ->assertOk()
            ->assertDontSee($plain);
    }

    public function test_a_created_key_works_and_stops_working_once_revoked(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('superadmin.companies.api-keys.store', $this->tenant), [
            'name' => 'Track',
            'scopes' => ['projects:read'],
        ]);

        $plain = session('newKey');

        $this->withHeader('Authorization', 'Bearer '.$plain)->getJson('/api/v1/projects')->assertOk();

        $token = PersonalAccessToken::firstOrFail();

        $this->actingAs($admin)
            ->post(route('superadmin.companies.api-keys.revoke', [$this->tenant, $token]))
            ->assertRedirect();

        $this->withHeader('Authorization', 'Bearer '.$plain)->getJson('/api/v1/projects')->assertStatus(401);
    }

    public function test_a_token_belonging_to_another_company_cannot_be_revoked_through_this_one(): void
    {
        // Route-model binding in this app resolves across every tenant, so the bound
        // token here is another company's. The controller has to refuse it itself.
        $other = Tenant::create(['slug' => 'beta', 'name' => 'Beta', 'initials' => 'BE']);
        $client = ApiClient::create(['tenant_id' => $other->id, 'name' => 'Other']);
        $client->mintKey(['projects:read']);

        $token = PersonalAccessToken::firstOrFail();

        $this->actingAs($this->superAdmin())
            ->post(route('superadmin.companies.api-keys.revoke', [$this->tenant, $token]))
            ->assertStatus(404);

        $this->assertSame(1, PersonalAccessToken::count());
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/SuperAdminApiKeyTest.php`
Expected: FAIL with `Route [superadmin.companies.api-keys] not defined`.

- [ ] **Step 3: Add the routes**

In `routes/web.php`, inside the `super.admin` group, immediately after the two `companies.features` routes:

```php
        // Machine API keys, per company. An app key belongs to an ApiClient rather than
        // a staff member, so it survives that person leaving and carries only the scopes
        // ticked here. Super-admin only: a key that reads projects is one mis-tick away
        // from a key that reads payslips.
        Route::get('/companies/{tenant:slug}/api-keys', [ApiKeyController::class, 'index'])->name('companies.api-keys');
        Route::post('/companies/{tenant:slug}/api-keys', [ApiKeyController::class, 'store'])->name('companies.api-keys.store');
        Route::post('/companies/{tenant:slug}/api-keys/{token}/revoke', [ApiKeyController::class, 'revoke'])->name('companies.api-keys.revoke');
```

Add the import at the top of `routes/web.php`, alphabetically among the other `SuperAdmin` controller imports:

```php
use App\Http\Controllers\SuperAdmin\ApiKeyController;
```

- [ ] **Step 4: Write the controller**

Create `app/Http/Controllers/SuperAdmin/ApiKeyController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use App\Models\AuditLog;
use App\Models\PersonalAccessToken;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View as ViewContract;

/**
 * Issue and revoke machine API keys for one company.
 *
 * The plaintext is rendered exactly once, on the redirect straight after minting.
 * Only its sha256 hash is stored, so there is no second chance and no recovery flow:
 * a lost key is revoked and replaced, same as a leaked one.
 *
 * Sits behind super.admin alongside the feature matrix. This is the one screen that
 * makes an integration possible on production, where there is no shell.
 */
class ApiKeyController extends Controller
{
    /** Every key issued for this company, with its scopes and last use. */
    public function index(Tenant $tenant): ViewContract
    {
        return view('superadmin.companies.api-keys', [
            'company' => $tenant,
            'clients' => ApiClient::where('tenant_id', $tenant->id)
                ->with(['creator:id,name', 'tokens'])
                ->orderBy('name')
                ->get(),
            'scopes' => ApiClient::SCOPES,
        ]);
    }

    /** Create a client and mint its one key, flashing the plaintext for a single render. */
    public function store(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in(array_keys(ApiClient::SCOPES))],
        ]);

        $client = ApiClient::create([
            'tenant_id' => $tenant->id,
            'name' => $data['name'],
            'created_by' => $request->user()?->id,
        ]);

        $plain = $client->mintKey(array_values($data['scopes']))->plainTextToken;

        AuditLog::create([
            'tenant_id' => $tenant->id,
            'user_id' => $request->user()?->id,
            'actor_name' => $request->user()?->name ?? 'Super Admin',
            'action' => 'Issued API key',
            'target' => $client->name.' ('.implode(', ', $data['scopes']).')',
        ]);

        return back()
            ->with('ok', $client->name.' can now read the API.')
            ->with('newKey', $plain)
            ->with('newKeyName', $client->name);
    }

    /**
     * Revoke one key. Route-model binding resolves a token across every tenant
     * (SubstituteBindings runs before any tenant is active), so the token's own
     * tenant_id is checked here rather than assumed from the URL.
     */
    public function revoke(Request $request, Tenant $tenant, PersonalAccessToken $token): RedirectResponse
    {
        abort_unless($token->tenant_id === $tenant->id, 404);

        $name = $token->name;
        $token->delete();

        AuditLog::create([
            'tenant_id' => $tenant->id,
            'user_id' => $request->user()?->id,
            'actor_name' => $request->user()?->name ?? 'Super Admin',
            'action' => 'Revoked API key',
            'target' => $name,
        ]);

        return back()->with('ok', $name.' can no longer read the API.');
    }
}
```

- [ ] **Step 5: Write the view**

Create `resources/views/superadmin/companies/api-keys.blade.php`:

```blade
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $company->name }} · API keys</title>
    {{ Vite::fonts() }}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        .ak-card{background:#fff;border:1px solid var(--hairline,#e6e6ec);border-radius:12px;padding:18px;margin-bottom:14px;}
        .ak-name{font-weight:600;color:var(--ink);font-size:14.5px;}
        .ak-meta{font-size:12px;color:var(--muted);margin-top:3px;}
        .ak-scope{display:inline-block;font-size:11px;font-family:var(--font-mono);background:var(--hairline-soft);border:1px solid var(--hairline,#e6e6ec);color:var(--ink);padding:2px 8px;border-radius:9999px;margin:4px 4px 0 0;}
        .ak-btn{padding:8px 14px;border:none;border-radius:9px;font-size:13px;font-weight:600;background:var(--red);color:#fff;cursor:pointer;}
        .ak-revoke{padding:6px 12px;border:1px solid #f3c6c8;border-radius:9px;font-size:12px;font-weight:600;background:#fff;color:#a81820;cursor:pointer;}
        .ak-label{display:block;font-size:12px;font-weight:600;color:var(--ink);margin-bottom:5px;}
        .ak-input{width:100%;padding:9px 11px;border:1px solid var(--hairline,#e6e6ec);border-radius:9px;font-size:13.5px;font-family:inherit;color:var(--ink);}
        .ak-key{font-family:var(--font-mono);font-size:13px;word-break:break-all;background:#fff;border:1px solid #f3c6c8;border-radius:9px;padding:12px;margin-top:10px;}
    </style>
@include('partials.pwa-head')
</head>
<body>
<div style="min-height:100vh;background:var(--canvas);padding:48px 24px;">
    <div style="max-width:860px;margin:0 auto;">
        <a href="{{ route('superadmin.companies.show', $company) }}" style="font-size:13px;color:var(--muted);text-decoration:none;">← {{ $company->name }}</a>

        <div style="display:flex;align-items:center;gap:14px;margin:18px 0 8px;">
            <div style="width:48px;height:48px;border-radius:11px;background:{{ $company->color }};color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:18px;">{{ $company->initials }}</div>
            <div>
                <h1 style="font-weight:500;font-size:24px;letter-spacing:-0.4px;color:var(--ink);margin:0;">API keys</h1>
                <div style="font-size:13px;color:var(--muted);">One key per application, reading only what it is ticked for</div>
            </div>
        </div>
        <p style="font-size:13px;color:var(--muted);max-width:680px;margin:0 0 24px;">A key belongs to an application, not to a person, so nobody leaving breaks an integration. The key text is shown once when you create it and cannot be recovered afterwards — if it is lost, revoke it and create another.</p>

        @if (session('ok'))
            <div style="background:#eaf6f1;border:1px solid #bfe3d3;color:#0f5132;border-radius:10px;padding:13px 16px;margin-bottom:18px;font-size:14px;">{{ session('ok') }}</div>
        @endif
        @if ($errors->any())
            <div style="background:#fbeaeb;border:1px solid #f3c6c8;color:#a81820;border-radius:10px;padding:13px 16px;margin-bottom:18px;font-size:14px;">{{ $errors->first() }}</div>
        @endif

        @if (session('newKey'))
            <div style="background:#fbeaeb;border:1px solid #f3c6c8;border-radius:12px;padding:16px 18px;margin-bottom:22px;">
                <div style="font-weight:600;color:#a81820;font-size:14px;">Copy {{ session('newKeyName') }}'s key now</div>
                <div style="font-size:12.5px;color:#a81820;margin-top:3px;">This is the only time it will ever be shown. Leaving this page loses it for good.</div>
                <div class="ak-key" id="ak-new-key">{{ session('newKey') }}</div>
                <button class="ak-btn" style="margin-top:10px;" onclick="navigator.clipboard.writeText(document.getElementById('ak-new-key').textContent.trim());this.textContent='Copied';">Copy key</button>
            </div>
        @endif

        <div class="ak-card">
            <div class="ak-name" style="margin-bottom:14px;">Issue a key</div>
            <form method="POST" action="{{ route('superadmin.companies.api-keys.store', $company) }}">
                @csrf
                <label class="ak-label" for="ak-app-name">Application</label>
                <input class="ak-input" id="ak-app-name" name="name" required maxlength="80" placeholder="Track" value="{{ old('name') }}">

                <div class="ak-label" style="margin-top:16px;">May read</div>
                @foreach ($scopes as $key => $label)
                    <label style="display:flex;gap:9px;align-items:flex-start;padding:7px 0;font-size:13.5px;color:var(--ink);">
                        <input type="checkbox" name="scopes[]" value="{{ $key }}" style="margin-top:3px;">
                        <span>
                            <span style="font-family:var(--font-mono);font-size:12px;color:var(--muted);">{{ $key }}</span><br>
                            {{ $label }}
                        </span>
                    </label>
                @endforeach

                <button class="ak-btn" style="margin-top:16px;">Create key</button>
            </form>
        </div>

        @forelse ($clients as $client)
            @foreach ($client->tokens as $token)
                <div class="ak-card" style="display:flex;justify-content:space-between;gap:18px;align-items:flex-start;">
                    <div>
                        <div class="ak-name">{{ $client->name }}</div>
                        <div class="ak-meta">
                            Issued by {{ $client->creator?->name ?? 'unknown' }} on {{ $token->created_at?->format('j M Y') }}
                            · {{ $token->last_used_at ? 'last used '.$token->last_used_at->diffForHumans() : 'never used' }}
                        </div>
                        <div>
                            @foreach ((array) $token->abilities as $ability)
                                <span class="ak-scope">{{ $ability }}</span>
                            @endforeach
                        </div>
                    </div>
                    <form method="POST" action="{{ route('superadmin.companies.api-keys.revoke', [$company, $token]) }}"
                          onsubmit="return confirm('Revoke {{ $client->name }}\'s key? It stops working immediately.');">
                        @csrf
                        <button class="ak-revoke">Revoke</button>
                    </form>
                </div>
            @endforeach
        @empty
            <div class="ak-card" style="color:var(--muted);font-size:13.5px;">No keys issued for {{ $company->name }} yet.</div>
        @endforelse
    </div>
</div>
</body>
</html>
```

- [ ] **Step 6: Add the entry button**

In `resources/views/superadmin/companies/show.blade.php`, immediately after the existing "Feature matrix →" anchor on line 40, add a sibling with identical styling:

```blade
            <a href="{{ route('superadmin.companies.api-keys', $company) }}" class="uj-btn" style="text-decoration:none;padding:10px 16px;border:1px solid var(--hairline,#e6e6ec);border-radius:10px;font-size:13.5px;font-weight:600;background:#fff;color:var(--ink);">API keys →</a>
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/SuperAdminApiKeyTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 8: Run Pint, PHPStan, and rebuild assets if needed**

Run: `vendor/bin/pint --dirty --format agent`
Run: `vendor/bin/phpstan analyse --memory-limit=1G --no-progress`
Expected: both pass, 0 errors.

The view above uses only inline styles and existing CSS variables, so no Tailwind rebuild should be needed. Confirm with `git status -s public/build` — if anything changed, run `lerd artisan view:cache && bun run build` and stage `public/build`.

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/SuperAdmin/ApiKeyController.php resources/views/superadmin/companies/api-keys.blade.php resources/views/superadmin/companies/show.blade.php routes/web.php tests/Feature/SuperAdminApiKeyTest.php
git commit -m "feat(superadmin): issue and revoke per-app API keys from the console"
```

---

### Task 6: Agent-first docs

**Files:**
- Create: `docs/API.md`
- Create: `public/openapi.json`
- Test: `tests/Feature/Api/ApiDocsTest.php`

**Interfaces:**
- Consumes: `ApiClient::SCOPES` from Task 1; the six routes in `routes/api.php`.
- Produces: no code interface. The test is a drift guard, so adding a scope without documenting it fails a test rather than shipping.

**Audience:** a developer or a coding agent working in the DevStage 01, Track or SupportOS repo who has never seen AmanahKu. Be explicit about what is *not* there — no write, no push, no pagination — because an agent will otherwise invent it.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Api/ApiDocsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\ApiClient;
use Tests\TestCase;

/**
 * The docs are hand-written files, not generated, so nothing but this test stops
 * them drifting from the code. It fails the moment a scope exists that no consumer
 * could have read about.
 */
class ApiDocsTest extends TestCase
{
    public function test_every_scope_is_documented_in_both_files(): void
    {
        $markdown = file_get_contents(base_path('docs/API.md'));
        $openapi = file_get_contents(public_path('openapi.json'));

        $this->assertIsString($markdown);
        $this->assertIsString($openapi);

        foreach (array_keys(ApiClient::SCOPES) as $scope) {
            $this->assertStringContainsString($scope, $markdown, "docs/API.md does not mention the {$scope} scope.");
            $this->assertStringContainsString($scope, $openapi, "public/openapi.json does not mention the {$scope} scope.");
        }
    }

    public function test_the_openapi_file_is_valid_json_and_lists_every_route(): void
    {
        $spec = json_decode((string) file_get_contents(public_path('openapi.json')), true);

        $this->assertIsArray($spec);

        foreach (['/employees', '/leave-requests', '/payslips', '/projects', '/positions', '/timesheet-effort'] as $path) {
            $this->assertArrayHasKey($path, $spec['paths'], "openapi.json is missing {$path}.");
        }
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/Api/ApiDocsTest.php`
Expected: FAIL — `file_get_contents(): Failed to open stream` for `docs/API.md`.

- [ ] **Step 3: Write `docs/API.md`**

Create `docs/API.md` with these sections, in this order. Fill each response example by running the endpoint locally and pasting what it actually returns — do not hand-write a shape you have not seen.

1. **What this is** — AmanahKu is the source of truth for which projects exist in Unijaya and what kind each is. It answers *which projects exist and what kind they are*, not *how they are going*: budget, phases and delivery cadence live in Track, on purpose, so there are not two records to keep current.
2. **What it is not** — read-only, no write of any kind; no webhooks or callbacks, consumers poll; no pagination, every list returns the whole tenant; no rate limit today.
3. **Authentication** — `Authorization: Bearer <key>` on every request. Keys are issued per application from AmanahKu's super-admin console and carry a fixed list of scopes. A key is bound to exactly one company; there is no way to read another's data with it.
4. **Envelope** — every response is `{"data": …, "error": null}` on success and `{"data": null, "error": "message"}` on failure. `401` means the key is missing, unknown, or its company binding no longer holds. `403` means the key is valid but lacks the scope for that endpoint. `5xx` responses also carry a `reference` key; quote it when reporting a fault.
5. **Endpoints** — one section each for the six, in `ApiClient::SCOPES` order. For each: the path, the required scope, what it returns, a real response body, and a copy-pasteable `curl`. Note that `/timesheet-effort` requires a `week_start=YYYY-MM-DD` query parameter and that its figures deliberately carry no employee name, id or salary.
6. **Scopes** — the table from `ApiClient::SCOPES`, with a line saying `effort:read` should only be granted to an app that costs against it.

Example `curl` block to follow for each endpoint:

```bash
curl -H "Authorization: Bearer $AMANAHKU_KEY" https://amanahku.unijaya.com/api/v1/projects
```

- [ ] **Step 4: Write `public/openapi.json`**

Create an OpenAPI 3.1 document covering the same six paths. It must:

- declare `bearerAuth` under `components.securitySchemes` as `{"type": "http", "scheme": "bearer"}` and apply it globally
- give every path a `summary`, the response schema for `data`, and a `description` naming the required scope by its exact key (that is what the test greps for)
- document `401` and `403` responses with the `{data, error}` envelope
- declare `week_start` as a required query parameter of type `string`, format `date`, on `/timesheet-effort`

Follow this shape for every path, filling the `data` schema from the response you
actually observed:

```json
{
  "openapi": "3.1.0",
  "info": { "title": "AmanahKu API", "version": "1.0.0",
            "description": "Read-only. AmanahKu is the source of truth for which projects exist in Unijaya and what kind each is." },
  "servers": [{ "url": "https://amanahku.unijaya.com/api/v1" }],
  "security": [{ "bearerAuth": [] }],
  "components": {
    "securitySchemes": { "bearerAuth": { "type": "http", "scheme": "bearer" } },
    "schemas": {
      "Error": { "type": "object", "properties": {
        "data": { "type": "null" }, "error": { "type": "string" } } }
    },
    "responses": {
      "Unauthenticated": { "description": "Key missing, unknown, or its company binding no longer holds.",
        "content": { "application/json": { "schema": { "$ref": "#/components/schemas/Error" } } } },
      "MissingScope": { "description": "Key is valid but lacks the required scope.",
        "content": { "application/json": { "schema": { "$ref": "#/components/schemas/Error" } } } }
    }
  },
  "paths": {
    "/projects": {
      "get": {
        "summary": "Active projects and their category tags",
        "description": "Requires the projects:read scope.",
        "responses": {
          "200": { "description": "Every active project in the key's company.",
            "content": { "application/json": { "schema": { "type": "object", "properties": {
              "data": { "type": "array", "items": { "type": "object", "properties": {
                "id": { "type": "integer" },
                "code": { "type": ["string", "null"] },
                "name": { "type": "string" },
                "categories": { "type": "array", "items": { "type": "string" } }
              } } },
              "error": { "type": "null" }
            } } } } },
          "401": { "$ref": "#/components/responses/Unauthenticated" },
          "403": { "$ref": "#/components/responses/MissingScope" }
        }
      }
    }
  }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Api/ApiDocsTest.php`
Expected: PASS, 2 tests.

- [ ] **Step 6: Run the whole API suite**

Run: `php artisan test --compact tests/Feature/Api tests/Feature/ApiTokenTest.php tests/Feature/SuperAdminApiKeyTest.php`
Expected: PASS, everything.

- [ ] **Step 7: Commit**

```bash
git add docs/API.md public/openapi.json tests/Feature/Api/ApiDocsTest.php
git commit -m "docs(api): document the v1 endpoints for the apps that consume them"
```

---

## Final verification

- [ ] **Run the full suite:** `php artisan test --compact`
  Everything must pass. If anything outside the API area broke, that is a real regression, not an acceptable cost.
- [ ] **Static analysis:** `vendor/bin/phpstan analyse --memory-limit=1G --no-progress` → 0 errors.
- [ ] **Formatting:** `vendor/bin/pint --dirty --format agent` → passed.
- [ ] **Migration runs on the dev database:** `lerd artisan migrate`. Tests use a separate database, so a green suite does not prove the dev app still boots.
- [ ] **Set a token prefix** so keys are greppable and secret scanners can spot one committed by accident. Add `SANCTUM_TOKEN_PREFIX=amk_` to `.env` and `.env.example` (`config/sanctum.php:68` already reads it). Existing tokens are unaffected — the prefix applies to newly minted ones only.
  **A deploy does not carry `.env`.** The line has to be added to the staging host's own `.env` before the first key is minted there, and later to production's, or the prefix quietly does not apply on the host where it matters. Production's `.env` is devops-owned, so that half is a request, not a task.
- [ ] **Walk the screen by hand** at `http://localhost:9100/admin/companies/unijaya/api-keys`: create a key, confirm the plaintext shows once, refresh and confirm it is gone, call `/api/v1/projects` with it, revoke it, call again and get a 401.

## Out of scope, deliberately

Do not add any of these, even if they seem natural while working:

- **Write endpoints.** Roadmap.
- **Webhooks or push.** Roadmap, and blocked behind the production queue failures regardless.
- **Removing person-tokens or `php artisan api:token`.** This build is additive.
- **Track's settings form.** Different repository, and already done there (`679f8e3`).
- **Rate limiting per key, or key expiry.** Three first-party apps on internal infrastructure; revocation is the control that matters. `expires_at` stays null.
- **Sub-pillars in the projects payload.** Nothing has asked for them.
