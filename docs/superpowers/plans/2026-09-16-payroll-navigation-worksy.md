# Payroll Navigation (Worksy layout) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Split today's single `payroll` screen into Worksy's six groups (My Payroll, Transaction, Process, Payroll Review, Payment, Form) as sidebar children, each a tabbed screen, with every Worksy page either wired to the existing backend or shown as a "not yet available" stub.

**Architecture:** Navigation only. `Amanahku::nav()` gains six children under `payroll`; `AppController::screen` gates the five HR screens, redirects the parent, and feeds all six from the existing `payrollData()`. Today's `screens/payroll.blade.php` is cut into partials under `partials/payroll/<group>/`, each screen blade is a tab shell that includes them. One migration (`payslips.acknowledged_at`) and one new POST route (acknowledge). No calculator, model or export changes.

**Tech Stack:** Laravel 13 / PHP 8.5, Blade + Alpine 3, PHPUnit (sqlite), Pint, bun for assets.

**Spec:** `docs/superpowers/specs/2026-09-16-payroll-navigation-worksy-design.md`

## Global Constraints

- Screen ids, exactly: `payroll` (parent, landing), `payroll-my`, `payroll-transaction`, `payroll-process`, `payroll-review`, `payroll-payment`, `payroll-form`.
- MS labels, exactly: Gaji Saya, Transaksi, Proses, Semakan Gaji, Pembayaran, Borang. Official form names (Borang A, CP39, EA Form, CP8D, Form E) stay English in both languages.
- HR gate = `authorizeTenantRole($request, ['management', 'hr'])` (director collapses into management). Employees and managers get 403 on the five HR screens.
- `Features::MODULES['module.payroll']` lists all seven ids. Module off = all seven 404 and hidden from the sidebar.
- Tab deep link: `?tab=<id>`; Alpine writes the current tab back with `history.replaceState`.
- Every new label bilingual via `x-text="$store.ui.lang==='en' ? ... : ..."` (the pattern the payroll blade already uses).
- Stub card text: title, one sentence, pill "Spec F<n>" or "Follow-up". Card must contain the literal "Not yet available".
- Commit on `dev`, no worktree, no push. Run `vendor/bin/pint --dirty --format agent` before every commit that touches PHP.
- Dev DB migrate only via `lerd artisan migrate`. Tests: `php artisan test --compact <file>`.
- After Blade/CSS changes at the end: `lerd artisan view:clear && lerd artisan view:cache && bun run build`, commit `public/build` if it changed.
- No em dashes in new copy or commit messages.
- Do not rewrite markup that only moves. Cut and paste blocks from `resources/views/screens/payroll.blade.php` at commit `52ed8c4b` by the line ranges given; then fix only the route/screen references named in the task.

---

## File map

Create:
- `resources/views/partials/payroll/tabs.blade.php` (tab strip, takes `$tabs`)
- `resources/views/partials/payroll/stub.blade.php` (takes `$title`, `$body`, `$bodyMs`, `$pill`)
- `resources/views/screens/payroll-my.blade.php`, `payroll-transaction.blade.php`, `payroll-process.blade.php`, `payroll-review.blade.php`, `payroll-payment.blade.php`, `payroll-form.blade.php`
- `resources/views/partials/payroll/my/payslip.blade.php`, `my/ea-form.blade.php`
- `resources/views/partials/payroll/transaction/fixed.blade.php`, `transaction/individual.blade.php`, `transaction/takeon.blade.php`, `transaction/items.blade.php`
- `resources/views/partials/payroll/process/monthly.blade.php`
- `resources/views/partials/payroll/review/individual.blade.php`, `review/ea-form.blade.php`, `review/bulk-ea.blade.php`
- `resources/views/partials/payroll/payment/payout.blade.php`, `payment/submission.blade.php`, `payment/payslip.blade.php`, `payment/bulk-payslip.blade.php`, `payment/cp8d.blade.php`
- `resources/views/partials/payroll/form/form-e.blade.php`
- `database/migrations/2026_09_29_100400_add_acknowledged_at_to_payslips.php`
- `tests/Feature/PayrollNavigationTest.php`

Modify:
- `app/Support/Amanahku.php` (nav entry line 148, screen map line 361)
- `app/Support/Features.php` (line 34)
- `app/Http/Controllers/AppController.php` (screen gate ~162, 404 gate ~190, data map ~553)
- `app/Http/Controllers/Concerns/BuildsWorkData.php::payrollData` (add `myEaYears`, `payoutYear`, `payoutRuns`, `salaryEmployees` keeps)
- `app/Http/Controllers/PayrollController.php` (acknowledge, destroyRun redirect, finalize notification link)
- `app/Http/Controllers/EaFormController.php::show` (own-employee access)
- `routes/web.php` (acknowledge route inside the payroll throttle group, line ~742)
- `resources/views/layouts/app.blade.php` line 190 (`$wideScreens`)
- `resources/views/screens/profile.blade.php` Money tab (~line 660)
- `tests/Feature/ShippedScopeTest.php`, `tests/Feature/AllScreensRenderTest.php`

Delete (last task): `resources/views/screens/payroll.blade.php`.

---

### Task 1: Nav, gates, parent redirect, empty screens

**Files:**
- Modify: `app/Support/Amanahku.php:148`, `app/Support/Amanahku.php:361`
- Modify: `app/Support/Features.php:34`
- Modify: `app/Http/Controllers/AppController.php:128-192`, `:553`
- Modify: `resources/views/layouts/app.blade.php:190`
- Create: six `resources/views/screens/payroll-*.blade.php` (minimal shells, filled in later tasks)
- Modify: `tests/Feature/ShippedScopeTest.php:44-52`, `tests/Feature/AllScreensRenderTest.php:33`
- Test: `tests/Feature/PayrollNavigationTest.php`

**Interfaces:**
- Produces: screen ids above; `$data` for each of the six ids = `payrollData($request, $employee)` (same keys as today, including `privileged`).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/PayrollNavigationTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Support\FeatureManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PayrollNavigationTest extends TestCase
{
    use RefreshDatabase;

    private const HR_SCREENS = ['payroll-transaction', 'payroll-process', 'payroll-review', 'payroll-payment', 'payroll-form'];

    private const ALL_SCREENS = ['payroll', 'payroll-my', 'payroll-transaction', 'payroll-process', 'payroll-review', 'payroll-payment', 'payroll-form'];

    private Tenant $tenant;

    private User $hr;

    private User $manager;

    private User $empUser;

    private Employee $emp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);

        $this->hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $this->hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);
        Employee::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->hr->id, 'name' => 'Boss', 'status' => 'active', 'workload' => 'green']);

        $this->manager = User::create(['name' => 'Lead', 'email' => 'lead@example.com', 'password' => Hash::make('password')]);
        $this->manager->tenants()->attach($this->tenant->id, ['role' => 'manager']);
        Employee::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->manager->id, 'name' => 'Lead', 'status' => 'active', 'workload' => 'green']);

        $this->empUser = User::create(['name' => 'Worker', 'email' => 'worker@example.com', 'password' => Hash::make('password')]);
        $this->empUser->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->emp = Employee::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->empUser->id, 'name' => 'Worker', 'status' => 'active', 'workload' => 'green', 'salary' => 4000]);
    }

    private function acting(User $user): self
    {
        $this->actingAs($user)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    public function test_hr_opens_every_payroll_screen(): void
    {
        foreach (array_merge(['payroll-my'], self::HR_SCREENS) as $screen) {
            $this->acting($this->hr)->get("/app/{$screen}")->assertOk();
        }
    }

    public function test_employee_opens_my_payroll_but_not_the_hr_screens(): void
    {
        $this->acting($this->empUser)->get('/app/payroll-my')->assertOk();

        foreach (self::HR_SCREENS as $screen) {
            $this->acting($this->empUser)->get("/app/{$screen}")->assertForbidden();
        }
    }

    public function test_manager_cannot_open_the_hr_screens(): void
    {
        foreach (self::HR_SCREENS as $screen) {
            $this->acting($this->manager)->get("/app/{$screen}")->assertForbidden();
        }
    }

    public function test_parent_payroll_lands_by_role(): void
    {
        $this->acting($this->hr)->get('/app/payroll')->assertRedirect('/app/payroll-process');
        $this->acting($this->empUser)->get('/app/payroll')->assertRedirect('/app/payroll-my');
    }

    public function test_sidebar_lists_the_six_children_for_hr_and_only_my_payroll_for_staff(): void
    {
        $hr = $this->acting($this->hr)->get('/app/dash')->assertOk()->getContent();
        foreach (self::HR_SCREENS as $screen) {
            $this->assertStringContainsString(route('app.screen', ['screen' => $screen]), $hr);
        }
        $this->assertStringContainsString(route('app.screen', ['screen' => 'payroll-my']), $hr);

        $staff = $this->acting($this->empUser)->get('/app/dash')->assertOk()->getContent();
        $this->assertStringContainsString(route('app.screen', ['screen' => 'payroll-my']), $staff);
        foreach (self::HR_SCREENS as $screen) {
            $this->assertStringNotContainsString(route('app.screen', ['screen' => $screen]).'"', $staff);
        }
    }

    public function test_module_off_hides_all_seven_ids(): void
    {
        app(FeatureManager::class)->setTenant($this->tenant, 'module.payroll', false);

        foreach (self::ALL_SCREENS as $screen) {
            $this->acting($this->hr)->get("/app/{$screen}")->assertNotFound();
        }

        $html = $this->acting($this->hr)->get('/app/dash')->assertOk()->getContent();
        $this->assertStringNotContainsString('>Payroll<', $html);
        $this->assertStringNotContainsString(route('app.screen', ['screen' => 'payroll-my']), $html);
    }
}
```

- [ ] **Step 2: Run it, expect failures**

Run: `php artisan test --compact tests/Feature/PayrollNavigationTest.php`
Expected: FAIL. `payroll-my` 404 (not in any module), parent renders 200 instead of redirecting, sidebar lacks child links.

- [ ] **Step 3: Nav children + screen map**

`app/Support/Amanahku.php` line 148, replace the payroll `$s(...)` line with:

```php
            $s('Pay & Benefits', 'Gaji & Faedah', ['id' => 'payroll', 'label' => 'Payroll', 'label_ms' => 'Gaji', 'landing' => true, 'icon' => 'M2 7a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2zM12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6M6 8v8M18 8v8', 'children' => [
                ['id' => 'payroll-my', 'label' => 'My Payroll', 'label_ms' => 'Gaji Saya'],
                ['id' => 'payroll-transaction', 'label' => 'Transaction', 'label_ms' => 'Transaksi', 'roles' => ['management', 'hr']],
                ['id' => 'payroll-process', 'label' => 'Process', 'label_ms' => 'Proses', 'roles' => ['management', 'hr']],
                ['id' => 'payroll-review', 'label' => 'Payroll Review', 'label_ms' => 'Semakan Gaji', 'roles' => ['management', 'hr']],
                ['id' => 'payroll-payment', 'label' => 'Payment', 'label_ms' => 'Pembayaran', 'roles' => ['management', 'hr']],
                ['id' => 'payroll-form', 'label' => 'Form', 'label_ms' => 'Borang', 'roles' => ['management', 'hr']],
            ]]),
```

(`roles` on a child is honoured by `BuildsNav::navModel`, role already collapsed by `Permissions::effectiveRole`.)

Line 361, replace the `'payroll' => [...]` screen-map entry with seven entries:

```php
            'payroll' => ['title' => 'Payroll', 'title_ms' => 'Gaji', 'sub' => 'Monthly payroll, payslips and statutory forms.', 'sub_ms' => 'Gaji bulanan, slip gaji dan borang berkanun.', 'crumb' => ['Payroll']],
            'payroll-my' => ['title' => 'My Payroll', 'title_ms' => 'Gaji Saya', 'sub' => 'Your payslips, EA form and tax relief.', 'sub_ms' => 'Slip gaji, borang EA dan pelepasan cukai anda.', 'crumb' => ['Payroll', 'My Payroll']],
            'payroll-transaction' => ['title' => 'Transaction', 'title_ms' => 'Transaksi', 'sub' => 'Recurring and one-off pay lines, opening figures and the payroll item catalogue.', 'sub_ms' => 'Baris gaji tetap dan sekali, angka pembukaan dan katalog item gaji.', 'crumb' => ['Payroll', 'Transaction']],
            'payroll-process' => ['title' => 'Process', 'title_ms' => 'Proses', 'sub' => 'Create and track the monthly payroll run.', 'sub_ms' => 'Buat dan jejak run gaji bulanan.', 'crumb' => ['Payroll', 'Process']],
            'payroll-review' => ['title' => 'Payroll Review', 'title_ms' => 'Semakan Gaji', 'sub' => 'Check each payslip before payout, and EA forms per staff.', 'sub_ms' => 'Semak setiap slip gaji sebelum bayaran, dan borang EA setiap staf.', 'crumb' => ['Payroll', 'Payroll Review']],
            'payroll-payment' => ['title' => 'Payment', 'title_ms' => 'Pembayaran', 'sub' => 'Approve, finalize, and produce bank and statutory files.', 'sub_ms' => 'Lulus, finalize, dan hasilkan fail bank dan berkanun.', 'crumb' => ['Payroll', 'Payment']],
            'payroll-form' => ['title' => 'Form', 'title_ms' => 'Borang', 'sub' => 'Statutory forms for LHDN, KWSP, PERKESO and HRDF.', 'sub_ms' => 'Borang berkanun untuk LHDN, KWSP, PERKESO dan HRDF.', 'crumb' => ['Payroll', 'Form']],
```

- [ ] **Step 4: Feature module and wide screens**

`app/Support/Features.php` line 34:

```php
        'module.payroll' => ['Payroll & Compensation', ['payroll', 'payroll-my', 'payroll-transaction', 'payroll-process', 'payroll-review', 'payroll-payment', 'payroll-form'], 2],
```

`resources/views/layouts/app.blade.php` line 190, append to `$wideScreens`:

```php
                'messages', 'orgchart', 'board', 'side-quests', 'progression', 'profile',
                'payroll-my', 'payroll-transaction', 'payroll-process', 'payroll-review', 'payroll-payment', 'payroll-form'];
```

- [ ] **Step 5: AppController gate, redirect, data**

`app/Http/Controllers/AppController.php`:

Change the signature at line 129 to `public function screen(Request $request, string $screen = 'dash'): ViewContract|RedirectResponse` (`RedirectResponse` is already imported).

Inside `screen()`, right after the onboarding-content block (before the feature-gate comment, ~line 187) add:

```php
        if (in_array($screen, ['payroll-transaction', 'payroll-process', 'payroll-review', 'payroll-payment', 'payroll-form'], true)) {
            $this->authorizeTenantRole($request, ['management', 'hr']);
        }
```

Right after the `abort(404)` feature gate (line ~192) add:

```php
        // Parent "Payroll" is a landing: HR and management start at Process, everyone
        // else at their own payslips.
        if ($screen === 'payroll') {
            return redirect()->route('app.screen', $this->hasTenantRole($request, ['management', 'hr']) ? 'payroll-process' : 'payroll-my');
        }
```

In the data map (line ~553), replace `'payroll' => $this->payrollData($request, $employee),` with:

```php
            'payroll', 'payroll-my', 'payroll-transaction', 'payroll-process', 'payroll-review', 'payroll-payment', 'payroll-form' => $this->payrollData($request, $employee),
```

- [ ] **Step 6: Six shell blades**

Create each of the six files with this minimal content (title differs per file; the body is replaced in later tasks):

```blade
@extends('layouts.app')

@section('screen')
<div class="uj-card" style="padding:20px;">My Payroll</div>
@endsection
```

Titles: `payroll-my` "My Payroll", `payroll-transaction` "Transaction", `payroll-process` "Process", `payroll-review` "Payroll Review", `payroll-payment` "Payment", `payroll-form` "Form".

- [ ] **Step 7: Scope test lists**

`tests/Feature/ShippedScopeTest.php` OUT_OF_SCOPE line 46: replace `'payroll',` with `'payroll', 'payroll-my', 'payroll-transaction', 'payroll-process', 'payroll-review', 'payroll-payment', 'payroll-form',`.

`tests/Feature/AllScreensRenderTest.php` line 33: replace `'payroll',` with `'payroll-my', 'payroll-transaction', 'payroll-process', 'payroll-review', 'payroll-payment', 'payroll-form',` (the parent redirects now, so it leaves the 200 list).

- [ ] **Step 8: Run tests**

Run: `php artisan test --compact tests/Feature/PayrollNavigationTest.php tests/Feature/ShippedScopeTest.php tests/Feature/AllScreensRenderTest.php tests/Feature/SidebarNavTest.php tests/Feature/FeatureEnforcementTest.php`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A app resources tests
git commit -m "feat(payroll): six Worksy-style payroll screens in the sidebar, parent lands by role"
```

---

### Task 2: Tab shell and stub partials, every stub tab in place

**Files:**
- Create: `resources/views/partials/payroll/tabs.blade.php`, `resources/views/partials/payroll/stub.blade.php`
- Modify: the six screen blades
- Test: `tests/Feature/PayrollNavigationTest.php`

**Interfaces:**
- `tabs.blade.php` consumes `$tabs` = `array<string, array{0: string, 1: string}>` (id => [EN, MS]). Must be included inside an element with Alpine `tab` state.
- `stub.blade.php` consumes `$title` (string), `$body` (EN sentence), `$bodyMs` (MS sentence), `$pill` (string like `Spec F8` or `Follow-up`).
- Every screen blade sets `$tabs`, computes `$tab` from `request('tab')`, and wraps panes in `<div x-data="{ tab: @js($tab) }" x-init="$watch('tab', t => { const u = new URL(location.href); u.searchParams.set('tab', t); history.replaceState(null, '', u); })">`.

- [ ] **Step 1: Write the failing test**

Append to `PayrollNavigationTest`:

```php
    /** @return array<string, list<string>> screen => tab ids that are stubs */
    private static function stubTabs(): array
    {
        return [
            'payroll-my' => ['tp1'],
            'payroll-transaction' => ['cp38', 'rebate', 'tp1'],
            'payroll-process' => ['bonus', 'control'],
            'payroll-review' => ['batch-remove'],
            'payroll-payment' => ['audit'],
            'payroll-form' => ['borang-a', 'borang-8a', 'cp39', 'cp21', 'cp22', 'cp22a', 'sip2', 'pcb2', 'zakat', 'hrdf'],
        ];
    }

    public function test_every_stub_tab_renders_the_not_yet_available_card(): void
    {
        foreach (self::stubTabs() as $screen => $tabs) {
            $html = $this->acting($this->hr)->get("/app/{$screen}")->assertOk()->getContent();
            foreach ($tabs as $tab) {
                $this->assertStringContainsString("x-show=\"tab === '{$tab}'\"", $html, "{$screen} lacks tab {$tab}");
            }
            $this->assertStringContainsString('Not yet available', $html);
            $this->assertMatchesRegularExpression('/Spec F\d+|Follow-up/', $html);
        }
    }

    public function test_tab_query_selects_the_opening_tab(): void
    {
        $this->acting($this->hr)->get('/app/payroll-form?tab=hrdf')->assertOk()->assertSee("x-data=\"{ tab: 'hrdf' }\"", false);
        $this->acting($this->hr)->get('/app/payroll-form?tab=nope')->assertOk()->assertSee("x-data=\"{ tab: 'form-e' }\"", false);
    }
```

- [ ] **Step 2: Run, expect failure**

Run: `php artisan test --compact tests/Feature/PayrollNavigationTest.php --filter=stub`
Expected: FAIL, no `x-show` panes.

- [ ] **Step 3: Tab strip partial**

`resources/views/partials/payroll/tabs.blade.php`:

```blade
{{-- Tab strip shared by the payroll screens. $tabs: id => [EN, MS]. Needs Alpine `tab` in scope. --}}
<div style="display:flex;gap:4px;margin-bottom:16px;border-bottom:1px solid var(--hairline);flex-wrap:wrap;">
    @foreach ($tabs as $id => [$en, $ms])
        <button type="button" @click="tab = @js($id)" :style="tab === @js($id) ? { color:'var(--red)', borderBottom:'2px solid var(--red)' } : { color:'var(--muted)', borderBottom:'2px solid transparent' }" style="background:none;padding:9px 14px;font-size:13px;font-weight:500;cursor:pointer;margin-bottom:-1px;" x-text="$store.ui.lang==='en' ? @js($en) : @js($ms)">{{ $en }}</button>
    @endforeach
</div>
```

- [ ] **Step 4: Stub partial**

`resources/views/partials/payroll/stub.blade.php`:

```blade
{{-- "Not yet available" card for a Worksy page with no backend yet. $title, $body, $bodyMs, $pill. --}}
<div class="uj-card" style="max-width:640px;padding:26px;">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
        <h3 class="uj-card-title" style="margin:0;">{{ $title }}</h3>
        <span class="uj-pill" style="background:var(--canvas);border:1px solid var(--hairline);color:var(--muted);font-size:10.5px;">{{ $pill }}</span>
    </div>
    <div style="font-size:13px;font-weight:600;color:var(--ink);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Not yet available' : 'Belum tersedia'">Not yet available</div>
    <p style="font-size:12.5px;color:var(--muted);margin:0;" x-text="$store.ui.lang==='en' ? @js($body) : @js($bodyMs)">{{ $body }}</p>
</div>
```

- [ ] **Step 5: Screen shells with every tab (stubs now, real content later)**

Each screen blade gets this shape. Shown in full for `payroll-form.blade.php`; the others follow with their own `$tabs` and default:

```blade
@extends('layouts.app')

@php
    $tabs = [
        'form-e' => ['LHDN Form E', 'LHDN Form E'],
        'borang-a' => ['EPF Borang A', 'EPF Borang A'],
        'borang-8a' => ['Perkeso Borang 8A', 'Perkeso Borang 8A'],
        'cp39' => ['LHDN CP39', 'LHDN CP39'],
        'cp21' => ['CP21', 'CP21'],
        'cp22' => ['CP22', 'CP22'],
        'cp22a' => ['CP22A', 'CP22A'],
        'sip2' => ['Borang SIP 2', 'Borang SIP 2'],
        'pcb2' => ['PCB II', 'PCB II'],
        'zakat' => ['Zakat', 'Zakat'],
        'hrdf' => ['HRDF', 'HRDF'],
    ];
    $tab = array_key_exists((string) request('tab'), $tabs) ? (string) request('tab') : 'form-e';
@endphp

@section('screen')
<div x-data="{ tab: @js($tab) }" x-init="$watch('tab', t => { const u = new URL(location.href); u.searchParams.set('tab', t); history.replaceState(null, '', u); })">
    @include('partials.payroll.tabs', ['tabs' => $tabs])

    <div x-show="tab === 'form-e'" x-cloak>
        @include('partials.payroll.stub', ['title' => 'LHDN Form E', 'body' => 'Filled in Task 8.', 'bodyMs' => 'Diisi dalam Task 8.', 'pill' => 'Follow-up'])
    </div>
    <div x-show="tab === 'borang-a'" x-cloak>
        @include('partials.payroll.stub', ['title' => 'EPF Borang A', 'body' => 'Monthly KWSP contribution form generated from the finalized run.', 'bodyMs' => 'Borang caruman KWSP bulanan dijana daripada run yang difinalize.', 'pill' => 'Spec F6'])
    </div>
    <div x-show="tab === 'borang-8a'" x-cloak>
        @include('partials.payroll.stub', ['title' => 'Perkeso Borang 8A', 'body' => 'Monthly SOCSO contribution form generated from the finalized run.', 'bodyMs' => 'Borang caruman PERKESO bulanan dijana daripada run yang difinalize.', 'pill' => 'Spec F6'])
    </div>
    <div x-show="tab === 'cp39'" x-cloak>
        @include('partials.payroll.stub', ['title' => 'LHDN CP39', 'body' => 'Monthly PCB remittance form generated from the finalized run.', 'bodyMs' => 'Borang remitan PCB bulanan dijana daripada run yang difinalize.', 'pill' => 'Spec F6'])
    </div>
    <div x-show="tab === 'cp21'" x-cloak>
        @include('partials.payroll.stub', ['title' => 'CP21', 'body' => 'Notice for a staff member leaving Malaysia.', 'bodyMs' => 'Notis untuk staf yang meninggalkan Malaysia.', 'pill' => 'Spec F11'])
    </div>
    <div x-show="tab === 'cp22'" x-cloak>
        @include('partials.payroll.stub', ['title' => 'CP22', 'body' => 'Notice of a new employee to LHDN.', 'bodyMs' => 'Notis pekerja baharu kepada LHDN.', 'pill' => 'Spec F11'])
    </div>
    <div x-show="tab === 'cp22a'" x-cloak>
        @include('partials.payroll.stub', ['title' => 'CP22A', 'body' => 'Notice of cessation of employment to LHDN.', 'bodyMs' => 'Notis pemberhentian kerja kepada LHDN.', 'pill' => 'Spec F11'])
    </div>
    <div x-show="tab === 'sip2'" x-cloak>
        @include('partials.payroll.stub', ['title' => 'Borang SIP 2', 'body' => 'EIS notice of loss of employment.', 'bodyMs' => 'Notis SIP kehilangan pekerjaan.', 'pill' => 'Spec F11'])
    </div>
    <div x-show="tab === 'pcb2'" x-cloak>
        @include('partials.payroll.stub', ['title' => 'PCB II', 'body' => 'Annual PCB statement per staff member.', 'bodyMs' => 'Penyata PCB tahunan setiap staf.', 'pill' => 'Follow-up'])
    </div>
    <div x-show="tab === 'zakat'" x-cloak>
        @include('partials.payroll.stub', ['title' => 'Zakat', 'body' => 'Zakat deduction listing for the year.', 'bodyMs' => 'Senarai potongan zakat untuk tahun ini.', 'pill' => 'Follow-up'])
    </div>
    <div x-show="tab === 'hrdf'" x-cloak>
        @include('partials.payroll.stub', ['title' => 'HRDF', 'body' => 'HRD Corp levy return from the finalized run.', 'bodyMs' => 'Penyata levi HRD Corp daripada run yang difinalize.', 'pill' => 'Spec F7'])
    </div>
</div>
@endsection
```

The other five, same skeleton, with these `$tabs` (id => [EN, MS]) and defaults. Stub sentences and pills listed; non-stub tabs use a temporary stub with pill `Follow-up` and body "Filled in Task N." until that task lands.

`payroll-my.blade.php`, default `payslip`:
```php
    $tabs = ['payslip' => ['Payslip', 'Slip Gaji'], 'ea-form' => ['EA Form', 'EA Form'], 'tp1' => ['Personal Tax Relief (TP1)', 'Pelepasan Cukai (TP1)']];
```
tp1 stub: "Declare your personal tax reliefs so PCB is computed on the right base." / "Isytihar pelepasan cukai peribadi supaya PCB dikira atas asas yang betul." pill `Spec F8`.

`payroll-transaction.blade.php`, default `fixed`:
```php
    $tabs = [
        'fixed' => ['Fixed Transaction', 'Transaksi Tetap'],
        'individual' => ['Individual Transaction', 'Transaksi Individu'],
        'cp38' => ['CP38', 'CP38'],
        'rebate' => ['Tax Rebate', 'Rebat Cukai'],
        'takeon' => ['Payroll Figures Take On', 'Angka Pembukaan Gaji'],
        'tp1' => ['Personal Tax Relief (TP1)', 'Pelepasan Cukai (TP1)'],
        'items' => ['Payroll Items', 'Item Gaji'],
    ];
```
cp38: "Extra monthly tax instalment ordered by LHDN for a staff member." / "Ansuran cukai tambahan bulanan yang diarahkan LHDN untuk seorang staf." pill `Spec F9`. rebate: "Zakat and levy offsets against PCB." / "Tolakan zakat dan levi terhadap PCB." pill `Follow-up`. tp1: same as My Payroll tp1, pill `Spec F8`.

`payroll-process.blade.php`, default `monthly`:
```php
    $tabs = ['monthly' => ['Monthly', 'Bulanan'], 'bonus' => ['Bonus', 'Bonus'], 'control' => ['Payroll Control', 'Kawalan Gaji']];
```
bonus: "A separate bonus run with its own PCB treatment." / "Run bonus berasingan dengan layanan PCB tersendiri." pill `Spec F10`. control: "Lock or unlock a pay month with a remark and attachment, and see who changed what." / "Kunci atau buka bulan gaji dengan catatan dan lampiran, dan lihat siapa mengubah apa." pill `Follow-up`.

`payroll-review.blade.php`, default `individual`:
```php
    $tabs = ['individual' => ['Individual Payroll', 'Gaji Individu'], 'batch-remove' => ['Batch Remove Payslip', 'Buang Slip Berkelompok'], 'ea-form' => ['EA Form', 'EA Form'], 'bulk-ea' => ['Bulk EA Form', 'EA Form Pukal']];
```
batch-remove: "Drop several staff from a draft run at once." / "Keluarkan beberapa staf daripada run draf sekali gus." pill `Follow-up`.

`payroll-payment.blade.php`, default `payout`:
```php
    $tabs = [
        'payout' => ['Payout Management', 'Pengurusan Bayaran'],
        'submission' => ['Bank/Statutory Submission', 'Penyerahan Bank/Berkanun'],
        'payslip' => ['Individual Pay Slip', 'Slip Gaji Individu'],
        'bulk-payslip' => ['Bulk Pay Slip', 'Slip Gaji Pukal'],
        'cp8d' => ['LHDN CP8D', 'LHDN CP8D'],
        'audit' => ['IRB Audit Files', 'Fail Audit LHDN'],
    ];
```
audit: "Audit file export in LHDN's format." / "Eksport fail audit dalam format LHDN." pill `Follow-up`.

- [ ] **Step 6: Run tests**

Run: `php artisan test --compact tests/Feature/PayrollNavigationTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add resources tests
git commit -m "feat(payroll): tab shells for the six payroll screens, stub cards for pages without a backend"
```

---

### Task 3: My Payroll: Payslip tab with Acknowledge, EA Form tab

**Files:**
- Create: `database/migrations/2026_09_29_100400_add_acknowledged_at_to_payslips.php`
- Modify: `app/Models/Payslip.php` (cast), `routes/web.php:742`, `app/Http/Controllers/PayrollController.php`, `app/Http/Controllers/EaFormController.php:37-47`, `app/Http/Controllers/Concerns/BuildsWorkData.php::payrollData`
- Create: `resources/views/partials/payroll/my/payslip.blade.php`, `resources/views/partials/payroll/my/ea-form.blade.php`
- Modify: `resources/views/screens/payroll-my.blade.php`
- Test: `tests/Feature/PayrollNavigationTest.php`

**Interfaces:**
- Route `payroll.payslips.acknowledge`: `POST /app/payroll/payslips/{payslip}/acknowledge`, `PayrollController::acknowledgePayslip(Request, Payslip): RedirectResponse`.
- `payrollData` gains `'myEaYears' => list<int>` (years of the viewer's finalized payslips, newest first).
- `EaFormController::show` allows the employee's own record (same rule `pdf` already uses).

- [ ] **Step 1: Failing tests**

Append to `PayrollNavigationTest` (add `use App\Models\AuditLog; use App\Models\PayrollRun; use App\Models\Payslip;`):

```php
    private function finalizedPayslipFor(Employee $employee, string $period = '2026-03'): Payslip
    {
        $run = PayrollRun::forceCreate(['tenant_id' => $this->tenant->id, 'period' => $period, 'status' => 'finalized', 'finalized_at' => now()]);
        $slip = new Payslip(['employee_id' => $employee->id]);
        $slip->tenant_id = $this->tenant->id;
        $slip->payroll_run_id = $run->id;
        $slip->forceFill(['basic' => 4000, 'gross' => 4000, 'net_pay' => 3500])->save();

        return $slip;
    }

    public function test_my_payroll_shows_own_slip_and_acknowledges_it_once(): void
    {
        $slip = $this->finalizedPayslipFor($this->emp);

        $this->acting($this->empUser)->get('/app/payroll-my?payslip='.$slip->id)->assertOk()
            ->assertSee(route('payroll.payslips.acknowledge', $slip), false)
            ->assertSee('EA Form');

        $this->acting($this->empUser)->post(route('payroll.payslips.acknowledge', $slip))
            ->assertRedirect(route('app.screen', ['screen' => 'payroll-my', 'payslip' => $slip->id]));
        $this->assertNotNull($slip->fresh()->acknowledged_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'Acknowledged payslip']);

        $this->acting($this->empUser)->post(route('payroll.payslips.acknowledge', $slip))->assertStatus(422);
        $this->acting($this->empUser)->get('/app/payroll-my?payslip='.$slip->id)->assertOk()->assertSee('Acknowledged on');
    }

    public function test_cannot_acknowledge_another_persons_payslip(): void
    {
        $other = Employee::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->manager->id, 'name' => 'Lead2', 'status' => 'active', 'workload' => 'green']);
        $slip = $this->finalizedPayslipFor($other);

        $this->acting($this->empUser)->post(route('payroll.payslips.acknowledge', $slip))->assertForbidden();
        $this->assertNull($slip->fresh()->acknowledged_at);
    }

    public function test_employee_can_view_own_ea_form_but_not_anothers(): void
    {
        $this->finalizedPayslipFor($this->emp);
        $this->acting($this->empUser)->get(route('payroll.ea-form.show', ['employee' => $this->emp->id, 'year' => 2026]))->assertOk();

        $other = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Other', 'status' => 'active', 'workload' => 'green']);
        $this->acting($this->empUser)->get(route('payroll.ea-form.show', ['employee' => $other->id, 'year' => 2026]))->assertForbidden();
    }
```

If `PayrollRun` or `Payslip` require other columns on create, read `tests/Feature/PayrollTest.php` for how it builds runs and copy that.

- [ ] **Step 2: Run, expect failure**

Run: `php artisan test --compact tests/Feature/PayrollNavigationTest.php --filter="acknowledge|ea_form"`
Expected: FAIL, route not defined.

- [ ] **Step 3: Migration + cast**

```bash
php artisan make:migration add_acknowledged_at_to_payslips --table=payslips --no-interaction
```
Rename the file to `2026_09_29_100400_add_acknowledged_at_to_payslips.php`. Body:

```php
    public function up(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->timestamp('acknowledged_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->dropColumn('acknowledged_at');
        });
    }
```
If `payslips` has no `finalized_at` column, drop the `->after(...)`.

`app/Models/Payslip.php` casts: add `'acknowledged_at' => 'datetime',`.

- [ ] **Step 4: Route + controller**

`routes/web.php` after line 742 (`payroll.payslips.update`):

```php
            Route::post('/app/payroll/payslips/{payslip}/acknowledge', [PayrollController::class, 'acknowledgePayslip'])->name('payroll.payslips.acknowledge');
```

`PayrollController`, after `updatePayslip`:

```php
    /**
     * A staff member confirms they have seen their own issued payslip. Once only, own
     * slip only, finalized runs only. HR does not acknowledge on anyone's behalf.
     */
    public function acknowledgePayslip(Request $request, Payslip $payslip): RedirectResponse
    {
        $employee = $request->attributes->get('employee');
        $tenant = app(CurrentTenant::class)->get();
        abort_unless($tenant && $payslip->tenant_id === $tenant->id, 403);
        abort_unless($employee && $payslip->employee_id === $employee->id, 403);
        abort_unless($payslip->payrollRun?->status === 'finalized', 422);
        abort_if($payslip->acknowledged_at !== null, 422);

        $payslip->forceFill(['acknowledged_at' => now()])->save();
        AuditLog::record('Acknowledged payslip', $payslip->payrollRun->label);

        return redirect()->route('app.screen', ['screen' => 'payroll-my', 'payslip' => $payslip->id])->with('ok', 'Payslip acknowledged.');
    }
```

Check the file's existing `use` lines for `CurrentTenant` and `RedirectResponse`; add if missing. If the controller reads the tenant some other way (grep `CurrentTenant` in the file), copy that way.

`EaFormController::show` lines 37-41: replace `$this->authorizeTenantRole($request, self::ADMIN_ROLES);` with the same block `pdf` uses:

```php
        if (! $this->hasTenantRole($request, self::ADMIN_ROLES)) {
            $requester = $request->attributes->get('employee');
            abort_unless($requester && $requester->id === $employee->id, 403);
        }
```

- [ ] **Step 5: Data**

`BuildsWorkData::payrollData`: after `$myPayslips` is built, add

```php
        $myEaYears = $myPayslips->map(fn ($p) => (int) substr((string) $p->payrollRun?->period, 0, 4))->filter()->unique()->sortDesc()->values()->all();
```
and put `'myEaYears' => $myEaYears,` into both return arrays (non-privileged and privileged).

- [ ] **Step 6: Payslip partial**

`resources/views/partials/payroll/my/payslip.blade.php`: paste lines 44-224 of `screens/payroll.blade.php` at 52ed8c4b (the `@if (!empty($selectedPayslip))` detail block through the employee list, dropping the `@elseif (empty($privileged))` line and the trailing `@else`). Wrap as:

```blade
@php
    $statusColor = ['draft' => 'var(--amber)', 'approved' => 'var(--info)', 'finalized' => 'var(--success)'];
    $statusMs = ['draft' => 'Draf', 'approved' => 'Diluluskan', 'finalized' => 'Difinalize'];
    $money = fn ($v) => 'RM '.number_format((float) $v, 2);
@endphp
@if (!empty($selectedPayslip))
    ... detail block (lines 45-199) ...
@else
    ... list block (lines 203-222) ...
@endif
```

Edits inside the pasted block:
- Every `route('app.screen', 'payroll')` and `route('app.screen', ['screen' => 'payroll', 'payslip' => $p->id])` becomes the `payroll-my` equivalent.
- The "Back to payroll" text becomes "Back to my payslips" / "Kembali ke slip gaji saya".
- Remove the privileged EA-form link (`@if (!empty($privileged) && $p->employee_id && $run->period)` ... `@endif`, lines 62-64); the EA Form tab covers it.
- After the Download PDF link inside `@if ($run?->status === 'finalized')`, add:

```blade
                @if ($p->acknowledged_at)
                    <span style="font-size:12px;color:var(--success);"><span x-text="$store.ui.lang==='en' ? 'Acknowledged on' : 'Diakui pada'">Acknowledged on</span> {{ $p->acknowledged_at->format('d M Y') }}</span>
                @elseif (($employee ?? null)?->id === $p->employee_id)
                    <form method="post" action="{{ route('payroll.payslips.acknowledge', $p) }}">@csrf<button class="uj-btn-primary" style="height:34px;padding:0 12px;font-size:12px;" x-text="$store.ui.lang==='en' ? 'Acknowledge' : 'Akui'">Acknowledge</button></form>
                @endif
```

`$employee` is not passed to screens by default. In `payroll-my.blade.php`, add `@php $employee = request()->attributes->get('employee'); @endphp` at the top.

- [ ] **Step 7: EA Form partial**

`resources/views/partials/payroll/my/ea-form.blade.php`:

```blade
@php $me = request()->attributes->get('employee'); @endphp
<div class="uj-card" style="max-width:680px;">
    <div class="uj-card-head"><h3 class="uj-card-title">EA Form</h3></div>
    @forelse ($myEaYears ?? [] as $year)
        <div style="display:flex;align-items:center;justify-content:space-between;gap:14px;padding:14px 20px;border-bottom:1px solid var(--hairline-soft);">
            <div style="font-size:13.5px;color:var(--ink);font-weight:500;">{{ $year }}</div>
            <div style="display:flex;gap:8px;">
                <a href="{{ route('payroll.ea-form.show', ['employee' => $me?->id, 'year' => $year]) }}" class="uj-btn-ghost" style="height:32px;padding:0 12px;font-size:12px;display:inline-flex;align-items:center;text-decoration:none;" x-text="$store.ui.lang==='en' ? 'View' : 'Lihat'">View</a>
                <a href="{{ route('payroll.ea-form.pdf', ['employee' => $me?->id, 'year' => $year]) }}" class="uj-btn-ghost" style="height:32px;padding:0 12px;font-size:12px;display:inline-flex;align-items:center;text-decoration:none;">PDF</a>
            </div>
        </div>
    @empty
        <div style="padding:28px 20px;text-align:center;color:var(--muted);font-size:12.5px;" x-text="$store.ui.lang==='en' ? 'Your EA form appears here once a payslip has been issued for the year.' : 'Borang EA anda muncul di sini setelah slip gaji dikeluarkan untuk tahun itu.'">Your EA form appears here once a payslip has been issued for the year.</div>
    @endforelse
</div>
```

- [ ] **Step 8: Wire the screen**

In `payroll-my.blade.php`, replace the two temporary stub includes:

```blade
    <div x-show="tab === 'payslip'" x-cloak>@include('partials.payroll.my.payslip')</div>
    <div x-show="tab === 'ea-form'" x-cloak>@include('partials.payroll.my.ea-form')</div>
```

Also move the non-privileged guide (`partials.guide` block, lines 11-42 of the old blade, employee branch text only) to the top of the `@section('screen')` in `payroll-my.blade.php` with `'key' => 'payroll-my'` and no `steps`.

- [ ] **Step 9: Migrate dev DB, run tests**

```bash
lerd artisan migrate
php artisan test --compact tests/Feature/PayrollNavigationTest.php tests/Feature/PayrollTest.php tests/Feature/EaFormDataTest.php
```
Expected: PASS.

- [ ] **Step 10: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A app database resources routes tests
git commit -m "feat(payroll): My Payroll screen with payslip acknowledge and own EA form"
```

---

### Task 4: Transaction screen (Fixed, Individual, Take On, Items)

**Files:**
- Create: `resources/views/partials/payroll/transaction/fixed.blade.php`, `individual.blade.php`, `takeon.blade.php`, `items.blade.php`
- Modify: `resources/views/screens/payroll-transaction.blade.php`
- Test: `tests/Feature/PayrollNavigationTest.php`, `tests/Feature/FixedTransactionTest.php`, `tests/Feature/IndividualTransactionTest.php` (if present), `tests/Feature/PayrollItemsTest.php` (if present)

**Interfaces:**
- Consumes from `payrollData`: `salaryEmployees`, `fixedTransactions` (grouped by employee_id), `fixedTransactionItems`, `currentPeriod`, `itxPeriod`, `itxTransactions`, `itxPeriodFinalized`, `itxPeriodHasDraftRun`, `openingYear`, `openingEmployees`, `openingFigures`, `payrollItems`.
- Writes keep returning `back()`, which lands on the URL the form was on, `?tab=` included (replaceState keeps it current). No controller change.

- [ ] **Step 1: Failing test**

Append to `PayrollNavigationTest`:

```php
    public function test_transaction_screen_carries_the_fixed_individual_takeon_and_items_forms(): void
    {
        $html = $this->acting($this->hr)->get('/app/payroll-transaction')->assertOk()->getContent();

        $this->assertStringContainsString(route('payroll.fixed-transactions.store'), $html);
        $this->assertStringContainsString(route('payroll.individual-transactions.store'), $html);
        $this->assertStringContainsString(route('payroll.opening'), $html);
        $this->assertStringContainsString('Payroll Figures Take On', $html);
        $this->assertStringContainsString(route('app.screen', 'profile'), $html);
        $this->assertStringNotContainsString(route('payroll.salary'), $html);
    }

    public function test_individual_transaction_period_filter_targets_the_transaction_screen(): void
    {
        $this->acting($this->hr)->get('/app/payroll-transaction?tab=individual&itx_period=2026-02')->assertOk()
            ->assertSee('action="'.route('app.screen', 'payroll-transaction').'"', false)
            ->assertSee("x-data=\"{ tab: 'individual' }\"", false);
    }
```

- [ ] **Step 2: Run, expect failure**

Run: `php artisan test --compact tests/Feature/PayrollNavigationTest.php --filter=transaction`

- [ ] **Step 3: Fixed Transaction partial**

`resources/views/partials/payroll/transaction/fixed.blade.php`. Staff picker left, chosen person's fixed transactions right. The per-person block is lines 508-598 of the old blade (the `<div x-data="{ ftAdding: false, ftEditing: null, ftEnding: null }" ...>` through its matching `</div>`, ending just before the `{{-- ... Payment & statutory ... --}}` comment). That block references `$e`, `$empFt`, `$s` and `$fixedTransactionItems`; keep the names.

```blade
@php $money = fn ($v) => 'RM '.number_format((float) $v, 2); @endphp
<div x-data="{
        q: '',
        pick: {{ (int) request('emp', $salaryEmployees->first()?->id ?? 0) }},
        rows: @js($salaryEmployees->map(fn ($e) => ['id' => $e->id, 'h' => mb_strtolower(trim($e->display_name.' '.$e->name.' '.$e->position.' '.$e->employee_id))])->values()),
        hit(r) { return this.q.trim() === '' || r.h.includes(this.q.trim().toLowerCase()); },
     }" style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;">
    {{-- Staff picker --}}
    <div class="uj-card" style="flex:1;min-width:240px;max-width:300px;padding:0;">
        <div style="padding:12px;border-bottom:1px solid var(--hairline);">
            <input type="search" x-model="q" @keydown.escape="q = ''" :placeholder="$store.ui.lang==='en' ? 'Search name or ID' : 'Cari nama atau ID'" style="width:100%;height:32px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:12.5px;outline:none;">
        </div>
        <div style="max-height:560px;overflow:auto;">
            @foreach ($salaryEmployees as $e)
                <button type="button" x-show="hit(rows[{{ $loop->index }}])" @click="pick = {{ $e->id }}" :style="pick === {{ $e->id }} ? 'background:var(--canvas);' : ''" style="display:flex;width:100%;text-align:left;align-items:center;gap:10px;padding:10px 14px;border:0;border-bottom:1px solid var(--hairline-soft);background:none;cursor:pointer;">
                    <div style="width:28px;height:28px;border-radius:50%;background:{{ $e->avatar_color ?? '#3a6ea5' }};color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:600;flex-shrink:0;">{{ $e->initials ?? mb_substr($e->name, 0, 2) }}</div>
                    <div style="min-width:0;"><div style="font-size:12.5px;color:var(--ink);font-weight:500;">{{ $e->display_name ?? $e->name }}</div><div style="font-size:11px;color:var(--muted);">{{ $e->position }}</div></div>
                </button>
            @endforeach
        </div>
    </div>

    {{-- Chosen person's fixed transactions --}}
    <div style="flex:2;min-width:380px;">
        @foreach ($salaryEmployees as $e)
            @php $s = $e->salaryStructure; $empFt = $fixedTransactions->get($e->id, collect()); @endphp
            <div x-show="pick === {{ $e->id }}" x-cloak class="uj-card" style="padding:20px;">
                <h3 class="uj-card-title" style="margin-bottom:4px;">{{ $e->name }}</h3>
                <div style="font-size:12px;color:var(--muted);margin-bottom:12px;">{{ $e->position }} · <span x-text="$store.ui.lang==='en' ? 'Basic' : 'Asas'">Basic</span> {{ $money($e->salary) }}</div>
                {{-- old blade lines 508-598 pasted here, unchanged --}}
            </div>
        @endforeach
        @if ($salaryEmployees->isEmpty())
            <div class="uj-card" style="padding:22px;color:var(--muted);font-size:13px;" x-text="$store.ui.lang==='en' ? 'No active staff yet.' : 'Belum ada staf aktif.'">No active staff yet.</div>
        @endif
    </div>
</div>
```

If `$e->initials` or `$e->employee_id` do not exist on `Employee`, use `mb_substr($e->name, 0, 2)` and drop the id from the search haystack. Check `app/Models/Employee.php` first.

- [ ] **Step 4: Individual, Take On, Items partials**

`transaction/individual.blade.php`: paste old lines 654-760 (the inner `<div class="uj-card" style="max-width:820px;">` of the `individual` tab, not the outer `x-show` div). Wrap the whole file in `<div x-data="{ itxAdding: false, itxEditing: null }">...</div>` and put the `$money`/`$statusColor` `@php` block at the top (same three lines as Task 3 step 6). Change the period filter form `action="{{ route('app.screen', 'payroll') }}"` (old line 657) to `route('app.screen', 'payroll-transaction')` and add `<input type="hidden" name="tab" value="individual">` right after `@csrf`-less form opening.

`transaction/takeon.blade.php`: paste old lines 763-844 (the whole `opening` tab div), remove the `x-show="tab === 'opening'" x-cloak` from the outer div, keep its `x-data`. Change the card title text "Previous employment (TP3)" to "Payroll Figures Take On" / "Angka Pembukaan Gaji" and add under the title:

```blade
<p style="font-size:12px;color:var(--muted);margin:2px 0 10px;" x-text="$store.ui.lang==='en' ? 'Opening figures from a previous employer this year (TP3), so PCB for the rest of the year is right.' : 'Angka pembukaan daripada majikan terdahulu tahun ini (TP3), supaya PCB untuk baki tahun betul.'">Opening figures from a previous employer this year (TP3), so PCB for the rest of the year is right.</p>
```

`transaction/items.blade.php`: paste old lines 848-905 (the inner card of the `items` tab), wrapped in `<div x-data="{ editItem: null }">`. Prepend the `$money` `@php` line.

- [ ] **Step 5: Wire the screen**

`payroll-transaction.blade.php`, above the tab strip, the note:

```blade
    <p style="font-size:12.5px;color:var(--muted);margin:0 0 14px;">
        <span x-text="$store.ui.lang==='en' ? 'Bank account, EPF, SOCSO and tax numbers are on each staff member\'s profile, Bank & Statutory tab.' : 'Akaun bank, nombor EPF, SOCSO dan cukai ada pada profil setiap staf, tab Bank & Berkanun.'">Bank account, EPF, SOCSO and tax numbers are on each staff member's profile, Bank & Statutory tab.</span>
        <a href="{{ route('app.screen', 'profile') }}" style="color:var(--red);" x-text="$store.ui.lang==='en' ? 'Open profiles' : 'Buka profil'">Open profiles</a>
    </p>
```

Replace the four temporary stubs with the partial includes (`fixed`, `individual`, `takeon`, `items`). Default tab: `individual` when `request()->filled('itx_period')`, else `fixed` (mirror the old blade's default logic, applied before the `?tab=` override so `?tab=` wins when present).

- [ ] **Step 6: Run tests**

Run: `php artisan test --compact tests/Feature/PayrollNavigationTest.php tests/Feature/FixedTransactionTest.php` plus any `IndividualTransaction*`, `PayrollItem*`, `Opening*` test files that exist (`ls tests/Feature | grep -i "individual\|item\|opening"`).
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add resources tests
git commit -m "feat(payroll): Transaction screen with staff-first fixed transactions, one-offs, take on and items"
```

---

### Task 5: Process screen (stat cards, Monthly)

**Files:**
- Create: `resources/views/partials/payroll/process/monthly.blade.php`
- Modify: `resources/views/screens/payroll-process.blade.php`
- Test: `tests/Feature/PayrollNavigationTest.php`, `tests/Feature/PayrollTest.php`

**Interfaces:**
- Consumes `runs`, `activeRun` (latest run) from `payrollData`.
- Run rows link to `payroll-review?tab=individual&run=<id>` and `payroll-payment?tab=payout&run=<id>`.

- [ ] **Step 1: Failing test**

```php
    public function test_process_screen_has_the_create_form_and_run_links(): void
    {
        $slip = $this->finalizedPayslipFor($this->emp, '2026-01');
        $runId = $slip->payroll_run_id;

        $html = $this->acting($this->hr)->get('/app/payroll-process')->assertOk()->getContent();
        $this->assertStringContainsString(route('payroll.runs.create'), $html);
        $this->assertStringContainsString('Month End', $html);
        $this->assertStringContainsString(route('app.screen', ['screen' => 'payroll-review', 'tab' => 'individual', 'run' => $runId]), $html);
        $this->assertStringContainsString(route('app.screen', ['screen' => 'payroll-payment', 'tab' => 'payout', 'run' => $runId]), $html);
    }
```

- [ ] **Step 2: Run, expect failure**

Run: `php artisan test --compact tests/Feature/PayrollNavigationTest.php --filter=process_screen`

- [ ] **Step 3: Monthly partial**

`process/monthly.blade.php`:

```blade
@php
    $statusColor = ['draft' => 'var(--amber)', 'approved' => 'var(--info)', 'finalized' => 'var(--success)'];
    $statusMs = ['draft' => 'Draf', 'approved' => 'Diluluskan', 'finalized' => 'Difinalize'];
    $money = fn ($v) => 'RM '.number_format((float) $v, 2);
@endphp
<div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;">
    <div class="uj-card" style="flex:1;min-width:260px;max-width:340px;padding:20px;">
        {{-- old blade lines 258-270: the create form, verbatim --}}
    </div>

    <div class="uj-card" style="flex:2;min-width:420px;padding:0;">
        <div class="uj-card-head"><h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Payroll runs' : 'Run gaji'">Payroll runs</h3></div>
        <table style="width:100%;border-collapse:collapse;font-size:12.5px;">
            <thead><tr style="color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.5px;">
                <th style="text-align:left;padding:10px 18px;" x-text="$store.ui.lang==='en' ? 'Period' : 'Tempoh'">Period</th>
                <th style="text-align:left;padding:10px 8px;" x-text="$store.ui.lang==='en' ? 'Cycle' : 'Kitaran'">Cycle</th>
                <th style="text-align:left;padding:10px 8px;">Status</th>
                <th style="text-align:right;padding:10px 8px;" x-text="$store.ui.lang==='en' ? 'Staff' : 'Staf'">Staff</th>
                <th style="text-align:right;padding:10px 8px;" x-text="$store.ui.lang==='en' ? 'Net' : 'Bersih'">Net</th>
                <th style="padding:10px 18px;"></th>
            </tr></thead>
            <tbody>
            @forelse ($runs as $r)
                <tr style="border-top:1px solid var(--hairline-soft);">
                    <td style="padding:12px 18px;font-weight:500;color:var(--ink);">{{ $r->label }}</td>
                    <td style="padding:12px 8px;">Month End</td>
                    <td style="padding:12px 8px;"><span class="uj-pill" style="background:#fff;border:1px solid var(--hairline);color:{{ $statusColor[$r->status] ?? 'var(--muted)' }};text-transform:capitalize;font-size:10.5px;" x-text="$store.ui.lang==='en' ? @js($r->status) : @js($statusMs[$r->status] ?? $r->status)">{{ $r->status }}</span></td>
                    <td style="padding:12px 8px;text-align:right;">{{ $r->payslips_count }}</td>
                    <td style="padding:12px 8px;text-align:right;font-family:var(--font-mono);">{{ $money($r->totals['net'] ?? 0) }}</td>
                    <td style="padding:12px 18px;text-align:right;white-space:nowrap;">
                        <a href="{{ route('app.screen', ['screen' => 'payroll-review', 'tab' => 'individual', 'run' => $r->id]) }}" style="color:var(--red);font-size:12px;text-decoration:none;margin-right:10px;" x-text="$store.ui.lang==='en' ? 'Review' : 'Semak'">Review</a>
                        <a href="{{ route('app.screen', ['screen' => 'payroll-payment', 'tab' => 'payout', 'run' => $r->id]) }}" style="color:var(--red);font-size:12px;text-decoration:none;" x-text="$store.ui.lang==='en' ? 'Payment' : 'Bayaran'">Payment</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" style="padding:20px 18px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'No payroll runs yet. Create one to begin.' : 'Belum ada run gaji. Buat satu untuk mula.'">No payroll runs yet. Create one to begin.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
```

`$r->totals` is the accessor `PayrollRun::totals` the old stat row already reads via `$activeRun->totals`. If it needs payslips loaded, change the `runs` query in `payrollData` to `PayrollRun::withCount('payslips')->with('payslips')->orderByDesc('period')->get()`.

- [ ] **Step 4: Wire the screen**

`payroll-process.blade.php`: above the tab strip paste the stat row (old lines 229-242: the `@php $latest = ...` and the four `uj-stat` cards; drop the `$payslipRows` line). Replace the `monthly` stub with `@include('partials.payroll.process.monthly')`. Move the privileged guide block (old lines 11-42, HR branch only, `steps` updated: "Salary structure" step becomes "Make sure every active employee has a salary on their profile.", "Opening figures" step points at "Transaction, Payroll Figures Take On", "Open each payslip" step points at "Payroll Review", "Finalize" step points at "Payment, Payout Management") to the top of this screen with `'key' => 'payroll-process'`.

- [ ] **Step 5: Run tests**

Run: `php artisan test --compact tests/Feature/PayrollNavigationTest.php tests/Feature/PayrollTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add resources tests
git commit -m "feat(payroll): Process screen with the monthly run card and run table"
```

---

### Task 6: Payroll Review screen (Individual Payroll, EA Form, Bulk EA)

**Files:**
- Create: `resources/views/partials/payroll/review/individual.blade.php`, `review/ea-form.blade.php`, `review/bulk-ea.blade.php`
- Modify: `resources/views/screens/payroll-review.blade.php`, `app/Http/Controllers/PayrollController.php::updatePayslip` return (line ~1053)
- Test: `tests/Feature/PayrollNavigationTest.php`, `tests/Feature/PayrollTest.php`

**Interfaces:**
- Consumes `runs`, `activeRun` (`?run=`), `individualTransactionsForActiveRun`, `fixedTransactionItems`, `isManagementTier`, `salaryEmployees`.
- `updatePayslip` redirects to `route('app.screen', ['screen' => 'payroll-review', 'tab' => 'individual', 'run' => $payslip->payroll_run_id, 'payslip' => $payslip->id])`.

- [ ] **Step 1: Failing test**

```php
    public function test_review_screen_lists_the_run_and_its_payslips(): void
    {
        $slip = $this->finalizedPayslipFor($this->emp, '2026-02');

        $html = $this->acting($this->hr)->get('/app/payroll-review?tab=individual&run='.$slip->payroll_run_id)->assertOk()->getContent();
        $this->assertStringContainsString('name="run"', $html);
        $this->assertStringContainsString('Worker', $html);
        $this->assertStringContainsString(route('payroll.export.ea-forms', ['year' => 2026]), $html);
        $this->assertStringContainsString(route('payroll.ea-form.show', ['employee' => $this->emp->id, 'year' => 2026]), $html);
    }
```

And in `tests/Feature/PayrollTest.php`, find the test that posts to `/app/payroll/payslips/{id}` and add to its assertion chain:

```php
->assertRedirect(route('app.screen', ['screen' => 'payroll-review', 'tab' => 'individual', 'run' => $run->id, 'payslip' => $payslip->id]))
```
(use whatever variable names that test already has).

- [ ] **Step 2: Run, expect failure**

Run: `php artisan test --compact tests/Feature/PayrollNavigationTest.php --filter=review_screen`

- [ ] **Step 3: Individual Payroll partial**

`review/individual.blade.php`:

```blade
@php
    $statusColor = ['draft' => 'var(--amber)', 'approved' => 'var(--info)', 'finalized' => 'var(--success)'];
    $statusMs = ['draft' => 'Draf', 'approved' => 'Diluluskan', 'finalized' => 'Difinalize'];
    $money = fn ($v) => 'RM '.number_format((float) $v, 2);
    $latest = $activeRun?->totals ?? [];
    $payslipRows = $activeRun ? $activeRun->payslips->sortBy('employee.name')->values() : collect();
    $openSlip = (int) request('payslip', $payslipRows->first()?->id ?? 0);
@endphp
<form method="get" action="{{ route('app.screen', 'payroll-review') }}" style="display:flex;align-items:center;gap:8px;margin-bottom:14px;">
    <input type="hidden" name="tab" value="individual">
    <label style="font-size:12.5px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Run' : 'Run'">Run</label>
    <select name="run" onchange="this.form.submit()" style="height:34px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:12.5px;">
        @foreach ($runs as $r)
            <option value="{{ $r->id }}" @selected($activeRun?->id === $r->id)>{{ $r->label }} · {{ $r->status }}</option>
        @endforeach
    </select>
</form>

@if (!$activeRun)
    <div class="uj-card" style="padding:22px;color:var(--muted);font-size:13px;" x-text="$store.ui.lang==='en' ? 'No payroll run yet. Create one under Process.' : 'Belum ada run gaji. Buat satu di bawah Proses.'">No payroll run yet. Create one under Process.</div>
@else
<div x-data="{
        q: '',
        editing: null,
        pick: {{ $openSlip }},
        rows: @js($payslipRows->map(fn ($p) => mb_strtolower(trim($p->employee?->display_name.' '.$p->employee?->name.' '.$p->employee?->position)))->values()),
        hit(h) { return this.q.trim() === '' || h.includes(this.q.trim().toLowerCase()); },
     }" style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;">
    {{-- Staff picker --}}
    <div class="uj-card" style="flex:1;min-width:240px;max-width:300px;padding:0;">
        <div style="padding:12px;border-bottom:1px solid var(--hairline);">
            <input type="search" x-model="q" @keydown.escape="q = ''" :placeholder="$store.ui.lang==='en' ? 'Search name or nickname' : 'Cari nama atau gelaran'" style="width:100%;height:32px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:12.5px;outline:none;">
        </div>
        <div style="max-height:560px;overflow:auto;">
            @foreach ($payslipRows as $p)
                <button type="button" x-show="hit(rows[{{ $loop->index }}])" @click="pick = {{ $p->id }}" :style="pick === {{ $p->id }} ? 'background:var(--canvas);' : ''" style="display:flex;width:100%;text-align:left;align-items:center;gap:10px;padding:10px 14px;border:0;border-bottom:1px solid var(--hairline-soft);background:none;cursor:pointer;">
                    <div style="min-width:0;flex:1;"><div style="font-size:12.5px;color:var(--ink);font-weight:500;">{{ $p->employee?->name }}</div><div style="font-size:11px;color:var(--muted);">{{ $p->employee?->position }}</div></div>
                    <span style="font-size:12px;font-family:var(--font-mono);color:var(--ink);">{{ $money($p->net_pay) }}</span>
                </button>
            @endforeach
        </div>
    </div>

    {{-- Chosen payslip --}}
    <div class="uj-card" style="flex:2;min-width:420px;padding:0;">
        {{-- old blade lines 292-341: run header (label, gross/net line, employer line, status pill, draft-figures notice). Drop the action buttons (approve/finalize/delete/bank file/exports) and the type-to-delete block; those live on Payment now. --}}
        @foreach ($payslipRows as $p)
            <div x-show="pick === {{ $p->id }}" x-cloak>
                {{-- old blade lines 356-437: one payslip row + inline editor, minus the outer `x-show="hit(...)"` wrapper. Keep every form field exactly. --}}
            </div>
        @endforeach
    </div>
</div>
@endif
```

In the pasted payslip row, make the name plain text (no link); the PDF lives on Payment. The Edit toggle keeps `editing`.

- [ ] **Step 4: EA Form and Bulk EA partials**

`review/ea-form.blade.php`:

```blade
@php $year = (int) request('year', now()->year); @endphp
<div class="uj-card" style="max-width:820px;padding:0;">
    <div class="uj-card-head" style="gap:10px;">
        <h3 class="uj-card-title">EA Form</h3>
        <form method="get" action="{{ route('app.screen', 'payroll-review') }}" style="margin-left:auto;display:flex;gap:8px;align-items:center;">
            <input type="hidden" name="tab" value="ea-form">
            <input name="year" type="number" min="2020" max="2100" value="{{ $year }}" style="width:90px;height:32px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:12.5px;">
            <button class="uj-btn-ghost" style="height:32px;padding:0 12px;font-size:12px;" x-text="$store.ui.lang==='en' ? 'Show' : 'Papar'">Show</button>
        </form>
    </div>
    @foreach ($salaryEmployees as $e)
        <div style="display:flex;align-items:center;justify-content:space-between;gap:14px;padding:12px 20px;border-top:1px solid var(--hairline-soft);">
            <div><div style="font-size:13px;color:var(--ink);font-weight:500;">{{ $e->name }}</div><div style="font-size:11px;color:var(--muted);">{{ $e->position }}</div></div>
            <div style="display:flex;gap:8px;">
                <a href="{{ route('payroll.ea-form.show', ['employee' => $e->id, 'year' => $year]) }}" class="uj-btn-ghost" style="height:30px;padding:0 10px;font-size:12px;display:inline-flex;align-items:center;text-decoration:none;" x-text="$store.ui.lang==='en' ? 'View' : 'Lihat'">View</a>
                <a href="{{ route('payroll.ea-form.pdf', ['employee' => $e->id, 'year' => $year]) }}" class="uj-btn-ghost" style="height:30px;padding:0 10px;font-size:12px;display:inline-flex;align-items:center;text-decoration:none;">PDF</a>
            </div>
        </div>
    @endforeach
</div>
```

`review/bulk-ea.blade.php`:

```blade
@php $year = (int) request('year', now()->year); @endphp
<div class="uj-card" style="max-width:520px;padding:22px;">
    <h3 class="uj-card-title" style="margin-bottom:12px;">Bulk EA Form</h3>
    <form method="get" action="{{ route('payroll.export.ea-forms', ['year' => $year]) }}" x-data="{ y: {{ $year }} }" @submit.prevent="location.href = '{{ url('/app/payroll/ea-forms') }}/' + y">
        <label style="display:block;font-size:12.5px;color:var(--muted);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Year' : 'Tahun'">Year</label>
        <input x-model="y" type="number" min="2020" max="2100" style="width:120px;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;margin-bottom:12px;">
        <div style="font-size:12px;color:var(--muted);margin-bottom:12px;">{{ $tenant['name'] ?? '' }}</div>
        <button class="uj-btn-primary" style="height:36px;padding:0 16px;font-size:13px;" x-text="$store.ui.lang==='en' ? 'Generate' : 'Jana'">Generate</button>
    </form>
</div>
```

- [ ] **Step 5: Redirect + wire**

`PayrollController::updatePayslip` line ~1053: replace `return back()->with('ok', ...)` with

```php
        return redirect()->route('app.screen', ['screen' => 'payroll-review', 'tab' => 'individual', 'run' => $payslip->payroll_run_id, 'payslip' => $payslip->id])
            ->with('ok', 'Payslip updated for '.$payslip->employee->name.' (net RM '.number_format($comp->netPay, 2).').');
```

`payroll-review.blade.php`: replace the three temporary stubs with the partial includes.

- [ ] **Step 6: Run tests**

Run: `php artisan test --compact tests/Feature/PayrollNavigationTest.php tests/Feature/PayrollTest.php tests/Feature/FixedTransactionTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A app resources tests
git commit -m "feat(payroll): Payroll Review screen with per-staff payslip editor and EA forms"
```

---

### Task 7: Payment screen (Payout, Submission, Pay Slips, CP8D)

**Files:**
- Create: `resources/views/partials/payroll/payment/payout.blade.php`, `submission.blade.php`, `payslip.blade.php`, `bulk-payslip.blade.php`, `cp8d.blade.php`
- Modify: `resources/views/screens/payroll-payment.blade.php`, `app/Http/Controllers/PayrollController.php` (`approveRun` ~1066, `finalizeRun` ~1119 and ~1126, `destroyRun` ~1192), `BuildsWorkData::payrollData`
- Test: `tests/Feature/PayrollNavigationTest.php`, `tests/Feature/PayrollTest.php`, `tests/Feature/FeatureEnforcementTest.php`

**Interfaces:**
- `payrollData` gains `'payoutYear' => int` (`?year`, default current year) and `'payoutRuns' => Collection<PayrollRun>` (runs whose period starts with that year, with `payslips` loaded, newest first).
- approve, finalize, delete all redirect to `route('app.screen', ['screen' => 'payroll-payment', 'tab' => 'payout'])` (approve and finalize add `'run' => $run->id`).
- Finalize notification link becomes `route('app.screen', 'payroll-my')`.

- [ ] **Step 1: Failing tests**

```php
    public function test_payment_screen_lists_the_years_runs_with_actions_and_files(): void
    {
        $slip = $this->finalizedPayslipFor($this->emp, '2026-02');
        $run = $slip->payrollRun;

        $html = $this->acting($this->hr)->get('/app/payroll-payment?tab=payout&year=2026')->assertOk()->getContent();
        $this->assertStringContainsString($run->label, $html);
        $this->assertStringContainsString(route('payroll.export.bank', $run), $html);
        $this->assertStringContainsString(route('payroll.export.statutory', $run), $html);
        $this->assertStringContainsString(route('payroll.export.payslips-pdf', $run), $html);
        $this->assertStringContainsString(route('payroll.payslips.pdf', $slip), $html);
        $this->assertStringContainsString(route('payroll.form-e.cp8d', ['year' => 2026]), $html);
        $this->assertStringContainsString('Spec F6', $html);

        $this->acting($this->hr)->get('/app/payroll-payment?tab=payout&year=2019')->assertOk()->assertDontSee($run->label);
    }

    public function test_run_actions_land_on_payout_management(): void
    {
        $slip = $this->finalizedPayslipFor($this->emp, '2026-02');
        $run = $slip->payrollRun;
        $run->forceFill(['status' => 'draft', 'finalized_at' => null])->save();

        $this->acting($this->hr)->post(route('payroll.runs.approve', $run))
            ->assertRedirect(route('app.screen', ['screen' => 'payroll-payment', 'tab' => 'payout', 'run' => $run->id]));
        $this->acting($this->hr)->post(route('payroll.runs.delete', $run))
            ->assertRedirect(route('app.screen', ['screen' => 'payroll-payment', 'tab' => 'payout']));
    }
```

If `destroyRun` needs a `confirm` field or refuses approved runs, read `PayrollController::destroyRun` and post what it requires (a draft run deletes without the typed period; check).

- [ ] **Step 2: Run, expect failure**

Run: `php artisan test --compact tests/Feature/PayrollNavigationTest.php --filter="payment_screen|run_actions"`

- [ ] **Step 3: Data**

In `payrollData` privileged return, add:

```php
            'payoutYear' => $payoutYear = (int) ($request->integer('year') ?: now()->year),
            'payoutRuns' => PayrollRun::withCount('payslips')->with('payslips.employee')
                ->where('period', 'like', $payoutYear.'-%')->orderByDesc('period')->get(),
```

- [ ] **Step 4: Redirects**

`PayrollController`:
- `approveRun` ~1066: `return redirect()->route('app.screen', ['screen' => 'payroll-payment', 'tab' => 'payout', 'run' => $run->id])->with('ok', $run->label.' payroll approved. Finalize to issue payslips.');`
- `finalizeRun` ~1126: same target with `'run' => $run->id`, keep its message but replace the em dash with a comma: `' payroll finalized, payslips issued and employees notified.'`
- `finalizeRun` ~1119 notification: `route('app.screen', 'payroll-my'),`
- `destroyRun` ~1192: `return redirect()->route('app.screen', ['screen' => 'payroll-payment', 'tab' => 'payout'])->with('ok', $label.' payroll run deleted.');`

Grep `tests/Feature` for `assertRedirect(` on approve/finalize/delete posts with an explicit URL and update any to the new targets (most only assert `assertRedirect()` with no argument, which still passes).

- [ ] **Step 5: Payout partial**

`payment/payout.blade.php`:

```blade
@php
    $statusColor = ['draft' => 'var(--amber)', 'approved' => 'var(--info)', 'finalized' => 'var(--success)'];
    $statusMs = ['draft' => 'Draf', 'approved' => 'Diluluskan', 'finalized' => 'Difinalize'];
    $money = fn ($v) => 'RM '.number_format((float) $v, 2);
@endphp
<form method="get" action="{{ route('app.screen', 'payroll-payment') }}" style="display:flex;align-items:center;gap:8px;margin-bottom:14px;">
    <input type="hidden" name="tab" value="payout">
    <label style="font-size:12.5px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Year' : 'Tahun'">Year</label>
    <input name="year" type="number" min="2020" max="2100" value="{{ $payoutYear }}" onchange="this.form.submit()" style="width:90px;height:32px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:12.5px;">
</form>
<div class="uj-card" style="padding:0;">
    @forelse ($payoutRuns as $r)
        @php $t = $r->totals; @endphp
        <div style="border-top:1px solid var(--hairline-soft);padding:14px 20px;">
            <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
                <div style="min-width:120px;"><div style="font-size:13.5px;color:var(--ink);font-weight:600;">{{ $r->label }}</div><div style="font-size:11px;color:var(--muted);">{{ $r->payslips_count }} <span x-text="$store.ui.lang==='en' ? 'staff' : 'staf'">staff</span></div></div>
                <span class="uj-pill" style="background:#fff;border:1px solid var(--hairline);color:{{ $statusColor[$r->status] ?? 'var(--muted)' }};text-transform:capitalize;font-size:10.5px;" x-text="$store.ui.lang==='en' ? @js($r->status) : @js($statusMs[$r->status] ?? $r->status)">{{ $r->status }}</span>
                <span style="font-family:var(--font-mono);font-size:13px;color:var(--ink);"><span x-text="$store.ui.lang==='en' ? 'Net' : 'Bersih'">Net</span> {{ $money($t['net'] ?? 0) }}</span>
                <span style="font-size:11.5px;color:var(--muted);">{{ $r->finalized_at?->format('d M Y') ?? '' }}</span>
                <div style="margin-left:auto;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    {{-- old blade lines 302-309: approve / finalize / delete forms, with $activeRun replaced by $r --}}
                    {{-- old blade lines 326-335: the finalized-run type-to-delete block gated by $isManagementTier, with $activeRun replaced by $r --}}
                    <a href="{{ route('app.screen', ['screen' => 'payroll-payment', 'tab' => 'submission', 'run' => $r->id]) }}" style="font-size:12px;color:var(--red);text-decoration:none;" x-text="$store.ui.lang==='en' ? 'Files' : 'Fail'">Files</a>
                </div>
            </div>
        </div>
    @empty
        <div style="padding:22px;color:var(--muted);font-size:13px;" x-text="$store.ui.lang==='en' ? 'No payroll runs in this year.' : 'Tiada run gaji dalam tahun ini.'">No payroll runs in this year.</div>
    @endforelse
</div>
```

- [ ] **Step 6: Submission, Pay Slip, Bulk, CP8D partials**

Shared run picker snippet (put at the top of `submission`, `payslip`, `bulk-payslip`; `$tabId` differs):

```blade
<form method="get" action="{{ route('app.screen', 'payroll-payment') }}" style="display:flex;align-items:center;gap:8px;margin-bottom:14px;">
    <input type="hidden" name="tab" value="{{ $tabId }}">
    <label style="font-size:12.5px;color:var(--muted);">Run</label>
    <select name="run" onchange="this.form.submit()" style="height:34px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:12.5px;">
        @foreach ($runs as $r)<option value="{{ $r->id }}" @selected($activeRun?->id === $r->id)>{{ $r->label }} · {{ $r->status }}</option>@endforeach
    </select>
</form>
```

`payment/submission.blade.php` (`$tabId = 'submission'`), below the picker, when `$activeRun`:

```blade
@php $money = fn ($v) => 'RM '.number_format((float) $v, 2); $ps = $activeRun->payslips; @endphp
<div class="uj-card" style="max-width:820px;padding:20px;">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:12px;margin-bottom:16px;">
        @foreach (['Net' => $ps->sum('net_pay'), 'EPF' => $ps->sum('epf_employee') + $ps->sum('epf_employer'), 'SOCSO' => $ps->sum('socso_employee') + $ps->sum('socso_employer'), 'EIS' => $ps->sum('eis_employee') + $ps->sum('eis_employer'), 'PCB' => $ps->sum('pcb')] as $k => $v)
            <div><div style="font-size:11px;color:var(--muted);text-transform:uppercase;">{{ $k }}</div><div style="font-family:var(--font-mono);font-size:14px;color:var(--ink);">{{ $money($v) }}</div></div>
        @endforeach
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        {{-- old blade lines 313-317: bank file form (with its format select) and the statutory report link, $activeRun kept --}}
        @foreach (['KWSP Form A', 'PERKESO 8A', 'LHDN CP39'] as $f)
            <button type="button" disabled class="uj-btn-ghost" style="height:36px;padding:0 12px;font-size:12px;opacity:.55;cursor:not-allowed;">{{ $f }} <span class="uj-pill" style="margin-left:6px;font-size:10px;">Spec F6</span></button>
        @endforeach
    </div>
</div>
```
Column names (`epf_employee`, `pcb`, ...) must match `payslips`; check `Payslip::casts()` and use the real names.

`payment/payslip.blade.php` (`$tabId = 'payslip'`): staff rows with net and a PDF link:

```blade
<div class="uj-card" style="max-width:720px;padding:0;">
    @foreach ($activeRun?->payslips->sortBy('employee.name') ?? [] as $p)
        <div style="display:flex;align-items:center;gap:12px;padding:12px 20px;border-top:1px solid var(--hairline-soft);">
            <div style="flex:1;font-size:13px;color:var(--ink);font-weight:500;">{{ $p->employee?->name }}</div>
            <span style="font-family:var(--font-mono);font-size:13px;">{{ $money($p->net_pay) }}</span>
            <a href="{{ route('payroll.payslips.pdf', $p) }}" class="uj-btn-ghost" style="height:30px;padding:0 10px;font-size:12px;display:inline-flex;align-items:center;text-decoration:none;">PDF</a>
        </div>
    @endforeach
</div>
```
(Payslip PDFs of a draft run: if `PayrollPdfController::show` refuses non-finalized runs, show the link only when `$activeRun->status === 'finalized'` and a muted "Finalize first" note otherwise.)

`payment/bulk-payslip.blade.php` (`$tabId = 'bulk-payslip'`): one card with `<a href="{{ route('payroll.export.payslips-pdf', $activeRun) }}" class="uj-btn-primary">Download all</a>` when `$activeRun`.

`payment/cp8d.blade.php`: same shape as `review/bulk-ea.blade.php` with the URL base `url('/app/payroll/form-e')` and `'/' + y + '/cp8d'`, title "LHDN CP8D".

- [ ] **Step 7: Wire the screen, run tests**

Replace the five temporary stubs in `payroll-payment.blade.php` with the partial includes (pass `['tabId' => '...']` where needed).

Run: `php artisan test --compact tests/Feature/PayrollNavigationTest.php tests/Feature/PayrollTest.php tests/Feature/FeatureEnforcementTest.php tests/Feature/FixedTransactionTest.php`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A app resources tests
git commit -m "feat(payroll): Payment screen with payout actions, bank and statutory files, payslip PDFs and CP8D"
```

---

### Task 8: Form screen (LHDN Form E) and retiring the old screen

**Files:**
- Create: `resources/views/partials/payroll/form/form-e.blade.php`
- Modify: `resources/views/screens/payroll-form.blade.php`, `resources/views/screens/profile.blade.php` (~660)
- Delete: `resources/views/screens/payroll.blade.php`
- Test: `tests/Feature/PayrollNavigationTest.php`

- [ ] **Step 1: Failing test**

```php
    public function test_form_screen_links_form_e_and_profile_links_my_payroll(): void
    {
        $slip = $this->finalizedPayslipFor($this->emp, '2026-02');

        $this->acting($this->hr)->get('/app/payroll-form')->assertOk()
            ->assertSee(route('payroll.form-e.show', ['year' => now()->year]), false)
            ->assertSee(route('payroll.form-e.pdf', ['year' => now()->year]), false);

        $this->acting($this->empUser)->get('/app/profile')->assertOk()
            ->assertSee(route('app.screen', ['screen' => 'payroll-my', 'payslip' => $slip->id]), false);
    }

    public function test_the_old_payroll_view_is_gone(): void
    {
        $this->assertFileDoesNotExist(resource_path('views/screens/payroll.blade.php'));
    }
```

- [ ] **Step 2: Run, expect failure**

- [ ] **Step 3: Form E partial**

`form/form-e.blade.php`:

```blade
@php $year = (int) request('year', now()->year); @endphp
<div class="uj-card" style="max-width:520px;padding:22px;">
    <h3 class="uj-card-title" style="margin-bottom:12px;">LHDN Form E</h3>
    <form method="get" action="{{ route('app.screen', 'payroll-form') }}" style="display:flex;gap:8px;align-items:flex-end;margin-bottom:14px;">
        <input type="hidden" name="tab" value="form-e">
        <div><label style="display:block;font-size:12.5px;color:var(--muted);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Year' : 'Tahun'">Year</label>
        <input name="year" type="number" min="2020" max="2100" value="{{ $year }}" style="width:120px;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;"></div>
        <button class="uj-btn-ghost" style="height:36px;padding:0 12px;font-size:12px;" x-text="$store.ui.lang==='en' ? 'Show' : 'Papar'">Show</button>
    </form>
    <div style="display:flex;gap:8px;">
        <a href="{{ route('payroll.form-e.show', ['year' => $year]) }}" class="uj-btn-primary" style="height:36px;padding:0 14px;font-size:13px;display:inline-flex;align-items:center;text-decoration:none;" x-text="$store.ui.lang==='en' ? 'View' : 'Lihat'">View</a>
        <a href="{{ route('payroll.form-e.pdf', ['year' => $year]) }}" class="uj-btn-ghost" style="height:36px;padding:0 14px;font-size:13px;display:inline-flex;align-items:center;text-decoration:none;">PDF</a>
    </div>
</div>
```

Replace the `form-e` temporary stub in `payroll-form.blade.php` with `@include('partials.payroll.form.form-e')`.

- [ ] **Step 4: Profile Money link**

`profile.blade.php` ~line 661: wrap the payslip row so the net amount links to the slip:

```blade
<a href="{{ route('app.screen', ['screen' => 'payroll-my', 'payslip' => $ps->id]) }}" style="font-size:13px;color:var(--ink);font-weight:500;font-family:var(--font-mono);text-decoration:none;">{{ $money($ps->net_pay) }}</a>
```
(replace the `<div ...>{{ $money($ps->net_pay) }}</div>` line only). Only the viewer's own profile should link there: gate with `@if (($profile?->user_id ?? null) === auth()->id())`, else keep the plain div. Check the variable name the profile uses for the viewed employee (`$profile`).

- [ ] **Step 5: Delete the old screen, sweep references**

```bash
git rm resources/views/screens/payroll.blade.php
grep -rn "screens.payroll'\|'screen' => 'payroll'\]\|app.screen', 'payroll')" app resources tests --include='*.php'
```
Every hit must become a specific child screen (`payroll-my` for staff-facing links such as dashboard cards or notifications, `payroll-process` for HR-facing ones). Fix each and list them in the commit message.

- [ ] **Step 6: Run the full suite**

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact
```
Expected: all green.

- [ ] **Step 7: Assets and commit**

```bash
lerd artisan view:clear && lerd artisan view:cache && bun run build
git add -A app resources tests public/build
git commit -m "feat(payroll): Form screen with LHDN Form E, old payroll screen retired, links point at the new screens"
```

---

### Task 9: Browser walk

- [ ] **Step 1:** Headless Chromium (`/home/shzwn/.cache/ms-playwright/chromium-1234/chrome-linux64/chrome` via `/home/shzwn/Projects/Asas/node_modules/playwright-core`), log in as HR (`hidayahsuffya.unijaya@gmail.com` / `password`) at `http://localhost:9100`, screenshot each of the six screens and every tab into `~/mockups/payroll-nav-walk/<screen>-<tab>.png`. Log in as Shazwan (`shazwanshah.unijaya@gmail.com`) and screenshot `payroll-my` (list and one slip) plus a 403 on `payroll-process`.
- [ ] **Step 2:** Click Acknowledge on one slip as Shazwan, screenshot, then revert via tinker: `Payslip::whereNotNull('acknowledged_at')->update(['acknowledged_at' => null]); AuditLog::where('action','Acknowledged payslip')->delete();` and note the before/after audit count.
- [ ] **Step 3:** Report in plain talk: what moved where, what is stubbed, what tests cover, anything left.

---

## Self-review

- Spec coverage: nav children + landing (T1), gating + module list + scope tests (T1), tab shells + stubs with pills (T2), My Payroll payslip/acknowledge/EA/TP1 (T3), Transaction tabs incl. note and retired salary tab (T4), Process stats + Monthly + stubs (T5), Review individual/EA/bulk EA/stub (T6), Payment payout/submission/payslips/bulk/CP8D/stub with redirects (T7), Form E + form stubs + profile links + old screen removed (T8), migration (T3), audit (T3), bilingual (each task), browser walk (T9).
- Placeholders: temporary "Filled in Task N" stubs are explicit and replaced by the named task. Old-blade line ranges are the actual content to move.
- Names: `payroll.payslips.acknowledge`, `acknowledgePayslip`, `myEaYears`, `payoutYear`, `payoutRuns`, `partials.payroll.tabs`, `partials.payroll.stub`, tab ids consistent between the screen `$tabs` arrays and `PayrollNavigationTest::stubTabs()`.
