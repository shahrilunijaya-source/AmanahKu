# Payroll Phase 1 (F1 to F7) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the first live payroll month filable: employer and employee statutory identity with a readiness gate, calendar-day proration of basic pay, wage-floor and deduction guards, the seven-day pay-date rule, the three agency upload files (KWSP Form A, PERKESO Borang 8A, LHDN CP39) and the HRD Corp levy.

**Architecture:** Everything hangs off the existing `PayrollController` run lifecycle (createRun → updatePayslip → finalizeRun) and `PayrollCalculator`. New pure classes go under `App\Services\Payroll\` (`Proration`, `MinimumWage`, `PayrollReadiness`, `Statutory\*` exporters) so the money math and file layouts are unit-testable without a database. UI lands in the existing payroll partials; no new screens.

**Tech Stack:** Laravel 13, PHP 8.5, Blade + Alpine, PHPUnit (sqlite), Larastan level 5.

**Spec:** `docs/superpowers/specs/2026-09-02-payroll-features-design.html` sections F1 to F7 and section 6 (data model). Section 7 lists rules that must not be tidied.

## Global Constraints

- Commit on `dev`, no worktree, no push. One commit per task.
- Format PHP before each commit: `vendor/bin/pint --dirty --format agent`. Larastan level 5 must stay green: `vendor/bin/phpstan analyse --memory-limit=512M`.
- Dev DB migrate only via `lerd artisan migrate`. Tests use their own sqlite DB: `php artisan test --compact <file>`.
- After any Blade change: `lerd artisan view:clear && lerd artisan view:cache && bun run build`, then commit `public/build` if it changed. bun, never npm.
- Route-model binding is NOT tenant-scoped. Every controller action that binds a model checks `tenant_id` against `app(CurrentTenant::class)->id()`.
- Statutory constants (RM1,700 floor, HRD Corp 1% / 0.5%) live in code with an effective date. Never tenant-editable.
- Three wage bases stay separate (EPF, PERKESO, HRD Corp), each driven by PayrollItem flags. Two proration divisors coexist: calendar days for basic pay (s.18A), 26 days × 8 hours for unpaid leave and overtime.
- Never delete or rewrite an audit row. Every state change writes `AuditLog::record(...)`.
- Finalized runs stay immutable except `paid_at`, set only by the dedicated Mark paid action.
- Every UI string carries EN and MS via the existing `x-text="$store.ui.lang==='en' ? ... : ..."` pattern or the `$L(en, ms)` helper already in the partial.
- Copy: "Payroll Item", "Fixed Transaction", "Individual Transaction" (Worksy terms).

**Scope decisions (state to the user in the recap):**
- F5 reuses the existing `payroll_runs.payment_date` column (already a `date` cast and on the create-run form) instead of adding `pay_date`.
- F2's `employees.last_working_day` already exists (migration 2026_09_26_100100). F2/F3 read it first, then the OffboardingCase.
- F2's extra salary-structure columns with no consumer in F1 to F7 (`tax_resident_type`, `epf_voluntary_employee_rate`, `epf_voluntary_employer_rate`, `children_relief_full/half`) are deferred to the phase that consumes them (F9). Only `socso_exempt`, `hrdf_exempt` and `bank_code` are added now.
- F6: the CP39 layout is transcribed from Exhibit 4 of `docs/statutory/spesifikasi-kaedah-pengiraan-berkomputer-pcb-2026.pdf` (in repo). The KWSP Form A CSV and PERKESO Borang 8A layouts have no official document in the repo; those two exporters ship with `verified(): false` (same convention as `BankFileFormat`), the download page says so, and their golden files pin our layout until the official document is added to `docs/statutory/`.
- Readiness refusals return `back()->withErrors([...])` like the existing duplicate-period refusal, not a bare 422, so the form shows the message.

---

### Task 1: F1 Employer statutory identity

**Files:**
- Create: `database/migrations/2026_10_01_100000_add_statutory_identity_to_tenants_table.php`
- Modify: `app/Http/Controllers/AdminController.php:44-70` (validate + write)
- Modify: `resources/views/screens/settings.blade.php` (new section after the Employer's TIN input, before Website)
- Modify: `app/Services/Payroll/FormEData.php:39-41` and `incompleteFields()` at 107
- Create: `app/Support/StatutoryOptions.php` additions (constants `EMPLOYER_CATEGORIES`, `EMPLOYER_STATUSES`)
- Test: `tests/Feature/EmployerStatutoryIdentityTest.php`

**Interfaces:**
- Produces: tenant columns `epf_employer_no`, `socso_employer_code`, `hrdf_registration_no`, `employer_category`, `employer_status`, `paying_bank_code`, `paying_bank_account_no`, `payroll_contact_name`, `payroll_contact_phone` (all nullable strings). `StatutoryOptions::EMPLOYER_CATEGORIES` and `::EMPLOYER_STATUSES` arrays keyed by Form E code. Task 3 reads `epf_employer_no`, `socso_employer_code`, `employer_tin`, `hrdf_registration_no`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Payroll\FormEData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EmployerStatutoryIdentityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $this->hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);
        $this->actingAs($this->hr)->withSession(['current_tenant' => $this->tenant->id]);
    }

    public function test_settings_save_persists_statutory_registration(): void
    {
        $this->post(route('admin.settings.update'), [
            'name' => 'Acme',
            'employer_tin' => '1234567890',
            'epf_employer_no' => ' 12345678 ',
            'socso_employer_code' => 'A1234567890X',
            'hrdf_registration_no' => '1234567-K',
            'employer_category' => '2',
            'employer_status' => '1',
            'paying_bank_code' => 'MBBEMYKL',
            'paying_bank_account_no' => '514011223344',
            'payroll_contact_name' => 'Aini',
            'payroll_contact_phone' => '0123456789',
        ])->assertSessionHasNoErrors();

        $t = $this->tenant->fresh();
        $this->assertSame('12345678', $t->epf_employer_no);
        $this->assertSame('A1234567890X', $t->socso_employer_code);
        $this->assertSame('1234567-K', $t->hrdf_registration_no);
        $this->assertSame('2', $t->employer_category);
        $this->assertSame('MBBEMYKL', $t->paying_bank_code);
        $this->assertSame('Aini', $t->payroll_contact_name);
    }

    public function test_epf_number_rejects_letters_beyond_digits_and_hyphens(): void
    {
        $this->post(route('admin.settings.update'), ['name' => 'Acme', 'epf_employer_no' => '12 34!'])
            ->assertSessionHasErrors('epf_employer_no');
    }

    public function test_form_e_carries_items_3_to_5(): void
    {
        $this->tenant->update(['employer_tin' => '1234567890', 'employer_category' => '2', 'employer_status' => '1']);
        $data = app(FormEData::class)->build($this->tenant->fresh(), 2026);

        $this->assertSame('2', $data['basic_particulars']['category_of_employer']);
        $this->assertSame('1', $data['basic_particulars']['status_of_employer']);
        $this->assertSame('E', $data['basic_particulars']['tin_type_code']);
        $labels = array_column($data['incomplete'], 'box');
        $this->assertNotContains('Item 3', $labels);
        $this->assertNotContains('Item 4', $labels);
    }
}
```

Check `FormEData`'s public method name and signature first (`grep -n "public function" app/Services/Payroll/FormEData.php`) and adjust the call in the third test to match; the assertions stay.

- [ ] **Step 2: Run it, expect failure**

Run: `php artisan test --compact tests/Feature/EmployerStatutoryIdentityTest.php`
Expected: FAIL (unknown columns / null particulars).

- [ ] **Step 3: Migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employer statutory identity (spec F1): the registration numbers every upload file and
 * annual form is keyed on. employer_tin and registration_number already exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('epf_employer_no', 40)->nullable()->after('employer_tin');
            $table->string('socso_employer_code', 40)->nullable()->after('epf_employer_no');
            $table->string('hrdf_registration_no', 40)->nullable()->after('socso_employer_code');
            $table->string('employer_category', 2)->nullable()->after('hrdf_registration_no');   // Form E item 3
            $table->string('employer_status', 2)->nullable()->after('employer_category');        // Form E item 4
            $table->string('paying_bank_code', 11)->nullable()->after('employer_status');
            $table->string('paying_bank_account_no', 40)->nullable()->after('paying_bank_code');
            $table->string('payroll_contact_name', 120)->nullable()->after('paying_bank_account_no');
            $table->string('payroll_contact_phone', 40)->nullable()->after('payroll_contact_name');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'epf_employer_no', 'socso_employer_code', 'hrdf_registration_no', 'employer_category', 'employer_status',
                'paying_bank_code', 'paying_bank_account_no', 'payroll_contact_name', 'payroll_contact_phone',
            ]);
        });
    }
};
```

- [ ] **Step 4: StatutoryOptions constants**

Add to `app/Support/StatutoryOptions.php` after `BANKS`:

```php
    /** Form E item 3 (Category of employer) codes, per the Form E sample in docs/statutory. */
    public const EMPLOYER_CATEGORIES = ['1' => 'Government', '2' => 'Statutory body', '3' => 'Private sector', '4' => 'Others'];

    /** Form E item 4 (Status of employer) codes. */
    public const EMPLOYER_STATUSES = ['1' => 'Company', '2' => 'Partnership', '3' => 'Sole proprietor', '4' => 'Association / society', '5' => 'Others'];

    /**
     * Malaysian bank SWIFT/BIC codes keyed by the BANKS display name. Used by the salary
     * structure bank picker (bank_code) and the employer's paying bank.
     */
    public const BANK_CODES = [
        'Affin Bank' => 'PHBMMYKL', 'Agrobank' => 'AGOBMYKL', 'Alliance Bank' => 'MFBBMYKL', 'AmBank' => 'ARBKMYKL',
        'Bank Islam' => 'BIMBMYKL', 'Bank Muamalat' => 'BMMBMYKL', 'Bank Rakyat' => 'BKRMMYKL', 'Bank Simpanan Nasional' => 'BSNAMYK1',
        'CIMB Bank' => 'CIBBMYKL', 'Citibank' => 'CITIMYKL', 'Hong Leong Bank' => 'HLBBMYKL', 'HSBC Bank' => 'HBMBMYKL',
        'Kuwait Finance House' => 'KFHOMYKL', 'Maybank' => 'MBBEMYKL', 'MBSB Bank' => 'AFBQMYKL', 'OCBC Bank' => 'OCBCMYKL',
        'Public Bank' => 'PBBEMYKL', 'RHB Bank' => 'RHBBMYKL', 'Standard Chartered' => 'SCBLMYKX', 'United Overseas Bank' => 'UOVBMYKL',
    ];
```

Verify the codes against the Form E sample PDF for items 3/4 (`pdftotext docs/statutory/form-e-sample-2025.pdf - | grep -n -i -A6 "category of employer"`); if the sample lists different code labels, use the sample's.

- [ ] **Step 5: AdminController validation and write**

In `updateSettings`, add after the `employer_tin` rule:

```php
            // Statutory registration numbers (spec F1). Loose format on purpose: agencies
            // change formats, a hard regex would block real numbers.
            'epf_employer_no' => ['nullable', 'regex:/^[0-9\-]+$/', 'max:40'],
            'socso_employer_code' => ['nullable', 'regex:/^[A-Za-z0-9\-]+$/', 'max:40'],
            'hrdf_registration_no' => ['nullable', 'regex:/^[A-Za-z0-9\-]+$/', 'max:40'],
            'employer_category' => ['nullable', Rule::in(array_keys(StatutoryOptions::EMPLOYER_CATEGORIES))],
            'employer_status' => ['nullable', Rule::in(array_keys(StatutoryOptions::EMPLOYER_STATUSES))],
            'paying_bank_code' => ['nullable', Rule::in(array_values(StatutoryOptions::BANK_CODES))],
            'paying_bank_account_no' => ['nullable', 'regex:/^[0-9\-]+$/', 'max:40'],
            'payroll_contact_name' => ['nullable', 'string', 'max:120'],
            'payroll_contact_phone' => ['nullable', 'string', 'max:40'],
```

Before `$request->validate`, trim the numeric-ish fields so the regex sees clean input:

```php
        $request->merge(collect(['epf_employer_no', 'socso_employer_code', 'hrdf_registration_no', 'paying_bank_account_no'])
            ->mapWithKeys(fn (string $k) => [$k => trim((string) $request->input($k)) ?: null])->all());
```

In the `$update` array add:

```php
            'epf_employer_no' => $data['epf_employer_no'] ?? null,
            'socso_employer_code' => $data['socso_employer_code'] ?? null,
            'hrdf_registration_no' => $data['hrdf_registration_no'] ?? null,
            'employer_category' => $data['employer_category'] ?? null,
            'employer_status' => $data['employer_status'] ?? null,
            'paying_bank_code' => $data['paying_bank_code'] ?? null,
            'paying_bank_account_no' => $data['paying_bank_account_no'] ?? null,
            'payroll_contact_name' => $data['payroll_contact_name'] ?? null,
            'payroll_contact_phone' => $data['payroll_contact_phone'] ?? null,
```

Add `use App\Support\StatutoryOptions;` and `use Illuminate\Validation\Rule;` imports if missing.

- [ ] **Step 6: Settings form section**

In `resources/views/screens/settings.blade.php`, directly after the `employer_tin` hint include (line ~65), add:

```blade
            @php
                $inp = 'width:100%;height:42px;padding:0 14px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;outline:none;';
                $lab = 'display:block;font-size:13px;font-weight:500;color:var(--ink);margin:14px 0 6px;';
            @endphp
            <div class="uj-section-head" style="margin-top:22px;" x-text="$store.ui.lang==='en' ? 'Statutory registration' : 'Pendaftaran berkanun'">Statutory registration</div>
            @include('partials.hint', ['en' => 'Every KWSP, PERKESO, LHDN and HRD Corp file is keyed on these numbers. A payroll run cannot be created while the EPF number, SOCSO code or TIN is blank.', 'ms' => 'Setiap fail KWSP, PERKESO, LHDN dan HRD Corp berkunci pada nombor ini. Run gaji tidak boleh dibuat selagi nombor KWSP, kod PERKESO atau TIN kosong.'])
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:0 20px;">
                <div><label style="{{ $lab }}" x-text="$store.ui.lang==='en' ? 'EPF employer number' : 'No. majikan KWSP'">EPF employer number</label><input name="epf_employer_no" value="{{ old('epf_employer_no', $company->epf_employer_no) }}" style="{{ $inp }}" />@error('epf_employer_no')<div style="font-size:12px;color:var(--error);">{{ $message }}</div>@enderror</div>
                <div><label style="{{ $lab }}" x-text="$store.ui.lang==='en' ? 'SOCSO employer code' : 'Kod majikan PERKESO'">SOCSO employer code</label><input name="socso_employer_code" value="{{ old('socso_employer_code', $company->socso_employer_code) }}" style="{{ $inp }}" />@error('socso_employer_code')<div style="font-size:12px;color:var(--error);">{{ $message }}</div>@enderror</div>
                <div><label style="{{ $lab }}" x-text="$store.ui.lang==='en' ? 'HRD Corp registration number' : 'No. pendaftaran HRD Corp'">HRD Corp registration number</label><input name="hrdf_registration_no" value="{{ old('hrdf_registration_no', $company->hrdf_registration_no) }}" style="{{ $inp }}" />@error('hrdf_registration_no')<div style="font-size:12px;color:var(--error);">{{ $message }}</div>@enderror</div>
                <div><label style="{{ $lab }}" x-text="$store.ui.lang==='en' ? 'Category of employer (Form E item 3)' : 'Kategori majikan (Borang E item 3)'">Category of employer (Form E item 3)</label>
                    <select name="employer_category" style="{{ $inp }}"><option value="">—</option>@foreach (\App\Support\StatutoryOptions::EMPLOYER_CATEGORIES as $k => $v)<option value="{{ $k }}" @selected(old('employer_category', $company->employer_category) === $k)>{{ $k }} · {{ $v }}</option>@endforeach</select></div>
                <div><label style="{{ $lab }}" x-text="$store.ui.lang==='en' ? 'Status of employer (Form E item 4)' : 'Status majikan (Borang E item 4)'">Status of employer (Form E item 4)</label>
                    <select name="employer_status" style="{{ $inp }}"><option value="">—</option>@foreach (\App\Support\StatutoryOptions::EMPLOYER_STATUSES as $k => $v)<option value="{{ $k }}" @selected(old('employer_status', $company->employer_status) === $k)>{{ $k }} · {{ $v }}</option>@endforeach</select></div>
                <div><label style="{{ $lab }}" x-text="$store.ui.lang==='en' ? 'Paying bank' : 'Bank pembayar'">Paying bank</label>
                    <select name="paying_bank_code" style="{{ $inp }}"><option value="">—</option>@foreach (\App\Support\StatutoryOptions::BANK_CODES as $name => $code)<option value="{{ $code }}" @selected(old('paying_bank_code', $company->paying_bank_code) === $code)>{{ $name }}</option>@endforeach</select></div>
                <div><label style="{{ $lab }}" x-text="$store.ui.lang==='en' ? 'Paying account number' : 'No. akaun pembayar'">Paying account number</label><input name="paying_bank_account_no" value="{{ old('paying_bank_account_no', $company->paying_bank_account_no) }}" style="{{ $inp }}" /></div>
                <div><label style="{{ $lab }}" x-text="$store.ui.lang==='en' ? 'Payroll contact name' : 'Nama pegawai gaji'">Payroll contact name</label><input name="payroll_contact_name" value="{{ old('payroll_contact_name', $company->payroll_contact_name) }}" style="{{ $inp }}" /></div>
                <div><label style="{{ $lab }}" x-text="$store.ui.lang==='en' ? 'Payroll contact phone' : 'Telefon pegawai gaji'">Payroll contact phone</label><input name="payroll_contact_phone" value="{{ old('payroll_contact_phone', $company->payroll_contact_phone) }}" style="{{ $inp }}" /></div>
            </div>
```

- [ ] **Step 7: FormEData items 3 to 5**

Replace lines 39-41 with:

```php
                'category_of_employer' => $tenant->employer_category,
                'status_of_employer' => $tenant->employer_status,
                // Item 5: the TIN type is "E" whenever an employer TIN is on file.
                'tin_type_code' => $tenant->employer_tin ? 'E' : null,
```

In `incompleteFields()`, make Item 3 / Item 4 / Item 5 entries conditional on the field being blank (keep the existing array shape; wrap each in `if ($tenant->employer_category === null)` etc.). Update `resources/views/pdf/form-e.blade.php:39-43` `$blankClass` usage only if the view needs a non-blank class for filled values (check how `employer_tin` is rendered on the same row and mirror it).

- [ ] **Step 8: Run tests, phpstan, pint; migrate dev; commit**

```bash
php artisan test --compact tests/Feature/EmployerStatutoryIdentityTest.php tests/Feature/FormEDataTest.php tests/Feature/FormEControllerTest.php
vendor/bin/phpstan analyse --memory-limit=512M
vendor/bin/pint --dirty --format agent
lerd artisan migrate
lerd artisan view:clear && lerd artisan view:cache && bun run build
git add -A database/migrations app/Http/Controllers/AdminController.php app/Support/StatutoryOptions.php app/Services/Payroll/FormEData.php resources/views tests/Feature/EmployerStatutoryIdentityTest.php public/build
git commit -m "feat(payroll): employer statutory registration numbers on company settings, Form E items 3 to 5 filled"
```

---

### Task 2: F2 salary structure columns (bank_code, socso_exempt, hrdf_exempt)

**Files:**
- Create: `database/migrations/2026_10_01_100100_add_bank_code_and_exemptions_to_salary_structures_table.php`
- Modify: `app/Models/SalaryStructure.php` (fillable + casts)
- Modify: `app/Http/Controllers/PayrollController.php:65-176` `storeSalary` (validate + write)
- Modify: `resources/views/partials/profile/bank-tab.blade.php` (two checkboxes in the EPF · SOCSO / EIS section)
- Test: `tests/Feature/SalaryStructureBankCodeTest.php`

**Interfaces:**
- Produces: `salary_structures.bank_code` (string 11, nullable, derived from `bank_name` via `StatutoryOptions::BANK_CODES` on save and backfilled by the migration), `socso_exempt` bool, `hrdf_exempt` bool. Task 3 reads `bank_code`/`socso_exempt`; Task 9 reads `hrdf_exempt`.

- [ ] **Step 1: Failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\SalaryStructure;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SalaryStructureBankCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_a_bank_name_derives_the_bank_code_and_exemptions_persist(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $hr->tenants()->attach($tenant->id, ['role' => 'hr']);
        $emp = Employee::create(['tenant_id' => $tenant->id, 'name' => 'Worker', 'staff_id' => 'AC-1', 'status' => 'active', 'workload' => 'green']);

        $this->actingAs($hr)->withSession(['current_tenant' => $tenant->id])
            ->post(route('payroll.salary'), [
                'employee_id' => $emp->id, 'bank_name' => 'Maybank', 'bank_account_no' => '514011223344',
                'socso_exempt' => '1', 'hrdf_exempt' => '1',
            ])->assertSessionHasNoErrors();

        $s = SalaryStructure::where('employee_id', $emp->id)->firstOrFail();
        $this->assertSame('MBBEMYKL', $s->bank_code);
        $this->assertTrue($s->socso_exempt);
        $this->assertTrue($s->hrdf_exempt);
    }
}
```

If `storeSalary` requires more fields (check its validate array), add the minimum required ones to the post payload.

- [ ] **Step 2: Run, expect failure**

`php artisan test --compact tests/Feature/SalaryStructureBankCodeTest.php`

- [ ] **Step 3: Migration with backfill**

```php
<?php

declare(strict_types=1);

use App\Support\StatutoryOptions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec F2: bank_code (SWIFT/BIC, what upload files carry) derived from the free-text
 * bank_name once here and on every save after; SOCSO/HRD Corp exemption switches.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_structures', function (Blueprint $table) {
            $table->string('bank_code', 11)->nullable()->after('bank_name');
            $table->boolean('socso_exempt')->default(false)->after('socso_category');
            $table->boolean('hrdf_exempt')->default(false)->after('socso_exempt');
        });

        foreach (StatutoryOptions::BANK_CODES as $name => $code) {
            // Match "Maybank", "MAYBANK", "Malayan Banking Berhad (MAYBANK) (MBB)" by the display
            // name's first word; anything unmatched stays null and the readiness gate flags it.
            $needle = strtolower(explode(' ', $name)[0]);
            DB::table('salary_structures')->whereNull('bank_code')
                ->whereRaw('LOWER(bank_name) LIKE ?', ['%'.$needle.'%'])
                ->update(['bank_code' => $code]);
        }
    }

    public function down(): void
    {
        Schema::table('salary_structures', function (Blueprint $table) {
            $table->dropColumn(['bank_code', 'socso_exempt', 'hrdf_exempt']);
        });
    }
};
```

- [ ] **Step 4: Model + controller**

`SalaryStructure`: add `'bank_code', 'socso_exempt', 'hrdf_exempt'` to `$fillable`; add `'socso_exempt' => 'boolean', 'hrdf_exempt' => 'boolean'` to casts.

`storeSalary` validate: add `'socso_exempt' => ['boolean'], 'hrdf_exempt' => ['boolean'],`. Where the structure attributes are assembled from `$data` (find the array that writes `bank_name`), add:

```php
            'bank_code' => StatutoryOptions::BANK_CODES[$data['bank_name'] ?? ''] ?? null,
            'socso_exempt' => $request->boolean('socso_exempt'),
            'hrdf_exempt' => $request->boolean('hrdf_exempt'),
```

- [ ] **Step 5: Form checkboxes**

In `bank-tab.blade.php` inside the `EPF · SOCSO / EIS` grid, after the Nationality select:

```blade
                <label style="{{ $chkRow }}">{!! $chk('socso_exempt', (bool) $s?->socso_exempt) !!} {!! $L('SOCSO exempt (no PERKESO number required)', 'Dikecualikan PERKESO (no. PERKESO tidak diperlukan)') !!}</label>
                <label style="{{ $chkRow }}">{!! $chk('hrdf_exempt', (bool) $s?->hrdf_exempt) !!} {!! $L('HRD Corp levy exempt', 'Dikecualikan levi HRD Corp') !!}</label>
```

Also add read-only rows to the display block (line ~25 list): `['SOCSO exempt', 'Dikecualikan PERKESO', $s ? $yn($s->socso_exempt) : '—']` and the HRD Corp one, mirroring the existing `$yn` usage.

- [ ] **Step 6: Test, phpstan, pint, migrate, build, commit**

```bash
php artisan test --compact tests/Feature/SalaryStructureBankCodeTest.php tests/Feature/PayrollTest.php
vendor/bin/phpstan analyse --memory-limit=512M && vendor/bin/pint --dirty --format agent
lerd artisan migrate && lerd artisan view:clear && lerd artisan view:cache && bun run build
git add -A database/migrations app/Models/SalaryStructure.php app/Http/Controllers/PayrollController.php resources/views/partials/profile/bank-tab.blade.php tests/Feature/SalaryStructureBankCodeTest.php public/build
git commit -m "feat(payroll): bank code derived from bank name, SOCSO and HRD Corp exemption switches on the salary structure"
```

---
### Task 3: F2 PayrollReadiness service

**Files:**
- Create: `app/Services/Payroll/PayrollReadiness.php`
- Test: `tests/Feature/PayrollReadinessTest.php`

**Interfaces:**
- Consumes: Task 1 tenant columns, Task 2 `bank_code`/`socso_exempt`, `FeatureManager::value($tenant, 'payroll.hrdf')` (Task 9 adds the setting; until then `value()` returns null which reads as "off").
- Produces:

```php
final class PayrollReadiness
{
    public function __construct(private readonly FeatureManager $features) {}

    /** @return list<string>  Missing employer fields, human labels, e.g. 'EPF employer number'. */
    public function employerGaps(Tenant $tenant): array;

    /**
     * One row per currently employed employee (status active/probation/on_leave).
     * @return list<array{employee: Employee, blocking: list<string>, warnings: list<string>}>
     */
    public function employeeRows(Tenant $tenant): array;

    /** @param list<int> $excludedIds  @return list<array{employee: Employee, blocking: list<string>, warnings: list<string>}> rows that still block. */
    public function blockingRows(Tenant $tenant, array $excludedIds = []): array;
}
```

- [ ] **Step 1: Failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\SalaryStructure;
use App\Models\Tenant;
use App\Services\Payroll\PayrollReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollReadinessTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC',
            'employer_tin' => '1234567890', 'epf_employer_no' => '12345678', 'socso_employer_code' => 'A123']);
        app(\App\Tenancy\CurrentTenant::class)->set($this->tenant);
    }

    private function readyEmployee(array $overrides = [], array $structure = []): Employee
    {
        $emp = Employee::create(array_merge([
            'tenant_id' => $this->tenant->id, 'name' => 'Worker', 'staff_id' => 'AC-'.random_int(1, 9999), 'status' => 'active', 'workload' => 'green',
            'nric' => '880101-14-5500', 'date_of_birth' => '1988-01-01', 'joined_at' => '2020-01-01', 'salary' => 3000,
        ], $overrides));
        SalaryStructure::forceCreate(array_merge([
            'tenant_id' => $this->tenant->id, 'employee_id' => $emp->id, 'basic_salary' => 3000,
            'bank_name' => 'Maybank', 'bank_code' => 'MBBEMYKL', 'bank_account_no' => '514011223344',
            'epf_no' => '12345678', 'socso_no' => '880101145500', 'tax_no' => 'SG12345678',
        ], $structure));

        return $emp;
    }

    public function test_a_complete_employee_has_no_gaps(): void
    {
        $this->readyEmployee();
        $rows = app(PayrollReadiness::class)->employeeRows($this->tenant);
        $this->assertCount(1, $rows);
        $this->assertSame([], $rows[0]['blocking']);
        $this->assertSame([], $rows[0]['warnings']);
    }

    public function test_missing_items_are_named_and_a_missing_tin_only_warns(): void
    {
        $this->readyEmployee(['nric' => null, 'salary' => 0], ['epf_no' => null, 'bank_code' => null, 'tax_no' => null]);
        $rows = app(PayrollReadiness::class)->employeeRows($this->tenant);
        $this->assertSame(['Basic pay', 'NRIC', 'EPF number', 'Bank'], $rows[0]['blocking']);
        $this->assertSame(['TIN'], $rows[0]['warnings']);
    }

    public function test_socso_exempt_skips_the_socso_number(): void
    {
        $this->readyEmployee([], ['socso_no' => null, 'socso_exempt' => true]);
        $this->assertSame([], app(PayrollReadiness::class)->employeeRows($this->tenant)[0]['blocking']);
    }

    public function test_employee_without_a_structure_blocks_and_exclusion_lifts_it(): void
    {
        $emp = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'New', 'staff_id' => 'AC-9', 'status' => 'active', 'workload' => 'green']);
        $svc = app(PayrollReadiness::class);
        $this->assertSame(['Salary structure'], $svc->blockingRows($this->tenant)[0]['blocking']);
        $this->assertSame([], $svc->blockingRows($this->tenant, [$emp->id]));
    }

    public function test_employer_gaps_name_the_blank_fields(): void
    {
        $this->tenant->update(['socso_employer_code' => null]);
        $this->assertSame(['SOCSO employer code'], app(PayrollReadiness::class)->employerGaps($this->tenant->fresh()));
    }
}
```

Check how other tests set the current tenant (`grep -rn "CurrentTenant" tests/Feature | head`); if `CurrentTenant` has no `set()`, use the same approach those tests use (usually `actingAs` + `withSession`, then a request) or resolve the tenant scope by querying with `withoutGlobalScopes` inside the service. `Employee::active()` is tenant-scoped through `BelongsToTenant`, so the service must be called with the tenant resolved.

- [ ] **Step 2: Run, expect failure** (class not found).

- [ ] **Step 3: Implementation**

```php
<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\Tenant;
use App\Services\FeatureManager;

/**
 * Spec F2 readiness gate: what must be on file before a payroll run can be created.
 * A payslip with a blank identifier is a rejected agency upload on the 15th, so every
 * item here blocks except the TIN (LHDN accepts the NRIC in CP39 for staff without one).
 */
final class PayrollReadiness
{
    public function __construct(private readonly FeatureManager $features) {}

    /** @return list<string> */
    public function employerGaps(Tenant $tenant): array
    {
        $gaps = [];
        if (blank($tenant->epf_employer_no)) {
            $gaps[] = 'EPF employer number';
        }
        if (blank($tenant->socso_employer_code)) {
            $gaps[] = 'SOCSO employer code';
        }
        if (blank($tenant->employer_tin)) {
            $gaps[] = "Employer's TIN (E number)";
        }
        // HRD Corp number is only needed once the levy is switched on (spec F7).
        $hrdf = (string) ($this->features->value($tenant, 'payroll.hrdf') ?? 'off');
        if ($hrdf !== 'off' && blank($tenant->hrdf_registration_no)) {
            $gaps[] = 'HRD Corp registration number';
        }

        return $gaps;
    }

    /** @return list<array{employee: Employee, blocking: list<string>, warnings: list<string>}> */
    public function employeeRows(Tenant $tenant): array
    {
        return Employee::active()->where('tenant_id', $tenant->id)
            ->whereIn('status', ['active', 'probation', 'on_leave'])
            ->with('salaryStructure')->orderBy('name')->get()
            ->map(fn (Employee $e) => ['employee' => $e, ...$this->gapsFor($e)])
            ->values()->all();
    }

    /**
     * @param  list<int>  $excludedIds
     * @return list<array{employee: Employee, blocking: list<string>, warnings: list<string>}>
     */
    public function blockingRows(Tenant $tenant, array $excludedIds = []): array
    {
        return array_values(array_filter(
            $this->employeeRows($tenant),
            fn (array $row) => $row['blocking'] !== [] && ! in_array($row['employee']->id, $excludedIds, true),
        ));
    }

    /** @return array{blocking: list<string>, warnings: list<string>} */
    private function gapsFor(Employee $e): array
    {
        $s = $e->salaryStructure;
        if ($s === null) {
            return ['blocking' => ['Salary structure'], 'warnings' => []];
        }
        $blocking = [];
        if ((float) ($e->salary ?? 0) <= 0) {
            $blocking[] = 'Basic pay';
        }
        if (blank($e->nric)) {
            $blocking[] = 'NRIC';
        }
        if (blank($s->epf_no)) {
            $blocking[] = 'EPF number';
        }
        if (! $s->socso_exempt && blank($s->socso_no)) {
            $blocking[] = 'SOCSO number';
        }
        if (blank($s->bank_code) || blank($s->bank_account_no)) {
            $blocking[] = 'Bank';
        }
        if ($e->date_of_birth === null) {
            $blocking[] = 'Date of birth';
        }
        if ($e->joined_at === null) {
            $blocking[] = 'Joined date';
        }
        $warnings = blank($s->tax_no) ? ['TIN'] : [];

        return ['blocking' => $blocking, 'warnings' => $warnings];
    }
}
```

`Employee::active()` may already scope by tenant through the global scope; the explicit `where('tenant_id')` is harmless and keeps the service usable from the scheduler.

- [ ] **Step 4: Test, phpstan, pint, commit**

```bash
php artisan test --compact tests/Feature/PayrollReadinessTest.php
vendor/bin/phpstan analyse --memory-limit=512M && vendor/bin/pint --dirty --format agent
git add app/Services/Payroll/PayrollReadiness.php tests/Feature/PayrollReadinessTest.php
git commit -m "feat(payroll): readiness check listing what each employee and the employer still needs before a run"
```

---

### Task 4: F2 readiness panel, create-run gate and exclusions

**Files:**
- Create: `database/migrations/2026_10_01_100200_add_excluded_employee_ids_to_payroll_runs_table.php`
- Modify: `app/Models/PayrollRun.php` (casts + fillable)
- Modify: `app/Http/Controllers/PayrollController.php:734-770` `createRun`
- Modify: `app/Http/Controllers/Concerns/BuildsWorkData.php:526` `payrollData` (privileged branch)
- Create: `resources/views/partials/payroll/process/readiness.blade.php`
- Modify: `resources/views/partials/payroll/process/monthly.blade.php` (include panel above the create card, button count)
- Test: `tests/Feature/PayrollRunReadinessGateTest.php`

**Interfaces:**
- Consumes: `PayrollReadiness::employerGaps/employeeRows/blockingRows` (Task 3).
- Produces: `payroll_runs.excluded_employee_ids` json (list<int>); view vars `readinessEmployer` (list<string>), `readinessRows` (list of rows), `readinessBlockingCount` (int). Create-run form field `exclude_employee_ids[]`.

- [ ] **Step 1: Failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\SalaryStructure;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PayrollRunReadinessGateTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC',
            'employer_tin' => '1234567890', 'epf_employer_no' => '12345678', 'socso_employer_code' => 'A123']);
        $this->hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $this->hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);
        $this->actingAs($this->hr)->withSession(['current_tenant' => $this->tenant->id]);
    }

    private function employee(string $name, array $structure = []): Employee
    {
        $emp = Employee::create(['tenant_id' => $this->tenant->id, 'name' => $name, 'staff_id' => 'AC-'.$name, 'status' => 'active', 'workload' => 'green',
            'nric' => '880101-14-5500', 'date_of_birth' => '1988-01-01', 'joined_at' => '2020-01-01', 'salary' => 3000]);
        SalaryStructure::forceCreate(array_merge(['tenant_id' => $this->tenant->id, 'employee_id' => $emp->id, 'basic_salary' => 3000,
            'bank_name' => 'Maybank', 'bank_code' => 'MBBEMYKL', 'bank_account_no' => '1', 'epf_no' => '1', 'socso_no' => '1', 'tax_no' => 'SG1'], $structure));

        return $emp;
    }

    public function test_unready_employee_blocks_and_names_the_field(): void
    {
        $this->employee('Ready');
        $this->employee('Gap', ['epf_no' => null]);
        $this->post(route('payroll.runs.create'), ['period' => '2026-06'])
            ->assertSessionHasErrors('readiness');
        $this->assertStringContainsString('Gap: EPF number', session('errors')->first('readiness'));
        $this->assertSame(0, PayrollRun::count());
    }

    public function test_excluding_the_employee_lets_the_run_through_and_stores_it(): void
    {
        $this->employee('Ready');
        $gap = $this->employee('Gap', ['epf_no' => null]);
        $this->post(route('payroll.runs.create'), ['period' => '2026-06', 'exclude_employee_ids' => [$gap->id]])
            ->assertSessionHasNoErrors();
        $run = PayrollRun::firstOrFail();
        $this->assertSame([$gap->id], $run->excluded_employee_ids);
        $this->assertSame(1, $run->payslips()->count());
    }

    public function test_missing_tin_alone_does_not_block(): void
    {
        $this->employee('NoTin', ['tax_no' => null]);
        $this->post(route('payroll.runs.create'), ['period' => '2026-06'])->assertSessionHasNoErrors();
    }

    public function test_blank_employer_epf_number_blocks(): void
    {
        $this->tenant->update(['epf_employer_no' => null]);
        $this->employee('Ready');
        $this->post(route('payroll.runs.create'), ['period' => '2026-06'])->assertSessionHasErrors('readiness');
        $this->assertStringContainsString('EPF employer number', session('errors')->first('readiness'));
    }
}
```

- [ ] **Step 2: Run, expect failure.**

- [ ] **Step 3: Migration + model**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Spec F2: employees HR explicitly left out of a run (paid outside payroll). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->json('excluded_employee_ids')->nullable()->after('pull_options');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropColumn('excluded_employee_ids');
        });
    }
};
```

`PayrollRun`: add `'excluded_employee_ids'` to `$fillable` and `'excluded_employee_ids' => 'array'` to casts.

- [ ] **Step 4: createRun gate**

In `createRun`, add to the validate array:

```php
            'exclude_employee_ids' => ['nullable', 'array'],
            'exclude_employee_ids.*' => ['integer', Rule::exists('employees', 'id')->where('tenant_id', $tid)],
```

After the duplicate-period check and before `$employees = ...`:

```php
        // Spec F2 readiness gate: refuse while the employer or any included employee is
        // missing an identifier an agency upload needs. Exclusions are HR's explicit call
        // and are stored on the run.
        $excluded = array_map('intval', $data['exclude_employee_ids'] ?? []);
        $tenant = app(CurrentTenant::class)->get();
        $readiness = app(PayrollReadiness::class);
        $problems = array_map(fn (string $g) => 'Company: '.$g, $readiness->employerGaps($tenant));
        foreach ($readiness->blockingRows($tenant, $excluded) as $row) {
            $problems[] = $row['employee']->name.': '.implode(', ', $row['blocking']);
        }
        if ($problems !== []) {
            return back()->withErrors(['readiness' => 'Not ready to run payroll. '.implode(' · ', $problems)])->withInput();
        }
```

Then change the employees query to add `->whereNotIn('id', $excluded)` and pass `'excluded_employee_ids' => $excluded ?: null` into `new PayrollRun([...])`. Add `use App\Services\Payroll\PayrollReadiness;` and confirm `Rule` is imported. Add the exclusion to the audit line: `.($excluded ? ' · excluded: '.count($excluded) : '')`.

- [ ] **Step 5: View data + panel**

In `payrollData` privileged return array add:

```php
            'readinessEmployer' => $readinessEmployer = app(PayrollReadiness::class)->employerGaps($tenant = app(CurrentTenant::class)->get()),
            'readinessRows' => $readinessRows = app(PayrollReadiness::class)->employeeRows($tenant),
            'readinessBlockingCount' => count($readinessEmployer) + count(array_filter($readinessRows, fn (array $r) => $r['blocking'] !== [])),
```

(Add the imports `App\Services\Payroll\PayrollReadiness` and `App\Tenancy\CurrentTenant` to `BuildsWorkData` if missing.) The non-privileged branch gets `'readinessEmployer' => [], 'readinessRows' => [], 'readinessBlockingCount' => 0`.

Create `resources/views/partials/payroll/process/readiness.blade.php`:

```blade
{{-- Spec F2 readiness panel: every currently employed person, red mark per missing item.
     Expects $readinessEmployer (list<string>), $readinessRows, $readinessBlockingCount. --}}
@php
    $gapRows = array_filter($readinessRows, fn ($r) => $r['blocking'] !== [] || $r['warnings'] !== []);
@endphp
<div class="uj-card" style="padding:18px 20px;margin-bottom:16px;">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
        <h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Readiness' : 'Kesediaan'">Readiness</h3>
        @if ($readinessBlockingCount === 0)
            <span class="uj-pill" style="background:var(--red-tint);color:var(--success);" x-text="$store.ui.lang==='en' ? 'Everyone is ready' : 'Semua sedia'">Everyone is ready</span>
        @else
            <span class="uj-pill" style="background:var(--red-tint);color:var(--error);"><span x-text="$store.ui.lang==='en' ? 'Not ready' : 'Belum sedia'">Not ready</span> · {{ $readinessBlockingCount }}</span>
        @endif
    </div>
    @error('readiness')<div style="margin-top:10px;background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:8px 11px;">{{ $message }}</div>@enderror
    @if ($readinessEmployer)
        <div style="margin-top:10px;font-size:12.5px;"><b x-text="$store.ui.lang==='en' ? 'Company' : 'Syarikat'">Company</b>: <span style="color:var(--error);">{{ implode(', ', $readinessEmployer) }}</span> · <a href="{{ route('app.screen', 'settings') }}" style="color:var(--red);" x-text="$store.ui.lang==='en' ? 'Fix in Settings' : 'Betulkan di Tetapan'">Fix in Settings</a></div>
    @endif
    @if ($gapRows)
        <table style="width:100%;border-collapse:collapse;font-size:12.5px;margin-top:10px;">
            @foreach ($gapRows as $r)
                <tr style="border-top:1px solid var(--hairline-soft);">
                    <td style="padding:8px 0;font-weight:500;color:var(--ink);white-space:nowrap;"><a href="{{ route('app.screen', ['screen' => 'profile', 'employee' => $r['employee']->id, 'tab' => 'bank']) }}" style="color:inherit;text-decoration:none;">{{ $r['employee']->name }}</a></td>
                    <td style="padding:8px;">
                        @foreach ($r['blocking'] as $g)<span class="uj-pill" style="background:var(--red-tint);color:var(--error);margin-right:4px;">{{ $g }}</span>@endforeach
                        @foreach ($r['warnings'] as $g)<span class="uj-pill" style="background:#fff7e6;color:var(--amber);margin-right:4px;">{{ $g }} · <span x-text="$store.ui.lang==='en' ? 'warning' : 'amaran'">warning</span></span>@endforeach
                    </td>
                    <td style="padding:8px 0;text-align:right;white-space:nowrap;">
                        @if ($r['blocking'] !== [])
                            <label style="font-size:12px;color:var(--muted);cursor:pointer;"><input type="checkbox" form="create-run-form" name="exclude_employee_ids[]" value="{{ $r['employee']->id }}" @checked(in_array($r['employee']->id, (array) old('exclude_employee_ids', []))) /> <span x-text="$store.ui.lang==='en' ? 'Leave out of this run' : 'Kecualikan dari run ini'">Leave out of this run</span></label>
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>
    @endif
</div>
```

Check the profile screen's route/tab names with `grep -n "'profile'" routes/web.php app/Support/Amanahku.php | head` and adjust the link.

In `monthly.blade.php`: include the panel at the top (`@include('partials.payroll.process.readiness')` before the outer flex div), give the create form `id="create-run-form"`, and change the submit button to show the count and disable when blocked:

```blade
            <button type="submit" class="uj-btn-primary" style="height:40px;width:100%;font-size:13.5px;" @if ($readinessBlockingCount > 0) disabled title="Fix the readiness list above, or leave the person out of this run" @endif><span x-text="$store.ui.lang==='en' ? 'Generate draft run' : 'Jana draft run'">Generate draft run</span>@if ($readinessBlockingCount > 0) · {{ $readinessBlockingCount }}@endif</button>
```

The disabled button is a convenience; the server-side gate in Step 4 is what enforces it (ticking "Leave out" re-enables it via a tiny Alpine: wrap the card in `x-data="{ excluded: 0 }"` only if you find the disabled state blocks the exclusion flow; otherwise rely on a page reload after the tick, which the checkbox does not trigger, so use `:disabled="{{ $readinessBlockingCount }} - excluded > 0"` with `@change="excluded += $event.target.checked ? 1 : -1"` on each checkbox and `x-data="{ excluded: 0 }"` on the panel's parent element).

- [ ] **Step 6: Test, phpstan, pint, migrate, build, commit**

```bash
php artisan test --compact tests/Feature/PayrollRunReadinessGateTest.php tests/Feature/PayrollTest.php tests/Feature/PayrollTransactionsPullTest.php
vendor/bin/phpstan analyse --memory-limit=512M && vendor/bin/pint --dirty --format agent
lerd artisan migrate && lerd artisan view:clear && lerd artisan view:cache && bun run build
git add -A database/migrations app/Models/PayrollRun.php app/Http/Controllers resources/views/partials/payroll/process tests/Feature/PayrollRunReadinessGateTest.php public/build
git commit -m "feat(payroll): readiness panel and gate on create run, with explicit per-employee exclusions"
```

Existing payroll tests that create runs will now need the tenant's three employer numbers and each employee's identifiers; update their `setUp` to include `employer_tin`, `epf_employer_no`, `socso_employer_code` on the tenant and `nric`, `date_of_birth`, `joined_at` on employees, `epf_no`, `socso_no`, `bank_code`, `bank_account_no` on structures. Do this in the same commit.

---

### Task 5: F3 Basic pay proration

**Files:**
- Create: `app/Services/Payroll/Proration.php`
- Create: `database/migrations/2026_10_01_100300_add_proration_columns.php`
- Modify: `app/Models/PayrollItem.php` (fillable, SYSTEM_ITEMS gains a prorate flag, `seedFor` backfill)
- Modify: `app/Http/Controllers/PayrollController.php` (`prorationFactor` 586, `createRun` basic input, `updatePayslip` basic override, `updateItem`)
- Modify: `resources/views/pdf/payslip.blade.php` (days line), `resources/views/partials/payroll/review/individual.blade.php` (basic override input), `resources/views/partials/payroll/transaction/items.blade.php` (flag checkbox)
- Test: `tests/Unit/ProrationTest.php`, `tests/Feature/PayrollProrationTest.php`

**Interfaces:**
- Produces:

```php
final class Proration
{
    /** @return array{employed: int, in_month: int} calendar days, both clamped to the period. */
    public static function days(string $period, ?CarbonInterface $joinedAt, ?CarbonInterface $lastWorkingDay): array;
    /** monthly ÷ days in month × days employed, rounded to 2 dp (EA s.18A). */
    public static function prorate(float $monthly, int $employed, int $inMonth): float;
}
```
Columns: `payroll_items.prorate_on_incomplete_month` bool; `payslips.days_employed` smallint nullable, `days_in_month` smallint nullable, `basic_overridden` bool default false.

- [ ] **Step 1: Unit test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Payroll\Proration;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class ProrationTest extends TestCase
{
    public function test_joiner_in_a_29_day_february(): void
    {
        $d = Proration::days('2028-02', CarbonImmutable::parse('2028-02-08'), null);
        $this->assertSame(['employed' => 22, 'in_month' => 29], $d);
        $this->assertSame(2275.86, Proration::prorate(3000, 22, 29));
    }

    public function test_leaver_on_15_april(): void
    {
        $d = Proration::days('2028-04', CarbonImmutable::parse('2020-01-01'), CarbonImmutable::parse('2028-04-15'));
        $this->assertSame(['employed' => 15, 'in_month' => 30], $d);
        $this->assertSame(1500.00, Proration::prorate(3000, 15, 30));
    }

    public function test_full_month_is_untouched(): void
    {
        $d = Proration::days('2028-04', CarbonImmutable::parse('2020-01-01'), null);
        $this->assertSame(['employed' => 30, 'in_month' => 30], $d);
        $this->assertSame(3000.00, Proration::prorate(3000, 30, 30));
    }

    public function test_left_before_the_month_gives_zero_days(): void
    {
        $this->assertSame(0, Proration::days('2028-04', CarbonImmutable::parse('2020-01-01'), CarbonImmutable::parse('2028-03-31'))['employed']);
    }
}
```

- [ ] **Step 2: Run, expect failure.**

- [ ] **Step 3: Implement Proration**

```php
<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Incomplete-month wages, Employment Act s.18A: monthly wages ÷ days in that month ×
 * days employed. Calendar days, real month length. This is deliberately a different
 * divisor from the 26-day ordinary rate PayrollCalculator uses for unpaid leave and
 * overtime (s.60I); see spec section 7.
 */
final class Proration
{
    /** @return array{employed: int, in_month: int} */
    public static function days(string $period, ?CarbonInterface $joinedAt, ?CarbonInterface $lastWorkingDay): array
    {
        $start = CarbonImmutable::createFromFormat('Y-m-d', $period.'-01')->startOfDay();
        $end = $start->endOfMonth()->startOfDay();
        $inMonth = $end->day;

        $from = $joinedAt !== null && $joinedAt->gt($start) ? CarbonImmutable::instance($joinedAt)->startOfDay() : $start;
        $to = $lastWorkingDay !== null && $lastWorkingDay->lt($end) ? CarbonImmutable::instance($lastWorkingDay)->startOfDay() : $end;

        if ($from->gt($end) || $to->lt($start) || $from->gt($to)) {
            return ['employed' => 0, 'in_month' => $inMonth];
        }

        return ['employed' => $to->day - $from->day + 1, 'in_month' => $inMonth];
    }

    public static function prorate(float $monthly, int $employed, int $inMonth): float
    {
        if ($inMonth <= 0) {
            return 0.0;
        }

        return round($monthly / $inMonth * min($employed, $inMonth), 2);
    }
}
```

- [ ] **Step 4: Run unit test, expect pass.**

- [ ] **Step 5: Feature test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\SalaryStructure;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PayrollProrationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Employee $emp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC',
            'employer_tin' => '1234567890', 'epf_employer_no' => '12345678', 'socso_employer_code' => 'A123']);
        PayrollItem::seedFor($this->tenant);
        $hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);
        $this->emp = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Joiner', 'staff_id' => 'AC-1', 'status' => 'active', 'workload' => 'green',
            'nric' => '880101-14-5500', 'date_of_birth' => '1988-01-01', 'joined_at' => '2028-02-08', 'salary' => 3000]);
        SalaryStructure::forceCreate(['tenant_id' => $this->tenant->id, 'employee_id' => $this->emp->id, 'basic_salary' => 3000,
            'bank_name' => 'Maybank', 'bank_code' => 'MBBEMYKL', 'bank_account_no' => '1', 'epf_no' => '1', 'socso_no' => '1', 'tax_no' => 'SG1']);
        $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id]);
    }

    public function test_joiner_basic_is_prorated_and_day_counts_stored(): void
    {
        $this->post(route('payroll.runs.create'), ['period' => '2028-02'])->assertSessionHasNoErrors();
        $p = Payslip::firstOrFail();
        $this->assertSame(2275.86, $p->basic);
        $this->assertSame(22, $p->days_employed);
        $this->assertSame(29, $p->days_in_month);
        $this->assertFalse($p->basic_overridden);
    }

    public function test_last_working_day_wins_over_full_month(): void
    {
        $this->emp->update(['joined_at' => '2020-01-01', 'last_working_day' => '2028-04-15']);
        $this->post(route('payroll.runs.create'), ['period' => '2028-04'])->assertSessionHasNoErrors();
        $this->assertSame(1500.00, Payslip::firstOrFail()->basic);
    }

    public function test_hr_can_override_the_prorated_basic(): void
    {
        $this->post(route('payroll.runs.create'), ['period' => '2028-02']);
        $p = Payslip::firstOrFail();
        $this->post(route('payroll.payslips.update', $p), ['basic' => '3000'])->assertSessionHasNoErrors();
        $p->refresh();
        $this->assertSame(3000.00, $p->basic);
        $this->assertTrue($p->basic_overridden);
    }

    public function test_item_flag_off_disables_proration(): void
    {
        PayrollItem::where('tenant_id', $this->tenant->id)->where('code', 'basic-salary')->update(['prorate_on_incomplete_month' => false]);
        $this->post(route('payroll.runs.create'), ['period' => '2028-02']);
        $this->assertSame(3000.00, Payslip::firstOrFail()->basic);
    }
}
```

Check `PayrollItem::seedFor` signature (`grep -n "function seedFor" app/Models/PayrollItem.php`) and adjust the call.

- [ ] **Step 6: Migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Spec F3: which items prorate on an incomplete month, and the day counts a payslip prints. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_items', function (Blueprint $table) {
            $table->boolean('prorate_on_incomplete_month')->default(false)->after('perkeso_liable');
        });
        DB::table('payroll_items')->whereIn('code', ['basic-salary', 'fixed-allowance'])->update(['prorate_on_incomplete_month' => true]);

        Schema::table('payslips', function (Blueprint $table) {
            $table->unsignedSmallInteger('days_employed')->nullable()->after('basic');
            $table->unsignedSmallInteger('days_in_month')->nullable()->after('days_employed');
            $table->boolean('basic_overridden')->default(false)->after('days_in_month');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_items', fn (Blueprint $t) => $t->dropColumn('prorate_on_incomplete_month'));
        Schema::table('payslips', fn (Blueprint $t) => $t->dropColumn(['days_employed', 'days_in_month', 'basic_overridden']));
    }
};
```

`PayrollItem`: add `'prorate_on_incomplete_month'` to `$fillable`; in `seedFor`, when creating a system item set `'prorate_on_incomplete_month' => in_array($code, ['basic-salary', 'fixed-allowance'], true)`. `Payslip` casts: `'days_employed' => 'integer', 'days_in_month' => 'integer', 'basic_overridden' => 'boolean'`.

- [ ] **Step 7: Controller**

Replace the body of `prorationFactor()` with:

```php
    private function prorationFactor(Employee $employee, string $period): float
    {
        $d = $this->employedDays($employee, $period);

        return $d['in_month'] > 0 ? min(1.0, $d['employed'] / $d['in_month']) : 0.0;
    }

    /**
     * Calendar days employed within the period (spec F3). Leaving date: the employee
     * record's last_working_day first, then an offboarding case's last_day.
     *
     * @return array{employed: int, in_month: int}
     */
    private function employedDays(Employee $employee, string $period): array
    {
        $periodStart = $this->periodStart($period);
        $periodEnd = $periodStart->copy()->endOfMonth();
        $lastDay = $employee->last_working_day;
        if ($lastDay === null) {
            $raw = OffboardingCase::where('employee_id', $employee->id)
                ->whereIn('status', ['in_progress', 'completed'])
                ->whereBetween('last_day', [$periodStart->toDateString(), $periodEnd->toDateString()])
                ->orderByDesc('last_day')->value('last_day');
            $lastDay = $raw !== null ? Carbon::parse($raw) : null;
        }

        return Proration::days($period, $employee->joined_at, $lastDay);
    }
```

In `createRun` replace the `'basic' => (float) ($employee->salary ?? 0),` line with:

```php
                    'basic' => $basic,
```

and before `$inputs = [` add:

```php
                $days = $this->employedDays($employee, $data['period']);
                $prorateBasic = (bool) ($catalog->get('basic-salary')?->prorate_on_incomplete_month ?? true);
                $basic = $prorateBasic && $days['employed'] < $days['in_month']
                    ? Proration::prorate((float) ($employee->salary ?? 0), $days['employed'], $days['in_month'])
                    : (float) ($employee->salary ?? 0);
```

In the `forceFill([...])` of the new payslip add `'days_employed' => $days['employed'], 'days_in_month' => $days['in_month'],`.

In `updatePayslip` validate add `'basic' => ['nullable', 'numeric', 'min:0', 'max:10000000'],`. Where `$baseInputs['basic'] = $payslip->basic` is set, change to:

```php
                'basic' => $basicOverridden ? (float) $rawBasic : $payslip->basic,
```

with, next to the other `$raw*` reads above the transaction: `$rawBasic = $request->input('basic'); $basicOverridden = $rawBasic !== null && $rawBasic !== '';`. Where the payslip is `forceFill`ed after compute, add `'basic_overridden' => $basicOverridden || $payslip->basic_overridden` (an override persists until the run is regenerated). Add `use App\Services\Payroll\Proration;`.

In `updateItem` validate + update add `'prorate_on_incomplete_month' => ['boolean']` / `=> $request->boolean('prorate_on_incomplete_month')`.

- [ ] **Step 8: Views**

`review/individual.blade.php`: next to the `pcb_override` input (line ~93), add a `basic` number input with label EN "Basic (prorated {{ $p->days_employed }}/{{ $p->days_in_month }} days)" / MS "Gaji pokok (prorata … hari)", value `old('basic', $p->basic_overridden ? $p->basic : '')`, placeholder the generated figure, hint "Leave blank to keep the prorated figure." Show a small "overridden" pill when `$p->basic_overridden`.

`transaction/items.blade.php`: add a checkbox column "Prorate on incomplete month" / "Prorata bulan tidak penuh" bound to `prorate_on_incomplete_month`, mirroring the `epf_liable` checkbox markup.

`pdf/payslip.blade.php`: in the Basic Salary earnings row (find where lines are looped; if basic prints from a `PayslipLine`, add after the name): `@if ($p->days_employed !== null && $p->days_employed < $p->days_in_month) <span style="color:#666;">({{ $p->days_employed }} / {{ $p->days_in_month }} days)</span>@endif`.

- [ ] **Step 9: Test, phpstan, pint, migrate, build, commit**

```bash
php artisan test --compact tests/Unit/ProrationTest.php tests/Feature/PayrollProrationTest.php tests/Feature/PayrollTest.php tests/Feature/PayrollTransactionsPullTest.php tests/Feature/PayslipPdfTest.php
vendor/bin/phpstan analyse --memory-limit=512M && vendor/bin/pint --dirty --format agent
lerd artisan migrate && lerd artisan view:clear && lerd artisan view:cache && bun run build
git add -A app database/migrations resources/views tests public/build
git commit -m "feat(payroll): calendar-day proration of basic pay for joiners and leavers, day counts on the payslip, HR override"
```

---
### Task 6: F4 Minimum wage warning and Fixed Transaction consent reference

**Files:**
- Create: `app/Services/Payroll/MinimumWage.php`
- Create: `database/migrations/2026_10_01_100400_add_consent_reference_to_fixed_transactions_table.php`
- Modify: `app/Models/FixedTransaction.php` (fillable)
- Modify: `app/Http/Controllers/PayrollController.php` (`storeSalary` flash warning; `validateFixedTransaction` 282-311)
- Modify: `resources/views/partials/payroll/transaction/fixed.blade.php` (consent field on add/edit forms, shown for deduction items)
- Test: `tests/Feature/PayrollWageFloorTest.php`

**Interfaces:**
- Produces: `MinimumWage::MONTHLY = 1700.00`, `MinimumWage::EFFECTIVE = '2025-02-01'`, `MinimumWage::below(float $basic): bool`. Column `fixed_transactions.consent_reference` string 160 nullable.

- [ ] **Step 1: Failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\FixedTransaction;
use App\Models\PayrollItem;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Payroll\MinimumWage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PayrollWageFloorTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Employee $emp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        PayrollItem::seedFor($this->tenant);
        $hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);
        $this->emp = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Worker', 'staff_id' => 'AC-1', 'status' => 'active', 'workload' => 'green', 'salary' => 1500]);
        $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id]);
    }

    public function test_floor_constant(): void
    {
        $this->assertSame(1700.00, MinimumWage::MONTHLY);
        $this->assertTrue(MinimumWage::below(1699.99));
        $this->assertFalse(MinimumWage::below(1700.00));
    }

    public function test_saving_a_structure_below_the_floor_warns_but_saves(): void
    {
        $this->post(route('payroll.salary'), ['employee_id' => $this->emp->id, 'bank_name' => 'Maybank', 'bank_account_no' => '1'])
            ->assertSessionHasNoErrors()->assertSessionHas('warn');
        $this->assertStringContainsString('RM1,700', session('warn'));
    }

    public function test_deduction_fixed_transaction_stores_consent_reference(): void
    {
        $loan = PayrollItem::where('tenant_id', $this->tenant->id)->where('code', 'staff-loan')->firstOrFail();
        $this->post(route('payroll.fixed-transactions.store'), [
            'employee_id' => $this->emp->id, 'payroll_item_id' => $loan->id, 'amount' => 100, 'start_period' => '2026-06',
            'consent_reference' => 'Loan agreement LA-2026-03',
        ])->assertSessionHasNoErrors();
        $this->assertSame('Loan agreement LA-2026-03', FixedTransaction::firstOrFail()->consent_reference);
    }
}
```

Adjust `storeSalary` / `storeFixedTransaction` payloads to whatever their validate arrays require (read them first).

- [ ] **Step 2: Run, expect failure.**

- [ ] **Step 3: MinimumWage**

```php
<?php

declare(strict_types=1);

namespace App\Services\Payroll;

/**
 * Minimum Wages Order 2024: RM1,700 a month nationwide from 1 February 2025. Statutory,
 * not tenant-editable; a new Order is a code change here with a new effective date.
 */
final class MinimumWage
{
    public const MONTHLY = 1700.00;

    public const EFFECTIVE = '2025-02-01';

    public static function below(float $basic): bool
    {
        return round($basic, 2) < self::MONTHLY;
    }
}
```

- [ ] **Step 4: Migration + model + controller**

Migration adds `$table->string('consent_reference', 160)->nullable()->after('remarks');` to `fixed_transactions` (down drops it). Add `'consent_reference'` to `FixedTransaction::$fillable`.

`validateFixedTransaction`: add rule `'consent_reference' => ['nullable', 'string', 'max:160'],` and return `'consent_reference' => $data['consent_reference'] ?? null,` (update the docblock array shape too).

`storeSalary`: after the structure is saved and before the redirect, add:

```php
        $basic = (float) ($employee->salary ?? 0);
        $warn = MinimumWage::below($basic)
            ? 'Basic pay RM'.number_format($basic, 2).' is below the RM1,700 monthly minimum (Minimum Wages Order 2024, from '.MinimumWage::EFFECTIVE.'). Check the employment type: the floor does not apply to interns or apprentices.'
            : null;
```

and chain `->with('warn', $warn)` on the redirect when `$warn !== null`. `$employee` is whatever variable `storeSalary` resolved the employee into (read the method). Check `resources/views` for an existing `session('warn')` banner (`grep -rn "session('warn')" resources/views | head -3`); if none, render it in the bank tab next to the `session('ok')` banner with the amber hint style.

- [ ] **Step 5: Fixed Transaction form**

In `transaction/fixed.blade.php`, in both the add and edit forms after the `remarks` input, add a text input `consent_reference` (maxlength 160) labelled EN "Written consent reference (deductions)" / MS "Rujukan kebenaran bertulis (potongan)", hint EN "EA s.24: a non-statutory deduction needs the employee's written consent. Put the date signed or the agreement reference." Show the stored value in the list row when present.

- [ ] **Step 6: Test, phpstan, pint, migrate, build, commit**

```bash
php artisan test --compact tests/Feature/PayrollWageFloorTest.php tests/Feature/PayrollTest.php
vendor/bin/phpstan analyse --memory-limit=512M && vendor/bin/pint --dirty --format agent
lerd artisan migrate && lerd artisan view:clear && lerd artisan view:cache && bun run build
git add -A app database/migrations resources/views tests public/build
git commit -m "feat(payroll): RM1,700 wage floor warning on salary save, consent reference on deduction Fixed Transactions"
```

---

### Task 7: F4 Deduction cap, overtime warning, negative net carry forward, finalize guards

**Files:**
- Create: `database/migrations/2026_10_01_100500_add_deduction_guards_to_payslips_table.php`
- Modify: `app/Services/Payroll/PayrollCalculator.php` (cap flag, carry forward), `app/Services/Payroll/PayslipComputation.php`
- Modify: `app/Models/Payslip.php` (casts)
- Modify: `app/Http/Controllers/PayrollController.php` (`createRun` OT warning + attrs, `updatePayslip` attrs, `finalizeRun` guards, new `confirmDeductionConsent`, new `carryForward`)
- Modify: `routes/web.php` (two POST routes inside the payroll throttle group)
- Modify: `resources/views/partials/payroll/review/individual.blade.php` (flags + two buttons)
- Test: `tests/Unit/PayrollCalculatorTest.php` (append), `tests/Feature/PayrollDeductionGuardsTest.php`

**Interfaces:**
- Produces: calculator input `carry_forward: bool` (default false); `PayslipComputation` gains `public bool $deductionCapExceeded` and `public float $carriedForward`; `toPayslipAttributes()` emits `deduction_cap_exceeded`, `carried_forward_amount`. Columns: `payslips.deduction_cap_exceeded` bool, `deduction_consent_confirmed` bool, `carried_forward_amount` decimal(12,2) default 0. Routes `payroll.payslips.consent` (POST `/app/payroll/payslips/{payslip}/consent`) and `payroll.payslips.carry-forward` (POST `/app/payroll/payslips/{payslip}/carry-forward`). Constant `PayrollCalculator::DEDUCTION_CAP = 0.5`, `PayrollCalculator::OVERTIME_HOURS_CAP = 104`.

- [ ] **Step 1: Unit tests (append to PayrollCalculatorTest)**

```php
    public function test_deduction_cap_trips_above_fifty_percent_of_gross_and_ignores_statutory(): void
    {
        // Gross 1,000: fixed deductions of exactly 500.00 (50.00%) do not trip; 500.10 does.
        $at = $this->calc->compute(['basic' => 1000, 'fixed_deductions_total' => 500.00]);
        $over = $this->calc->compute(['basic' => 1000, 'fixed_deductions_total' => 500.10]);
        $this->assertFalse($at->deductionCapExceeded);
        $this->assertTrue($over->deductionCapExceeded);

        // EPF/SOCSO/EIS/PCB are statutory and never count toward the cap.
        $statutoryOnly = $this->calc->compute(['basic' => 1000, 'pcb' => 600]);
        $this->assertFalse($statutoryOnly->deductionCapExceeded);
    }

    public function test_carry_forward_zeroes_a_negative_net_and_records_the_shortfall(): void
    {
        $neg = $this->calc->compute(['basic' => 1000, 'fixed_deductions_total' => 1500]);
        $this->assertLessThan(0, $neg->netPay);

        $c = $this->calc->compute(['basic' => 1000, 'fixed_deductions_total' => 1500, 'carry_forward' => true]);
        $this->assertSame(0.0, $c->netPay);
        $this->assertSame(abs($neg->netPay), $c->carriedForward);
        $this->assertSame($neg->totalDeductions - $c->carriedForward, $c->totalDeductions);
    }
```

- [ ] **Step 2: Run, expect failure** (`php artisan test --compact tests/Unit/PayrollCalculatorTest.php`).

- [ ] **Step 3: Calculator**

Add constants after `OVERTIME_MULTIPLIER`:

```php
    /** EA s.24: total non-statutory deductions in a month may not exceed half of wages. */
    public const DEDUCTION_CAP = 0.5;

    /** Employment (Limitation of Overtime Work) Regulations 1980: 104 hours a month. */
    public const OVERTIME_HOURS_CAP = 104;
```

Add `carry_forward?: bool,` to the `@param` shape. After `$totalDeductions` / `$netPay` are computed, replace those two lines with:

```php
        $totalDeductions = round($epfEmployee + $socsoEmployee + $eisEmployee + $skbbkEmployee + $pcbEffective + $pcbAdditional + $zakat + $cp38 + $otherDeductionsTotal + $fixedDeductionsTotal + $individualDeductionsTotal, 2);

        // EA s.24 cap (spec F4): only non-statutory deductions count — zakat, CP38, loans,
        // advances, one-offs. EPF/SOCSO/EIS/SKBBK/PCB are the law's own deductions.
        $nonStatutory = round($zakat + $cp38 + $otherDeductionsTotal + $fixedDeductionsTotal + $individualDeductionsTotal, 2);
        $deductionCapExceeded = $gross > 0 && $nonStatutory > round($gross * self::DEDUCTION_CAP, 2);

        $netPay = round($statWage - $totalDeductions + $claimsReimbursement, 2);
        // Negative net is not a payable figure. With carry_forward HR has chosen Worksy's
        // "deduct next month": cap this month's deductions so net lands on zero and hand
        // the shortfall to the caller, who books it as next period's Individual Transaction.
        $carriedForward = 0.0;
        if (! empty($inputs['carry_forward']) && $netPay < 0) {
            $carriedForward = round(-$netPay, 2);
            $totalDeductions = round($totalDeductions - $carriedForward, 2);
            $netPay = 0.0;
        }
```

Pass `deductionCapExceeded: $deductionCapExceeded, carriedForward: $carriedForward,` into the constructor. In `PayslipComputation` add `public bool $deductionCapExceeded = false, public float $carriedForward = 0.0,` as the last two constructor params and add to `toPayslipAttributes()`:

```php
            'deduction_cap_exceeded' => $this->deductionCapExceeded,
            'carried_forward_amount' => $this->carriedForward,
```

- [ ] **Step 4: Run unit tests, expect pass.**

- [ ] **Step 5: Feature test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\FixedTransaction;
use App\Models\IndividualTransaction;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\SalaryStructure;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PayrollDeductionGuardsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Employee $emp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC',
            'employer_tin' => '1234567890', 'epf_employer_no' => '12345678', 'socso_employer_code' => 'A123']);
        PayrollItem::seedFor($this->tenant);
        $hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);
        $this->emp = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Worker', 'staff_id' => 'AC-1', 'status' => 'active', 'workload' => 'green',
            'nric' => '880101-14-5500', 'date_of_birth' => '1988-01-01', 'joined_at' => '2020-01-01', 'salary' => 2000]);
        SalaryStructure::forceCreate(['tenant_id' => $this->tenant->id, 'employee_id' => $this->emp->id, 'basic_salary' => 2000,
            'bank_name' => 'Maybank', 'bank_code' => 'MBBEMYKL', 'bank_account_no' => '1', 'epf_no' => '1', 'socso_no' => '1', 'tax_no' => 'SG1']);
        $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id]);
    }

    private function loan(float $amount): void
    {
        $item = PayrollItem::where('tenant_id', $this->tenant->id)->where('code', 'staff-loan')->firstOrFail();
        FixedTransaction::forceCreate(['tenant_id' => $this->tenant->id, 'employee_id' => $this->emp->id, 'payroll_item_id' => $item->id,
            'amount' => $amount, 'start_period' => '2026-01', 'prorate' => false]);
    }

    private function run(): PayrollRun
    {
        $this->post(route('payroll.runs.create'), ['period' => '2026-06', 'payment_date' => '2026-06-30']);

        return PayrollRun::firstOrFail();
    }

    public function test_cap_exceeded_blocks_finalize_until_consent_is_confirmed(): void
    {
        $this->loan(1100);   // 55% of 2,000
        $run = $this->run();
        $p = Payslip::firstOrFail();
        $this->assertTrue($p->deduction_cap_exceeded);

        $this->post(route('payroll.runs.finalize', $run))->assertStatus(422);

        $this->post(route('payroll.payslips.consent', $p))->assertSessionHasNoErrors();
        $this->assertTrue($p->fresh()->deduction_consent_confirmed);
        $this->assertDatabaseHas('audit_logs', ['action' => 'Confirmed deduction consent']);

        $this->post(route('payroll.runs.finalize', $run))->assertSessionHasNoErrors();
        $this->assertSame('finalized', $run->fresh()->status);
    }

    public function test_negative_net_blocks_finalize_and_carry_forward_books_next_month(): void
    {
        $this->loan(2500);
        $run = $this->run();
        $p = Payslip::firstOrFail();
        $this->assertLessThan(0, $p->net_pay);
        $shortfall = abs($p->net_pay);

        $this->post(route('payroll.runs.finalize', $run))->assertStatus(422);

        $this->post(route('payroll.payslips.carry-forward', $p))->assertSessionHasNoErrors();
        $p->refresh();
        $this->assertSame(0.0, $p->net_pay);
        $this->assertSame($shortfall, (float) $p->carried_forward_amount);

        $it = IndividualTransaction::where('employee_id', $this->emp->id)->where('period', '2026-07')->firstOrFail();
        $this->assertSame($shortfall, (float) $it->amount);
        $this->assertStringContainsString('June 2026', (string) $it->remarks);
    }
}
```

The cap test's consent step means the run also still exceeds the cap; consent lifts the block. Note the loan of 2,500 also trips the cap, so in the second test consent would also be needed to finalize; the test stops at carry forward on purpose.

- [ ] **Step 6: Migration + model**

```php
        Schema::table('payslips', function (Blueprint $table) {
            $table->boolean('deduction_cap_exceeded')->default(false)->after('total_deductions');
            $table->boolean('deduction_consent_confirmed')->default(false)->after('deduction_cap_exceeded');
            $table->decimal('carried_forward_amount', 12, 2)->default(0)->after('deduction_consent_confirmed');
        });
```

(down drops the three). `Payslip` casts: `'deduction_cap_exceeded' => 'boolean', 'deduction_consent_confirmed' => 'boolean', 'carried_forward_amount' => 'float'`.

- [ ] **Step 7: Controller**

`createRun`: track `$overtimeWarnings = [];` outside the transaction (pass by reference into the closure with `use (&$overtimeWarnings)`); inside the loop after `$pulledOvertimeHours`: `if ($pulledOvertimeHours > PayrollCalculator::OVERTIME_HOURS_CAP) { $overtimeWarnings[] = $employee->name.' ('.$pulledOvertimeHours.'h)'; }`. Append to `$msg`: `if ($overtimeWarnings) { $msg .= ' Warning: overtime above the 104-hour monthly limit for '.implode(', ', $overtimeWarnings).'.'; }`.

`updatePayslip`: pass `'carry_forward' => $payslip->carried_forward_amount > 0` in `$baseInputs` so a recompute keeps the policy; after saving the recomputed attributes call `$this->syncCarriedForward($payslip, $comp);`.

New methods:

```php
    /** Spec F4: HR confirms the employee's written consent for deductions above the s.24 cap. */
    public function confirmDeductionConsent(Request $request, Payslip $payslip): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $this->assertTenant($payslip);
        abort_unless($payslip->payrollRun->isEditable(), 422, 'This payroll run is finalized and locked.');

        $payslip->forceFill(['deduction_consent_confirmed' => ! $payslip->deduction_consent_confirmed])->save();
        AuditLog::record($payslip->deduction_consent_confirmed ? 'Confirmed deduction consent' : 'Withdrew deduction consent', $payslip->employee->name.' · '.$payslip->payrollRun->label);

        return back()->with('ok', 'Consent '.($payslip->deduction_consent_confirmed ? 'recorded' : 'withdrawn').' for '.$payslip->employee->name.'.');
    }

    /**
     * Spec F4 "deduct next month": cap this month's deductions at gross, net becomes zero,
     * the shortfall is queued as next period's Individual Transaction (subject to the same
     * 50% cap there when that run is created).
     */
    public function carryForward(Request $request, Payslip $payslip): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $this->assertTenant($payslip);
        abort_unless($payslip->payrollRun->isEditable(), 422, 'This payroll run is finalized and locked.');
        abort_unless($payslip->net_pay < 0, 422, 'Net pay is not negative.');

        DB::transaction(function () use ($payslip) {
            $shortfall = round(-$payslip->net_pay, 2);
            $payslip->forceFill([
                'total_deductions' => round($payslip->total_deductions - $shortfall, 2),
                'net_pay' => 0.0,
                'carried_forward_amount' => $shortfall,
            ])->save();
            $this->syncCarriedForward($payslip, null);
            $this->recalcTotals($payslip->payrollRun);
            AuditLog::record('Carried shortfall to next month', $payslip->employee->name.' · '.$payslip->payrollRun->label.' · RM '.number_format($shortfall, 2));
        });

        return back()->with('ok', 'Shortfall carried to next month for '.$payslip->employee->name.'.');
    }

    /**
     * Keep next period's carried-forward Individual Transaction equal to the payslip's
     * carried_forward_amount: create, update or remove it. Tagged by remark so a recompute
     * finds its own row and never touches a one-off HR typed.
     */
    private function syncCarriedForward(Payslip $payslip, ?PayslipComputation $comp): void
    {
        $amount = $comp !== null ? $comp->carriedForward : (float) $payslip->carried_forward_amount;
        if ($comp !== null) {
            $payslip->forceFill(['carried_forward_amount' => $amount])->save();
        }
        $nextPeriod = $this->periodStart($payslip->payrollRun->period)->addMonth()->format('Y-m');
        $remark = 'Carried forward from '.$payslip->payrollRun->label;
        $existing = IndividualTransaction::where('employee_id', $payslip->employee_id)->forPeriod($nextPeriod)->where('remarks', $remark)->first();

        if ($amount <= 0) {
            $existing?->delete();

            return;
        }
        $item = PayrollItem::where('tenant_id', $payslip->tenant_id)->where('code', 'other-deduction')->firstOrFail();
        if ($existing) {
            $existing->update(['amount' => $amount]);
        } else {
            IndividualTransaction::create(['employee_id' => $payslip->employee_id, 'payroll_item_id' => $item->id, 'period' => $nextPeriod, 'amount' => $amount, 'remarks' => $remark, 'created_by_id' => Auth::id()]);
        }
    }
```

`finalizeRun`: after the four-eyes check, before the transaction:

```php
        // Spec F4 guards: a payslip over the s.24 deduction cap needs recorded consent, and
        // a negative net is not payable until HR carries the shortfall forward.
        $slips = $run->payslips()->with('employee')->get();
        $unconsented = $slips->filter(fn (Payslip $p) => $p->deduction_cap_exceeded && ! $p->deduction_consent_confirmed);
        abort_if($unconsented->isNotEmpty(), 422, 'Deductions exceed 50% of wages (EA s.24) without recorded consent for: '.$unconsented->map(fn (Payslip $p) => $p->employee->name)->implode(', ').'.');
        $negative = $slips->filter(fn (Payslip $p) => $p->net_pay < 0);
        abort_if($negative->isNotEmpty(), 422, 'Net pay is negative for: '.$negative->map(fn (Payslip $p) => $p->employee->name)->implode(', ').'. Carry the shortfall to next month first.');
```

Routes, inside the payroll throttle group:

```php
            Route::post('/app/payroll/payslips/{payslip}/consent', [PayrollController::class, 'confirmDeductionConsent'])->name('payroll.payslips.consent');
            Route::post('/app/payroll/payslips/{payslip}/carry-forward', [PayrollController::class, 'carryForward'])->name('payroll.payslips.carry-forward');
```

- [ ] **Step 8: Review view**

In `review/individual.blade.php` per payslip row header (near the Gross/Net figures), add:

```blade
@if ($p->deduction_cap_exceeded)
    <form method="post" action="{{ route('payroll.payslips.consent', $p) }}" style="display:inline;">@csrf
        <span class="uj-pill" style="background:{{ $p->deduction_consent_confirmed ? 'var(--red-tint)' : '#fff7e6' }};color:{{ $p->deduction_consent_confirmed ? 'var(--success)' : 'var(--amber)' }};" x-text="$store.ui.lang==='en' ? 'Deductions over 50% (s.24)' : 'Potongan melebihi 50% (s.24)'">Deductions over 50% (s.24)</span>
        @if ($activeRun->isEditable())<button type="submit" class="uj-btn-ghost" style="height:26px;padding:0 8px;font-size:11px;" x-text="$store.ui.lang==='en' ? ({{ $p->deduction_consent_confirmed ? 'true' : 'false' }} ? 'Consent recorded · withdraw' : 'Employee consented in writing') : ({{ $p->deduction_consent_confirmed ? 'true' : 'false' }} ? 'Kebenaran direkod · tarik balik' : 'Pekerja beri kebenaran bertulis')">Employee consented in writing</button>@endif
    </form>
@endif
@if ($p->net_pay < 0 && $activeRun->isEditable())
    <form method="post" action="{{ route('payroll.payslips.carry-forward', $p) }}" style="display:inline;">@csrf
        <span class="uj-pill" style="background:var(--red-tint);color:var(--error);" x-text="$store.ui.lang==='en' ? 'Net pay negative' : 'Gaji bersih negatif'">Net pay negative</span>
        <button type="submit" class="uj-btn-ghost" style="height:26px;padding:0 8px;font-size:11px;" x-text="$store.ui.lang==='en' ? 'Carry to next month' : 'Bawa ke bulan depan'">Carry to next month</button>
    </form>
@endif
@if ($p->carried_forward_amount > 0)
    <span class="uj-pill" style="background:#fff7e6;color:var(--amber);">RM {{ number_format($p->carried_forward_amount, 2) }} <span x-text="$store.ui.lang==='en' ? 'carried to next month' : 'dibawa ke bulan depan'">carried to next month</span></span>
@endif
@if ($p->pulled_overtime_hours > \App\Services\Payroll\PayrollCalculator::OVERTIME_HOURS_CAP)
    <span class="uj-pill" style="background:#fff7e6;color:var(--amber);" x-text="$store.ui.lang==='en' ? 'Overtime above 104h' : 'Kerja lebih masa melebihi 104j'">Overtime above 104h</span>
@endif
```

Use the variable the partial already uses for the run (`$activeRun`) and payslip (`$p`); check the loop variable names at the top of the file.

- [ ] **Step 9: Test, phpstan, pint, migrate, build, commit**

```bash
php artisan test --compact tests/Unit/PayrollCalculatorTest.php tests/Feature/PayrollDeductionGuardsTest.php tests/Feature/PayrollTest.php tests/Feature/PayrollTransactionsPullTest.php
vendor/bin/phpstan analyse --memory-limit=512M && vendor/bin/pint --dirty --format agent
lerd artisan migrate && lerd artisan view:clear && lerd artisan view:cache && bun run build
git add -A app database/migrations routes resources/views tests public/build
git commit -m "feat(payroll): s.24 deduction cap with consent, 104h overtime warning, negative net carried to next month, finalize guards"
```

---

### Task 8: F5 Pay date seven-day rule, Mark paid, HR dashboard card, payslip PDF date

**Files:**
- Create: `database/migrations/2026_10_01_100600_add_paid_at_to_payroll_runs_table.php`
- Modify: `app/Models/PayrollRun.php` (casts), `app/Http/Controllers/PayrollController.php` (`finalizeRun`, new `markPaid`), `routes/web.php`
- Modify: `resources/views/partials/payroll/payment/payout.blade.php` (pay date + override reason on the finalize form, Mark paid button)
- Modify: `resources/views/pdf/payslip.blade.php:53` (`$payDate` from `payment_date`)
- Modify: `app/Support/DashboardWidgets.php` (new `payroll` entry), `app/Http/Controllers/Concerns/BuildsDashboardWidgets.php` (payload), create `resources/views/partials/dash/widgets/payroll.blade.php`
- Test: `tests/Feature/PayrollPayDateTest.php`

**Interfaces:**
- Consumes: existing `payroll_runs.payment_date`.
- Produces: `payroll_runs.paid_at` datetime nullable, `pay_date_override_reason` string 240 nullable. `finalizeRun` accepts `payment_date` (required if blank on the run) and `pay_date_override_reason`. Route `payroll.runs.mark-paid` POST `/app/payroll/runs/{run}/mark-paid`. `PayrollRun::payByDate(): CarbonImmutable` (period end + 7 days). Dashboard widget id `payroll` (roles management/hr, screen `payroll-process`).

- [ ] **Step 1: Failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PayrollRun;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PayrollPayDateTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private PayrollRun $run;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);
        $this->run = PayrollRun::forceCreate(['tenant_id' => $this->tenant->id, 'period' => '2026-08', 'label' => 'August 2026', 'status' => 'draft']);
        $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id]);
    }

    public function test_pay_by_date_is_the_seventh_day_after_period_end(): void
    {
        $this->assertSame('2026-09-07', $this->run->payByDate()->toDateString());
    }

    public function test_finalize_refuses_the_eighth_day_and_accepts_the_seventh(): void
    {
        $this->post(route('payroll.runs.finalize', $this->run), ['payment_date' => '2026-09-08'])->assertSessionHasErrors('payment_date');
        $this->assertSame('draft', $this->run->fresh()->status);

        $this->post(route('payroll.runs.finalize', $this->run), ['payment_date' => '2026-09-07'])->assertSessionHasNoErrors();
        $this->assertSame('finalized', $this->run->fresh()->status);
        $this->assertSame('2026-09-07', $this->run->fresh()->payment_date->toDateString());
    }

    public function test_finalize_without_a_pay_date_is_refused(): void
    {
        $this->post(route('payroll.runs.finalize', $this->run), [])->assertSessionHasErrors('payment_date');
    }

    public function test_override_with_reason_is_allowed_and_audited(): void
    {
        $this->post(route('payroll.runs.finalize', $this->run), ['payment_date' => '2026-09-10', 'pay_date_override_reason' => 'Overtime portion paid with September wages'])
            ->assertSessionHasNoErrors();
        $r = $this->run->fresh();
        $this->assertSame('finalized', $r->status);
        $this->assertSame('Overtime portion paid with September wages', $r->pay_date_override_reason);
        $this->assertDatabaseHas('audit_logs', ['action' => 'Pay date later than seven days']);
    }

    public function test_mark_paid_sets_paid_at_on_a_finalized_run_only(): void
    {
        $this->post(route('payroll.runs.mark-paid', $this->run))->assertStatus(422);
        $this->run->forceFill(['status' => 'finalized', 'finalized_at' => now(), 'payment_date' => '2026-09-05'])->save();
        $this->post(route('payroll.runs.mark-paid', $this->run))->assertSessionHasNoErrors();
        $this->assertNotNull($this->run->fresh()->paid_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'Marked payroll paid']);
    }
}
```

The error message for the eighth day must quote the section: assert `assertStringContainsString('s.19', session('errors')->first('payment_date'))` in the second test.

- [ ] **Step 2: Run, expect failure.**

- [ ] **Step 3: Migration + model**

```php
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->timestamp('paid_at')->nullable()->after('finalized_at');
            $table->string('pay_date_override_reason', 240)->nullable()->after('payment_date');
        });
```

`PayrollRun`: casts `'paid_at' => 'datetime'`; `pay_date_override_reason` stays out of `$fillable` (set via forceFill in finalize). Add:

```php
    /** EA s.19: wages are due no later than the seventh day after the wage period ends. */
    public function payByDate(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m', $this->period)->endOfMonth()->startOfDay()->addDays(7);
    }
```

with `use Carbon\CarbonImmutable;`.

- [ ] **Step 4: Controller**

`finalizeRun`, right after the two `authorize`/`assertTenant` lines:

```php
        // Spec F5: a pay date is required at finalize and must be within seven days of the
        // period end (EA s.19) unless HR gives a reason, which is audited.
        $data = $request->validate([
            'payment_date' => [$run->payment_date ? 'nullable' : 'required', 'date'],
            'pay_date_override_reason' => ['nullable', 'string', 'max:240'],
        ], ['payment_date.required' => 'Set the pay date before finalizing (EA s.19: wages are due within seven days of the period end).']);
        $payDate = isset($data['payment_date']) ? CarbonImmutable::parse($data['payment_date']) : CarbonImmutable::instance($run->payment_date);
        $late = $payDate->gt($run->payByDate());
        if ($late && blank($data['pay_date_override_reason'] ?? null)) {
            return back()->withErrors(['payment_date' => 'Pay date '.$payDate->format('j M Y').' is later than the seventh day after the period end ('.$run->payByDate()->format('j M Y').'). EA s.19 requires payment within seven days; give a reason to override.'])->withInput();
        }
```

Inside the transaction's `forceFill` add `'payment_date' => $payDate->toDateString(), 'pay_date_override_reason' => $late ? $data['pay_date_override_reason'] : null,` and after it:

```php
            if ($late) {
                AuditLog::record('Pay date later than seven days', $run->label.' · '.$payDate->format('j M Y').' · '.$data['pay_date_override_reason']);
            }
```

New action:

```php
    /** Spec F5: the one field that changes after finalize, set only here. */
    public function markPaid(Request $request, PayrollRun $run): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $this->assertTenant($run);
        abort_unless($run->status === 'finalized', 422, 'Only a finalized run can be marked paid.');
        abort_if($run->paid_at !== null, 422, 'Already marked paid.');

        $run->forceFill(['paid_at' => now()])->save();
        AuditLog::record('Marked payroll paid', $run->label);

        return back()->with('ok', $run->label.' marked paid.');
    }
```

Route in the throttle group: `Route::post('/app/payroll/runs/{run}/mark-paid', [PayrollController::class, 'markPaid'])->name('payroll.runs.mark-paid');`. Import `Carbon\CarbonImmutable`.

- [ ] **Step 5: Payout view + PDF**

`payout.blade.php` finalize form (line ~25): add a date input `payment_date` (value `old('payment_date', $r->payment_date?->toDateString() ?? $r->payByDate()->subDays(7)->toDateString())`, i.e. defaults to the last day of the period), a text input `pay_date_override_reason` wrapped in `<div x-show="late" x-cloak>` where the form has `x-data="{ late: false }"` and the date input `@change="late = $event.target.value > '{{ $r->payByDate()->toDateString() }}'"`; label EN "Reason for paying after the seventh day (EA s.19)" / MS "Sebab bayar selepas hari ketujuh (AK s.19)". Show `@error('payment_date')`. After the Finalized pill, when `$r->status === 'finalized'`: if `$r->paid_at` show "Paid {{ $r->paid_at->format('j M Y') }}", else a small form to `route('payroll.runs.mark-paid', $r)` with button EN "Mark paid" / MS "Tanda dibayar", plus the "pay by" date in muted text, red if `now()->gt($r->payByDate())`.

`pdf/payslip.blade.php:53`: `$payDate = ($run?->payment_date ?? $run?->finalized_at)?->format('d/m/Y') ?? now()->format('d/m/Y');`

- [ ] **Step 6: Dashboard card**

`DashboardWidgets::ALL` add after `'stuck'`:

```php
        'payroll' => [
            'title' => 'Payroll this month', 'title_ms' => 'Gaji bulan ini',
            'blurb' => 'Latest run: finalized, pay-by date and whether it is marked paid.',
            'blurb_ms' => 'Run terkini: dimuktamadkan, tarikh bayar dan sama ada sudah ditanda dibayar.',
            'category' => 'HR', 'roles' => ['management', 'hr'], 'screen' => 'payroll-process', 'column' => 'right',
        ],
```

(Match `category`/`roles` values to what neighbouring entries use for HR-only cards; `grep -n "'roles' => \[" app/Support/DashboardWidgets.php`.)

`BuildsDashboardWidgets` payload match: `'payroll' => $this->payrollWidget(),` and:

```php
    /** @return array{run: ?PayrollRun, payBy: ?string, late: bool} */
    private function payrollWidget(): array
    {
        $run = PayrollRun::orderByDesc('period')->first();

        return [
            'run' => $run,
            'payBy' => $run?->payByDate()->format('j M Y'),
            'late' => $run !== null && $run->status === 'finalized' && $run->paid_at === null && now()->gt($run->payByDate()),
        ];
    }
```

`partials/dash/widgets/payroll.blade.php`:

```blade
@php $run = $w['run'] ?? null; @endphp
<div class="uj-dw-body">
    @if (! $run)
        <p class="uj-dw-empty" x-text="$store.ui.lang==='en' ? 'No payroll run yet.' : 'Belum ada run gaji.'">No payroll run yet.</p>
    @else
        <div style="font-size:13.5px;font-weight:600;color:var(--ink);">{{ $run->label }}</div>
        <div style="font-size:12.5px;color:{{ $w['late'] ? 'var(--error)' : 'var(--body)' }};margin-top:4px;">
            @if ($run->status !== 'finalized')
                <span x-text="$store.ui.lang==='en' ? 'Draft, not finalized.' : 'Draf, belum dimuktamadkan.'">Draft, not finalized.</span>
            @else
                <span x-text="$store.ui.lang==='en' ? 'Finalized' : 'Dimuktamadkan'">Finalized</span> {{ $run->finalized_at?->format('j M') }} ·
                <span x-text="$store.ui.lang==='en' ? 'pay by' : 'bayar sebelum'">pay by</span> {{ $w['payBy'] }} ·
                @if ($run->paid_at)
                    <span x-text="$store.ui.lang==='en' ? 'paid' : 'dibayar'">paid</span> {{ $run->paid_at->format('j M') }}
                @else
                    <span x-text="$store.ui.lang==='en' ? 'not yet marked paid' : 'belum ditanda dibayar'">not yet marked paid</span>
                @endif
            @endif
        </div>
    @endif
</div>
<div class="uj-dw-foot"><a href="{{ route('app.screen', ['screen' => 'payroll-payment', 'tab' => 'payout']) }}" x-text="$store.ui.lang==='en' ? 'Open payroll' : 'Buka gaji'">Open payroll</a></div>
```

Match the `uj-dw-foot` link markup used by `leave.blade.php`'s footer. Read `docs/build/contracts/dashboard-slots.md` first: if it forbids new cards outside listed slots, register this card in the slot it names for HR payroll; if no slot fits, place it `'after' => 'stuck'` and note it in the recap.

- [ ] **Step 7: Test, phpstan, pint, migrate, build, commit**

```bash
php artisan test --compact tests/Feature/PayrollPayDateTest.php tests/Feature/PayrollTest.php tests/Feature/PayrollDeductionGuardsTest.php tests/Feature/PayslipPdfTest.php tests/Feature/DashboardWidgetsTest.php
vendor/bin/phpstan analyse --memory-limit=512M && vendor/bin/pint --dirty --format agent
lerd artisan migrate && lerd artisan view:clear && lerd artisan view:cache && bun run build
git add -A app database/migrations routes resources/views tests public/build
git commit -m "feat(payroll): pay date required at finalize with the seven-day rule, Mark paid, HR dashboard card, pay date on payslip PDF"
```

Existing finalize tests now need `payment_date` in the POST body (or set on the run); update them in this commit.

---
### Task 9: F7 HRD Corp levy in the calculation

**Files:**
- Create: `database/migrations/2026_10_01_100700_add_hrdf_columns.php`
- Create: `app/Services/Payroll/HrdCorpLevy.php`
- Modify: `app/Support/Features.php` (SETTINGS entry after `payroll.four_eyes`)
- Modify: `app/Models/PayrollItem.php` (fillable, seedFor), `app/Models/Payslip.php` (casts)
- Modify: `app/Services/Payroll/PayrollCalculator.php`, `PayslipComputation.php`
- Modify: `app/Http/Controllers/PayrollController.php` (`withWageBaseFlags`, `createRun`, `updatePayslip`, `updateItem`)
- Modify: `resources/views/partials/payroll/transaction/items.blade.php` (flag checkbox)
- Test: `tests/Unit/PayrollCalculatorTest.php` (append), `tests/Feature/PayrollHrdfLevyTest.php`

**Interfaces:**
- Produces: setting `payroll.hrdf` enum `off` / `1` / `0.5` (default `off`); `HrdCorpLevy::RATES = ['off' => 0.0, '1' => 0.01, '0.5' => 0.005]`, `HrdCorpLevy::EFFECTIVE`, `HrdCorpLevy::rate(string $setting): float`; `payroll_items.hrdf_liable` bool; `payslips.hrdf_levy` decimal(12,2); calculator input `hrdf_rate: float` (default 0) and per-line flag `hrdf_liable`; `PayslipComputation::$hrdfLevy` float, emitted as `hrdf_levy` and included in `employer_cost`.

- [ ] **Step 1: Unit tests (append to PayrollCalculatorTest)**

```php
    public function test_hrdf_levy_is_one_percent_of_liable_wages_after_unpaid_leave_and_is_employer_cost_only(): void
    {
        $inputs = [
            'basic' => 2600, 'allowances_total' => 400, 'bonus' => 1000, 'unpaid_days' => 1, 'hrdf_rate' => 0.01,
            'lines' => [
                ['amount' => 2600, 'epf_liable' => true, 'perkeso_liable' => true, 'hrdf_liable' => true],
                ['amount' => 400, 'epf_liable' => true, 'perkeso_liable' => true, 'hrdf_liable' => true],
                ['amount' => 1000, 'epf_liable' => true, 'perkeso_liable' => false, 'hrdf_liable' => false],
            ],
            'overtime_flags' => ['epf_liable' => false, 'perkeso_liable' => true, 'hrdf_liable' => false],
        ];
        $c = $this->calc->compute($inputs);
        // Base: 2,600 + 400 − one unpaid day (2,600 / 26 = 100) = 2,900; 1% = 29.00.
        $this->assertSame(29.00, $c->hrdfLevy);

        $without = $this->calc->compute(['hrdf_rate' => 0.0] + $inputs);
        $this->assertSame(0.0, $without->hrdfLevy);
        $this->assertSame(round($without->employerCost + 29.00, 2), $c->employerCost);
        $this->assertSame($without->netPay, $c->netPay);
        $this->assertSame($without->totalDeductions, $c->totalDeductions);
    }

    // With no catalogue `lines`, the base falls back to basic + allowances_total - unpaid deduction.
    public function test_hrdf_levy_without_catalogue_lines_uses_basic_plus_allowances(): void
    {
        $c = $this->calc->compute(['basic' => 3000, 'allowances_total' => 200, 'hrdf_rate' => 0.005]);
        $this->assertSame(16.00, $c->hrdfLevy);
    }
```

- [ ] **Step 2: Run, expect failure.**

- [ ] **Step 3: HrdCorpLevy + calculator**

```php
<?php

declare(strict_types=1);

namespace App\Services\Payroll;

/**
 * HRD Corp levy, Pembangunan Sumber Manusia Berhad Act 2001: employers with 10 or more
 * Malaysian employees pay 1% of monthly wages; 5 to 9 may register voluntarily at 0.5%.
 * Wages here are basic salary plus fixed allowances after unpaid leave. It is the
 * employer's cost only. Rates are statutory, not tenant-editable; the tenant only chooses
 * which registration applies (Features 'payroll.hrdf').
 */
final class HrdCorpLevy
{
    public const EFFECTIVE = '2021-03-01';

    /** @var array<string, float> */
    public const RATES = ['off' => 0.0, '1' => 0.01, '0.5' => 0.005];

    public static function rate(?string $setting): float
    {
        return self::RATES[$setting ?? 'off'] ?? 0.0;
    }
}
```

Calculator: add `hrdf_rate?: float|int|string,` to the shape and `hrdf_liable?: bool` to the `lines` / `overtime_flags` / `individual_earning_lines` shapes. Inside the `if ($catalogueLines !== null && $overtimeFlags !== null)` branch add `$hrdfBase = ! empty($overtimeFlags['hrdf_liable']) ? $overtimeAmount : 0.0;` and in the loop `if (! empty($line['hrdf_liable'])) { $hrdfBase += $lineAmount; }`; in the `else` branch `$hrdfBase = $basic + $allowancesTotal;`. After the EIS block:

```php
        // HRD Corp levy (spec F7): a third wage base, employer side only. Unpaid leave
        // reduces it exactly as it reduces the EPF base.
        $hrdfRate = max(0.0, (float) ($inputs['hrdf_rate'] ?? 0));
        $hrdfLevy = $hrdfRate > 0 ? round(max(0.0, $hrdfBase - $unpaidDeduction) * $hrdfRate, 2) : 0.0;
```

Change employer cost to `round($statWage + $epfEmployer + $socsoEmployer + $eisEmployer + $hrdfLevy, 2)` and pass `hrdfLevy: $hrdfLevy` to the DTO. In `PayslipComputation` add `public float $hrdfLevy = 0.0,` (after `carriedForward`) and `'hrdf_levy' => $this->hrdfLevy,` to the attributes.

- [ ] **Step 4: Run unit tests, expect pass.**

- [ ] **Step 5: Feature test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PayrollItem;
use App\Models\Payslip;
use App\Models\SalaryStructure;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FeatureManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PayrollHrdfLevyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC',
            'employer_tin' => '1234567890', 'epf_employer_no' => '12345678', 'socso_employer_code' => 'A123', 'hrdf_registration_no' => 'H-1']);
        PayrollItem::seedFor($this->tenant);
        app(FeatureManager::class)->setTenant($this->tenant, 'payroll.hrdf', '1');
        $hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);
        $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id]);
    }

    private function employee(string $name, array $structure = []): Employee
    {
        $emp = Employee::create(['tenant_id' => $this->tenant->id, 'name' => $name, 'staff_id' => 'AC-'.$name, 'status' => 'active', 'workload' => 'green',
            'nric' => '880101-14-5500', 'date_of_birth' => '1988-01-01', 'joined_at' => '2020-01-01', 'salary' => 3000]);
        SalaryStructure::forceCreate(array_merge(['tenant_id' => $this->tenant->id, 'employee_id' => $emp->id, 'basic_salary' => 3000, 'nationality' => 'citizen',
            'bank_name' => 'Maybank', 'bank_code' => 'MBBEMYKL', 'bank_account_no' => '1', 'epf_no' => '1', 'socso_no' => '1', 'tax_no' => 'SG1'], $structure));

        return $emp;
    }

    public function test_citizen_gets_one_percent_foreigner_and_exempt_get_zero(): void
    {
        $citizen = $this->employee('Citizen');
        $foreign = $this->employee('Foreign', ['nationality' => 'foreign']);
        $exempt = $this->employee('Exempt', ['hrdf_exempt' => true]);

        $this->post(route('payroll.runs.create'), ['period' => '2026-06'])->assertSessionHasNoErrors();

        $this->assertSame(30.00, (float) Payslip::where('employee_id', $citizen->id)->value('hrdf_levy'));
        $this->assertSame(0.0, (float) Payslip::where('employee_id', $foreign->id)->value('hrdf_levy'));
        $this->assertSame(0.0, (float) Payslip::where('employee_id', $exempt->id)->value('hrdf_levy'));
    }

    public function test_setting_off_means_no_levy(): void
    {
        app(FeatureManager::class)->setTenant($this->tenant, 'payroll.hrdf', 'off');
        $this->employee('Citizen');
        $this->post(route('payroll.runs.create'), ['period' => '2026-06']);
        $this->assertSame(0.0, (float) Payslip::firstOrFail()->hrdf_levy);
    }

    public function test_levy_on_requires_the_registration_number(): void
    {
        $this->tenant->update(['hrdf_registration_no' => null]);
        $this->employee('Citizen');
        $this->post(route('payroll.runs.create'), ['period' => '2026-06'])->assertSessionHasErrors('readiness');
    }
}
```

- [ ] **Step 6: Migration, registry, models, controller**

Migration:

```php
        Schema::table('payroll_items', fn (Blueprint $t) => $t->boolean('hrdf_liable')->default(false)->after('prorate_on_incomplete_month'));
        DB::table('payroll_items')->whereIn('code', ['basic-salary', 'fixed-allowance'])->update(['hrdf_liable' => true]);
        Schema::table('payslips', fn (Blueprint $t) => $t->decimal('hrdf_levy', 12, 2)->default(0)->after('eis_employer'));
```

`Features::SETTINGS`, after `payroll.four_eyes`:

```php
        'payroll.hrdf' => [
            'label' => 'HRD Corp levy',
            'label_ms' => 'Levi HRD Corp',
            'type' => 'enum', 'scope' => 'tenant', 'default' => 'off',
            'options' => ['off' => 'Not registered', '1' => '1% (10 or more Malaysian employees)', '0.5' => '0.5% (5 to 9, voluntary)'],
            'options_ms' => ['off' => 'Tidak berdaftar', '1' => '1% (10 atau lebih pekerja warganegara)', '0.5' => '0.5% (5 hingga 9, sukarela)'],
            'help' => 'Employer-side levy on basic pay plus fixed allowances for Malaysian employees. Nothing is deducted from staff. Needs the HRD Corp registration number in Settings.',
            'help_ms' => 'Levi majikan atas gaji pokok dan elaun tetap pekerja warganegara. Tiada potongan daripada staf. Perlukan nombor pendaftaran HRD Corp di Tetapan.',
        ],
```

`PayrollItem`: fillable + seedFor sets `'hrdf_liable' => in_array($code, ['basic-salary', 'fixed-allowance'], true)`. `Payslip` cast `'hrdf_levy' => 'float'`.

`withWageBaseFlags`: make `$flagsFor` also return `'hrdf_liable' => $item ? (bool) $item->hrdf_liable : in_array($code, ['basic-salary', 'fixed-allowance'], true)`. In `createRun` (and the matching arrays in `updatePayslip`) add `'hrdf_liable' => (bool) $l['item']->hrdf_liable,` to `fixed_earning_lines` and `individual_earning_lines`, and add to `$inputs`:

```php
                    // Spec F7: citizens only, and not when HR has marked the employee exempt.
                    'hrdf_rate' => (($structure->nationality ?? 'citizen') === 'citizen' && ! $structure->hrdf_exempt) ? $hrdfRate : 0.0,
```

with `$hrdfRate = HrdCorpLevy::rate((string) app(FeatureManager::class)->value(app(CurrentTenant::class)->get(), 'payroll.hrdf'));` computed once before the loop (and once in `updatePayslip`). `updateItem`: validate + write `hrdf_liable`. Items view: checkbox column "HRD Corp" mirroring `epf_liable`.

- [ ] **Step 7: Test, phpstan, pint, migrate, build, commit**

```bash
php artisan test --compact tests/Unit/PayrollCalculatorTest.php tests/Feature/PayrollHrdfLevyTest.php tests/Feature/PayrollTest.php tests/Feature/PayrollReadinessTest.php
vendor/bin/phpstan analyse --memory-limit=512M && vendor/bin/pint --dirty --format agent
lerd artisan migrate && lerd artisan view:clear && lerd artisan view:cache && bun run build
git add -A app database/migrations resources/views tests public/build
git commit -m "feat(payroll): HRD Corp levy as an employer cost, per-item liability flag, citizens only"
```

---

### Task 10: F6 LHDN CP39 exporter

**Files:**
- Create: `app/Services/Payroll/Statutory/StatutoryFile.php` (abstract base), `app/Services/Payroll/Statutory/LhdnCp39.php`
- Create: `tests/Fixtures/statutory/cp39-2026-06.txt`
- Test: `tests/Feature/StatutoryCp39Test.php`

**Interfaces:**
- Produces:

```php
abstract class StatutoryFile
{
    abstract public function key(): string;                       // 'cp39' | 'kwsp-form-a' | 'perkeso-8a' | 'hrdcorp'
    abstract public function label(): string;
    abstract public function verified(): bool;                    // layout matches an official document in docs/statutory
    abstract public function filename(PayrollRun $run, Tenant $tenant): string;
    abstract public function contentType(): string;
    /** @param Collection<int, Payslip> $payslips  (employee.salaryStructure eager loaded) */
    abstract public function build(PayrollRun $run, Tenant $tenant, Collection $payslips): string;
    protected function cents(float|int|string $v, int $width): string;   // 120.5 → '0000012050' at width 10
    protected function ascii(string $s): string;                  // upper-case ASCII, non-ASCII stripped
    protected function digits(?string $s): string;                // keep 0-9 only
}
```

Layout source: Exhibit 4, `docs/statutory/spesifikasi-kaedah-pengiraan-berkomputer-pcb-2026.pdf` p.42. Header 57 chars: `H`(1) HQ employer no(10, zero-left) employer no(10) year(4) month(2) total MTD(10, cents) MTD records(5) total CP38(10) CP38 records(5). Detail 136 chars: `D`(1) TIN(11, digits, zero-left) name(60, space-right) old IC(12, blank) new IC(12) passport(12) country(2) MTD(8, cents) CP38(8, cents) employee no(10, space-right). Lines end `\r\n`. Filename `<employerNo 10 digits>MM_YYYY.txt`.

- [ ] **Step 1: Failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\SalaryStructure;
use App\Models\Tenant;
use App\Services\Payroll\Statutory\LhdnCp39;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatutoryCp39Test extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private PayrollRun $run;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC', 'employer_tin' => '9123456708']);
        $this->run = PayrollRun::forceCreate(['tenant_id' => $this->tenant->id, 'period' => '2026-06', 'label' => 'June 2026', 'status' => 'finalized', 'finalized_at' => now()]);
        $this->slip('Aminah binti Ali', 'AC-0001', '880101-14-5500', 'IG 531367080', 120.50, 15.00, 0);
        $this->slip('Tan Wei Ming', 'AC-0002', '900202-10-5511', null, 0, 0, 50.00);
    }

    private function slip(string $name, string $staffId, string $nric, ?string $tin, float $pcb, float $pcbAdd, float $cp38): void
    {
        $emp = Employee::create(['tenant_id' => $this->tenant->id, 'name' => $name, 'staff_id' => $staffId, 'status' => 'active', 'workload' => 'green', 'nric' => $nric]);
        SalaryStructure::forceCreate(['tenant_id' => $this->tenant->id, 'employee_id' => $emp->id, 'basic_salary' => 5000, 'tax_no' => $tin, 'nationality' => 'citizen']);
        Payslip::forceCreate(['tenant_id' => $this->tenant->id, 'payroll_run_id' => $this->run->id, 'employee_id' => $emp->id,
            'basic' => 5000, 'gross' => 5000, 'pcb' => $pcb, 'pcb_additional' => $pcbAdd, 'cp38' => $cp38, 'net_pay' => 4000]);
    }

    private function build(): string
    {
        $slips = $this->run->payslips()->with('employee.salaryStructure')->get()->sortBy(fn ($p) => $p->employee->name)->values();

        return (new LhdnCp39)->build($this->run, $this->tenant, $slips);
    }

    public function test_matches_the_golden_file(): void
    {
        $this->assertSame(file_get_contents(base_path('tests/Fixtures/statutory/cp39-2026-06.txt')), $this->build());
    }

    public function test_field_offsets_follow_exhibit_4(): void
    {
        [$h, $d1, $d2] = explode("\r\n", $this->build());
        $this->assertSame(57, strlen($h));
        $this->assertSame('H', $h[0]);
        $this->assertSame('9123456708', substr($h, 1, 10));
        $this->assertSame('9123456708', substr($h, 11, 10));
        $this->assertSame('2026', substr($h, 21, 4));
        $this->assertSame('06', substr($h, 25, 2));
        $this->assertSame('0000013550', substr($h, 27, 10));   // 120.50 + 15.00
        $this->assertSame('00001', substr($h, 37, 5));
        $this->assertSame('0000005000', substr($h, 42, 10));
        $this->assertSame('00001', substr($h, 52, 5));

        $this->assertSame(136, strlen($d1));
        $this->assertSame('D', $d1[0]);
        $this->assertSame('00531367080', substr($d1, 1, 11));
        $this->assertSame(str_pad('AMINAH BINTI ALI', 60), substr($d1, 12, 60));
        $this->assertSame(str_repeat(' ', 12), substr($d1, 72, 12));
        $this->assertSame('880101145500', substr($d1, 84, 12));
        $this->assertSame('MY', substr($d1, 108, 2));
        $this->assertSame('00013550', substr($d1, 110, 8));
        $this->assertSame('00000000', substr($d1, 118, 8));
        $this->assertSame(str_pad('AC-0001', 10), substr($d1, 126, 10));

        // No TIN: zeros in the TIN field, NRIC in the IC field.
        $this->assertSame('00000000000', substr($d2, 1, 11));
        $this->assertSame('900202105511', substr($d2, 84, 12));
        $this->assertSame('00005000', substr($d2, 118, 8));
    }

    public function test_filename_is_employer_number_month_year(): void
    {
        $this->assertSame('912345670806_2026.txt', (new LhdnCp39)->filename($this->run, $this->tenant));
    }

    public function test_employees_with_no_mtd_and_no_cp38_are_left_out(): void
    {
        $this->slip('Zero Tax', 'AC-0003', '950303-10-5522', 'SG1', 0, 0, 0);
        $this->assertCount(3, explode("\r\n", $this->build()));
    }
}
```

Create the golden file by writing the expected bytes by hand from the layout (not by running the exporter): header then the two detail lines joined with `\r\n`, no trailing newline. Use a short PHP one-off in the scratchpad to assemble the string from `str_pad` calls that mirror the layout table above, inspect it, then save it to `tests/Fixtures/statutory/cp39-2026-06.txt`.

- [ ] **Step 2: Run, expect failure.**

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Services\Payroll\Statutory;

use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Tenant;
use Illuminate\Support\Collection;

/**
 * An agency upload file built from a finalized run. `verified()` follows the BankFileFormat
 * convention: true only when the layout is transcribed from an official document kept in
 * docs/statutory; false means a structural approximation the UI and audit trail flag.
 */
abstract class StatutoryFile
{
    abstract public function key(): string;

    abstract public function label(): string;

    abstract public function verified(): bool;

    abstract public function filename(PayrollRun $run, Tenant $tenant): string;

    abstract public function contentType(): string;

    /** @param  Collection<int, Payslip>  $payslips */
    abstract public function build(PayrollRun $run, Tenant $tenant, Collection $payslips): string;

    /** Ringgit as zero-padded cents with no decimal point: 120.5 at width 10 is 0000012050. */
    protected function cents(float|int|string $value, int $width): string
    {
        return str_pad((string) (int) round(((float) $value) * 100), $width, '0', STR_PAD_LEFT);
    }

    protected function ascii(string $value): string
    {
        $plain = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return strtoupper(preg_replace('/[^\x20-\x7E]/', '', $plain === false ? $value : $plain) ?? '');
    }

    protected function digits(?string $value): string
    {
        return preg_replace('/\D/', '', (string) $value) ?? '';
    }

    protected function amount(float|int|string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Services\Payroll\Statutory;

use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Tenant;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * LHDN CP39 MTD text file. Layout: Exhibit 4 of the LHDN computerised-calculation spec,
 * docs/statutory/spesifikasi-kaedah-pengiraan-berkomputer-pcb-2026.pdf (header 57
 * characters, detail 136). MTD is pcb + pcb_additional; CP38 is its own column.
 */
final class LhdnCp39 extends StatutoryFile
{
    public const LAYOUT_EFFECTIVE = '2026-01-01';

    public function key(): string
    {
        return 'cp39';
    }

    public function label(): string
    {
        return 'LHDN CP39 (PCB)';
    }

    public function verified(): bool
    {
        return true;
    }

    public function contentType(): string
    {
        return 'text/plain';
    }

    public function filename(PayrollRun $run, Tenant $tenant): string
    {
        [$year, $month] = explode('-', $run->period);

        return $this->employerNo($tenant).$month.'_'.$year.'.txt';
    }

    public function build(PayrollRun $run, Tenant $tenant, Collection $payslips): string
    {
        [$year, $month] = explode('-', $run->period);
        $rows = $payslips->filter(fn (Payslip $p) => ((float) $p->pcb + (float) $p->pcb_additional + (float) $p->cp38) > 0)->values();

        $details = [];
        $mtdTotal = 0;
        $cp38Total = 0;
        $mtdCount = 0;
        $cp38Count = 0;
        foreach ($rows as $p) {
            $mtd = round((float) $p->pcb + (float) $p->pcb_additional, 2);
            $cp38 = round((float) $p->cp38, 2);
            $mtdTotal += (int) round($mtd * 100);
            $cp38Total += (int) round($cp38 * 100);
            $mtdCount += $mtd > 0 ? 1 : 0;
            $cp38Count += $cp38 > 0 ? 1 : 0;
            $s = $p->employee?->salaryStructure;
            $foreign = ($s?->nationality ?? 'citizen') === 'foreign';

            $details[] = 'D'
                .str_pad(substr($this->digits($s?->tax_no), -11), 11, '0', STR_PAD_LEFT)
                .str_pad(substr($this->ascii((string) $p->employee?->name), 0, 60), 60)
                .str_repeat(' ', 12)
                .str_pad($foreign ? '' : substr($this->digits($p->employee?->nric), 0, 12), 12)
                .str_repeat(' ', 12)   // ponytail: passport number has no column yet; add when foreign staff are on payroll
                .($foreign ? '  ' : 'MY')
                .$this->cents($mtd, 8)
                .$this->cents($cp38, 8)
                .str_pad(substr($this->ascii((string) $p->employee?->staff_id), 0, 10), 10);
        }

        $employerNo = $this->employerNo($tenant);
        $header = 'H'.$employerNo.$employerNo.$year.$month
            .str_pad((string) $mtdTotal, 10, '0', STR_PAD_LEFT).str_pad((string) $mtdCount, 5, '0', STR_PAD_LEFT)
            .str_pad((string) $cp38Total, 10, '0', STR_PAD_LEFT).str_pad((string) $cp38Count, 5, '0', STR_PAD_LEFT);

        // Refuse to produce a file that does not balance or is off-width.
        $sumMtd = array_sum(array_map(fn (string $d) => (int) substr($d, 110, 8), $details));
        $sumCp38 = array_sum(array_map(fn (string $d) => (int) substr($d, 118, 8), $details));
        if ($sumMtd !== $mtdTotal || $sumCp38 !== $cp38Total || strlen($header) !== 57 || array_filter($details, fn (string $d) => strlen($d) !== 136)) {
            throw new RuntimeException('CP39 file does not balance against its header.');
        }

        return implode("\r\n", [$header, ...$details]);
    }

    private function employerNo(Tenant $tenant): string
    {
        return str_pad(substr($this->digits($tenant->employer_tin), -10), 10, '0', STR_PAD_LEFT);
    }
}
```

- [ ] **Step 4: Test, phpstan, pint, commit**

```bash
php artisan test --compact tests/Feature/StatutoryCp39Test.php
vendor/bin/phpstan analyse --memory-limit=512M && vendor/bin/pint --dirty --format agent
git add app/Services/Payroll/Statutory tests/Feature/StatutoryCp39Test.php tests/Fixtures/statutory
git commit -m "feat(payroll): LHDN CP39 text file exporter per Exhibit 4, balanced header, golden file"
```

---

### Task 11: F6/F7 KWSP Form A, PERKESO Borang 8A and HRD Corp levy files

**Files:**
- Create: `app/Services/Payroll/Statutory/KwspFormA.php`, `PerkesoBorang8A.php`, `HrdCorpLevyFile.php`, `StatutoryFileRegistry.php`
- Create: `tests/Fixtures/statutory/kwsp-form-a-2026-06.csv`, `perkeso-8a-2026-06.txt`, `hrdcorp-2026-06.csv`
- Test: `tests/Feature/StatutoryAgencyFilesTest.php`

**Interfaces:**
- Consumes: `StatutoryFile` (Task 10), tenant columns (Task 1), `payslips.hrdf_levy` (Task 9).
- Produces: `StatutoryFileRegistry::all(): array<string, StatutoryFile>`, `::find(?string $key): ?StatutoryFile`. All three new exporters return `verified(): false` until the official layout document is added to `docs/statutory/`.

Layouts (ours, pinned by golden files):
- **KWSP Form A** CSV, contribution month = wage month + 1. Line 1 header record: `EMPLOYER NO,CONTRIBUTION MONTH,TOTAL EMPLOYER,TOTAL EMPLOYEE,RECORDS` then values line; line 3 column names `EPF NO,NRIC,NAME,WAGES,EMPLOYER SHARE,EMPLOYEE SHARE`; one row per payslip with `epf_employee + epf_employer > 0`. Wages = the payslip's EPF wage. There is no stored EPF wage column, so use `gross − overtime_amount` (the calculator's EPF rule) rounded to 2 dp. Month as `MMYYYY`. Amounts 2 dp, no thousands separator. Cells through `Csv::safeRow`.
- **PERKESO Borang 8A** fixed-width text, one line per employee with `socso_employee + socso_employer + eis_employee + eis_employer > 0`: employer code(12, space-right) · SOCSO no or NRIC digits(12, space-right) · NRIC digits(12) · name(45, ASCII upper, space-right) · contribution month `MMYYYY`(6) · wage capped at 6,000 in cents(8) · SOCSO employer cents(6) · SOCSO employee cents(6) · EIS employer cents(6) · EIS employee cents(6). Line length 119, `\r\n` joined. SKBBK is not in this layout; the download page says it is paid through the portal (spec F6 rule).
- **HRD Corp** CSV: header `EMPLOYER CODE,MONTH,NRIC,NAME,WAGES,LEVY`; one row per payslip with `hrdf_levy > 0`; final `TOTAL` row. Wages column = `round(hrdf_levy / rate, 2)` is fragile, so store nothing new: print basic + allowances_total − unpaid_deduction.

- [ ] **Step 1: Failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\SalaryStructure;
use App\Models\Tenant;
use App\Services\Payroll\Statutory\HrdCorpLevyFile;
use App\Services\Payroll\Statutory\KwspFormA;
use App\Services\Payroll\Statutory\PerkesoBorang8A;
use App\Services\Payroll\Statutory\StatutoryFileRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class StatutoryAgencyFilesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private PayrollRun $run;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC',
            'employer_tin' => '9123456708', 'epf_employer_no' => '012345678', 'socso_employer_code' => 'B3200012345Z', 'hrdf_registration_no' => 'HRD-7788']);
        $this->run = PayrollRun::forceCreate(['tenant_id' => $this->tenant->id, 'period' => '2026-06', 'label' => 'June 2026', 'status' => 'finalized', 'finalized_at' => now()]);
        $this->slip('Aminah binti Ali', '880101-14-5500', 'EPF11112222', '880101145500', 5000, 0, 550, 650, 24.75, 86.65, 9.90, 9.90, 50.00);
        $this->slip('Tan Wei Ming', '900202-10-5511', 'EPF33334444', '900202105511', 8000, 500, 880, 960, 29.75, 104.15, 11.90, 11.90, 80.00);
    }

    private function slip(string $name, string $nric, string $epfNo, string $socsoNo, float $gross, float $ot, float $epfEe, float $epfEr, float $socEe, float $socEr, float $eisEe, float $eisEr, float $hrdf): void
    {
        $emp = Employee::create(['tenant_id' => $this->tenant->id, 'name' => $name, 'staff_id' => 'AC-'.substr($nric, 0, 4), 'status' => 'active', 'workload' => 'green', 'nric' => $nric]);
        SalaryStructure::forceCreate(['tenant_id' => $this->tenant->id, 'employee_id' => $emp->id, 'basic_salary' => $gross - $ot, 'epf_no' => $epfNo, 'socso_no' => $socsoNo]);
        Payslip::forceCreate(['tenant_id' => $this->tenant->id, 'payroll_run_id' => $this->run->id, 'employee_id' => $emp->id,
            'basic' => $gross - $ot, 'overtime_amount' => $ot, 'gross' => $gross,
            'epf_employee' => $epfEe, 'epf_employer' => $epfEr, 'socso_employee' => $socEe, 'socso_employer' => $socEr,
            'eis_employee' => $eisEe, 'eis_employer' => $eisEr, 'hrdf_levy' => $hrdf, 'net_pay' => 1]);
    }

    /** @return Collection<int, Payslip> */
    private function slips(): Collection
    {
        return $this->run->payslips()->with('employee.salaryStructure')->get()->sortBy(fn ($p) => $p->employee->name)->values();
    }

    public function test_kwsp_form_a_matches_golden_and_uses_next_month(): void
    {
        $out = (new KwspFormA)->build($this->run, $this->tenant, $this->slips());
        $this->assertSame(file_get_contents(base_path('tests/Fixtures/statutory/kwsp-form-a-2026-06.csv')), $out);
        $this->assertStringContainsString('012345678,072026,1610.00,1430.00,2', $out);
        $this->assertStringContainsString('EPF33334444,900202105511,TAN WEI MING,7500.00,960.00,880.00', $out);   // wages exclude overtime
    }

    public function test_perkeso_8a_matches_golden_caps_wage_and_has_fixed_width(): void
    {
        $out = (new PerkesoBorang8A)->build($this->run, $this->tenant, $this->slips());
        $this->assertSame(file_get_contents(base_path('tests/Fixtures/statutory/perkeso-8a-2026-06.txt')), $out);
        foreach (explode("\r\n", $out) as $line) {
            $this->assertSame(119, strlen($line));
        }
        $tan = explode("\r\n", $out)[1];
        $this->assertSame('00600000', substr($tan, 87, 8));   // 8,000 capped at 6,000.00
    }

    public function test_hrd_corp_file_matches_golden_with_total(): void
    {
        $out = (new HrdCorpLevyFile)->build($this->run, $this->tenant, $this->slips());
        $this->assertSame(file_get_contents(base_path('tests/Fixtures/statutory/hrdcorp-2026-06.csv')), $out);
        $this->assertStringContainsString('TOTAL,,,,12500.00,130.00', $out);
    }

    public function test_registry_lists_all_four_and_flags_unverified_layouts(): void
    {
        $all = StatutoryFileRegistry::all();
        $this->assertSame(['kwsp-form-a', 'perkeso-8a', 'cp39', 'hrdcorp'], array_keys($all));
        $this->assertTrue($all['cp39']->verified());
        $this->assertFalse($all['kwsp-form-a']->verified());
        $this->assertNull(StatutoryFileRegistry::find('nope'));
    }
}
```

Hand-write the three golden files from the layout descriptions above (CSV lines end `\n` as `fputcsv` writes them; the 8A file uses `\r\n` with no trailing newline). Offsets for 8A: employer 0-11, SOCSO no 12-23, NRIC 24-35, name 36-80, month 81-86, wage 87-94, then four 6-wide amounts to 118.

- [ ] **Step 2: Run, expect failure.**

- [ ] **Step 3: Implement the three exporters**

Each extends `StatutoryFile`, mirrors `LhdnCp39`'s structure, `verified()` returns `false`, carries a class docblock stating: "Layout is ours, pinned by tests/Fixtures/statutory/<file>; not yet checked against the agency's published specification. Add the official document to docs/statutory and flip verified() once it matches." CSV exporters build their string with:

```php
    /** @param  array<int, array<int, string|int|float|null>>  $rows */
    protected function csv(array $rows): string
    {
        $out = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($out, Csv::safeRow($row));
        }
        rewind($out);

        return (string) stream_get_contents($out);
    }
```

(put `csv()` on `StatutoryFile`; import `App\Support\Csv`). Filenames: `KWSP-FormA-<epf_employer_no>-<MMYYYY>.csv`, `PERKESO-8A-<socso_employer_code>-<MMYYYY>.txt`, `HRDCorp-<hrdf_registration_no>-<MMYYYY>.csv`, where `MMYYYY` is the contribution month (wage month + 1) for KWSP and PERKESO and the wage month for HRD Corp. Contribution month helper on the base class:

```php
    protected function contributionMonth(PayrollRun $run): string
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $run->period.'-01')->addMonth()->format('mY');
    }
```

KwspFormA row: `[$s?->epf_no, $this->digits($emp->nric), $this->ascii($emp->name), $this->amount($p->gross - $p->overtime_amount), $this->amount($p->epf_employer), $this->amount($p->epf_employee)]`.
PerkesoBorang8A line: `str_pad(substr(code,0,12),12) . str_pad(substr($this->digits($s?->socso_no) ?: $this->digits($emp->nric),0,12),12) . str_pad(substr($this->digits($emp->nric),0,12),12) . str_pad(substr($this->ascii($emp->name),0,45),45) . $this->contributionMonth($run) . $this->cents(min(6000, (float) $p->gross), 8) . $this->cents($p->socso_employer,6) . $this->cents($p->socso_employee,6) . $this->cents($p->eis_employer,6) . $this->cents($p->eis_employee,6)`.
HrdCorpLevyFile row: `[$tenant->hrdf_registration_no, wageMonth 'mY', digits(nric), ascii(name), amount(basic + allowances_total − unpaid_deduction), amount(hrdf_levy)]`, then the TOTAL row.

Registry:

```php
<?php

declare(strict_types=1);

namespace App\Services\Payroll\Statutory;

final class StatutoryFileRegistry
{
    /** @return array<string, StatutoryFile> */
    public static function all(): array
    {
        $files = [new KwspFormA, new PerkesoBorang8A, new LhdnCp39, new HrdCorpLevyFile];

        return array_combine(array_map(fn (StatutoryFile $f) => $f->key(), $files), $files);
    }

    public static function find(?string $key): ?StatutoryFile
    {
        return self::all()[$key ?? ''] ?? null;
    }
}
```

- [ ] **Step 4: Test, phpstan, pint, commit**

```bash
php artisan test --compact tests/Feature/StatutoryAgencyFilesTest.php tests/Feature/StatutoryCp39Test.php
vendor/bin/phpstan analyse --memory-limit=512M && vendor/bin/pint --dirty --format agent
git add app/Services/Payroll/Statutory tests/Feature/StatutoryAgencyFilesTest.php tests/Fixtures/statutory
git commit -m "feat(payroll): KWSP Form A, PERKESO Borang 8A and HRD Corp levy files with golden fixtures (layouts flagged unverified)"
```

---

### Task 12: F6 download route, links on the finalized run, audit, docs

**Files:**
- Modify: `app/Http/Controllers/PayrollExportController.php` (new `statutoryFile`)
- Modify: `routes/web.php` (after line 800)
- Modify: `resources/views/partials/payroll/payment/submission.blade.php` (replace the disabled "Spec F6" buttons)
- Modify: `docs/statutory/README.md` (new section)
- Test: `tests/Feature/PayrollExportTest.php` (append)

**Interfaces:**
- Consumes: `StatutoryFileRegistry::find()`, `StatutoryFile::build/filename/contentType/label/verified`.
- Produces: route `payroll.export.statutory-file` GET `/app/payroll/runs/{run}/statutory-file/{key}`.

- [ ] **Step 1: Failing tests (append to PayrollExportTest)**

```php
    public function test_cp39_downloads_for_a_finalized_run_and_is_audited(): void
    {
        $this->tenant->update(['employer_tin' => '9123456708']);
        $run = $this->finalizedRun();
        $res = $this->actingHr()->get(route('payroll.export.statutory-file', [$run, 'cp39']));
        $res->assertOk();
        $this->assertStringContainsString('912345670806_2026.txt', (string) $res->headers->get('content-disposition'));
        $this->assertStringStartsWith('H9123456708', $res->streamedContent());
        $this->assertDatabaseHas('audit_logs', ['action' => 'Exported statutory file']);
    }

    public function test_statutory_file_refuses_a_draft_run_an_unknown_key_an_employee_and_another_tenant(): void
    {
        $draft = $this->finalizedRun('draft');
        $this->actingHr()->get(route('payroll.export.statutory-file', [$draft, 'cp39']))->assertStatus(422);

        $draft->forceFill(['status' => 'finalized', 'finalized_at' => now()])->save();
        $this->actingHr()->get(route('payroll.export.statutory-file', [$draft, 'nope']))->assertNotFound();
        $this->actingEmployee()->get(route('payroll.export.statutory-file', [$draft, 'cp39']))->assertForbidden();

        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'initials' => 'OT']);
        $foreign = PayrollRun::forceCreate(['tenant_id' => $other->id, 'period' => '2026-06', 'label' => 'June 2026', 'status' => 'finalized', 'finalized_at' => now()]);
        $this->actingHr()->get(route('payroll.export.statutory-file', [$foreign, 'cp39']))->assertForbidden();
    }

    public function test_hrd_corp_file_is_refused_when_the_levy_is_off(): void
    {
        $run = $this->finalizedRun();
        $this->actingHr()->get(route('payroll.export.statutory-file', [$run, 'hrdcorp']))->assertStatus(422);
    }
```

- [ ] **Step 2: Run, expect failure.**

- [ ] **Step 3: Controller + route**

```php
    /**
     * Agency upload file (spec F6/F7) for a finalized run: KWSP Form A, PERKESO Borang 8A,
     * LHDN CP39 or the HRD Corp levy file. Every one carries NRICs, so every download is
     * audited; an unverified layout is named as such in the trail.
     */
    public function statutoryFile(Request $request, PayrollRun $run, string $key): StreamedResponse
    {
        $this->authorize($request, $run);
        $file = StatutoryFileRegistry::find($key) ?? abort(404);
        $tenant = app(CurrentTenant::class)->get();
        if ($key === 'hrdcorp') {
            abort_if(HrdCorpLevy::rate((string) app(FeatureManager::class)->value($tenant, 'payroll.hrdf')) <= 0, 422, 'HRD Corp levy is switched off for this company.');
        }

        $payslips = $run->payslips()->with('employee.salaryStructure')->get()
            ->sortBy(fn ($p) => $p->employee?->name)->values();
        $body = $file->build($run, $tenant, $payslips);

        AuditLog::record('Exported statutory file', $run->label.' · '.$file->label().' · '.$payslips->count().' employees · includes NRIC'.($file->verified() ? '' : ' (unverified layout)'));

        return response()->streamDownload(function () use ($body) {
            echo $body;
        }, $file->filename($run, $tenant), ['Content-Type' => $file->contentType()]);
    }
```

Imports: `App\Services\Payroll\Statutory\StatutoryFileRegistry`, `App\Services\Payroll\HrdCorpLevy`, `App\Services\FeatureManager`. Route:

```php
        Route::get('/app/payroll/runs/{run}/statutory-file/{key}', [PayrollExportController::class, 'statutoryFile'])
            ->where('key', '[a-z0-9\-]+')->name('payroll.export.statutory-file');
```

- [ ] **Step 4: Submission view**

Replace the `@foreach (['KWSP Form A', 'PERKESO 8A', 'LHDN CP39'] as $f) ... @endforeach` block with:

```blade
        @php
            $hrdfOn = \App\Services\Payroll\HrdCorpLevy::rate((string) app(\App\Services\FeatureManager::class)->value(app(\App\Tenancy\CurrentTenant::class)->get(), 'payroll.hrdf')) > 0;
            $fileLabels = ['kwsp-form-a' => ['EPF file', 'Fail KWSP'], 'perkeso-8a' => ['SOCSO/EIS file', 'Fail PERKESO/SIP'], 'cp39' => ['PCB file', 'Fail PCB'], 'hrdcorp' => ['HRD Corp file', 'Fail HRD Corp']];
        @endphp
        @foreach (\App\Services\Payroll\Statutory\StatutoryFileRegistry::all() as $key => $file)
            @continue($key === 'hrdcorp' && ! $hrdfOn)
            @if ($activeRun->status === 'finalized')
                <a href="{{ route('payroll.export.statutory-file', [$activeRun, $key]) }}" class="uj-btn-ghost" style="height:36px;padding:0 12px;font-size:12px;display:inline-flex;align-items:center;text-decoration:none;"><span x-text="$store.ui.lang==='en' ? @js($fileLabels[$key][0]) : @js($fileLabels[$key][1])">{{ $fileLabels[$key][0] }}</span>@unless ($file->verified())<span class="uj-pill" style="margin-left:6px;font-size:10px;" x-text="$store.ui.lang==='en' ? 'check layout' : 'semak susun atur'">check layout</span>@endunless</a>
            @else
                <button type="button" disabled title="Available once this run is finalized" class="uj-btn-ghost" style="height:36px;padding:0 12px;font-size:12px;opacity:.55;cursor:not-allowed;" x-text="$store.ui.lang==='en' ? @js($fileLabels[$key][0]) : @js($fileLabels[$key][1])">{{ $fileLabels[$key][0] }}</button>
            @endif
        @endforeach
```

Below the button row add one hint: EN "All four are due by the 15th of next month. The PCB file follows LHDN's published layout. The EPF, SOCSO/EIS and HRD Corp files marked 'check layout' have not been checked against the agency's current upload specification yet: try them on the portal's validator before relying on them. SKBBK is not in the SOCSO/EIS file; pay it through the ASSIST portal." with an MS translation, via `partials.hint` (`'tone' => 'warn'`).

- [ ] **Step 5: docs/statutory/README.md**

Append a section "Agency upload files" stating: CP39 layout = Exhibit 4 of the PCB spec PDF already in this folder, implemented in `App\Services\Payroll\Statutory\LhdnCp39`, golden file `tests/Fixtures/statutory/cp39-2026-06.txt`; KWSP Form A, PERKESO Borang 8A and HRD Corp layouts are provisional (`verified(): false`), and the steps to verify: download the agency's current layout document into this folder, add a table row, re-transcribe the golden file from the document's own example, flip `verified()`.

- [ ] **Step 6: Full verification and commit**

```bash
php artisan test --compact tests/Feature/PayrollExportTest.php
vendor/bin/phpstan analyse --memory-limit=512M && vendor/bin/pint --dirty --format agent
lerd artisan view:clear && lerd artisan view:cache && bun run build
php artisan test --compact
git add -A app routes resources/views docs/statutory/README.md tests public/build
git commit -m "feat(payroll): agency upload file downloads on the finalized run, audited, unverified layouts labelled"
```

Expected: full suite green. Then walk it in the browser at http://localhost:9100 as HR (hidayahsuffya): Settings statutory section, readiness panel, create a run for a month with a mid-month joiner, review flags, finalize with pay date, Mark paid, download all files. Put screenshots in `~/mockups/payroll-phase1-walk/`. Delete only audit rows the walk itself created, if any cleanup is asked for.

---

## Self-review notes

- Spec coverage: F1 Task 1; F2 Tasks 2 to 4 (deferred columns listed under Scope decisions); F3 Task 5; F4 Tasks 6 and 7; F5 Task 8; F6 Tasks 10 to 12; F7 Tasks 9, 11, 12. Spec F3 "every recurring allowance prorates" is already true for Fixed Transactions through their own `prorate` flag; the new item flag drives basic pay and is exposed for Fixed Allowance.
- Names checked across tasks: `PayrollReadiness::employerGaps/employeeRows/blockingRows`, `Proration::days/prorate`, `MinimumWage::below`, `HrdCorpLevy::rate`, `StatutoryFile::build(PayrollRun, Tenant, Collection)`, `StatutoryFileRegistry::all/find`, payslip columns `days_employed`, `days_in_month`, `basic_overridden`, `deduction_cap_exceeded`, `deduction_consent_confirmed`, `carried_forward_amount`, `hrdf_levy`.
- Known follow-up outside this plan: passport number column for foreign staff in CP39; official Form A / 8A / e-TRiS layout documents.
