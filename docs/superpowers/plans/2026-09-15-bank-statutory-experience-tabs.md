# Bank & Statutory + Experience Tabs Implementation Plan (sub-project 3)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Worksy's Bank & Statutory tab (the salary structure, edited from the profile) and Experience tab (TP3 figures, previous employment, education, certificates, awards, languages, plus the existing training and skills lists) to the employee profile.

**Architecture:** Bank & Statutory reuses `SalaryStructure` and posts to the existing `payroll.salary` route (which already `back()`s, so the `?tab=bank` URL survives); the route gains seven new nullable columns. TP3 reuses `payroll.opening` the same way. The five new Experience record types share one table-per-type schema but a single `ExperienceRecordController` driven by a type map (model class + rules), so add/edit/delete is written once. Tabs plug into the profile tab row like Personal/Family did.

**Tech Stack:** Laravel 13 / PHP 8.5, Blade + Alpine, PHPUnit (sqlite), Pint. Dev DB via `lerd artisan migrate`. Assets via `lerd artisan view:clear && lerd artisan view:cache && bun run build`.

**Spec:** `docs/superpowers/specs/2026-09-15-employee-record-and-progression-design.md` section 3 and "Shared rules".

## Global Constraints

- Salary write/view = `director`, `hr` only: `$this->hasTenantRole($request, ['director','hr'])` (existing `canSeeMoney` on the profile is self OR director/hr; editing is director/hr only).
- Experience records: HR/management edit anyone (`hasTenantRole($request, ['management','hr'])`); the person edits their own. `manager` sees neither new tab on a report.
- Every controller re-checks `$employee->tenant_id === app(CurrentTenant::class)->id()`; route-model binding is not tenant-safe.
- Every write calls `AuditLog::record(string $action, ?string $target)`.
- Migrations additive only. Enum-like columns are strings with `Rule::in` validation.
- Bilingual labels via the escaped `$L` helper: `'<span x-text="'.e("\$store.ui.lang==='en' ? ".json_encode($en).' : '.json_encode($ms)).'">'.e($en).'</span>'`.
- Bank account number on self-view: mask to last four digits (`'•••• '.substr($no, -4)`).
- Payroll calculations must not change: no existing `salary_structures` column changes meaning; `children_relief_count` stays the manual relief-unit count (auto child-relief count is out of scope).
- Return to the same tab after save: the two payroll routes use `back()` (referer keeps `?tab=`); the experience controller redirects to `route('app.screen','profile').'?emp='.$id.'&tab=experience'`.
- Commit after each task. Never bare `git stash`.

## Test scaffolding (copy into every new test file)

```php
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

private Tenant $tenant;

protected function setUp(): void
{
    parent::setUp();
    $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
    app(CurrentTenant::class)->set($this->tenant);
}

private function login(string $role, array $attrs = []): Employee
{
    $user = User::create(['name' => ucfirst($role), 'email' => $role.'@example.com', 'password' => Hash::make('password')]);
    $user->tenants()->attach($this->tenant->id, ['role' => $role]);
    $employee = Employee::create(array_merge([
        'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
        'name' => ucfirst($role), 'status' => 'active', 'workload' => 'green', 'joined_at' => '2025-01-06',
    ], $attrs));
    $this->actingAs($user)->withSession(['current_tenant' => $this->tenant->id]);

    return $employee;
}

private function emp(string $name, array $attrs = []): Employee
{
    return Employee::create(array_merge([
        'tenant_id' => $this->tenant->id, 'name' => $name, 'status' => 'active', 'workload' => 'green', 'joined_at' => '2026-01-05',
    ], $attrs));
}
```

## File map

| File | Responsibility |
|---|---|
| `database/migrations/2026_09_28_100000_add_bank_statutory_columns_to_salary_structures.php` | 7 new nullable columns |
| `database/migrations/2026_09_28_100100_create_employee_experience_tables.php` | 5 new tables |
| `app/Support/StatutoryOptions.php` | BANKS, TAX_CATEGORIES, EMPLOYEE_TAX_STATUS, EPF_SCHEMES, SOCSO_CATEGORIES, CHILD_RELIEF_CATEGORIES |
| `app/Support/ExperienceOptions.php` | SALARY_TYPES, QUALIFICATIONS, HONOURS, PROFICIENCY, plus `TYPES` map (type slug => model class, label pair) |
| `app/Models/{EmployeeWorkHistory,EmployeeEducation,EmployeeCertificate,EmployeeAward,EmployeeLanguage}.php` | one model per table, `BelongsToTenant` |
| `app/Models/SalaryStructure.php` | new fillables + casts |
| `app/Models/Employee.php` | five HasMany relations |
| `app/Http/Controllers/PayrollController.php` | `storeSalary` validates/writes the 7 new columns |
| `app/Http/Controllers/ExperienceRecordController.php` | `store`, `update`, `destroy` for all five types |
| `app/Http/Controllers/Concerns/BuildsPeopleData.php` | `bankGate`, `canEditSalaryStructure`, `experienceGate`, `canEditExperience`, `openingFigures`, `documents` |
| `resources/views/partials/profile/bank-tab.blade.php` | read grid + edit modal posting to `payroll.salary` |
| `resources/views/partials/profile/experience-tab.blade.php` | TP3 section + five record sections + training + skills |
| `resources/views/partials/profile/experience-form.blade.php` | one form, fields switched by `$type` |
| `resources/views/screens/profile.blade.php` | tab entries, panels, `editBank` flag, training removed from Assets tab |
| `resources/views/screens/payroll.blade.php` | "Edit on profile" link per salary row and per TP3 row |
| `tests/Feature/{BankStatutoryTabTest,ExperienceRecordTest,ProfileExperienceTabTest}.php` | tests |

---

### Task 1: Schema, option lists, models

**Files:**
- Create: both migrations, `app/Support/StatutoryOptions.php`, `app/Support/ExperienceOptions.php`, five models
- Modify: `app/Models/SalaryStructure.php` (`$fillable` ~line 19, `casts()` ~line 47), `app/Models/Employee.php` (after `familyMembers()`)
- Test: `tests/Feature/ExperienceSchemaTest.php`

**Produces:** `ExperienceOptions::TYPES` = `['work' => [EmployeeWorkHistory::class, 'Previous Employment', 'Pekerjaan Terdahulu'], 'education' => [...], 'certificate' => [...], 'award' => [...], 'language' => [...]]`; `Employee::workHistories()`, `educations()`, `certificates()`, `awards()`, `languages()`.

- [ ] **Step 1: Failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Support\ExperienceOptions;
use App\Support\StatutoryOptions;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ExperienceSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_tables_columns_relations_and_options(): void
    {
        foreach (['bank_holder_name', 'tax_resident', 'tax_category', 'employee_tax_status', 'child_relief_breakdown', 'epf_scheme', 'socso_category'] as $c) {
            $this->assertTrue(Schema::hasColumn('salary_structures', $c), $c);
        }
        foreach (['employee_work_histories', 'employee_educations', 'employee_certificates', 'employee_awards', 'employee_languages'] as $t) {
            $this->assertTrue(Schema::hasTable($t), $t);
        }

        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        app(CurrentTenant::class)->set($tenant);
        $e = Employee::create(['tenant_id' => $tenant->id, 'name' => 'A', 'status' => 'active', 'workload' => 'green']);
        $e->workHistories()->create(['tenant_id' => $tenant->id, 'company' => 'Old Co', 'joined_on' => '2019-01-01', 'salary_type' => 'monthly']);
        $e->educations()->create(['tenant_id' => $tenant->id, 'qualification_type' => 'bachelor', 'institute' => 'UM', 'from_year' => 2015, 'to_year' => 2019]);
        $e->certificates()->create(['tenant_id' => $tenant->id, 'name' => 'AWS']);
        $e->awards()->create(['tenant_id' => $tenant->id, 'title' => 'Dean List', 'year' => 2018]);
        $e->languages()->create(['tenant_id' => $tenant->id, 'language' => 'Malay', 'speaking' => 'native', 'reading' => 'native', 'writing' => 'fluent']);
        $this->assertSame('2019-01-01', $e->workHistories()->first()->joined_on->toDateString());
        $this->assertSame(1, $e->languages()->count());

        $s = $e->salaryStructure()->create(['tenant_id' => $tenant->id, 'basic_salary' => 3000, 'tax_resident' => false, 'child_relief_breakdown' => ['under_18' => ['100' => 1, '50' => 0]]]);
        $this->assertFalse($s->fresh()->tax_resident);
        $this->assertSame(1, $s->fresh()->child_relief_breakdown['under_18']['100']);

        $this->assertContains('Maybank', StatutoryOptions::BANKS);
        $this->assertSame(['work', 'education', 'certificate', 'award', 'language'], array_keys(ExperienceOptions::TYPES));
        $this->assertSame(\App\Models\EmployeeEducation::class, ExperienceOptions::TYPES['education'][0]);
    }
}
```

- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/ExperienceSchemaTest.php` → FAIL.

- [ ] **Step 3: Migrations**

`2026_09_28_100000_add_bank_statutory_columns_to_salary_structures.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Worksy Bank & Statutory fields. All nullable; payroll maths reads none of them. */
    public function up(): void
    {
        Schema::table('salary_structures', function (Blueprint $table) {
            $table->string('bank_holder_name', 160)->nullable();
            $table->boolean('tax_resident')->default(true);
            $table->string('tax_category', 8)->nullable();
            $table->string('employee_tax_status', 32)->nullable();
            $table->json('child_relief_breakdown')->nullable();
            $table->string('epf_scheme', 32)->nullable();
            $table->string('socso_category', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('salary_structures', fn (Blueprint $table) => $table->dropColumn(['bank_holder_name', 'tax_resident', 'tax_category', 'employee_tax_status', 'child_relief_breakdown', 'epf_scheme', 'socso_category']));
    }
};
```

`2026_09_28_100100_create_employee_experience_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Worksy Experience tab: previous employment, education, certificates, awards, languages. */
    public function up(): void
    {
        $base = function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
        };
        $attachment = fn (Blueprint $table) => $table->foreignId('document_id')->nullable()->constrained('employee_documents')->nullOnDelete();

        Schema::create('employee_work_histories', function (Blueprint $table) use ($base) {
            $base($table);
            $table->string('company', 160);
            $table->string('address', 255)->nullable();
            $table->date('joined_on')->nullable();
            $table->string('joined_as', 120)->nullable();
            $table->date('resigned_on')->nullable();
            $table->string('position_held', 120)->nullable();
            $table->decimal('last_drawn_salary', 12, 2)->nullable();
            $table->string('salary_type', 10)->nullable(); // monthly, weekly, daily
            $table->string('industry', 120)->nullable();
            $table->string('reason_to_leave', 255)->nullable();
            $table->timestamps();
            $table->index('employee_id');
        });

        Schema::create('employee_educations', function (Blueprint $table) use ($base, $attachment) {
            $base($table);
            $table->string('qualification_type', 16); // high_school, vocational, associate, bachelor, master, doctorate
            $table->string('major', 160)->nullable();
            $table->string('institute', 160)->nullable();
            $table->unsignedSmallInteger('from_year')->nullable();
            $table->unsignedSmallInteger('to_year')->nullable();
            $table->string('honours', 8)->nullable(); // first, second, third, none
            $table->decimal('cgpa', 3, 2)->nullable();
            $table->string('remark', 500)->nullable();
            $attachment($table);
            $table->timestamps();
            $table->index('employee_id');
        });

        Schema::create('employee_certificates', function (Blueprint $table) use ($base, $attachment) {
            $base($table);
            $table->string('name', 160);
            $table->string('category', 120)->nullable();
            $table->date('awarded_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->string('awarded_by', 160)->nullable();
            $table->string('remark', 500)->nullable();
            $attachment($table);
            $table->timestamps();
            $table->index('employee_id');
        });

        Schema::create('employee_awards', function (Blueprint $table) use ($base, $attachment) {
            $base($table);
            $table->string('title', 160);
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('remark', 500)->nullable();
            $attachment($table);
            $table->timestamps();
            $table->index('employee_id');
        });

        Schema::create('employee_languages', function (Blueprint $table) use ($base) {
            $base($table);
            $table->string('language', 80);
            $table->string('speaking', 12)->nullable(); // basic, intermediate, fluent, native
            $table->string('reading', 12)->nullable();
            $table->string('writing', 12)->nullable();
            $table->timestamps();
            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        foreach (['employee_languages', 'employee_awards', 'employee_certificates', 'employee_educations', 'employee_work_histories'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
```

- [ ] **Step 4: Option classes**

`app/Support/StatutoryOptions.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support;

/** Fixed lists for the Bank & Statutory tab (Worksy's Malaysian lists). Not tenant-editable. */
final class StatutoryOptions
{
    public const BANKS = [
        'Affin Bank', 'Agrobank', 'Alliance Bank', 'AmBank', 'Bank Islam', 'Bank Muamalat', 'Bank Rakyat', 'Bank Simpanan Nasional',
        'CIMB Bank', 'Citibank', 'Hong Leong Bank', 'HSBC Bank', 'Kuwait Finance House', 'Maybank', 'MBSB Bank', 'OCBC Bank',
        'Public Bank', 'RHB Bank', 'Standard Chartered', 'United Overseas Bank', 'Other',
    ];

    /** LHDN PCB category codes. */
    public const TAX_CATEGORIES = ['1' => 'Category 1 · Single', '2' => 'Category 2 · Married, spouse not working', '3' => 'Category 3 · Married, spouse working'];

    public const EMPLOYEE_TAX_STATUS = ['normal' => 'Normal', 'returning_expert' => 'Returning Expert Programme', 'knowledge_worker' => 'Knowledge Worker (Iskandar)', 'non_resident' => 'Non-resident'];

    public const EPF_SCHEMES = ['statutory' => 'Statutory rate', 'voluntary_higher' => 'Voluntary higher rate', 'exempt' => 'Exempt'];

    public const SOCSO_CATEGORIES = ['category_1' => 'Category 1 · Employment injury + invalidity', 'category_2' => 'Category 2 · Employment injury only', 'exempt' => 'Exempt'];

    /** LHDN child relief categories; each holds a count at 100% and a count at 50% (shared custody). */
    public const CHILD_RELIEF_CATEGORIES = [
        'under_18' => ['Under 18', 'Bawah 18 tahun'],
        'over_18_education' => ['18+ in full-time education', '18+ pendidikan sepenuh masa'],
        'disabled' => ['Disabled child', 'Anak kurang upaya'],
        'disabled_education' => ['Disabled child, 18+ in education', 'Anak kurang upaya, 18+ pendidikan'],
    ];
}
```

`app/Support/ExperienceOptions.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\EmployeeAward;
use App\Models\EmployeeCertificate;
use App\Models\EmployeeEducation;
use App\Models\EmployeeLanguage;
use App\Models\EmployeeWorkHistory;

/** Experience tab record types and their fixed option lists. */
final class ExperienceOptions
{
    /** type slug => [model class, EN heading, MS heading, employee relation name]. */
    public const TYPES = [
        'work' => [EmployeeWorkHistory::class, 'Previous Employment', 'Pekerjaan Terdahulu', 'workHistories'],
        'education' => [EmployeeEducation::class, 'Education', 'Pendidikan', 'educations'],
        'certificate' => [EmployeeCertificate::class, 'Certificates', 'Sijil', 'certificates'],
        'award' => [EmployeeAward::class, 'Awards / Scholarship', 'Anugerah / Biasiswa', 'awards'],
        'language' => [EmployeeLanguage::class, 'Languages', 'Bahasa', 'languages'],
    ];

    public const SALARY_TYPES = ['monthly', 'weekly', 'daily'];

    public const QUALIFICATIONS = ['high_school' => 'High School', 'vocational' => 'Vocational', 'associate' => 'Associate', 'bachelor' => 'Bachelor', 'master' => 'Master', 'doctorate' => 'Doctorate'];

    public const HONOURS = ['first' => 'First class', 'second' => 'Second class', 'third' => 'Third class', 'none' => 'None'];

    public const PROFICIENCY = ['basic', 'intermediate', 'fluent', 'native'];
}
```

- [ ] **Step 5: Models** (five files, same shape; shown for work history, the others differ only in class name and casts)

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Experience tab · Previous Employment row. */
class EmployeeWorkHistory extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['joined_on' => 'date', 'resigned_on' => 'date', 'last_drawn_salary' => 'float'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
```

Casts per model: `EmployeeEducation` → `['from_year' => 'integer', 'to_year' => 'integer', 'cgpa' => 'float']` plus `document(): BelongsTo` to `EmployeeDocument`; `EmployeeCertificate` → `['awarded_on' => 'date', 'expires_on' => 'date']` plus `document()`; `EmployeeAward` → `['year' => 'integer']` plus `document()`; `EmployeeLanguage` → `[]` (no casts method needed).

`Employee.php`, after `familyMembers()`:

```php
public function workHistories(): HasMany { return $this->hasMany(EmployeeWorkHistory::class)->orderByDesc('joined_on'); }
public function educations(): HasMany { return $this->hasMany(EmployeeEducation::class)->orderByDesc('to_year'); }
public function certificates(): HasMany { return $this->hasMany(EmployeeCertificate::class)->orderByDesc('awarded_on'); }
public function awards(): HasMany { return $this->hasMany(EmployeeAward::class)->orderByDesc('year'); }
public function languages(): HasMany { return $this->hasMany(EmployeeLanguage::class)->orderBy('language'); }
```

(Write each on multiple lines per Pint.)

`SalaryStructure.php`: append to `$fillable`: `'bank_holder_name', 'tax_resident', 'tax_category', 'employee_tax_status', 'child_relief_breakdown', 'epf_scheme', 'socso_category'`; to `casts()`: `'tax_resident' => 'boolean', 'child_relief_breakdown' => 'array'`.

- [ ] **Step 6: Run** test → PASS. Pint.
- [ ] **Step 7: Commit** `git add database/migrations app/Support app/Models tests/Feature/ExperienceSchemaTest.php && git commit -m "feat(experience): statutory columns, experience tables, option lists"`.

---

### Task 2: `storeSalary` takes the new fields; payroll screen links to the profile

**Files:**
- Modify: `app/Http/Controllers/PayrollController.php::storeSalary` (~line 63)
- Modify: `resources/views/screens/payroll.blade.php` (salary row ~line 483, TP3 row ~line 802)
- Test: `tests/Feature/BankStatutoryTabTest.php` (first two tests)

- [ ] **Step 1: Failing tests**

```php
use App\Models\SalaryStructure;

public function test_salary_save_persists_bank_and_statutory_fields(): void
{
    $this->login('hr');
    $e = $this->emp('Adibah');
    $this->from("/app/profile?emp={$e->id}&tab=bank")->post('/app/payroll/salary', [
        'employee_id' => $e->id, 'basic_salary' => 3500, 'bank_name' => 'Maybank', 'bank_account_no' => '112233445566', 'bank_holder_name' => 'Adibah Z',
        'tax_no' => 'SG123', 'tax_resident' => '0', 'tax_category' => '3', 'employee_tax_status' => 'normal',
        'child_relief' => ['under_18' => ['100' => 2, '50' => 0], 'disabled' => ['100' => 0, '50' => 1]],
        'epf_no' => 'E1', 'epf_scheme' => 'statutory', 'socso_no' => 'S1', 'socso_category' => 'category_1', 'children_relief_count' => 3,
    ])->assertRedirect("/app/profile?emp={$e->id}&tab=bank");
    $s = SalaryStructure::where('employee_id', $e->id)->firstOrFail();
    $this->assertSame('Adibah Z', $s->bank_holder_name);
    $this->assertFalse($s->tax_resident);
    $this->assertSame('3', $s->tax_category);
    $this->assertSame(2, $s->child_relief_breakdown['under_18']['100']);
    $this->assertSame(1, $s->child_relief_breakdown['disabled']['50']);
    $this->assertSame('category_1', $s->socso_category);
    $this->assertSame(3, $s->children_relief_count);
}

public function test_invalid_statutory_options_rejected(): void
{
    $this->login('hr');
    $e = $this->emp('Adibah');
    $this->post('/app/payroll/salary', ['employee_id' => $e->id, 'basic_salary' => 1000, 'tax_category' => '9', 'epf_scheme' => 'x', 'bank_name' => 'Not A Bank'])
        ->assertSessionHasErrors(['tax_category', 'epf_scheme', 'bank_name']);
}
```

- [ ] **Step 2: Run** → FAIL (columns not written / no errors).

- [ ] **Step 3: Controller** — in `storeSalary` add to the validation array (after `'bank_account_no'`):

```php
'bank_holder_name' => ['nullable', 'string', 'max:160'],
'tax_resident' => ['nullable', 'boolean'],
'tax_category' => ['nullable', Rule::in(array_keys(StatutoryOptions::TAX_CATEGORIES))],
'employee_tax_status' => ['nullable', Rule::in(array_keys(StatutoryOptions::EMPLOYEE_TAX_STATUS))],
'child_relief' => ['nullable', 'array'],
'child_relief.*' => ['array'],
'child_relief.*.*' => ['nullable', 'integer', 'min:0', 'max:20'],
'epf_scheme' => ['nullable', Rule::in(array_keys(StatutoryOptions::EPF_SCHEMES))],
'socso_category' => ['nullable', Rule::in(array_keys(StatutoryOptions::SOCSO_CATEGORIES))],
```

Change the existing `'bank_name'` rule to `['nullable', Rule::in(StatutoryOptions::BANKS)]` **only if** no existing test posts a free-text bank name: run `grep -rn "bank_name" tests/` first. If any test uses a name outside the list, keep `'string', 'max:60'` and drop `bank_name` from the invalid-options test instead.

Add to the `updateOrCreate` values:

```php
'bank_holder_name' => $data['bank_holder_name'] ?? null,
'tax_resident' => $request->has('tax_resident') ? $request->boolean('tax_resident') : true,
'tax_category' => $data['tax_category'] ?? null,
'employee_tax_status' => $data['employee_tax_status'] ?? null,
'child_relief_breakdown' => self::childRelief($data['child_relief'] ?? null),
'epf_scheme' => $data['epf_scheme'] ?? null,
'socso_category' => $data['socso_category'] ?? null,
```

and a helper in the same controller:

```php
/**
 * Normalise the child-relief grid to every LHDN category × {100, 50} as ints, or null when nothing was sent.
 *
 * @param  array<string, array<string, mixed>>|null  $grid
 * @return array<string, array{100: int, 50: int}>|null
 */
private static function childRelief(?array $grid): ?array
{
    if ($grid === null) {
        return null;
    }
    $out = [];
    foreach (array_keys(StatutoryOptions::CHILD_RELIEF_CATEGORIES) as $cat) {
        $out[$cat] = ['100' => (int) ($grid[$cat]['100'] ?? 0), '50' => (int) ($grid[$cat]['50'] ?? 0)];
    }

    return $out;
}
```

Import `App\Support\StatutoryOptions`.

- [ ] **Step 4: Payroll screen links.** In the salary row header (next to the Edit/Set button, ~line 483) add:

```blade
<a href="{{ route('app.screen', 'profile') }}?emp={{ $e->id }}&tab=bank" class="uj-btn-ghost" style="height:32px;padding:0 12px;font-size:12px;text-decoration:none;display:inline-flex;align-items:center;" x-text="$store.ui.lang==='en' ? 'Edit on profile' : 'Sunting di profil'">Edit on profile</a>
```

In the TP3 tab, in the per-employee row header above its form (~line 795, find the row's employee `$e` variable name there and use it), add the same link with `&tab=experience`.

- [ ] **Step 5: Run** the two tests plus `php artisan test --compact tests/Feature --filter=Payroll` → PASS. Pint.
- [ ] **Step 6: Commit** `git add app/Http/Controllers/PayrollController.php resources/views/screens/payroll.blade.php tests/Feature/BankStatutoryTabTest.php && git commit -m "feat(payroll): bank and statutory fields on salary structure, links to profile"`.

---

### Task 3: `ExperienceRecordController`

**Files:**
- Create: `app/Http/Controllers/ExperienceRecordController.php`
- Modify: `routes/web.php` (after `employees.family.destroy`)
- Test: `tests/Feature/ExperienceRecordTest.php`

**Produces:** routes `employees.experience.store` (POST `/app/employees/{employee}/experience/{type}`), `employees.experience.update` (POST `/app/experience/{type}/{id}`), `employees.experience.destroy` (POST `/app/experience/{type}/{id}/delete`). `{type}` is `whereIn` over `array_keys(ExperienceOptions::TYPES)`.

- [ ] **Step 1: Failing tests**

```php
use App\Models\EmployeeEducation;
use App\Models\EmployeeLanguage;
use App\Models\EmployeeWorkHistory;

public function test_hr_adds_edits_and_deletes_each_type(): void
{
    $this->login('hr');
    $e = $this->emp('Adibah');
    $cases = [
        'work' => [EmployeeWorkHistory::class, ['company' => 'Old Co', 'joined_on' => '2019-01-01', 'resigned_on' => '2021-06-30', 'last_drawn_salary' => '4500.50', 'salary_type' => 'monthly'], 'company', 'New Co'],
        'education' => [EmployeeEducation::class, ['qualification_type' => 'bachelor', 'institute' => 'UM', 'from_year' => 2015, 'to_year' => 2019, 'honours' => 'first', 'cgpa' => '3.75'], 'institute', 'UKM'],
        'certificate' => [\App\Models\EmployeeCertificate::class, ['name' => 'AWS SAA', 'awarded_on' => '2022-02-02'], 'name', 'AWS SAP'],
        'award' => [\App\Models\EmployeeAward::class, ['title' => 'Dean List', 'year' => 2018], 'title', 'Gold'],
        'language' => [EmployeeLanguage::class, ['language' => 'Malay', 'speaking' => 'native', 'reading' => 'native', 'writing' => 'fluent'], 'language', 'English'],
    ];
    foreach ($cases as $type => [$model, $payload, $field, $newValue]) {
        $this->post("/app/employees/{$e->id}/experience/{$type}", $payload)->assertRedirect("/app/profile?emp={$e->id}&tab=experience");
        $row = $model::firstOrFail();
        $this->post("/app/experience/{$type}/{$row->id}", array_merge($payload, [$field => $newValue]))->assertRedirect();
        $this->assertSame($newValue, $row->fresh()->{$field});
        $this->post("/app/experience/{$type}/{$row->id}/delete")->assertRedirect();
        $this->assertSame(0, $model::count(), $type);
    }
}

public function test_employee_edits_own_only(): void
{
    $me = $this->login('employee');
    $other = $this->emp('Adibah');
    $this->post("/app/employees/{$me->id}/experience/language", ['language' => 'Malay', 'speaking' => 'native'])->assertRedirect();
    $this->post("/app/employees/{$other->id}/experience/language", ['language' => 'Malay'])->assertForbidden();
    $theirs = $other->languages()->create(['tenant_id' => $this->tenant->id, 'language' => 'Tamil']);
    $this->post("/app/experience/language/{$theirs->id}", ['language' => 'X'])->assertForbidden();
    $this->post("/app/experience/language/{$theirs->id}/delete")->assertForbidden();
}

public function test_manager_cannot_edit_report(): void
{
    $m = $this->login('manager');
    $e = $this->emp('Adibah', ['reports_to_id' => $m->id]);
    $this->post("/app/employees/{$e->id}/experience/award", ['title' => 'X'])->assertForbidden();
}

public function test_invalid_type_and_options_rejected(): void
{
    $this->login('hr');
    $e = $this->emp('Adibah');
    $this->post("/app/employees/{$e->id}/experience/hobby", ['title' => 'X'])->assertNotFound();
    $this->post("/app/employees/{$e->id}/experience/education", ['qualification_type' => 'phd', 'honours' => 'gold', 'to_year' => 1800])
        ->assertSessionHasErrors(['qualification_type', 'honours', 'to_year']);
    $this->post("/app/employees/{$e->id}/experience/work", ['company' => 'A', 'joined_on' => '2020-01-01', 'resigned_on' => '2019-01-01'])
        ->assertSessionHasErrors('resigned_on');
}

public function test_attachment_must_belong_to_same_employee(): void
{
    $this->login('hr');
    $e = $this->emp('Adibah');
    $other = $this->emp('Bob');
    $doc = \App\Models\EmployeeDocument::create(['tenant_id' => $this->tenant->id, 'employee_id' => $other->id, 'title' => 'Cert', 'category' => 'other', 'file_path' => 'x', 'original_name' => 'x.pdf', 'mime' => 'application/pdf', 'size' => 1]);
    $this->post("/app/employees/{$e->id}/experience/certificate", ['name' => 'X', 'document_id' => $doc->id])->assertSessionHasErrors('document_id');
}

public function test_other_tenant_not_found(): void
{
    $this->login('hr');
    $other = Tenant::create(['slug' => 'zed', 'name' => 'Zed', 'initials' => 'ZD']);
    $s = Employee::withoutGlobalScopes()->create(['tenant_id' => $other->id, 'name' => 'S', 'status' => 'active', 'workload' => 'green']);
    $this->post("/app/employees/{$s->id}/experience/award", ['title' => 'X'])->assertNotFound();
    $row = EmployeeLanguage::withoutGlobalScopes()->create(['tenant_id' => $other->id, 'employee_id' => $s->id, 'language' => 'X']);
    $this->post("/app/experience/language/{$row->id}", ['language' => 'Y'])->assertNotFound();
}
```

Check `EmployeeDocument` required columns before running (`grep -n "nullable\|string(" database/migrations/*employee_documents*`); adjust the create payload to whatever is NOT nullable.

- [ ] **Step 2: Run** → FAIL.

- [ ] **Step 3: Routes**

```php
Route::post('/app/employees/{employee}/experience/{type}', [ExperienceRecordController::class, 'store'])->whereNumber('employee')->whereIn('type', array_keys(\App\Support\ExperienceOptions::TYPES))->name('employees.experience.store');
Route::post('/app/experience/{type}/{id}', [ExperienceRecordController::class, 'update'])->whereIn('type', array_keys(\App\Support\ExperienceOptions::TYPES))->whereNumber('id')->name('employees.experience.update');
Route::post('/app/experience/{type}/{id}/delete', [ExperienceRecordController::class, 'destroy'])->whereIn('type', array_keys(\App\Support\ExperienceOptions::TYPES))->whereNumber('id')->name('employees.experience.destroy');
```

Import `App\Http\Controllers\ExperienceRecordController`.

- [ ] **Step 4: Controller**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Support\ExperienceOptions;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Experience tab rows: previous employment, education, certificates, awards, languages.
 * One controller for all five; the {type} route segment picks the model and rules.
 * HR/management for anyone; a person for their own record.
 */
class ExperienceRecordController extends Controller
{
    public function store(Request $request, Employee $employee, string $type): RedirectResponse
    {
        $this->guard($request, $employee);
        $data = $this->validated($request, $type, $employee);
        $row = $employee->{ExperienceOptions::TYPES[$type][3]}()->create($data + ['tenant_id' => $employee->tenant_id]);
        AuditLog::record('Added '.$type.' record', $employee->name.' · '.self::label($row));

        return $this->back($employee);
    }

    public function update(Request $request, string $type, int $id): RedirectResponse
    {
        $row = $this->find($type, $id);
        $employee = $this->guard($request, $row->employee);
        $row->update($this->validated($request, $type, $employee));
        AuditLog::record('Updated '.$type.' record', $employee->name.' · '.self::label($row));

        return $this->back($employee);
    }

    public function destroy(Request $request, string $type, int $id): RedirectResponse
    {
        $row = $this->find($type, $id);
        $employee = $this->guard($request, $row->employee);
        $label = self::label($row);
        $row->delete();
        AuditLog::record('Removed '.$type.' record', $employee->name.' · '.$label);

        return $this->back($employee);
    }

    private function find(string $type, int $id): Model
    {
        /** @var class-string<Model> $class */
        $class = ExperienceOptions::TYPES[$type][0];

        return $class::query()->findOrFail($id); // tenant global scope → 404 across tenants
    }

    private function guard(Request $request, ?Employee $employee): Employee
    {
        abort_unless($employee && $employee->tenant_id === app(CurrentTenant::class)->id(), 404);
        $own = $request->attributes->get('employee');
        abort_unless($this->hasTenantRole($request, ['management', 'hr']) || ($own && $own->id === $employee->id), 403);

        return $employee;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, string $type, Employee $employee): array
    {
        $attachment = ['document_id' => ['nullable', 'integer', Rule::exists('employee_documents', 'id')->where('employee_id', $employee->id)]];
        $rules = match ($type) {
            'work' => [
                'company' => ['required', 'string', 'max:160'],
                'address' => ['nullable', 'string', 'max:255'],
                'joined_on' => ['nullable', 'date'],
                'joined_as' => ['nullable', 'string', 'max:120'],
                'resigned_on' => ['nullable', 'date', 'after_or_equal:joined_on'],
                'position_held' => ['nullable', 'string', 'max:120'],
                'last_drawn_salary' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
                'salary_type' => ['nullable', Rule::in(ExperienceOptions::SALARY_TYPES)],
                'industry' => ['nullable', 'string', 'max:120'],
                'reason_to_leave' => ['nullable', 'string', 'max:255'],
            ],
            'education' => [
                'qualification_type' => ['required', Rule::in(array_keys(ExperienceOptions::QUALIFICATIONS))],
                'major' => ['nullable', 'string', 'max:160'],
                'institute' => ['nullable', 'string', 'max:160'],
                'from_year' => ['nullable', 'integer', 'min:1950', 'max:2100'],
                'to_year' => ['nullable', 'integer', 'min:1950', 'max:2100', 'gte:from_year'],
                'honours' => ['nullable', Rule::in(array_keys(ExperienceOptions::HONOURS))],
                'cgpa' => ['nullable', 'numeric', 'min:0', 'max:4'],
                'remark' => ['nullable', 'string', 'max:500'],
            ] + $attachment,
            'certificate' => [
                'name' => ['required', 'string', 'max:160'],
                'category' => ['nullable', 'string', 'max:120'],
                'awarded_on' => ['nullable', 'date'],
                'expires_on' => ['nullable', 'date', 'after_or_equal:awarded_on'],
                'awarded_by' => ['nullable', 'string', 'max:160'],
                'remark' => ['nullable', 'string', 'max:500'],
            ] + $attachment,
            'award' => [
                'title' => ['required', 'string', 'max:160'],
                'year' => ['nullable', 'integer', 'min:1950', 'max:2100'],
                'remark' => ['nullable', 'string', 'max:500'],
            ] + $attachment,
            'language' => [
                'language' => ['required', 'string', 'max:80'],
                'speaking' => ['nullable', Rule::in(ExperienceOptions::PROFICIENCY)],
                'reading' => ['nullable', Rule::in(ExperienceOptions::PROFICIENCY)],
                'writing' => ['nullable', Rule::in(ExperienceOptions::PROFICIENCY)],
            ],
        };
        $rules['_row'] = ['nullable', 'integer'];

        $validator = validator($request->all(), $rules);
        if ($validator->fails()) {
            // Flashed before the throw so the Experience tab reopens the right form with the errors.
            session()->flash('form', 'experience:'.$type);
            throw new ValidationException($validator);
        }
        $data = $validator->validated();
        unset($data['_row']);

        return array_map(fn ($v) => $v === '' ? null : $v, $data);
    }

    private static function label(Model $row): string
    {
        return (string) ($row->company ?? $row->institute ?? $row->name ?? $row->title ?? $row->language ?? $row->getKey());
    }

    private function back(Employee $employee): RedirectResponse
    {
        return redirect(route('app.screen', 'profile').'?emp='.$employee->id.'&tab=experience')->with('ok', 'Experience record saved.');
    }
}
```

`gte:from_year` on a nullable pair: Laravel skips `gte` when the other field is absent only if you use `'nullable'` and `from_year` is null? No: `gte` compares against `null` and fails. Guard it: replace with a closure `function ($a, $v, $fail) use ($request) { if ($request->filled('from_year') && (int) $v < (int) $request->input('from_year')) { $fail('To year must not be before from year.'); } }`. Same for `after_or_equal:joined_on` / `awarded_on` (Laravel's `after_or_equal` with a null other field throws); use the closure pattern comparing dates with `strtotime` only when the other field is filled.

- [ ] **Step 5: Run** → PASS. Pint.
- [ ] **Step 6: Commit** `git add app/Http/Controllers/ExperienceRecordController.php routes/web.php tests/Feature/ExperienceRecordTest.php && git commit -m "feat(experience): one controller for work, education, certificate, award and language rows"`.

---

### Task 4: Profile gates, Bank & Statutory tab, Experience tab

**Files:**
- Modify: `app/Http/Controllers/Concerns/BuildsPeopleData.php` (`$with` ~118, after `$canEditPersonal` ~172, return array after `'familyMembers'` ~280)
- Modify: `resources/views/screens/profile.blade.php` (outer `x-data` line 59, `$tabs` ~238, panels ~322, Assets tab ~710)
- Create: `partials/profile/bank-tab.blade.php`, `experience-tab.blade.php`, `experience-form.blade.php`
- Test: `tests/Feature/ProfileExperienceTabTest.php` + remaining `BankStatutoryTabTest` tests

- [ ] **Step 1: Failing tests** (`ProfileExperienceTabTest`)

```php
public function test_hr_sees_bank_and_experience_tabs_with_full_account_and_tp3(): void
{
    $this->login('hr');
    $e = $this->emp('Adibah');
    $e->salaryStructure()->create(['tenant_id' => $this->tenant->id, 'basic_salary' => 3000, 'bank_name' => 'Maybank', 'bank_account_no' => '112233445566']);
    $e->certificates()->create(['tenant_id' => $this->tenant->id, 'name' => 'AWS SAA']);
    \App\Models\PayrollOpeningFigure::create(['tenant_id' => $this->tenant->id, 'employee_id' => $e->id, 'year' => 2025, 'gross' => 12000]);
    $this->get("/app/profile?emp={$e->id}")->assertOk()
        ->assertSee('data-tab="bank"', false)->assertSee('data-tab="experience"', false)
        ->assertSee('112233445566')->assertSee('name="bank_holder_name"', false)
        ->assertSee('AWS SAA')->assertSee('12,000.00')->assertSee('name="pcb_paid"', false);
}

public function test_employee_own_view_masks_account_and_hides_tp3_but_can_add_experience(): void
{
    $me = $this->login('employee');
    $me->salaryStructure()->create(['tenant_id' => $this->tenant->id, 'basic_salary' => 3000, 'bank_name' => 'Maybank', 'bank_account_no' => '112233445566']);
    $this->get('/app/profile')->assertOk()
        ->assertSee('data-tab="bank"', false)->assertSee('data-tab="experience"', false)
        ->assertSee('•••• 5566')->assertDontSee('112233445566')->assertDontSee('name="bank_holder_name"', false)
        ->assertDontSee('name="pcb_paid"', false)->assertSee("/app/employees/{$me->id}/experience/language", false);
}

public function test_manager_sees_neither_tab_on_a_report(): void
{
    $m = $this->login('manager');
    $e = $this->emp('Adibah', ['reports_to_id' => $m->id]);
    $this->get("/app/profile?emp={$e->id}")->assertOk()
        ->assertDontSee('data-tab="bank"', false)->assertDontSee('data-tab="experience"', false);
}

public function test_training_moved_out_of_assets_tab_into_experience(): void
{
    $this->login('hr');
    $e = $this->emp('Adibah');
    $html = $this->get("/app/profile?emp={$e->id}")->assertOk()->getContent();
    $assets = substr($html, strpos($html, "tab === 'assets'"));
    $this->assertStringNotContainsString('No training records', $assets);
    $experience = substr($html, strpos($html, "tab === 'experience'"), strpos($html, "tab === 'work'") - strpos($html, "tab === 'experience'"));
    $this->assertStringContainsString('No training records', $experience);
}
```

Note the panel order in Step 4 puts `experience` before `work`, which the last test relies on.

- [ ] **Step 2: Run** → FAIL.

- [ ] **Step 3: `BuildsPeopleData::profileData`**

Add `'salaryStructure', 'workHistories', 'educations.document', 'certificates.document', 'awards.document', 'languages', 'documents'` to `$with` (check the documents relation name on `Employee` with `grep -n "EmployeeDocument" app/Models/Employee.php`; if none exists, add `public function documents(): HasMany { return $this->hasMany(EmployeeDocument::class)->latest(); }`).

After `$canEditPersonal`:

```php
// Bank & Statutory: the salary structure seen from the profile. Same audience as Money
// (self or director/HR); only director/HR may edit, and a self-view masks the account no.
$canEditSalaryStructure = $this->hasTenantRole($request, ['director', 'hr']);
$bankGate = $canSeeMoney;
// Experience: same audience as Employment (self or management/HR). TP3 figures are money → director/HR only.
$experienceGate = $employmentGate;
$canEditExperience = $canEditPersonal;
```

Return keys after `'familyMembers'`:

```php
'bankGate' => $bankGate,
'canEditSalaryStructure' => $canEditSalaryStructure,
'experienceGate' => $experienceGate,
'canEditExperience' => $canEditExperience,
'openingFigures' => ($experienceGate && $canEditSalaryStructure) ? \App\Models\PayrollOpeningFigure::where('employee_id', $e->id)->orderByDesc('year')->get() : collect(),
'documents' => $experienceGate ? $e->documents : collect(),
```

- [ ] **Step 4: `profile.blade.php`**

Outer `x-data` (line 59): add `, editBank: {{ ($errors->any() && session('form') === 'bank') ? 'true' : 'false' }}` inside the object, and extend the `edit:` condition's exclusion list to `! in_array(session('form'), ['personal', 'family', 'bank'], true) && ! str_starts_with((string) session('form'), 'experience:')`.

`$tabs`, after the Family entry block:

```php
if ($bankGate ?? false) {
    $tabs[] = ['bank', 'Bank & Statutory', 'Bank & Statutori'];
}
if ($experienceGate ?? false) {
    $tabs[] = ['experience', 'Experience', 'Pengalaman'];
}
```

Panels, after the Family panel `@endif`:

```blade
@if ($bankGate ?? false)
    {{-- Bank & Statutory · the salary structure (SalaryStructure) as Worksy shows it --}}
    <div x-show="tab === 'bank'" x-cloak class="uj-tab-stack" style="padding:20px;">@include('partials.profile.bank-tab')</div>
@endif
@if ($experienceGate ?? false)
    {{-- Experience · TP3, previous employment, education, certificates, awards, languages, training, skills --}}
    <div x-show="tab === 'experience'" x-cloak class="uj-tab-stack" style="padding:20px;">@include('partials.profile.experience-tab')</div>
@endif
```

Assets tab: rename the tab entry to `['assets', 'Assets', 'Aset']`, and delete the Training heading + `@forelse ($p->trainingRecords ...)` block from that panel (lines ~721–732). Keep `$tSc`/`$tSl` definitions (the Experience tab reuses them; find where they are defined with `grep -n "tSc = " resources/views/screens/profile.blade.php` and confirm they are set before the tab card).

- [ ] **Step 5: `partials/profile/bank-tab.blade.php`**

```blade
{{-- Bank & Statutory: SalaryStructure read grid + edit modal posting to payroll.salary (back() returns here).
     Expects $p, $canEditSalaryStructure, $fs. --}}
@php
    use App\Support\StatutoryOptions;
    $L = fn ($en, $ms) => '<span x-text="'.e("\$store.ui.lang==='en' ? ".json_encode($en).' : '.json_encode($ms)).'">'.e($en).'</span>';
    $s = $p->salaryStructure;
    $canEdit = $canEditSalaryStructure ?? false;
    $v = fn ($x) => filled($x) ? $x : '—';
    $yn = fn ($b) => $b ? 'Yes' : 'No';
    $acct = $s?->bank_account_no;
    $acctShown = $acct ? ($canEdit ? $acct : '•••• '.substr($acct, -4)) : '—';
    $relief = $s?->child_relief_breakdown ?? [];
    $sections = [
        ['Bank', 'Bank', [
            ['Bank', 'Bank', $v($s?->bank_name)], ['Account No', 'No. Akaun', $acctShown], ['Account Holder', 'Pemegang Akaun', $v($s?->bank_holder_name ?: $p->name)],
        ]],
        ['Income Tax', 'Cukai Pendapatan', [
            ['Tax No', 'No. Cukai', $v($s?->tax_no)], ['Resident', 'Pemastautin', $s ? $yn($s->tax_resident) : '—'],
            ['Employee Status', 'Status Pekerja', StatutoryOptions::EMPLOYEE_TAX_STATUS[$s?->employee_tax_status] ?? '—'],
            ['Tax Category', 'Kategori Cukai', StatutoryOptions::TAX_CATEGORIES[$s?->tax_category] ?? '—'],
            ['Spouse Working', 'Pasangan Bekerja', $s ? $yn($s->spouse_working) : '—'], ['Child Relief Units', 'Unit Pelepasan Anak', $v($s?->children_relief_count)],
            ['Disabled (self)', 'OKU (sendiri)', $s ? $yn($s->disabled_self) : '—'], ['Disabled (spouse)', 'OKU (pasangan)', $s ? $yn($s->disabled_spouse) : '—'],
        ]],
        ['EPF', 'KWSP', [['EPF No', 'No. KWSP', $v($s?->epf_no)], ['Scheme', 'Skim', StatutoryOptions::EPF_SCHEMES[$s?->epf_scheme] ?? '—']]],
        ['SOCSO / EIS', 'PERKESO / SIP', [['SOCSO No', 'No. PERKESO', $v($s?->socso_no)], ['Category', 'Kategori', StatutoryOptions::SOCSO_CATEGORIES[$s?->socso_category] ?? '—']]],
        ['Zakat / CP38 / SKBBK', 'Zakat / CP38 / SKBBK', [
            ['Zakat (monthly)', 'Zakat (bulanan)', $s ? 'RM '.number_format($s->zakat_monthly, 2) : '—'], ['CP38 (monthly)', 'CP38 (bulanan)', $s ? 'RM '.number_format($s->cp38_monthly, 2) : '—'], ['SKBBK', 'SKBBK', $s ? $yn($s->skbbk_opt_in) : '—'],
        ]],
    ];
    $lbl = 'display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;';
    $head = 'font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.6px;';
    $grid = 'display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px 16px;';
    $old = fn (string $k, $default = null) => old($k, $s?->{$k} ?? $default);
    $sel = function (string $name, array $options, $current, bool $keyed) use ($fs) {
        $h = '<select name="'.$name.'" style="'.$fs.'"><option value="">—</option>';
        foreach ($options as $k => $o) { $val = $keyed ? (string) $k : $o; $h .= '<option value="'.e($val).'"'.((string) $current === $val ? ' selected' : '').'>'.e($o).'</option>'; }
        return $h.'</select>';
    };
    $chk = fn (string $name, bool $on) => '<input type="hidden" name="'.$name.'" value="0" /><input type="checkbox" name="'.$name.'" value="1"'.(old($name, $on) ? ' checked' : '').' />';
@endphp

@if (! $s)
    <p style="font-size:12.5px;color:var(--muted);margin:0;">{!! $L('No salary structure set yet.', 'Struktur gaji belum ditetapkan.') !!}</p>
@endif
@if ($canEdit)
    <div style="display:flex;justify-content:flex-end;"><button type="button" @click="editBank = true" class="uj-btn-ghost" style="height:32px;padding:0 14px;font-size:12.5px;">{!! $L($s ? 'Edit' : 'Set up', $s ? 'Sunting' : 'Tetapkan') !!}</button></div>
@endif

@foreach ($sections as [$en, $ms, $rows])
    <div>
        <div style="{{ $head }}margin-bottom:12px;">{!! $L($en, $ms) !!}</div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px 32px;">
            @foreach ($rows as [$ren, $rms, $val])
                <div><div style="font-size:11px;color:var(--muted);margin-bottom:2px;">{!! $L($ren, $rms) !!}</div><div style="font-size:13px;color:var(--ink);">{{ $val }}</div></div>
            @endforeach
        </div>
        @if ($en === 'Income Tax' && $relief)
            <div style="margin-top:10px;font-size:12px;color:var(--body);">
                @foreach (StatutoryOptions::CHILD_RELIEF_CATEGORIES as $key => [$cen, $cms])
                    <div>{!! $L($cen, $cms) !!}: {{ $relief[$key]['100'] ?? 0 }} × 100%, {{ $relief[$key]['50'] ?? 0 }} × 50%</div>
                @endforeach
            </div>
        @endif
    </div>
@endforeach

@if ($canEdit)
    <template x-teleport="body">
    <div x-show="editBank" x-cloak @click.self="editBank = false" @keydown.escape.window="editBank = false"
         style="position:fixed;inset:0;z-index:120;display:flex;padding:40px 16px;background:rgba(18,18,30,.42);overflow-y:auto;">
        <form method="post" action="{{ route('payroll.salary') }}" class="uj-card" style="width:100%;max-width:760px;margin:auto;padding:20px;display:flex;flex-direction:column;gap:14px;max-height:calc(100vh - 80px);overflow-y:auto;">
            @csrf
            <input type="hidden" name="employee_id" value="{{ $p->id }}" />
            <div style="font-size:13px;font-weight:600;color:var(--ink);">{!! $L('Bank & statutory details', 'Butiran bank & statutori') !!} · {{ $p->name }}</div>
            @if ($errors->any() && session('form') === 'bank')<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:8px 11px;">{{ $errors->first() }}</div>@endif
            <div style="{{ $grid }}">
                <div><label style="{{ $lbl }}">{!! $L('Basic salary (RM / month)', 'Gaji pokok (RM / bulan)') !!}</label><input name="basic_salary" type="number" step="0.01" min="0" required value="{{ old('basic_salary', $s ? number_format($s->basic_salary, 2, '.', '') : '') }}" style="{{ $fs }}" /></div>
                <div><label style="{{ $lbl }}">{!! $L('Effective from', 'Berkuat kuasa dari') !!}</label><input name="effective_from" type="date" value="{{ old('effective_from', $s?->effective_from?->toDateString() ?? now()->toDateString()) }}" style="{{ $fs }}" /></div>
            </div>
            <div style="{{ $head }}">{!! $L('Bank', 'Bank') !!}</div>
            <div style="{{ $grid }}">
                <div><label style="{{ $lbl }}">{!! $L('Bank', 'Bank') !!}</label>{!! $sel('bank_name', StatutoryOptions::BANKS, $old('bank_name'), false) !!}</div>
                <div><label style="{{ $lbl }}">{!! $L('Account No', 'No. Akaun') !!}</label><input name="bank_account_no" value="{{ $old('bank_account_no') }}" maxlength="40" style="{{ $fs }}" /></div>
                <div x-data="{ custom: {{ $old('bank_holder_name') ? 'true' : 'false' }} }" style="grid-column:1/-1;">
                    <label style="display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--body);margin-bottom:6px;"><input type="checkbox" x-model="custom" /> {!! $L('Account holder name differs from employee name', 'Nama pemegang akaun berbeza daripada nama pekerja') !!}</label>
                    <input name="bank_holder_name" x-show="custom" :disabled="!custom" value="{{ $old('bank_holder_name') }}" maxlength="160" placeholder="{{ $p->name }}" style="{{ $fs }}" />
                </div>
            </div>
            <div style="{{ $head }}">{!! $L('Income Tax', 'Cukai Pendapatan') !!}</div>
            <div style="{{ $grid }}">
                <div><label style="{{ $lbl }}">{!! $L('Tax No', 'No. Cukai') !!}</label><input name="tax_no" value="{{ $old('tax_no') }}" maxlength="40" style="{{ $fs }}" /></div>
                <div><label style="{{ $lbl }}">{!! $L('Resident', 'Pemastautin') !!}</label>{!! $sel('tax_resident', ['1' => 'Yes', '0' => 'No'], old('tax_resident', $s ? ($s->tax_resident ? '1' : '0') : '1'), true) !!}</div>
                <div><label style="{{ $lbl }}">{!! $L('Employee Status', 'Status Pekerja') !!}</label>{!! $sel('employee_tax_status', StatutoryOptions::EMPLOYEE_TAX_STATUS, $old('employee_tax_status'), true) !!}</div>
                <div><label style="{{ $lbl }}">{!! $L('Tax Category', 'Kategori Cukai') !!}</label>{!! $sel('tax_category', StatutoryOptions::TAX_CATEGORIES, $old('tax_category'), true) !!}</div>
                <div><label style="{{ $lbl }}">{!! $L('Child relief units (PCB)', 'Unit pelepasan anak (PCB)') !!}</label><input name="children_relief_count" type="number" min="0" max="20" value="{{ $old('children_relief_count', 0) }}" style="{{ $fs }}" /></div>
                <label style="display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--body);">{!! $chk('spouse_working', (bool) $s?->spouse_working) !!} {!! $L('Spouse working', 'Pasangan bekerja') !!}</label>
                <label style="display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--body);">{!! $chk('disabled_self', (bool) $s?->disabled_self) !!} {!! $L('Disabled (self)', 'OKU (sendiri)') !!}</label>
                <label style="display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--body);">{!! $chk('disabled_spouse', (bool) $s?->disabled_spouse) !!} {!! $L('Disabled (spouse)', 'OKU (pasangan)') !!}</label>
            </div>
            <div style="font-size:12px;color:var(--muted);">{!! $L('Dependent children by LHDN category (count at 100% and at 50% shared relief). Reference only; PCB uses the relief units above.', 'Anak tanggungan mengikut kategori LHDN (bilangan pada 100% dan 50%). Rujukan sahaja; PCB menggunakan unit pelepasan di atas.') !!}</div>
            <div style="display:grid;grid-template-columns:1fr 90px 90px;gap:8px 12px;align-items:center;font-size:12.5px;">
                <div></div><div style="{{ $head }}">100%</div><div style="{{ $head }}">50%</div>
                @foreach (StatutoryOptions::CHILD_RELIEF_CATEGORIES as $key => [$cen, $cms])
                    <div>{!! $L($cen, $cms) !!}</div>
                    <input type="number" min="0" max="20" name="child_relief[{{ $key }}][100]" value="{{ old("child_relief.$key.100", $relief[$key]['100'] ?? 0) }}" style="{{ $fs }}" />
                    <input type="number" min="0" max="20" name="child_relief[{{ $key }}][50]" value="{{ old("child_relief.$key.50", $relief[$key]['50'] ?? 0) }}" style="{{ $fs }}" />
                @endforeach
            </div>
            <div style="{{ $head }}">EPF · SOCSO / EIS</div>
            <div style="{{ $grid }}">
                <div><label style="{{ $lbl }}">{!! $L('EPF No', 'No. KWSP') !!}</label><input name="epf_no" value="{{ $old('epf_no') }}" maxlength="40" style="{{ $fs }}" /></div>
                <div><label style="{{ $lbl }}">{!! $L('EPF Scheme', 'Skim KWSP') !!}</label>{!! $sel('epf_scheme', StatutoryOptions::EPF_SCHEMES, $old('epf_scheme'), true) !!}</div>
                <div><label style="{{ $lbl }}">{!! $L('SOCSO No', 'No. PERKESO') !!}</label><input name="socso_no" value="{{ $old('socso_no') }}" maxlength="40" style="{{ $fs }}" /></div>
                <div><label style="{{ $lbl }}">{!! $L('SOCSO Category', 'Kategori PERKESO') !!}</label>{!! $sel('socso_category', StatutoryOptions::SOCSO_CATEGORIES, $old('socso_category'), true) !!}</div>
                <div><label style="{{ $lbl }}">{!! $L('Nationality (statutory)', 'Kewarganegaraan (statutori)') !!}</label>{!! $sel('nationality', ['citizen' => 'Citizen', 'pr' => 'Permanent resident', 'foreign' => 'Foreign'], $old('nationality', 'citizen'), true) !!}</div>
            </div>
            <div style="{{ $head }}">Zakat · CP38 · SKBBK</div>
            <div style="{{ $grid }}">
                <div><label style="{{ $lbl }}">{!! $L('Zakat (RM / month)', 'Zakat (RM / bulan)') !!}</label><input name="zakat_monthly" type="number" step="0.01" min="0" value="{{ $old('zakat_monthly', 0) }}" style="{{ $fs }}" /></div>
                <div><label style="{{ $lbl }}">{!! $L('CP38 (RM / month)', 'CP38 (RM / bulan)') !!}</label><input name="cp38_monthly" type="number" step="0.01" min="0" value="{{ $old('cp38_monthly', 0) }}" style="{{ $fs }}" /></div>
                <label style="display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--body);">{!! $chk('skbbk_opt_in', (bool) $s?->skbbk_opt_in) !!} SKBBK</label>
            </div>
            <div style="display:flex;gap:8px;justify-content:flex-end;">
                <button type="button" @click="editBank = false" class="uj-btn-ghost" style="height:40px;padding:0 16px;font-size:13px;">{!! $L('Cancel', 'Batal') !!}</button>
                <button type="submit" class="uj-btn-primary" style="height:40px;padding:0 18px;font-size:13px;">{!! $L('Save changes', 'Simpan perubahan') !!}</button>
            </div>
        </form>
    </div>
    </template>
@endif
```

For the `session('form') === 'bank'` reopen to work, `PayrollController::storeSalary` must flash it on validation failure. Since it uses `$request->validate(...)`, wrap: `$validator = validator($request->all(), $rules); if ($validator->fails()) { session()->flash('form', 'bank'); throw new ValidationException($validator); } $data = $validator->validated();` (the payroll screen ignores that flash, harmless). Do this in Task 2 while editing the method; the invalid-options test still passes.

- [ ] **Step 6: `partials/profile/experience-tab.blade.php`**

```blade
{{-- Experience: TP3 (director/HR), previous employment, education, certificates, awards, languages, training, skills.
     Expects $p, $canEditExperience, $canEditSalaryStructure, $openingFigures, $documents, $skills, $fs, $tSc, $tSl. --}}
@php
    use App\Support\ExperienceOptions;
    $L = fn ($en, $ms) => '<span x-text="'.e("\$store.ui.lang==='en' ? ".json_encode($en).' : '.json_encode($ms)).'">'.e($en).'</span>';
    $lbl = 'display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;';
    $head = 'font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.6px;';
    $canEdit = $canEditExperience ?? false;
    $form = (string) session('form');
    $d = fn ($v) => $v?->format('d/m/Y');
    // One-line summary per row type for the collapsed card.
    $summary = fn (string $type, $r) => match ($type) {
        'work' => trim(($r->position_held ?? $r->joined_as ?? '').' · '.($d($r->joined_on) ?? '?').' – '.($d($r->resigned_on) ?? 'present'), ' ·'),
        'education' => trim((ExperienceOptions::QUALIFICATIONS[$r->qualification_type] ?? '').($r->major ? ' in '.$r->major : '').' · '.($r->from_year ?? '?').' – '.($r->to_year ?? '?'), ' ·'),
        'certificate' => trim(($r->awarded_by ?? '').($r->awarded_on ? ' · '.$d($r->awarded_on) : '').($r->expires_on ? ' · expires '.$d($r->expires_on) : ''), ' ·'),
        'award' => (string) ($r->year ?? ''),
        'language' => 'S '.ucfirst($r->speaking ?? '-').' · R '.ucfirst($r->reading ?? '-').' · W '.ucfirst($r->writing ?? '-'),
    };
    $title = fn (string $type, $r) => $r->company ?? $r->institute ?? $r->name ?? $r->title ?? $r->language ?? '—';
@endphp

@if ($canEditSalaryStructure ?? false)
    {{-- TP3 / previous employment figures: one PayrollOpeningFigure per year, posted to payroll.opening (back() returns here). --}}
    <div style="display:flex;flex-direction:column;gap:10px;">
        <div style="{{ $head }}">{!! $L('Previous Employment Figures (TP3)', 'Angka Pekerjaan Terdahulu (TP3)') !!}</div>
        @foreach ($openingFigures as $o)
            <div class="uj-card" x-data="{ open: false }" style="padding:12px 14px;">
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;font-size:12.5px;color:var(--body);">
                    <strong style="color:var(--ink);">{{ $o->year }}</strong>
                    <span>{!! $L('Gross', 'Kasar') !!} RM {{ number_format($o->gross, 2) }}</span><span>PCB RM {{ number_format($o->pcb_paid, 2) }}</span><span>EPF RM {{ number_format($o->epf, 2) }}</span><span>SOCSO RM {{ number_format($o->socso, 2) }}</span><span>EIS RM {{ number_format($o->eis, 2) }}</span><span>Zakat RM {{ number_format($o->zakat_paid, 2) }}</span>
                    <button type="button" @click="open = !open" class="uj-btn-ghost" style="margin-left:auto;height:28px;padding:0 10px;font-size:12px;">{!! $L('Edit', 'Sunting') !!}</button>
                </div>
                <div x-show="open" x-cloak style="margin-top:12px;">@include('partials.profile.opening-form', ['o' => $o])</div>
            </div>
        @endforeach
        <div x-data="{ add: false }">
            <button type="button" @click="add = !add" class="uj-btn-ghost" style="height:32px;padding:0 12px;font-size:12px;">+ {!! $L('Add year', 'Tambah tahun') !!}</button>
            <div x-show="add" x-cloak class="uj-card" style="margin-top:8px;padding:14px;">@include('partials.profile.opening-form', ['o' => null])</div>
        </div>
    </div>
@endif

@foreach (ExperienceOptions::TYPES as $type => [$class, $hen, $hms, $relation])
    @php $rows = $p->{$relation}; $addOpen = $form === 'experience:'.$type && ! old('_row'); @endphp
    <div style="display:flex;flex-direction:column;gap:10px;">
        <div style="{{ $head }}">{!! $L($hen, $hms) !!}</div>
        @forelse ($rows as $r)
            <div class="uj-card" x-data="{ open: {{ ($form === 'experience:'.$type && old('_row') == $r->id) ? 'true' : 'false' }} }" style="padding:12px 14px;">
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    <span style="font-size:13px;font-weight:600;color:var(--ink);">{{ $title($type, $r) }}</span>
                    <span style="font-size:12px;color:var(--muted);">{{ $summary($type, $r) }}</span>
                    @if (isset($r->document_id) && $r->document)<a href="{{ route('documents.download', $r->document) }}" style="font-size:12px;">📎 {{ $r->document->title }}</a>@endif
                    @if ($canEdit)<button type="button" @click="open = !open" class="uj-btn-ghost" style="margin-left:auto;height:28px;padding:0 10px;font-size:12px;">{!! $L('Edit', 'Sunting') !!}</button>@endif
                </div>
                @if ($canEdit)
                    <div x-show="open" x-cloak style="margin-top:12px;">
                        @include('partials.profile.experience-form', ['type' => $type, 'r' => $r, 'action' => route('employees.experience.update', [$type, $r->id])])
                        <form method="post" action="{{ route('employees.experience.destroy', [$type, $r->id]) }}" onsubmit="return confirm('Remove this record?')" style="margin-top:6px;">@csrf<button type="submit" class="uj-btn-ghost" style="height:32px;padding:0 12px;font-size:12px;color:var(--red);">{!! $L('Remove', 'Buang') !!}</button></form>
                    </div>
                @endif
            </div>
        @empty
            <p style="font-size:12.5px;color:var(--muted);margin:0;">{!! $L('No record found', 'Tiada rekod') !!}</p>
        @endforelse
        @if ($canEdit)
            <div x-data="{ add: {{ $addOpen ? 'true' : 'false' }} }">
                <button type="button" @click="add = !add" class="uj-btn-ghost" style="height:32px;padding:0 12px;font-size:12px;">+ {!! $L('Add', 'Tambah') !!}</button>
                <div x-show="add" x-cloak class="uj-card" style="margin-top:8px;padding:14px;">
                    @include('partials.profile.experience-form', ['type' => $type, 'r' => null, 'action' => route('employees.experience.store', [$p, $type])])
                </div>
            </div>
        @endif
    </div>
@endforeach

<div>
    <div style="{{ $head }}margin-bottom:10px;">{!! $L('Training', 'Latihan') !!}</div>
    @forelse ($p->trainingRecords as $r)
        @php $isOverdue = $r->status !== 'completed' && $r->due_at && $r->due_at->isPast(); @endphp
        <div class="uj-row" style="display:flex;align-items:center;gap:12px;padding:10px 0;border-top:1px solid var(--hairline-soft);">
            <div style="flex:1;min-width:0;"><div style="font-size:13px;color:var(--ink);font-weight:500;">{{ $r->course }}</div><div style="font-size:11.5px;color:var(--muted);">{{ $r->provider }}@if ($r->mandatory) · <span style="color:var(--red);font-weight:600;">Mandatory</span>@endif</div></div>
            <span style="font-size:12px;font-family:var(--font-mono);color:{{ $isOverdue ? 'var(--error)' : 'var(--muted)' }};white-space:nowrap;">{{ $r->due_at?->format('j M Y') ?? '—' }}{{ $isOverdue ? ' ⚠' : '' }}</span>
            <span style="display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:{{ $tSc[$r->status] ?? 'var(--muted)' }};white-space:nowrap;"><span style="width:8px;height:8px;border-radius:50%;background:{{ $tSc[$r->status] ?? 'var(--muted)' }};"></span>{{ $tSl[$r->status] ?? ucfirst($r->status) }}</span>
        </div>
    @empty
        <p style="font-size:12.5px;color:var(--muted);margin:0;" x-text="$store.ui.lang==='en' ? 'No training records.' : 'Tiada rekod latihan.'">No training records.</p>
    @endforelse
</div>

<div>
    <div style="{{ $head }}margin-bottom:10px;">{!! $L('Skills', 'Kemahiran') !!}</div>
    @forelse ($skills ?? [] as $es)
        <div class="uj-row" style="display:flex;align-items:center;gap:10px;padding:10px 0;border-top:1px solid var(--hairline-soft);">
            <span style="flex-shrink:0;width:8px;height:8px;border-radius:50%;background:{{ $es->verified ? 'var(--success)' : 'var(--muted-soft)' }};"></span>
            <div style="flex:1;min-width:0;"><div style="font-size:13px;color:var(--ink);font-weight:500;">{{ $es->skill?->name ?? '—' }}</div><div style="font-size:11.5px;color:var(--muted);">{{ $es->level_label }}</div></div>
        </div>
    @empty
        <p style="font-size:12.5px;color:var(--muted);margin:0;">{!! $L('No skills recorded.', 'Tiada kemahiran direkodkan.') !!}</p>
    @endforelse
</div>
```

Check the document download route name with `php artisan route:list --name=documents`; use whatever serves a file, or drop the link and show the title only if none exists.

`partials/profile/opening-form.blade.php` (TP3 row form, `$o` null = add):

```blade
@php $ov = fn (string $k, $default = '') => $o?->{$k} ?? $default; $num = fn (string $k) => '<input type="number" step="0.01" min="0" name="'.$k.'" value="'.e($o ? number_format((float) $o->{$k}, 2, '.', '') : '').'" style="'.$fs.'" />'; @endphp
<form method="post" action="{{ route('payroll.opening') }}" style="display:flex;flex-direction:column;gap:10px;">
    @csrf
    <input type="hidden" name="employee_id" value="{{ $p->id }}" />
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px 14px;">
        <div><label style="{{ $lbl }}">{!! $L('Year', 'Tahun') !!}</label><input type="number" name="year" required min="2000" max="2100" value="{{ $ov('year', now()->year) }}" {{ $o ? 'readonly' : '' }} style="{{ $fs }}" /></div>
        <div><label style="{{ $lbl }}">{!! $L('Gross', 'Kasar') !!}</label>{!! $num('gross') !!}</div>
        <div><label style="{{ $lbl }}">{!! $L('Income tax (PCB)', 'Cukai (PCB)') !!}</label>{!! $num('pcb_paid') !!}</div>
        <div><label style="{{ $lbl }}">{!! $L('Employee EPF', 'KWSP pekerja') !!}</label>{!! $num('epf') !!}</div>
        <div><label style="{{ $lbl }}">SOCSO</label>{!! $num('socso') !!}</div>
        <div><label style="{{ $lbl }}">EIS</label>{!! $num('eis') !!}</div>
        <div><label style="{{ $lbl }}">Zakat</label>{!! $num('zakat_paid') !!}</div>
        <div><label style="{{ $lbl }}">{!! $L('Additional gross', 'Kasar tambahan') !!}</label>{!! $num('additional_gross') !!}</div>
        <div><label style="{{ $lbl }}">{!! $L('Additional EPF', 'KWSP tambahan') !!}</label>{!! $num('additional_epf') !!}</div>
        <div><label style="{{ $lbl }}">{!! $L('Optional deductions', 'Potongan pilihan') !!}</label>{!! $num('optional_deductions') !!}</div>
        <div><label style="{{ $lbl }}">{!! $L('Exempt allowances', 'Elaun dikecualikan') !!}</label>{!! $num('exempt_allowances') !!}</div>
        <div><label style="{{ $lbl }}">{!! $L('Previous employer', 'Majikan terdahulu') !!}</label><input name="previous_employer" value="{{ $ov('previous_employer') }}" maxlength="120" style="{{ $fs }}" /></div>
        <div><label style="{{ $lbl }}">{!! $L('Employer TIN', 'TIN majikan') !!}</label><input name="previous_employer_tin" value="{{ $ov('previous_employer_tin') }}" maxlength="40" style="{{ $fs }}" /></div>
    </div>
    <button type="submit" class="uj-btn-primary" style="height:36px;padding:0 16px;font-size:13px;align-self:flex-start;">{!! $L('Save', 'Simpan') !!}</button>
</form>
```

- [ ] **Step 7: `partials/profile/experience-form.blade.php`** (`$type`, `$r` null = add, `$action`; inherits `$L`, `$lbl`, `$fs`, `$documents`)

```blade
@php
    use App\Support\ExperienceOptions;
    $isMine = session('form') === 'experience:'.$type && ($r ? old('_row') == $r->id : ! old('_row'));
    $o = fn (string $k, $default = null) => $isMine ? old($k, $default) : ($r?->{$k} ?? $default);
    $od = fn (string $k) => $isMine ? old($k) : $r?->{$k}?->toDateString();
    $in = fn (string $k, string $en, string $ms, string $type = 'text', string $extra = '') => '<div><label style="'.$lbl.'">'.$L($en, $ms).'</label><input type="'.$type.'" name="'.$k.'" value="'.e($type === 'date' ? $od($k) : $o($k)).'" '.$extra.' style="'.$fs.'" /></div>';
    $sel = function (string $k, string $en, string $ms, array $options, bool $keyed) use ($L, $lbl, $fs, $o) {
        $h = '<div><label style="'.$lbl.'">'.$L($en, $ms).'</label><select name="'.$k.'" style="'.$fs.'"><option value="">—</option>';
        foreach ($options as $key => $label) { $val = $keyed ? (string) $key : $label; $h .= '<option value="'.e($val).'"'.((string) $o($k) === $val ? ' selected' : '').'>'.e($keyed ? $label : ucfirst($label)).'</option>'; }
        return $h.'</select></div>';
    };
    $docs = function () use ($sel, $documents) { return $sel('document_id', 'Attachment (from Documents)', 'Lampiran (daripada Dokumen)', $documents->pluck('title', 'id')->all(), true); };
@endphp
<form method="post" action="{{ $action }}" style="display:flex;flex-direction:column;gap:10px;">
    @csrf
    @if ($r)<input type="hidden" name="_row" value="{{ $r->id }}" />@endif
    @if ($isMine && $errors->any())<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:8px 11px;">{{ $errors->first() }}</div>@endif
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px 14px;">
        @switch($type)
            @case('work')
                {!! $in('company', 'Company', 'Syarikat', 'text', 'required maxlength="160"') !!}
                {!! $in('industry', 'Industry', 'Industri', 'text', 'maxlength="120"') !!}
                {!! $in('joined_on', 'Joined on', 'Tarikh masuk', 'date') !!}
                {!! $in('joined_as', 'Joined as', 'Jawatan masuk', 'text', 'maxlength="120"') !!}
                {!! $in('resigned_on', 'Resigned on', 'Tarikh berhenti', 'date') !!}
                {!! $in('position_held', 'Last position held', 'Jawatan terakhir', 'text', 'maxlength="120"') !!}
                {!! $in('last_drawn_salary', 'Last drawn salary (RM)', 'Gaji terakhir (RM)', 'number', 'step="0.01" min="0"') !!}
                {!! $sel('salary_type', 'Salary type', 'Jenis gaji', ExperienceOptions::SALARY_TYPES, false) !!}
                <div style="grid-column:1/-1;">{!! $in('address', 'Address', 'Alamat', 'text', 'maxlength="255"') !!}</div>
                <div style="grid-column:1/-1;">{!! $in('reason_to_leave', 'Reason to leave', 'Sebab berhenti', 'text', 'maxlength="255"') !!}</div>
                @break
            @case('education')
                {!! $sel('qualification_type', 'Qualification', 'Kelayakan', ExperienceOptions::QUALIFICATIONS, true) !!}
                {!! $in('major', 'Major', 'Pengkhususan', 'text', 'maxlength="160"') !!}
                {!! $in('institute', 'Institute', 'Institusi', 'text', 'maxlength="160"') !!}
                {!! $in('from_year', 'From year', 'Dari tahun', 'number', 'min="1950" max="2100"') !!}
                {!! $in('to_year', 'To year', 'Hingga tahun', 'number', 'min="1950" max="2100"') !!}
                {!! $sel('honours', 'Honours', 'Kepujian', ExperienceOptions::HONOURS, true) !!}
                {!! $in('cgpa', 'CGPA', 'PNGK', 'number', 'step="0.01" min="0" max="4"') !!}
                {!! $docs() !!}
                <div style="grid-column:1/-1;">{!! $in('remark', 'Remark', 'Catatan', 'text', 'maxlength="500"') !!}</div>
                @break
            @case('certificate')
                {!! $in('name', 'Certificate', 'Sijil', 'text', 'required maxlength="160"') !!}
                {!! $in('category', 'Category', 'Kategori', 'text', 'maxlength="120"') !!}
                {!! $in('awarded_by', 'Awarded by', 'Dianugerahkan oleh', 'text', 'maxlength="160"') !!}
                {!! $in('awarded_on', 'Awarded on', 'Tarikh anugerah', 'date') !!}
                {!! $in('expires_on', 'Expires on', 'Tarikh tamat', 'date') !!}
                {!! $docs() !!}
                <div style="grid-column:1/-1;">{!! $in('remark', 'Remark', 'Catatan', 'text', 'maxlength="500"') !!}</div>
                @break
            @case('award')
                {!! $in('title', 'Title', 'Tajuk', 'text', 'required maxlength="160"') !!}
                {!! $in('year', 'Year', 'Tahun', 'number', 'min="1950" max="2100"') !!}
                {!! $docs() !!}
                <div style="grid-column:1/-1;">{!! $in('remark', 'Remark', 'Catatan', 'text', 'maxlength="500"') !!}</div>
                @break
            @case('language')
                {!! $in('language', 'Language', 'Bahasa', 'text', 'required maxlength="80"') !!}
                {!! $sel('speaking', 'Speaking', 'Pertuturan', ExperienceOptions::PROFICIENCY, false) !!}
                {!! $sel('reading', 'Reading', 'Bacaan', ExperienceOptions::PROFICIENCY, false) !!}
                {!! $sel('writing', 'Writing', 'Penulisan', ExperienceOptions::PROFICIENCY, false) !!}
                @break
        @endswitch
    </div>
    <button type="submit" class="uj-btn-primary" style="height:36px;padding:0 16px;font-size:13px;align-self:flex-start;">{!! $L('Save', 'Simpan') !!}</button>
</form>
```

- [ ] **Step 8: Remaining `BankStatutoryTabTest` tests** (masking is covered in `ProfileExperienceTabTest`; add here the payroll-unchanged guard):

```php
public function test_existing_payroll_salary_form_still_saves_without_new_fields(): void
{
    $this->login('hr');
    $e = $this->emp('Adibah');
    $this->post('/app/payroll/salary', ['employee_id' => $e->id, 'basic_salary' => 2500])->assertRedirect();
    $s = SalaryStructure::where('employee_id', $e->id)->firstOrFail();
    $this->assertTrue($s->tax_resident);
    $this->assertNull($s->child_relief_breakdown);
    $this->assertSame(0, $s->children_relief_count);
}
```

- [ ] **Step 9: Run** `php artisan test --compact tests/Feature/ProfileExperienceTabTest.php tests/Feature/BankStatutoryTabTest.php tests/Feature/ProfilePersonalTabsTest.php tests/Feature/ProfileEmploymentTabsTest.php tests/Feature/AllScreensRenderTest.php` → PASS. Pint.
- [ ] **Step 10: Commit** `git add app resources routes tests && git commit -m "feat(profile): Bank & Statutory and Experience tabs"`.

---

### Task 5: Dev DB migrate, browser walk, assets, full suite

- [ ] **Step 1:** `lerd artisan migrate`.
- [ ] **Step 2:** `lerd artisan view:clear && lerd artisan view:cache && bun run build`.
- [ ] **Step 3: Browser walk** (headless Chromium via `/home/shzwn/Projects/Asas/node_modules/playwright-core`, executable `~/.cache/ms-playwright/chromium-1234/chrome-linux64/chrome`; remove `.uj-modal-scrim` before clicking; submit with `form.requestSubmit()` selected by `action`; screenshot the tab card element, not `fullPage`, since `<main>` scrolls internally). Save to `~/mockups/bank-experience-walk/`:
  1. HR → emp 2 → Bank & Statutory: read grid, Edit modal, save with a bank + tax category → lands on `?tab=bank` with values.
  2. Experience: add a TP3 year, add one of each record type, edit one, remove one; training + skills lists render.
  3. Employee (shazwanshah) → own Bank tab shows masked account, no Edit button; Experience shows Add buttons, no TP3.
  4. Manager (kussairi) → a report (emp 6): neither tab.
  5. Payroll screen → "Edit on profile" link on a salary row goes to `?tab=bank`.
  Then revert the walk's data changes on emp 2 via tinker (salary structure fields set, opening figure year, "Walk *" records).
- [ ] **Step 4:** `php artisan test --compact` whole suite.
- [ ] **Step 5:** `git add public/build && git commit -m "build: assets for Bank & Statutory and Experience tabs"` (skip if `git status` shows no build change).
- [ ] **Step 6:** Report; next is sub-project 4 (Work + Attachment).

---

## Self-review against spec section 3

| Spec item | Task |
|---|---|
| Salary-structure form on the profile, same model/validation/gate; Payroll keeps a link | 2, 4 |
| Sections Bank / Income Tax / EPF / SOCSO-EIS / Zakat-CP38-SKBBK; bank list constant; custom holder toggle; four LHDN child categories × 100/50 | 1, 4 |
| 7 new `salary_structures` columns | 1, 2 |
| Self-view read-only, account masked to last four | 4 |
| TP3 relocated (per-year figures, HR/director only) | 4 |
| Five new tables with the listed columns; attachment = employee_documents id | 1, 3 |
| Training + Skills rendered on Experience; Assets tab loses training | 4 |
| Employee adds own records; HR edits all | 3, 4 |
| Tests: save from new location, payroll unchanged, TP3 round-trip, CRUD + scoping, masking | 2, 3, 4 |

Known deviation: TP3 is per-year totals (the existing `payroll_opening_figures` shape), not Worksy's per-month grid. Changing the shape would touch PCB inputs and is out of scope for a "relocate".
