# Personal + Family Tabs Implementation Plan (sub-project 2)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Worksy's Personal and Family tabs to the employee profile, editable by HR/management and by the employee on their own record (NRIC and passport HR-only), with family members stored in a new table.

**Architecture:** New `employees` columns for the personal fields plus one new table `employee_family_members`. Two controllers (`PersonalRecordController::update`, `FamilyMemberController` store/update/destroy) each re-check tenant ownership and the caller's right to edit that person. Two Blade partials plug into the existing profile tab row exactly the way the Employment/Timeline tabs did in sub-project 1 (`$tabs` list + `x-show` panels + `?tab=` deep link). Option lists live in `App\Support\PersonalOptions` constants.

**Tech Stack:** Laravel 13 / PHP 8.5, Blade + Alpine, PHPUnit (sqlite), Pint. Dev DB via `lerd artisan migrate`. Assets via `lerd artisan view:clear && lerd artisan view:cache && bun run build`.

**Spec:** `docs/superpowers/specs/2026-09-15-employee-record-and-progression-design.md` section 2 and "Shared rules".

## Global Constraints

- Edit roles = `hr`, `director`, `management` via `hasTenantRole($request, ['management','hr'])` (director collapses to management). Employee edits own Personal and Family only. `manager` sees neither tab on anyone else.
- NRIC and passport (`nric`, `passport_no`, `passport_expiry`, `permit_no`, `permit_expiry`) are HR/management-only writes. Employee self-edit silently ignores them (do not error; drop the keys).
- Every controller re-checks `$employee->tenant_id === app(CurrentTenant::class)->id()`; route-model binding is not tenant-safe.
- Every write calls `AuditLog::record(string $action, ?string $target)`.
- Migrations additive only. Enum-like columns are strings with app-level validation.
- Bilingual labels via the `$L` helper pattern (see Task 4), which HTML-escapes the Alpine expression: `'<span x-text="'.e("\$store.ui.lang==='en' ? ".json_encode($en).' : '.json_encode($ms)).'">'.e($en).'</span>'`.
- After a save the screen returns to the same tab: redirect to `route('app.screen','profile').'?emp='.$id.'&tab=personal'` (or `family`); the tab card already reads `?tab=`.
- Never bare `git stash`. Commit after each task.

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

/** Logs in as $role and returns that user's own employee row. Each role once per test (unique email). */
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
| `database/migrations/2026_09_27_100000_add_personal_columns_to_employees.php` | 18 new nullable columns |
| `database/migrations/2026_09_27_100100_create_employee_family_members_table.php` | new table |
| `app/Support/PersonalOptions.php` | RELIGIONS, RACES, NATIONALITIES, MARITAL, BLOOD_TYPES, RELATIONS, OCCUPATIONS, EDUCATION constants |
| `app/Models/Employee.php` | `PERSONAL_FIELDS`, `IDENTITY_FIELDS`, casts, `familyMembers()` |
| `app/Models/EmployeeFamilyMember.php` | model, `BelongsToTenant` |
| `app/Http/Controllers/PersonalRecordController.php` | `update` |
| `app/Http/Controllers/FamilyMemberController.php` | `store`, `update`, `destroy` |
| `app/Http/Controllers/Concerns/BuildsPeopleData.php` | `$personalGate`, `$canEditPersonal`, `$canEditIdentity`, `$familyMembers` |
| `resources/views/partials/profile/personal-tab.blade.php` | read grid + edit modal |
| `resources/views/partials/profile/family-tab.blade.php` | four sections, row forms |
| `resources/views/screens/profile.blade.php` | tab entries + panels + `editPersonal` flag |
| `routes/web.php` | 4 routes |
| `tests/Feature/PersonalRecordTest.php`, `tests/Feature/FamilyMemberTest.php`, `tests/Feature/ProfilePersonalTabsTest.php` | tests |

---

### Task 1: Schema and option lists

**Files:**
- Create: `database/migrations/2026_09_27_100000_add_personal_columns_to_employees.php`
- Create: `database/migrations/2026_09_27_100100_create_employee_family_members_table.php`
- Create: `app/Support/PersonalOptions.php`
- Create: `app/Models/EmployeeFamilyMember.php`
- Modify: `app/Models/Employee.php` (casts near line 135, add constants + relation)
- Test: `tests/Feature/PersonalSchemaTest.php`

**Produces:** `Employee::PERSONAL_FIELDS` (array of the employee-editable column names), `Employee::IDENTITY_FIELDS` (HR-only), `Employee::familyMembers(): HasMany`, `EmployeeFamilyMember` model, `PersonalOptions::*` constants.

- [ ] **Step 1: Failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeFamilyMember;
use App\Models\Tenant;
use App\Support\PersonalOptions;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PersonalSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_columns_table_and_options_exist(): void
    {
        foreach (['first_name', 'last_name', 'full_name_ic', 'religion', 'race', 'nationality', 'blood_type', 'personal_email', 'address_2', 'city', 'state', 'postcode', 'country', 'emergency_contact_relationship', 'passport_no', 'passport_expiry', 'permit_no', 'permit_expiry'] as $c) {
            $this->assertTrue(Schema::hasColumn('employees', $c), $c);
        }
        $this->assertTrue(Schema::hasTable('employee_family_members'));

        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        app(CurrentTenant::class)->set($tenant);
        $e = Employee::create(['tenant_id' => $tenant->id, 'name' => 'A', 'status' => 'active', 'workload' => 'green']);
        $this->assertSame('Malaysia', $e->fresh()->country);

        $m = $e->familyMembers()->create(['tenant_id' => $tenant->id, 'relation' => 'father', 'name' => 'Pak', 'date_of_birth' => '1960-02-03']);
        $this->assertInstanceOf(EmployeeFamilyMember::class, $m);
        $this->assertSame('1960-02-03', $m->fresh()->date_of_birth->toDateString());

        $this->assertContains('Malaysian', PersonalOptions::NATIONALITIES);
        $this->assertContains('Malay', PersonalOptions::RACES);
        $this->assertSame(['father', 'mother', 'spouse', 'child', 'dependent'], PersonalOptions::RELATIONS);
        $this->assertSame(['student', 'unemployed', 'working'], PersonalOptions::OCCUPATIONS);
    }
}
```

- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/PersonalSchemaTest.php` → FAIL (column missing).

- [ ] **Step 3: Migrations**

`2026_09_27_100000_add_personal_columns_to_employees.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COLUMNS = ['first_name', 'last_name', 'full_name_ic', 'religion', 'race', 'nationality', 'blood_type', 'personal_email', 'address_2', 'city', 'state', 'postcode', 'country', 'emergency_contact_relationship', 'passport_no', 'passport_expiry', 'permit_no', 'permit_expiry'];

    /** Worksy Personal tab fields. Everything nullable; country defaults to Malaysia. */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('first_name', 120)->nullable();
            $table->string('last_name', 120)->nullable();
            $table->string('full_name_ic', 200)->nullable();
            $table->string('religion', 40)->nullable();
            $table->string('race', 60)->nullable();
            $table->string('nationality', 80)->nullable();
            $table->string('blood_type', 4)->nullable();
            $table->string('personal_email', 190)->nullable();
            $table->string('address_2', 255)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('postcode', 12)->nullable();
            $table->string('country', 80)->nullable()->default('Malaysia');
            $table->string('emergency_contact_relationship', 60)->nullable();
            $table->string('passport_no', 40)->nullable();
            $table->date('passport_expiry')->nullable();
            $table->string('permit_no', 60)->nullable();
            $table->date('permit_expiry')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('employees', fn (Blueprint $table) => $table->dropColumn(self::COLUMNS));
    }
};
```

`2026_09_27_100100_create_employee_family_members_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Worksy Family tab: parents, spouse, children, other dependents. One row each. */
    public function up(): void
    {
        Schema::create('employee_family_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('relation', 16); // father, mother, spouse, child, dependent
            $table->string('name', 160);
            $table->string('phone', 40)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('nric', 40)->nullable();
            $table->string('occupation', 16)->nullable(); // student, unemployed, working
            $table->string('employer_name', 160)->nullable(); // spouse
            $table->date('marriage_date')->nullable(); // spouse
            $table->string('education', 80)->nullable(); // child
            $table->string('gender', 10)->nullable();
            $table->string('nationality', 80)->nullable();
            $table->boolean('deceased')->default(false);
            $table->string('address', 255)->nullable();
            $table->text('remark')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'relation']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_family_members');
    }
};
```

- [ ] **Step 4: `app/Support/PersonalOptions.php`**

```php
<?php

declare(strict_types=1);

namespace App\Support;

/** Fixed option lists for the Personal and Family tabs (Worksy lists). Not tenant-editable. */
final class PersonalOptions
{
    public const RELIGIONS = ['Islam', 'Buddhism', 'Christianity', 'Hinduism', 'Sikhism', 'Taoism', 'Other', 'None'];

    public const RACES = ['Malay', 'Chinese', 'Indian', 'Bumiputera Sabah', 'Bumiputera Sarawak', 'Orang Asli', 'Eurasian', 'Other'];

    public const BLOOD_TYPES = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

    /** value => [en, ms]. Matches WelcomeWizardController plus Worksy's "Living Together". */
    public const MARITAL = [
        'single' => ['Single', 'Bujang'],
        'married' => ['Married', 'Berkahwin'],
        'living_together' => ['Living Together', 'Tinggal Bersama'],
        'divorced' => ['Divorced', 'Bercerai'],
        'widowed' => ['Widowed', 'Balu/Duda'],
    ];

    public const RELATIONS = ['father', 'mother', 'spouse', 'child', 'dependent'];

    public const OCCUPATIONS = ['student', 'unemployed', 'working'];

    public const EDUCATION = ['None', 'Pre-school', 'Primary', 'Secondary', 'Certificate', 'Diploma', 'Degree', 'Master', 'PhD'];

    public const NATIONALITIES = [
        'Malaysian', 'Afghan', 'Albanian', 'Algerian', 'American', 'Argentine', 'Australian', 'Austrian', 'Bangladeshi', 'Belgian', 'Bhutanese', 'Bosnian', 'Brazilian', 'British', 'Bruneian', 'Bulgarian', 'Cambodian', 'Cameroonian', 'Canadian', 'Chilean', 'Chinese', 'Colombian', 'Croatian', 'Cuban', 'Czech', 'Danish', 'Dutch', 'Egyptian', 'Emirati', 'Ethiopian', 'Filipino', 'Finnish', 'French', 'German', 'Ghanaian', 'Greek', 'Hong Konger', 'Hungarian', 'Indian', 'Indonesian', 'Iranian', 'Iraqi', 'Irish', 'Israeli', 'Italian', 'Japanese', 'Jordanian', 'Kazakh', 'Kenyan', 'Korean', 'Kuwaiti', 'Laotian', 'Lebanese', 'Libyan', 'Macanese', 'Maldivian', 'Mexican', 'Mongolian', 'Moroccan', 'Myanmar', 'Nepalese', 'New Zealander', 'Nigerian', 'Norwegian', 'Omani', 'Pakistani', 'Palestinian', 'Peruvian', 'Polish', 'Portuguese', 'Qatari', 'Romanian', 'Russian', 'Saudi', 'Senegalese', 'Serbian', 'Singaporean', 'Slovak', 'Somali', 'South African', 'Spanish', 'Sri Lankan', 'Sudanese', 'Swedish', 'Swiss', 'Syrian', 'Taiwanese', 'Tanzanian', 'Thai', 'Timorese', 'Tunisian', 'Turkish', 'Ugandan', 'Ukrainian', 'Uzbek', 'Venezuelan', 'Vietnamese', 'Yemeni', 'Zimbabwean', 'Other',
    ];
}
```

- [ ] **Step 5: Model** `app/Models/EmployeeFamilyMember.php`

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeFamilyMember extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['date_of_birth' => 'date', 'marriage_date' => 'date', 'deceased' => 'boolean'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
```

Check the trait namespace with `grep -rn "trait BelongsToTenant" app/` and use whatever it is (it is the same trait `EmployeeProgression` uses; copy that model's `use` line).

- [ ] **Step 6: `Employee.php`** — next to `EMPLOYMENT_FIELDS`:

```php
/** Personal tab fields the person may edit on their own record. */
public const PERSONAL_FIELDS = [
    'first_name', 'last_name', 'full_name_ic', 'nickname', 'religion', 'date_of_birth', 'gender', 'marital_status', 'race', 'nationality', 'blood_type',
    'phone', 'personal_email', 'address', 'address_2', 'city', 'state', 'postcode', 'country',
    'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relationship',
];

/** HR/management only: identity documents. */
public const IDENTITY_FIELDS = ['nric', 'passport_no', 'passport_expiry', 'permit_no', 'permit_expiry'];

public function familyMembers(): HasMany
{
    return $this->hasMany(EmployeeFamilyMember::class)->orderBy('relation')->orderBy('date_of_birth');
}
```

Add casts `'passport_expiry' => 'date', 'permit_expiry' => 'date'` in `casts()`.

- [ ] **Step 7: Run** the schema test → PASS. `vendor/bin/pint --dirty --format agent`.

- [ ] **Step 8: Commit** `git add database/migrations app/Support/PersonalOptions.php app/Models tests/Feature/PersonalSchemaTest.php && git commit -m "feat(personal): personal columns, family members table, option lists"`.

---

### Task 2: `PersonalRecordController::update`

**Files:**
- Create: `app/Http/Controllers/PersonalRecordController.php`
- Modify: `routes/web.php` (next to `employees.employment.update`)
- Test: `tests/Feature/PersonalRecordTest.php`

**Produces:** route `POST /app/employees/{employee}/personal` named `employees.personal.update`.

- [ ] **Step 1: Failing tests** (use the scaffolding block)

```php
public function test_hr_updates_personal_and_identity(): void
{
    $this->login('hr');
    $e = $this->emp('Adibah');
    $this->post("/app/employees/{$e->id}/personal", [
        'first_name' => 'Adibah', 'last_name' => 'Zainal', 'religion' => 'Islam', 'race' => 'Malay', 'nationality' => 'Malaysian',
        'blood_type' => 'O+', 'personal_email' => 'adibah@gmail.com', 'address' => '1 Jalan A', 'address_2' => 'Taman B', 'city' => 'Shah Alam',
        'state' => 'Selangor', 'postcode' => '40000', 'country' => 'Malaysia', 'emergency_contact_name' => 'Mak', 'emergency_contact_phone' => '0123', 'emergency_contact_relationship' => 'Mother',
        'nric' => '900101-10-1234', 'passport_no' => 'A123', 'passport_expiry' => '2030-01-01', 'gender' => 'female', 'marital_status' => 'married',
    ])->assertRedirect("/app/profile?emp={$e->id}&tab=personal");
    $e->refresh();
    $this->assertSame('Zainal', $e->last_name);
    $this->assertSame('900101-10-1234', $e->nric);
    $this->assertSame('2030-01-01', $e->passport_expiry->toDateString());
    $this->assertSame('Shah Alam', $e->city);
}

public function test_employee_edits_own_record_but_identity_is_ignored(): void
{
    $me = $this->login('employee', ['nric' => 'OLD']);
    $this->post("/app/employees/{$me->id}/personal", ['city' => 'Ipoh', 'nric' => 'NEW', 'passport_no' => 'P1'])->assertRedirect();
    $me->refresh();
    $this->assertSame('Ipoh', $me->city);
    $this->assertSame('OLD', $me->nric);
    $this->assertNull($me->passport_no);
}

public function test_employee_cannot_edit_someone_else(): void
{
    $this->login('employee');
    $e = $this->emp('Adibah');
    $this->post("/app/employees/{$e->id}/personal", ['city' => 'X'])->assertForbidden();
}

public function test_manager_cannot_edit_a_report(): void
{
    $m = $this->login('manager');
    $e = $this->emp('Adibah', ['reports_to_id' => $m->id]);
    $this->post("/app/employees/{$e->id}/personal", ['city' => 'X'])->assertForbidden();
}

public function test_invalid_option_is_rejected(): void
{
    $this->login('hr');
    $e = $this->emp('Adibah');
    $this->post("/app/employees/{$e->id}/personal", ['blood_type' => 'Z', 'marital_status' => 'complicated'])
        ->assertSessionHasErrors(['blood_type', 'marital_status']);
}

public function test_other_tenant_is_not_found(): void
{
    $this->login('hr');
    $other = Tenant::create(['slug' => 'zed', 'name' => 'Zed', 'initials' => 'ZD']);
    $s = Employee::withoutGlobalScopes()->create(['tenant_id' => $other->id, 'name' => 'S', 'status' => 'active', 'workload' => 'green']);
    $this->post("/app/employees/{$s->id}/personal", ['city' => 'X'])->assertNotFound();
}
```

- [ ] **Step 2: Run** → FAIL (404 route).

- [ ] **Step 3: Route** after `employees.employment.update`:

```php
Route::post('/app/employees/{employee}/personal', [PersonalRecordController::class, 'update'])->name('employees.personal.update');
```

Add `use App\Http\Controllers\PersonalRecordController;` with the other imports.

- [ ] **Step 4: Controller**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Support\PersonalOptions;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Personal tab save. HR/management edit anyone; a person edits their own record minus identity documents. */
class PersonalRecordController extends Controller
{
    public function update(Request $request, Employee $employee): RedirectResponse
    {
        abort_unless($employee->tenant_id === app(CurrentTenant::class)->id(), 403);
        $own = $request->attributes->get('employee');
        $isHr = $this->hasTenantRole($request, ['management', 'hr']);
        abort_unless($isHr || ($own && $own->id === $employee->id), 403);

        $rules = [
            'first_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'full_name_ic' => ['nullable', 'string', 'max:200'],
            'nickname' => ['nullable', 'string', 'max:60'],
            'religion' => ['nullable', Rule::in(PersonalOptions::RELIGIONS)],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', 'in:male,female'],
            'marital_status' => ['nullable', Rule::in(array_keys(PersonalOptions::MARITAL))],
            'race' => ['nullable', Rule::in(PersonalOptions::RACES)],
            'nationality' => ['nullable', Rule::in(PersonalOptions::NATIONALITIES)],
            'blood_type' => ['nullable', Rule::in(PersonalOptions::BLOOD_TYPES)],
            'phone' => ['nullable', 'string', 'max:40'],
            'personal_email' => ['nullable', 'email', 'max:190'],
            'address' => ['nullable', 'string', 'max:500'],
            'address_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postcode' => ['nullable', 'string', 'max:12'],
            'country' => ['nullable', 'string', 'max:80'],
            'emergency_contact_name' => ['nullable', 'string', 'max:160'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:40'],
            'emergency_contact_relationship' => ['nullable', 'string', 'max:60'],
        ];
        if ($isHr) {
            $rules += [
                'nric' => ['nullable', 'string', 'max:20'],
                'passport_no' => ['nullable', 'string', 'max:40'],
                'passport_expiry' => ['nullable', 'date'],
                'permit_no' => ['nullable', 'string', 'max:60'],
                'permit_expiry' => ['nullable', 'date'],
            ];
        }

        // Only keys that were actually sent change; validate() already drops anything not in $rules,
        // which is what silently strips nric/passport from a self-edit.
        $data = array_intersect_key($request->validate($rules), $request->all());
        $employee->update(array_map(fn ($v) => $v === '' ? null : $v, $data));

        AuditLog::record('Updated personal details', $employee->name);

        return redirect(route('app.screen', 'profile').'?emp='.$employee->id.'&tab=personal')->with('ok', 'Personal details saved.');
    }
}
```

- [ ] **Step 5: Run** the test file → PASS. Pint.

- [ ] **Step 6: Commit** `git add app/Http/Controllers/PersonalRecordController.php routes/web.php tests/Feature/PersonalRecordTest.php && git commit -m "feat(personal): PersonalRecordController with HR-only identity fields"`.

---

### Task 3: `FamilyMemberController` store / update / destroy

**Files:**
- Create: `app/Http/Controllers/FamilyMemberController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/FamilyMemberTest.php`

**Produces:** routes `POST /app/employees/{employee}/family` (`employees.family.store`), `POST /app/family/{member}` (`employees.family.update`), `POST /app/family/{member}/delete` (`employees.family.destroy`), all `->whereNumber(...)`.

- [ ] **Step 1: Failing tests**

```php
use App\Models\EmployeeFamilyMember;

public function test_hr_adds_edits_and_deletes_a_family_member(): void
{
    $this->login('hr');
    $e = $this->emp('Adibah');
    $this->post("/app/employees/{$e->id}/family", ['relation' => 'spouse', 'name' => 'Ali', 'marriage_date' => '2015-05-05', 'employer_name' => 'Petronas', 'occupation' => 'working'])
        ->assertRedirect("/app/profile?emp={$e->id}&tab=family");
    $m = EmployeeFamilyMember::firstOrFail();
    $this->assertSame('Petronas', $m->employer_name);

    $this->post("/app/family/{$m->id}", ['relation' => 'spouse', 'name' => 'Ali B', 'deceased' => '1'])->assertRedirect();
    $this->assertSame('Ali B', $m->fresh()->name);
    $this->assertTrue($m->fresh()->deceased);

    $this->post("/app/family/{$m->id}/delete")->assertRedirect();
    $this->assertSame(0, EmployeeFamilyMember::count());
}

public function test_employee_manages_own_family_only(): void
{
    $me = $this->login('employee');
    $other = $this->emp('Adibah');
    $this->post("/app/employees/{$me->id}/family", ['relation' => 'child', 'name' => 'Kid', 'education' => 'Primary'])->assertRedirect();
    $this->post("/app/employees/{$other->id}/family", ['relation' => 'child', 'name' => 'Kid'])->assertForbidden();

    $theirs = $other->familyMembers()->create(['tenant_id' => $this->tenant->id, 'relation' => 'father', 'name' => 'X']);
    $this->post("/app/family/{$theirs->id}", ['relation' => 'father', 'name' => 'Y'])->assertForbidden();
    $this->post("/app/family/{$theirs->id}/delete")->assertForbidden();
}

public function test_only_one_father_and_one_mother(): void
{
    $this->login('hr');
    $e = $this->emp('Adibah');
    $this->post("/app/employees/{$e->id}/family", ['relation' => 'father', 'name' => 'A'])->assertRedirect();
    $this->post("/app/employees/{$e->id}/family", ['relation' => 'father', 'name' => 'B'])->assertSessionHasErrors('relation');
    $this->assertSame(1, EmployeeFamilyMember::count());
}

public function test_invalid_relation_rejected(): void
{
    $this->login('hr');
    $e = $this->emp('Adibah');
    $this->post("/app/employees/{$e->id}/family", ['relation' => 'cousin', 'name' => 'A'])->assertSessionHasErrors('relation');
}

public function test_other_tenant_member_not_found(): void
{
    $this->login('hr');
    $other = Tenant::create(['slug' => 'zed', 'name' => 'Zed', 'initials' => 'ZD']);
    $s = Employee::withoutGlobalScopes()->create(['tenant_id' => $other->id, 'name' => 'S', 'status' => 'active', 'workload' => 'green']);
    $m = EmployeeFamilyMember::withoutGlobalScopes()->create(['tenant_id' => $other->id, 'employee_id' => $s->id, 'relation' => 'father', 'name' => 'X']);
    $this->post("/app/family/{$m->id}", ['relation' => 'father', 'name' => 'Y'])->assertNotFound();
    $this->post("/app/employees/{$s->id}/family", ['relation' => 'father', 'name' => 'Y'])->assertNotFound();
}
```

- [ ] **Step 2: Run** → FAIL.

- [ ] **Step 3: Routes** (same group, after `employees.personal.update`):

```php
Route::post('/app/employees/{employee}/family', [FamilyMemberController::class, 'store'])->whereNumber('employee')->name('employees.family.store');
Route::post('/app/family/{member}', [FamilyMemberController::class, 'update'])->whereNumber('member')->name('employees.family.update');
Route::post('/app/family/{member}/delete', [FamilyMemberController::class, 'destroy'])->whereNumber('member')->name('employees.family.destroy');
```

Import `App\Http\Controllers\FamilyMemberController`.

- [ ] **Step 4: Controller**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeFamilyMember;
use App\Support\PersonalOptions;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Family tab rows. HR/management for anyone; a person for their own record. */
class FamilyMemberController extends Controller
{
    public function store(Request $request, Employee $employee): RedirectResponse
    {
        $this->guard($request, $employee);
        $data = $this->validated($request, $employee);

        $employee->familyMembers()->create($data + ['tenant_id' => $employee->tenant_id]);
        AuditLog::record('Added family member', $employee->name.' · '.$data['name']);

        return $this->back($employee);
    }

    public function update(Request $request, EmployeeFamilyMember $member): RedirectResponse
    {
        $employee = $this->guard($request, $member->employee);
        $member->update($this->validated($request, $employee, $member));
        AuditLog::record('Updated family member', $employee->name.' · '.$member->name);

        return $this->back($employee);
    }

    public function destroy(Request $request, EmployeeFamilyMember $member): RedirectResponse
    {
        $employee = $this->guard($request, $member->employee);
        $name = $member->name;
        $member->delete();
        AuditLog::record('Removed family member', $employee->name.' · '.$name);

        return $this->back($employee);
    }

    private function guard(Request $request, ?Employee $employee): Employee
    {
        abort_unless($employee && $employee->tenant_id === app(CurrentTenant::class)->id(), 404);
        $own = $request->attributes->get('employee');
        abort_unless($this->hasTenantRole($request, ['management', 'hr']) || ($own && $own->id === $employee->id), 403);

        return $employee;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, Employee $employee, ?EmployeeFamilyMember $self = null): array
    {
        $data = $request->validate([
            'relation' => ['required', Rule::in(PersonalOptions::RELATIONS), function (string $attr, mixed $value, \Closure $fail) use ($employee, $self): void {
                // Worksy: one father, one mother. Spouse/child/dependent may repeat.
                if (in_array($value, ['father', 'mother'], true)
                    && $employee->familyMembers()->where('relation', $value)->when($self, fn ($q) => $q->whereKeyNot($self->id))->exists()) {
                    $fail('This person already has a '.$value.' on record.');
                }
            }],
            'name' => ['required', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'date_of_birth' => ['nullable', 'date'],
            'nric' => ['nullable', 'string', 'max:40'],
            'occupation' => ['nullable', Rule::in(PersonalOptions::OCCUPATIONS)],
            'employer_name' => ['nullable', 'string', 'max:160'],
            'marriage_date' => ['nullable', 'date'],
            'education' => ['nullable', Rule::in(PersonalOptions::EDUCATION)],
            'gender' => ['nullable', 'in:male,female'],
            'nationality' => ['nullable', Rule::in(PersonalOptions::NATIONALITIES)],
            'deceased' => ['nullable', 'boolean'],
            'address' => ['nullable', 'string', 'max:255'],
            'remark' => ['nullable', 'string', 'max:2000'],
        ]);
        $data['deceased'] = (bool) ($data['deceased'] ?? false);

        return array_map(fn ($v) => $v === '' ? null : $v, $data);
    }

    private function back(Employee $employee): RedirectResponse
    {
        return redirect(route('app.screen', 'profile').'?emp='.$employee->id.'&tab=family')->with('ok', 'Family details saved.');
    }
}
```

Note: `$member->employee` is null when the member belongs to another tenant (the `Employee` global scope hides it), so `guard()` returns 404, matching the test. `EmployeeFamilyMember` itself is tenant-scoped too, so the bound `$member` for another tenant is already a 404 from binding.

- [ ] **Step 5: Run** → PASS. Pint.

- [ ] **Step 6: Commit** `git add app/Http/Controllers/FamilyMemberController.php routes/web.php tests/Feature/FamilyMemberTest.php && git commit -m "feat(personal): family member store, update and delete"`.

---

### Task 4: Profile gates, Personal tab and Family tab

**Files:**
- Modify: `app/Http/Controllers/Concerns/BuildsPeopleData.php` (`profileData`: `$with` ~line 118, gates ~line 167, return array ~line 270)
- Modify: `resources/views/screens/profile.blade.php` (outer `x-data` line ~59, `$tabs` ~line 234, panels ~line 316)
- Create: `resources/views/partials/profile/personal-tab.blade.php`
- Create: `resources/views/partials/profile/family-tab.blade.php`
- Test: `tests/Feature/ProfilePersonalTabsTest.php`

**Consumes:** routes from Tasks 2–3, `PersonalOptions`, `Employee::PERSONAL_FIELDS`.

- [ ] **Step 1: Failing tests**

```php
public function test_hr_sees_personal_and_family_tabs_with_identity(): void
{
    $this->login('hr');
    $e = $this->emp('Adibah', ['nric' => '900101-10-1234', 'passport_no' => 'A9']);
    $this->get("/app/profile?emp={$e->id}")->assertOk()
        ->assertSee('data-tab="personal"', false)->assertSee('data-tab="family"', false)
        ->assertSee('900101-10-1234')->assertSee('name="passport_no"', false);
}

public function test_employee_sees_own_tabs_and_can_edit_but_no_identity_inputs(): void
{
    $this->login('employee', ['nric' => '900101-10-1234']);
    $this->get('/app/profile')->assertOk()
        ->assertSee('data-tab="personal"', false)->assertSee('data-tab="family"', false)
        ->assertSee('name="city"', false)
        ->assertDontSee('name="nric"', false)->assertDontSee('name="passport_no"', false)
        ->assertSee('900101-10-1234'); // may still read their own NRIC
}

public function test_manager_sees_neither_tab(): void
{
    $m = $this->login('manager');
    $e = $this->emp('Adibah', ['reports_to_id' => $m->id]);
    $this->get("/app/profile?emp={$e->id}")->assertOk()
        ->assertDontSee('data-tab="personal"', false)->assertDontSee('data-tab="family"', false);
}

public function test_family_rows_render_in_their_sections(): void
{
    $this->login('hr');
    $e = $this->emp('Adibah');
    $e->familyMembers()->create(['tenant_id' => $this->tenant->id, 'relation' => 'child', 'name' => 'Little One', 'education' => 'Primary']);
    $this->get("/app/profile?emp={$e->id}&tab=family")->assertOk()->assertSee('Little One')->assertSee('Primary');
}
```

- [ ] **Step 2: Run** → FAIL.

- [ ] **Step 3: `BuildsPeopleData::profileData`**

Add `'familyMembers'` to `$with`. After `$employmentGate`:

```php
// Personal + Family: same audience as Employment (self or HR/management; manager excluded).
// The person may edit their own; identity documents (NRIC/passport/permit) stay HR-only.
$personalGate = $employmentGate;
$canEditPersonal = $canEdit || ($own && $e && $own->id === $e->id);
```

In the returned array next to `'employmentGate'`:

```php
'personalGate' => $personalGate,
'canEditPersonal' => $canEditPersonal,
'canEditIdentity' => $canEdit,
'familyMembers' => $personalGate ? $e->familyMembers : collect(),
```

- [ ] **Step 4: `profile.blade.php`**

Outer `x-data` (line ~59) becomes:

```blade
<div x-data="{ edit: {{ ($errors->any() && ! $errors->hasAny(['effective_on', 'personal', 'relation'])) ? 'true' : 'false' }}, editEmployment: {{ $errors->has('effective_on') ? 'true' : 'false' }}, editPersonal: {{ ($errors->any() && session('form') === 'personal') ? 'true' : 'false' }} }" style="display:flex;flex-direction:column;gap:16px;">
```

For that `session('form')` to work, `PersonalRecordController` must return `back()->withInput()->with('form','personal')` on validation failure. Laravel's `validate()` throws before that, so wrap it: in `PersonalRecordController::update` replace `$request->validate($rules)` with

```php
$validator = validator($request->all(), $rules);
if ($validator->fails()) {
    return back()->withInput()->withErrors($validator)->with('form', 'personal');
}
$data = array_intersect_key($validator->validated(), $request->all());
```

(Do the same in `FamilyMemberController::validated` using `->with('form', 'family')` and have the family tab reopen the matching row form when `session('form') === 'family'` and `old('relation')` matches. Keep it simple: the family partial's "add" form is open when `session('form') === 'family'`.)

`$tabs` list, after the Timeline entry:

```php
if ($personalGate ?? false) {
    $tabs[] = ['personal', 'Personal', 'Peribadi'];
    $tabs[] = ['family', 'Family', 'Keluarga'];
}
```

Panels, after the Timeline panel:

```blade
@if ($personalGate ?? false)
    <div x-show="tab === 'personal'" x-cloak class="uj-tab-stack" style="padding:20px;">@include('partials.profile.personal-tab')</div>
    <div x-show="tab === 'family'" x-cloak class="uj-tab-stack" style="padding:20px;">@include('partials.profile.family-tab')</div>
@endif
```

- [ ] **Step 5: `partials/profile/personal-tab.blade.php`**

```blade
{{-- Personal tab: Worksy personal information, contact, emergency contact and identification.
     Expects $p (Employee), $canEditPersonal, $canEditIdentity, $fs. --}}
@php
    use App\Support\PersonalOptions;
    $L = fn ($en, $ms) => '<span x-text="'.e("\$store.ui.lang==='en' ? ".json_encode($en).' : '.json_encode($ms)).'">'.e($en).'</span>';
    $d = fn ($v) => $v ? $v->format('d/m/Y') : '—';
    $v = fn ($x) => filled($x) ? $x : '—';
    $marital = PersonalOptions::MARITAL[$p->marital_status][0] ?? '—';
    $sections = [
        ['Personal Information', 'Maklumat Peribadi', [
            ['First Name', 'Nama Pertama', $v($p->first_name)], ['Last Name', 'Nama Akhir', $v($p->last_name)],
            ['Full Name per IC/Passport', 'Nama Penuh mengikut IC/Pasport', $v($p->full_name_ic)], ['Known Name', 'Nama Panggilan', $v($p->nickname)],
            ['Religion', 'Agama', $v($p->religion)], ['Birth Date', 'Tarikh Lahir', $d($p->date_of_birth)],
            ['Gender', 'Jantina', $p->gender ? ucfirst($p->gender) : '—'], ['Marital Status', 'Status Perkahwinan', $marital],
            ['Race', 'Bangsa', $v($p->race)], ['Nationality', 'Kewarganegaraan', $v($p->nationality)], ['Blood Type', 'Jenis Darah', $v($p->blood_type)],
        ]],
        ['Contact & Address', 'Hubungan & Alamat', [
            ['Phone', 'Telefon', $v($p->phone)], ['Personal Email', 'E-mel Peribadi', $v($p->personal_email)],
            ['Address Line 1', 'Alamat Baris 1', $v($p->address)], ['Address Line 2', 'Alamat Baris 2', $v($p->address_2)],
            ['City', 'Bandar', $v($p->city)], ['State', 'Negeri', $v($p->state)], ['Postcode', 'Poskod', $v($p->postcode)], ['Country', 'Negara', $v($p->country)],
        ]],
        ['Emergency Contact', 'Hubungan Kecemasan', [
            ['Name', 'Nama', $v($p->emergency_contact_name)], ['Phone', 'Telefon', $v($p->emergency_contact_phone)], ['Relationship', 'Hubungan', $v($p->emergency_contact_relationship)],
        ]],
        ['Identification', 'Pengenalan', [
            ['NRIC', 'No. K/P', $v($p->nric)], ['Passport No', 'No. Pasport', $v($p->passport_no)], ['Passport Expiry', 'Tamat Pasport', $d($p->passport_expiry)],
            ['Permit No', 'No. Permit', $v($p->permit_no)], ['Permit Expiry', 'Tamat Permit', $d($p->permit_expiry)],
        ]],
    ];
    $lbl = 'display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;';
    $old = fn (string $k) => old($k, $p->{$k});
    $oldDate = fn (string $k) => old($k, $p->{$k}?->toDateString());
@endphp

@if ($canEditPersonal ?? false)
    <div style="display:flex;justify-content:flex-end;"><button type="button" @click="editPersonal = true" class="uj-btn-ghost" style="height:32px;padding:0 14px;font-size:12.5px;">{!! $L('Edit', 'Sunting') !!}</button></div>
@endif

@foreach ($sections as [$en, $ms, $rows])
    <div>
        <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.6px;margin-bottom:12px;">{!! $L($en, $ms) !!}</div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px 32px;">
            @foreach ($rows as [$ren, $rms, $val])
                <div><div style="font-size:11px;color:var(--muted);margin-bottom:2px;">{!! $L($ren, $rms) !!}</div><div style="font-size:13px;color:var(--ink);">{{ $val }}</div></div>
            @endforeach
        </div>
    </div>
@endforeach

@if ($canEditPersonal ?? false)
    <template x-teleport="body">
    <div x-show="editPersonal" x-cloak @click.self="editPersonal = false" @keydown.escape.window="editPersonal = false"
         style="position:fixed;inset:0;z-index:120;display:flex;padding:40px 16px;background:rgba(18,18,30,.42);overflow-y:auto;">
        <form method="post" action="{{ route('employees.personal.update', $p) }}" class="uj-card" style="width:100%;max-width:760px;margin:auto;padding:20px;display:flex;flex-direction:column;gap:14px;max-height:calc(100vh - 80px);overflow-y:auto;">
            @csrf
            <div style="font-size:13px;font-weight:600;color:var(--ink);">{!! $L('Edit personal details', 'Sunting butiran peribadi') !!} · {{ $p->name }}</div>
            @if ($errors->any() && session('form') === 'personal')<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:8px 11px;">{{ $errors->first() }}</div>@endif

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px 16px;">
                <div><label style="{{ $lbl }}">{!! $L('First Name', 'Nama Pertama') !!}</label><input name="first_name" value="{{ $old('first_name') }}" maxlength="120" style="{{ $fs }}" /></div>
                <div><label style="{{ $lbl }}">{!! $L('Last Name', 'Nama Akhir') !!}</label><input name="last_name" value="{{ $old('last_name') }}" maxlength="120" style="{{ $fs }}" /></div>
                <div><label style="{{ $lbl }}">{!! $L('Full Name per IC/Passport', 'Nama Penuh mengikut IC/Pasport') !!}</label><input name="full_name_ic" value="{{ $old('full_name_ic') }}" maxlength="200" style="{{ $fs }}" /></div>
                <div><label style="{{ $lbl }}">{!! $L('Known Name', 'Nama Panggilan') !!}</label><input name="nickname" value="{{ $old('nickname') }}" maxlength="60" style="{{ $fs }}" /></div>
                <div><label style="{{ $lbl }}">{!! $L('Religion', 'Agama') !!}</label><select name="religion" style="{{ $fs }}"><option value="">—</option>@foreach (PersonalOptions::RELIGIONS as $o)<option value="{{ $o }}" @selected($old('religion') === $o)>{{ $o }}</option>@endforeach</select></div>
                <div><label style="{{ $lbl }}">{!! $L('Birth Date', 'Tarikh Lahir') !!}</label><input type="date" name="date_of_birth" value="{{ $oldDate('date_of_birth') }}" style="{{ $fs }}" /></div>
                <div><label style="{{ $lbl }}">{!! $L('Gender', 'Jantina') !!}</label><select name="gender" style="{{ $fs }}"><option value="">—</option><option value="male" @selected($old('gender') === 'male')>Male</option><option value="female" @selected($old('gender') === 'female')>Female</option></select></div>
                <div><label style="{{ $lbl }}">{!! $L('Marital Status', 'Status Perkahwinan') !!}</label><select name="marital_status" style="{{ $fs }}"><option value="">—</option>@foreach (PersonalOptions::MARITAL as $k => [$men, $mms])<option value="{{ $k }}" @selected($old('marital_status') === $k)>{{ $men }}</option>@endforeach</select></div>
                <div><label style="{{ $lbl }}">{!! $L('Race', 'Bangsa') !!}</label><select name="race" style="{{ $fs }}"><option value="">—</option>@foreach (PersonalOptions::RACES as $o)<option value="{{ $o }}" @selected($old('race') === $o)>{{ $o }}</option>@endforeach</select></div>
                <div><label style="{{ $lbl }}">{!! $L('Nationality', 'Kewarganegaraan') !!}</label><select name="nationality" style="{{ $fs }}"><option value="">—</option>@foreach (PersonalOptions::NATIONALITIES as $o)<option value="{{ $o }}" @selected($old('nationality') === $o)>{{ $o }}</option>@endforeach</select></div>
                <div><label style="{{ $lbl }}">{!! $L('Blood Type', 'Jenis Darah') !!}</label><select name="blood_type" style="{{ $fs }}"><option value="">—</option>@foreach (PersonalOptions::BLOOD_TYPES as $o)<option value="{{ $o }}" @selected($old('blood_type') === $o)>{{ $o }}</option>@endforeach</select></div>
            </div>

            <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.6px;">{!! $L('Contact & Address', 'Hubungan & Alamat') !!}</div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px 16px;">
                <div><label style="{{ $lbl }}">{!! $L('Phone', 'Telefon') !!}</label><input name="phone" value="{{ $old('phone') }}" maxlength="40" style="{{ $fs }}" /></div>
                <div><label style="{{ $lbl }}">{!! $L('Personal Email', 'E-mel Peribadi') !!}</label><input type="email" name="personal_email" value="{{ $old('personal_email') }}" maxlength="190" style="{{ $fs }}" /></div>
                <div style="grid-column:1/-1;"><label style="{{ $lbl }}">{!! $L('Address Line 1', 'Alamat Baris 1') !!}</label><input name="address" value="{{ $old('address') }}" maxlength="500" style="{{ $fs }}" /></div>
                <div style="grid-column:1/-1;"><label style="{{ $lbl }}">{!! $L('Address Line 2', 'Alamat Baris 2') !!}</label><input name="address_2" value="{{ $old('address_2') }}" maxlength="255" style="{{ $fs }}" /></div>
                <div><label style="{{ $lbl }}">{!! $L('City', 'Bandar') !!}</label><input name="city" value="{{ $old('city') }}" maxlength="100" style="{{ $fs }}" /></div>
                <div><label style="{{ $lbl }}">{!! $L('State', 'Negeri') !!}</label><input name="state" value="{{ $old('state') }}" maxlength="100" style="{{ $fs }}" /></div>
                <div><label style="{{ $lbl }}">{!! $L('Postcode', 'Poskod') !!}</label><input name="postcode" value="{{ $old('postcode') }}" maxlength="12" style="{{ $fs }}" /></div>
                <div><label style="{{ $lbl }}">{!! $L('Country', 'Negara') !!}</label><input name="country" value="{{ $old('country') ?? 'Malaysia' }}" maxlength="80" style="{{ $fs }}" /></div>
            </div>

            <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.6px;">{!! $L('Emergency Contact', 'Hubungan Kecemasan') !!}</div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px 16px;">
                <div><label style="{{ $lbl }}">{!! $L('Name', 'Nama') !!}</label><input name="emergency_contact_name" value="{{ $old('emergency_contact_name') }}" maxlength="160" style="{{ $fs }}" /></div>
                <div><label style="{{ $lbl }}">{!! $L('Phone', 'Telefon') !!}</label><input name="emergency_contact_phone" value="{{ $old('emergency_contact_phone') }}" maxlength="40" style="{{ $fs }}" /></div>
                <div><label style="{{ $lbl }}">{!! $L('Relationship', 'Hubungan') !!}</label><input name="emergency_contact_relationship" value="{{ $old('emergency_contact_relationship') }}" maxlength="60" style="{{ $fs }}" /></div>
            </div>

            @if ($canEditIdentity ?? false)
                <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.6px;">{!! $L('Identification', 'Pengenalan') !!}</div>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px 16px;">
                    <div><label style="{{ $lbl }}">{!! $L('NRIC', 'No. K/P') !!}</label><input name="nric" value="{{ $old('nric') }}" maxlength="20" style="{{ $fs }}" /></div>
                    <div><label style="{{ $lbl }}">{!! $L('Passport No', 'No. Pasport') !!}</label><input name="passport_no" value="{{ $old('passport_no') }}" maxlength="40" style="{{ $fs }}" /></div>
                    <div><label style="{{ $lbl }}">{!! $L('Passport Expiry', 'Tamat Pasport') !!}</label><input type="date" name="passport_expiry" value="{{ $oldDate('passport_expiry') }}" style="{{ $fs }}" /></div>
                    <div><label style="{{ $lbl }}">{!! $L('Permit No', 'No. Permit') !!}</label><input name="permit_no" value="{{ $old('permit_no') }}" maxlength="60" style="{{ $fs }}" /></div>
                    <div><label style="{{ $lbl }}">{!! $L('Permit Expiry', 'Tamat Permit') !!}</label><input type="date" name="permit_expiry" value="{{ $oldDate('permit_expiry') }}" style="{{ $fs }}" /></div>
                </div>
            @endif

            <div style="display:flex;gap:8px;justify-content:flex-end;">
                <button type="button" @click="editPersonal = false" class="uj-btn-ghost" style="height:40px;padding:0 16px;font-size:13px;">{!! $L('Cancel', 'Batal') !!}</button>
                <button type="submit" class="uj-btn-primary" style="height:40px;padding:0 18px;font-size:13px;">{!! $L('Save changes', 'Simpan perubahan') !!}</button>
            </div>
        </form>
    </div>
    </template>
@endif
```

- [ ] **Step 6: `partials/profile/family-tab.blade.php`**

Four sections. Each existing row renders as a card with an inline `<form>` (fields prefilled, `Save` + `Remove` buttons, Alpine `open` toggle). Each section has an "Add" form (hidden until clicked; parents' Add hidden once a row exists). One reusable row-form fragment is defined with `@php $fields = ...` and rendered by a Blade loop, not a separate partial, to keep it in one file:

```blade
{{-- Family tab: Parents, Spouse, Children, Other Dependents. Expects $p, $familyMembers, $canEditPersonal, $fs. --}}
@php
    use App\Support\PersonalOptions;
    $L = fn ($en, $ms) => '<span x-text="'.e("\$store.ui.lang==='en' ? ".json_encode($en).' : '.json_encode($ms)).'">'.e($en).'</span>';
    $lbl = 'display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;';
    $groups = [
        ['Parents', 'Ibu Bapa', ['father', 'mother'], true],
        ['Spouse', 'Pasangan', ['spouse'], false],
        ['Children', 'Anak-anak', ['child'], false],
        ['Other Dependents', 'Tanggungan Lain', ['dependent'], false],
    ];
    $relL = ['father' => ['Father', 'Bapa'], 'mother' => ['Mother', 'Ibu'], 'spouse' => ['Spouse', 'Pasangan'], 'child' => ['Child', 'Anak'], 'dependent' => ['Dependent', 'Tanggungan']];
    $canEdit = $canEditPersonal ?? false;
    $addOpen = session('form') === 'family' && ! old('_member');
@endphp

@foreach ($groups as [$gen, $gms, $relations, $single])
    @php $rows = $familyMembers->whereIn('relation', $relations); @endphp
    <div style="display:flex;flex-direction:column;gap:10px;">
        <div style="display:flex;align-items:center;gap:12px;">
            <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.6px;">{!! $L($gen, $gms) !!}</div>
        </div>

        @forelse ($rows as $m)
            <div class="uj-card" x-data="{ open: {{ old('_member') == $m->id ? 'true' : 'false' }} }" style="padding:12px 14px;">
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    <span style="font-size:11px;font-weight:600;padding:2px 8px;border-radius:999px;background:var(--surface-2,#f1f1f4);color:var(--muted);">{!! $L(...$relL[$m->relation]) !!}</span>
                    <span style="font-size:13px;font-weight:600;color:var(--ink);">{{ $m->name }}</span>
                    @if ($m->deceased)<span style="font-size:11px;color:var(--muted);">({!! $L('deceased', 'meninggal dunia') !!})</span>@endif
                    <span style="font-size:12px;color:var(--muted);">{{ $m->date_of_birth?->format('d/m/Y') }}{{ $m->phone ? ' · '.$m->phone : '' }}{{ $m->occupation ? ' · '.ucfirst($m->occupation) : '' }}{{ $m->education ? ' · '.$m->education : '' }}{{ $m->employer_name ? ' · '.$m->employer_name : '' }}</span>
                    @if ($canEdit)<button type="button" @click="open = !open" class="uj-btn-ghost" style="margin-left:auto;height:28px;padding:0 10px;font-size:12px;">{!! $L('Edit', 'Sunting') !!}</button>@endif
                </div>
                @if ($canEdit)
                    <div x-show="open" x-cloak style="margin-top:12px;">
                        @include('partials.profile.family-form', ['action' => route('employees.family.update', $m), 'm' => $m, 'relations' => $relations, 'fs' => $fs, 'lbl' => $lbl, 'L' => $L, 'relL' => $relL])
                        <form method="post" action="{{ route('employees.family.destroy', $m) }}" onsubmit="return confirm('Remove this family member?')" style="margin-top:6px;">@csrf<button type="submit" class="uj-btn-ghost" style="height:32px;padding:0 12px;font-size:12px;color:var(--red);">{!! $L('Remove', 'Buang') !!}</button></form>
                    </div>
                @endif
            </div>
        @empty
            <p style="font-size:12.5px;color:var(--muted);margin:0;">{!! $L('No Record Found', 'Tiada Rekod') !!}</p>
        @endforelse

        @if ($canEdit && ! ($single && $rows->count() >= 2))
            <div x-data="{ add: {{ ($addOpen && in_array(old('relation'), $relations, true)) ? 'true' : 'false' }} }">
                <button type="button" @click="add = !add" class="uj-btn-ghost" style="height:32px;padding:0 12px;font-size:12px;">+ {!! $L('Add', 'Tambah') !!}</button>
                <div x-show="add" x-cloak class="uj-card" style="margin-top:8px;padding:14px;">
                    @include('partials.profile.family-form', ['action' => route('employees.family.store', $p), 'm' => null, 'relations' => array_values(array_diff($relations, $rows->pluck('relation')->all())), 'fs' => $fs, 'lbl' => $lbl, 'L' => $L, 'relL' => $relL])
                </div>
            </div>
        @endif
    </div>
@endforeach
```

And `partials/profile/family-form.blade.php` (the one shared row form; `$m` null = add):

```blade
@php
    use App\Support\PersonalOptions;
    $isMine = $m && old('_member') == $m->id;
    $o = fn (string $k, $default = null) => $isMine || ! $m ? old($k, $default) : ($m->{$k} ?? $default);
    $od = fn (string $k) => $isMine || ! $m ? old($k) : $m->{$k}?->toDateString();
    $rel = $o('relation', $relations[0] ?? 'dependent');
@endphp
<form method="post" action="{{ $action }}" style="display:flex;flex-direction:column;gap:10px;" x-data="{ rel: @js($rel) }">
    @csrf
    @if ($m)<input type="hidden" name="_member" value="{{ $m->id }}" />@endif
    @if (($isMine || ! $m) && $errors->any() && session('form') === 'family')<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:8px 11px;">{{ $errors->first() }}</div>@endif
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px 14px;">
        <div><label style="{{ $lbl }}">{!! $L('Relation', 'Hubungan') !!}</label><select name="relation" x-model="rel" style="{{ $fs }}">@foreach ($relations as $r)<option value="{{ $r }}">{{ $relL[$r][0] }}</option>@endforeach</select></div>
        <div><label style="{{ $lbl }}">{!! $L('Name', 'Nama') !!} *</label><input name="name" required value="{{ $o('name') }}" maxlength="160" style="{{ $fs }}" /></div>
        <div><label style="{{ $lbl }}">{!! $L('Phone', 'Telefon') !!}</label><input name="phone" value="{{ $o('phone') }}" maxlength="40" style="{{ $fs }}" /></div>
        <div><label style="{{ $lbl }}">{!! $L('Birth Date', 'Tarikh Lahir') !!}</label><input type="date" name="date_of_birth" value="{{ $od('date_of_birth') }}" style="{{ $fs }}" /></div>
        <div><label style="{{ $lbl }}">{!! $L('NRIC', 'No. K/P') !!}</label><input name="nric" value="{{ $o('nric') }}" maxlength="40" style="{{ $fs }}" /></div>
        <div><label style="{{ $lbl }}">{!! $L('Gender', 'Jantina') !!}</label><select name="gender" style="{{ $fs }}"><option value="">—</option><option value="male" @selected($o('gender') === 'male')>Male</option><option value="female" @selected($o('gender') === 'female')>Female</option></select></div>
        <div><label style="{{ $lbl }}">{!! $L('Nationality', 'Kewarganegaraan') !!}</label><select name="nationality" style="{{ $fs }}"><option value="">—</option>@foreach (PersonalOptions::NATIONALITIES as $n)<option value="{{ $n }}" @selected($o('nationality') === $n)>{{ $n }}</option>@endforeach</select></div>
        <div><label style="{{ $lbl }}">{!! $L('Occupation', 'Pekerjaan') !!}</label><select name="occupation" style="{{ $fs }}"><option value="">—</option>@foreach (PersonalOptions::OCCUPATIONS as $oc)<option value="{{ $oc }}" @selected($o('occupation') === $oc)>{{ ucfirst($oc) }}</option>@endforeach</select></div>
        <div x-show="rel === 'spouse'"><label style="{{ $lbl }}">{!! $L('Employer', 'Majikan') !!}</label><input name="employer_name" value="{{ $o('employer_name') }}" maxlength="160" style="{{ $fs }}" /></div>
        <div x-show="rel === 'spouse'"><label style="{{ $lbl }}">{!! $L('Marriage Date', 'Tarikh Perkahwinan') !!}</label><input type="date" name="marriage_date" value="{{ $od('marriage_date') }}" style="{{ $fs }}" /></div>
        <div x-show="rel === 'child'"><label style="{{ $lbl }}">{!! $L('Education', 'Pendidikan') !!}</label><select name="education" style="{{ $fs }}"><option value="">—</option>@foreach (PersonalOptions::EDUCATION as $ed)<option value="{{ $ed }}" @selected($o('education') === $ed)>{{ $ed }}</option>@endforeach</select></div>
        <div style="grid-column:1/-1;"><label style="{{ $lbl }}">{!! $L('Address', 'Alamat') !!}</label><input name="address" value="{{ $o('address') }}" maxlength="255" style="{{ $fs }}" /></div>
        <div style="grid-column:1/-1;"><label style="{{ $lbl }}">{!! $L('Remark', 'Catatan') !!}</label><input name="remark" value="{{ $o('remark') }}" maxlength="2000" style="{{ $fs }}" /></div>
        <label style="display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--body);"><input type="hidden" name="deceased" value="0" /><input type="checkbox" name="deceased" value="1" @checked((bool) $o('deceased', false)) /> {!! $L('Deceased', 'Meninggal dunia') !!}</label>
    </div>
    <button type="submit" class="uj-btn-primary" style="height:36px;padding:0 16px;font-size:13px;align-self:flex-start;">{!! $L('Save', 'Simpan') !!}</button>
</form>
```

Add `'_member' => ['nullable', 'integer']` to the family validation rules so the hidden field passes through, and strip it before `create`/`update`: `unset($data['_member']);` in `FamilyMemberController::validated` after validation.

- [ ] **Step 7: Run** `php artisan test --compact tests/Feature/ProfilePersonalTabsTest.php tests/Feature/PersonalRecordTest.php tests/Feature/FamilyMemberTest.php tests/Feature/ProfileEmploymentTabsTest.php tests/Feature/AllScreensRenderTest.php` → PASS. Pint.

- [ ] **Step 8: Commit** `git add app resources routes tests && git commit -m "feat(profile): Personal and Family tabs"`.

---

### Task 5: Dev DB migrate, browser check, assets, full suite

- [ ] **Step 1:** `lerd artisan migrate`.
- [ ] **Step 2:** `lerd artisan view:clear && lerd artisan view:cache && bun run build`.
- [ ] **Step 3: Browser walk** (headless Chromium via `/home/shzwn/Projects/Asas/node_modules/playwright-core`, executable `~/.cache/ms-playwright/chromium-1234/chrome-linux64/chrome`; remove `.uj-modal-scrim` nodes before clicking; submit forms with `form.requestSubmit()` targeted by `action` URL, never by button text):
  1. HR → any staff → Personal tab: four sections render, Edit opens the modal, change City, save → lands on `?tab=personal` with the new value.
  2. Family tab: add Father, add Spouse (employer + marriage date visible), add Child (education visible), edit one, remove one. Second Father add is refused with the message shown.
  3. Employee (shazwanshah) → own profile → Personal: Edit modal has no NRIC/passport inputs; save works. Family: add works.
  4. Manager (kussairi) → a report's profile: no Personal/Family tab.
  Save screenshots to `~/mockups/personal-family-walk/`.
- [ ] **Step 4:** `php artisan test --compact` whole suite.
- [ ] **Step 5:** `git add public/build && git commit -m "build: assets for Personal and Family tabs"`.
- [ ] **Step 6:** Report and hand off to sub-project 3 (Bank & Statutory + Experience).

---

## Self-review against the spec (section 2)

| Spec item | Task |
|---|---|
| 18 new personal columns, country default Malaysia | 1 |
| Option lists in `App\Support\PersonalOptions` | 1 |
| `PersonalRecordController::update`, employee self-edit minus NRIC/passport | 2, 4 (no identity inputs for self) |
| `employee_family_members` table with all listed columns | 1 |
| Four family sections, add/edit/delete, employee edits own | 3, 4 |
| Tenant scoping + audit on every write | 2, 3 |
| Manager sees no new tabs | 4 |
| `children_relief_count` untouched | (nothing to do) |
| Tests: personal persistence, NRIC rejection on self-edit, family CRUD, tenant, other person's family | 2, 3, 4 |
