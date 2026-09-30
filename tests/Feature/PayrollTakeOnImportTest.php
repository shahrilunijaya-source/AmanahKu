<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PayrollOpeningFigure;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FeatureManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Take On import: HR uploads the Summary tab of their own salary listing (one year-to-date
 * row per person, Worksy-era months only) instead of keying every figure in again.
 */
class PayrollTakeOnImportTest extends TestCase
{
    use RefreshDatabase;

    /** The Summary tab's header row, as HR's sheet has it. */
    private const HEADER = 'STAFF TETAP,BASIC,BONUS,ALLOWANCE,MEDICAL,MILEAGE,OTHERS,GROSS,EPF,TAX,SOCSO,EIS,ZAKAT,UNPAID LEAVE,ADVANCE,DEDUCTION,CP38,NET SALARY,EMPLOYER EPF,EMPLOYER SOCSO,EMPLOYER EIS,#REF!';

    private Tenant $tenant;

    private User $hr;

    private User $empUser;

    private Employee $aina;

    private Employee $badrul;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $this->hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);
        $this->empUser = User::create(['name' => 'Worker', 'email' => 'worker@example.com', 'password' => Hash::make('password')]);
        $this->empUser->tenants()->attach($this->tenant->id, ['role' => 'employee']);

        $this->aina = Employee::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->empUser->id, 'name' => 'Aina Binti Ahmad',
            'staff_id' => 'UR001', 'status' => 'active', 'workload' => 'green']);
        $this->badrul = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Badrul Hisham', 'staff_id' => 'UR002',
            'status' => 'active', 'workload' => 'green']);
    }

    /** @param list<string> $rows */
    private function preview(array $rows, string $header = self::HEADER, int $year = 2026, ?User $as = null): TestResponse
    {
        $csv = implode("\r\n", [$header, ...$rows])."\r\n";

        return $this->actingAs($as ?? $this->hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post('/app/payroll/opening/preview', ['year' => $year, 'file' => UploadedFile::fake()->createWithContent('summary.csv', $csv)], ['Accept' => 'application/json']);
    }

    /** @param list<array{line: int, employee_id: ?int, values: array<string, mixed>}> $rows */
    private function import(array $rows, int $year = 2026, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->hr)->withSession(['current_tenant' => $this->tenant->id])
            ->postJson('/app/payroll/opening/import', ['year' => $year, 'rows' => $rows]);
    }

    /**
     * Preview, then import the rows exactly as previewed (HR changes nothing).
     *
     * @param  list<string>  $rows
     */
    private function upload(array $rows, string $header = self::HEADER): TestResponse
    {
        return $this->import($this->preview($rows, $header)->assertOk()->json('rows'));
    }

    private function row(Employee $employee, int $year = 2026): ?PayrollOpeningFigure
    {
        return PayrollOpeningFigure::where('employee_id', $employee->id)->where('year', $year)->first();
    }

    public function test_a_summary_row_lands_on_the_right_take_on_lines(): void
    {
        // Basic 50,000 less 250.50 unpaid leave is the taxable salary. Medical, mileage and
        // others are claims (not income), advance only touched net pay.
        $this->upload([
            'Aina Binti Ahmad,"50,000.00","1,000.00",300.00,200.00,100.00,50.00,"51,650.00","5,500.00","1,500.00",400.00,90.00,800.00,250.50,500.00,0.00,90.00,"40,000.00","6,500.00",800.00,90.00,#REF!',
        ])->assertOk()->assertSessionHas('ok');

        $row = $this->row($this->aina);
        $this->assertNotNull($row);
        $this->assertSame(49749.5, $row->gross);
        $this->assertSame(1000.0, $row->additional_gross);
        $this->assertSame(5500.0, $row->epf);
        $this->assertSame(1500.0, $row->pcb_paid);
        $this->assertSame(800.0, $row->zakat_paid);
        $this->assertSame(400.0, $row->socso);
        $this->assertSame(90.0, $row->eis);
        $this->assertSame(200.0, $row->medical_claimed);
        $this->assertSame(0.0, $row->exempt_allowances);
        $this->assertEquals(['b1c' => 300.0, 'd2' => 90.0, 'employer_epf' => 6500.0, 'employer_socso' => 800.0, 'employer_eis' => 90.0], $row->ea_lines);
        $this->assertDatabaseHas('audit_logs', ['action' => 'Imported payroll take-on figures']);
    }

    public function test_preview_saves_nothing_and_lists_only_rows_with_pay(): void
    {
        $response = $this->preview([
            'Aina Binti Ahmad,"1,000.00",0.00,0.00,0.00,0.00,0.00,"1,000.00",110.00,0.00,5.00,2.00,0.00,0.00,0.00,0.00,0.00,883.00,130.00,17.50,2.00,',
            'INTERN,,,,,,,,,,,,,,,,,,,,,',
            ',,,,,,,,,,,,,,,,,,,,,',
            ',#REF!,#REF!,#REF!,#REF!,#REF!,#REF!,#REF!,#REF!,#REF!,#REF!,#REF!,#REF!,#REF!,#REF!,#REF!,#REF!,#REF!,#REF!,#REF!,#REF!,',
            'TOTAL,"  1,000.00 ",  -   ,  -   ,  -   ,  -   ,  -   ,"  1,000.00 ",  110.00 ,  -   ,  5.00 ,  2.00 ,  -   ,  -   ,  -   ,  -   ,  -   ,  883.00 ,  130.00 ,  17.50 ,  2.00 ,',
            'Director,"  200,000.00 ",  -   ,  -   ,  -   ,  -   ,  -   ,"  200,000.00 ",  -   ,  -   ,  -   ,  -   ,  -   ,  -   ,  -   ,  -   ,  -   ,"  200,000.00 ",  -   ,  -   ,  -   ,',
        ])->assertOk();

        $this->assertSame([2, 7], array_column($response->json('rows'), 'line'));
        $this->assertSame([$this->aina->id, null], array_column($response->json('rows'), 'employee_id'));
        $this->assertNull($response->json('rows.1.suggestion'));
        $this->assertSame('1,000.00', $response->json('rows.0.values.basic'));
        $this->assertSame(0, PayrollOpeningFigure::count());
    }

    /** Google Sheets exports every empty grid row, often well past the row cap. */
    public function test_trailing_empty_rows_do_not_count_toward_the_row_cap(): void
    {
        $this->upload([
            'Aina Binti Ahmad,"1,000.00",0.00,0.00,0.00,0.00,0.00,"1,000.00",0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,',
            ...array_fill(0, 1200, ',,,,,,,,,,,,,,,,,,,,,'),
        ])->assertOk();

        $this->assertSame(1000.0, $this->row($this->aina)?->gross);
    }

    public function test_names_match_ignoring_case_spacing_and_bin_binti(): void
    {
        $this->upload([
            'AINA  AHMAD,"1,000.00",0.00,0.00,0.00,0.00,0.00,"1,000.00",0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,',
            'Badrul Bin Hisham,"2,000.00",0.00,0.00,0.00,0.00,0.00,"2,000.00",0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,',
        ])->assertOk();

        $this->assertSame(1000.0, $this->row($this->aina)?->gross);
        $this->assertSame(2000.0, $this->row($this->badrul)?->gross);
    }

    public function test_a_staff_id_column_wins_over_the_name(): void
    {
        $this->upload(
            ['UR002,Aina Binti Ahmad,"2,000.00",0.00,0.00,0.00,0.00,0.00,"2,000.00",0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00'],
            'STAFF ID,STAFF TETAP,BASIC,BONUS,ALLOWANCE,MEDICAL,MILEAGE,OTHERS,GROSS,EPF,TAX,SOCSO,EIS,ZAKAT,UNPAID LEAVE,ADVANCE,DEDUCTION,CP38,NET SALARY,EMPLOYER EPF,EMPLOYER SOCSO,EMPLOYER EIS',
        )->assertOk();

        $this->assertNull($this->row($this->aina));
        $this->assertSame(2000.0, $this->row($this->badrul)?->gross);
    }

    /** HR picks the staff member for a name the file spells differently. */
    public function test_a_name_that_did_not_match_imports_under_the_staff_member_hr_picks(): void
    {
        $rows = $this->preview([
            'Aina Ahmed,"1,000.00",0.00,0.00,0.00,0.00,0.00,"1,000.00",0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,',
            'Leaver Long Gone,"3,000.00",0.00,0.00,0.00,0.00,0.00,"3,000.00",0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,',
        ])->json('rows');
        $this->assertNull($rows[0]['employee_id']);
        $rows[0]['employee_id'] = $this->aina->id;

        $this->import($rows)->assertOk();

        $this->assertSame(1000.0, $this->row($this->aina)?->gross);
        $this->assertSame(1, PayrollOpeningFigure::count());
    }

    /** A shortened or misspelt name is offered as a suggestion for HR to confirm, never matched on its own. */
    public function test_a_close_name_is_suggested_but_not_matched(): void
    {
        Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Nur Humaira Binti Muhibbudin', 'status' => 'active', 'workload' => 'green']);
        $irfan = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Ahmad Irfan Bin Harman', 'status' => 'active', 'workload' => 'green']);
        Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Ahmad Kussairi Bin Sutikno', 'status' => 'active', 'workload' => 'green']);

        $rows = $this->preview([
            'Ahmad Irfan,"1,000.00",0.00,0.00,0.00,0.00,0.00,"1,000.00",0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,',
            'Nur Humaira Binti Muhibbuddin,"1,000.00",0.00,0.00,0.00,0.00,0.00,"1,000.00",0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,',
            'Ahmad,"1,000.00",0.00,0.00,0.00,0.00,0.00,"1,000.00",0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,',
            'Ahmad Zulkifli,"1,000.00",0.00,0.00,0.00,0.00,0.00,"1,000.00",0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,',
        ])->json('rows');

        $this->assertSame([null, null, null, null], array_column($rows, 'employee_id'));
        $this->assertSame($irfan->id, $rows[0]['suggestion']);
        $this->assertSame(Employee::where('name', 'like', 'Nur Humaira%')->value('id'), $rows[1]['suggestion']);
        $this->assertNull($rows[2]['suggestion']);
        $this->assertNull($rows[3]['suggestion']);
    }

    /** Someone paid as an intern early in the year and as staff later shows up twice: both count. */
    public function test_two_rows_for_the_same_person_are_added_together(): void
    {
        $this->upload([
            'Aina Binti Ahmad,"3,000.00",0.00,0.00,50.00,0.00,0.00,"3,050.00",330.00,0.00,15.00,6.00,0.00,0.00,0.00,0.00,0.00,0.00,390.00,50.00,6.00,',
            'Aina Binti Ahmad,"2,000.00",0.00,0.00,0.00,0.00,0.00,"2,000.00",0.00,0.00,0.00,0.00,0.00,50.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,',
        ])->assertOk();

        $row = $this->row($this->aina);
        $this->assertSame(4950.0, $row?->gross);
        $this->assertSame(330.0, $row->epf);
        $this->assertSame(50.0, $row->medical_claimed);
    }

    public function test_a_row_whose_parts_do_not_add_up_points_at_gross_and_saves_nothing(): void
    {
        $this->upload([
            'Badrul Hisham,"2,000.00",0.00,0.00,0.00,0.00,0.00,"2,000.00",0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,',
            'Aina Binti Ahmad,"100,000.00",0.00,0.00,0.00,0.00,0.00,"90,000.00",0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,',
        ])->assertStatus(422)
            ->assertJsonPath('problems.3.gross', 'BASIC + BONUS + ALLOWANCE + MEDICAL + MILEAGE + OTHERS = 100,000.00, but GROSS is 90,000.00 (10,000.00 apart).')
            ->assertJsonMissingPath('problems.2');

        $this->assertSame(0, PayrollOpeningFigure::count());
    }

    /** HR fixes the marked cell on the review screen instead of editing the sheet. */
    public function test_a_cell_fixed_on_the_review_screen_imports(): void
    {
        $rows = $this->preview(['Aina Binti Ahmad,"100,000.00",0.00,0.00,0.00,0.00,0.00,"90,000.00",0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,'])->json('rows');
        $rows[0]['values']['unpaid'] = '10,000.00';
        $rows[0]['values']['gross'] = '100000';

        $this->import($rows)->assertOk();

        $this->assertSame(90000.0, $this->row($this->aina)?->gross);
    }

    public function test_a_non_number_points_at_its_cell_and_saves_nothing(): void
    {
        $this->upload(['Aina Binti Ahmad,#REF!,0.00,0.00,0.00,0.00,0.00,"1,000.00",0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,'])
            ->assertStatus(422)->assertJsonPath('problems.2.basic', "'#REF!' is not a number.");

        $this->assertSame(0, PayrollOpeningFigure::count());
    }

    /** A row left on Skip is not checked: its numbers are someone else's problem. */
    public function test_a_skipped_row_with_bad_numbers_does_not_block_the_rest(): void
    {
        $this->upload([
            'Aina Binti Ahmad,"1,000.00",0.00,0.00,0.00,0.00,0.00,"1,000.00",0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,',
            'Intern Nobody,"1,500.00",0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,',
        ])->assertOk();

        $this->assertSame(1000.0, $this->row($this->aina)?->gross);
    }

    public function test_a_file_missing_a_needed_column_is_refused(): void
    {
        $this->preview(['Aina Binti Ahmad,"1,000.00"'], 'STAFF TETAP,BASIC')->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_import_keeps_take_on_fields_the_sheet_does_not_carry(): void
    {
        PayrollOpeningFigure::forceCreate(['tenant_id' => $this->tenant->id, 'employee_id' => $this->aina->id, 'year' => 2026,
            'gross' => 1, 'optional_deductions' => 900, 'ea_lines' => ['d4' => 250, 'b1c_details' => 'Phone']]);

        $this->upload(['Aina Binti Ahmad,"1,000.00",0.00,0.00,0.00,0.00,0.00,"1,000.00",0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,'])->assertOk();

        $row = $this->row($this->aina);
        $this->assertSame(1000.0, $row?->gross);
        $this->assertSame(900.0, $row->optional_deductions);
        $this->assertSame(250.0, $row->line('d4'));
        $this->assertSame('Phone', $row->ea_lines['b1c_details'] ?? null);
    }

    /** A current staff member with no row would get no take-on at all, so HR is told. */
    public function test_current_staff_not_imported_are_named(): void
    {
        Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Left Early', 'status' => 'resigned', 'workload' => 'green']);

        $this->upload(['Aina Binti Ahmad,"1,000.00",0.00,0.00,0.00,0.00,0.00,"1,000.00",0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,'])
            ->assertOk()->assertSessionHas('ok', fn (string $msg) => str_contains($msg, 'not imported: Badrul Hisham.') && ! str_contains($msg, 'Left Early'));
    }

    public function test_nothing_picked_is_refused(): void
    {
        $this->upload(['Someone Else,"1,000.00",0.00,0.00,0.00,0.00,0.00,"1,000.00",0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,'])
            ->assertStatus(422)->assertJsonValidationErrors('rows');

        $this->assertDatabaseMissing('audit_logs', ['action' => 'Imported payroll take-on figures']);
    }

    public function test_a_previous_employer_tp3_row_is_flagged_and_not_overwritten(): void
    {
        PayrollOpeningFigure::forceCreate(['tenant_id' => $this->tenant->id, 'employee_id' => $this->aina->id, 'year' => 2026,
            'gross' => 5000, 'previous_employer' => 'Acme Prior Sdn Bhd']);

        $preview = $this->preview(['Aina Binti Ahmad,"1,000.00",0.00,0.00,0.00,0.00,0.00,"1,000.00",0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,']);
        $preview->assertJsonPath('tp3', [$this->aina->id]);
        $this->import($preview->json('rows'))->assertStatus(422)->assertJsonPath('problems.2.employee_id', fn ($m) => str_contains($m, 'Form TP3'));

        $this->assertSame(5000.0, $this->row($this->aina)?->gross);
    }

    public function test_a_staff_member_from_another_company_cannot_be_picked(): void
    {
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'initials' => 'OT']);
        $stranger = Employee::create(['tenant_id' => $other->id, 'name' => 'Stranger', 'status' => 'active', 'workload' => 'green']);
        $rows = $this->preview(['Aina Binti Ahmad,"1,000.00",0.00,0.00,0.00,0.00,0.00,"1,000.00",0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,'])->json('rows');
        $rows[0]['employee_id'] = $stranger->id;

        $this->import($rows)->assertStatus(422)->assertJsonPath('problems.2.employee_id', 'Not a staff member here.');

        $this->assertSame(0, PayrollOpeningFigure::withoutGlobalScopes()->count());
    }

    public function test_employee_cannot_preview_or_import(): void
    {
        $this->preview([], as: $this->empUser)->assertForbidden();
        $this->import([['line' => 2, 'employee_id' => $this->aina->id, 'values' => []]], as: $this->empUser)->assertForbidden();
    }

    /** Medical already claimed under the old system uses up the same yearly cap. */
    public function test_take_on_medical_counts_toward_the_medical_cap(): void
    {
        Storage::fake('local');
        app(FeatureManager::class)->setTenant($this->tenant, 'claims.medical_cap', 500);
        PayrollOpeningFigure::forceCreate(['tenant_id' => $this->tenant->id, 'employee_id' => $this->aina->id, 'year' => 2026, 'medical_claimed' => 400]);
        $claim = fn (float $amount, string $title) => $this->actingAs($this->empUser)->withSession(['current_tenant' => $this->tenant->id])
            ->post('/app/claims', ['type' => 'medical', 'title' => $title, 'amount' => $amount, 'date' => '2026-09-10',
                'receipt' => UploadedFile::fake()->create('r.pdf', 20, 'application/pdf')]);

        $claim(150, 'Too much')->assertSessionHasErrors('amount');
        $claim(100, 'Fits')->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('claims', ['title' => 'Too much']);
        $this->assertDatabaseHas('claims', ['title' => 'Fits']);
    }

    public function test_take_on_tab_saves_medical_claimed_by_hand(): void
    {
        $this->actingAs($this->hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post('/app/payroll/opening', ['employee_id' => $this->aina->id, 'year' => 2026, 'medical_claimed' => 320])
            ->assertSessionHasNoErrors();

        $this->assertSame(320.0, $this->row($this->aina)?->medical_claimed);
    }

    /**
     * In-app navigation swaps the page body and re-runs its scripts after alpine:init has
     * already fired, so the import card's component must also register straight away.
     */
    public function test_take_on_tab_registers_the_import_component_after_alpine_has_started(): void
    {
        $this->actingAs($this->hr)->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/payroll-transaction?tab=takeon')
            ->assertOk()
            ->assertSee("window.Alpine ? register() : document.addEventListener('alpine:init', register);", false);
    }

    /** A leaver added only for the year-end return is archived, and the import must still find them. */
    public function test_an_archived_leaver_is_matched_and_imported(): void
    {
        $leaver = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Chong Wei Lin', 'status' => 'resigned', 'workload' => 'green',
            'resigned_at' => '2026-03-01', 'last_working_day' => '2026-03-31', 'archived_at' => '2026-03-31']);

        $rows = $this->preview(['Chong Wei Lin,"1,000.00",0.00,0.00,0.00,0.00,0.00,"1,000.00",0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0.00,'])
            ->assertOk()->assertJsonPath('rows.0.employee_id', $leaver->id)->json('rows');
        $this->import($rows)->assertOk();

        $this->assertSame(1000.0, $this->row($leaver)?->gross);
    }

    /** A leaver shows on the Take On tab once they have figures, so HR can still check them; one without does not. */
    public function test_take_on_tab_lists_leavers_with_figures_only(): void
    {
        $archived = ['tenant_id' => $this->tenant->id, 'status' => 'resigned', 'workload' => 'green', 'archived_at' => '2026-03-31'];
        $withFigures = Employee::create([...$archived, 'name' => 'Chong Wei Lin']);
        Employee::create([...$archived, 'name' => 'Farid Kamal']);
        PayrollOpeningFigure::forceCreate(['tenant_id' => $this->tenant->id, 'employee_id' => $withFigures->id, 'year' => 2026, 'gross' => 1000]);

        $this->actingAs($this->hr)->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/payroll-transaction?tab=takeon')
            ->assertOk()->assertSee('Chong Wei Lin')->assertDontSee('Farid Kamal')->assertSee('Badrul Hisham');
    }
}
