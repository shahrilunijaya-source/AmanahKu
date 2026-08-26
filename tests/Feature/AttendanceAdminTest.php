<?php

namespace Tests\Feature;

use App\Attendance\ScheduleResolver;
use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Attendance Setup: client sites, the company-wide WFH policy, staff work arrangements,
 * and reversing a punch. Covers role gating, validation, and tenant isolation. Harness
 * mirrors OrgStructureAdminTest.
 */
class AttendanceAdminTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
    }

    private function actor(string $role): User
    {
        $this->seq++;
        $user = User::create(['name' => $role, 'email' => "{$role}{$this->seq}@example.com", 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => $role]);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => ucfirst($role), 'status' => 'active', 'workload' => 'green',
        ]);

        return $user;
    }

    private function actingAsRole(string $role): self
    {
        $this->actingAs($this->actor($role))->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    private function staff(string $arrangement, ?int $tenantId = null): Employee
    {
        return Employee::create([
            'tenant_id' => $tenantId ?? $this->tenant->id,
            'name' => 'WFH Worker', 'status' => 'active', 'workload' => 'green',
            'work_arrangement' => $arrangement,
        ]);
    }

    // --- Screen render ------------------------------------------------------

    public function test_attendance_setup_screen_renders(): void
    {
        Branch::create([
            'tenant_id' => $this->tenant->id, 'name' => 'KL', 'state' => 'WP',
            'work_start' => '10:00', 'work_end' => '19:00', 'min_hours' => 8,
        ]);
        $this->staff('wfh');
        $this->staff('hybrid');

        $html = $this->actingAsRole('hr')->get('/app/attendance-admin')->assertOk()->getContent();

        // Branch geofences moved to Company Settings → Branches; this screen no longer owns them.
        $this->assertStringNotContainsString('Branch geofences', $html);
        $this->assertStringContainsString('Client sites', $html);
        $this->assertStringContainsString('Work from home', $html);
        $this->assertStringNotContainsString('Registered home addresses', $html);
        $this->assertStringContainsString('Staff work arrangements', $html);
    }

    // --- Company-wide WFH hours ---------------------------------------------

    public function test_hr_can_set_the_company_wfh_policy(): void
    {
        $this->tenant->update(['late_grace_minutes' => 25]);

        $this->actingAsRole('hr')
            ->post('/app/attendance-admin/wfh-policy', [
                'wfh_work_start' => '10:00',
                'wfh_work_end' => '16:00',
                'wfh_min_hours' => 6,
            ])->assertRedirect()->assertSessionHas('ok');

        $t = $this->tenant->fresh();
        // DB time format differs by driver (SQLite 'HH:MM' / MySQL 'HH:MM:SS'); compare on HH:MM.
        $this->assertSame('10:00', substr((string) $t->wfh_work_start, 0, 5));
        $this->assertSame('16:00', substr((string) $t->wfh_work_end, 0, 5));
        $this->assertEquals(6.0, (float) $t->wfh_min_hours);
        // The WFH-hours form omits late_grace_minutes entirely; a plain 'nullable' rule would
        // have accepted the missing key as null and zeroed the grace. It must survive untouched.
        $this->assertSame(25, $t->late_grace_minutes);
    }

    public function test_late_grace_can_be_set_to_zero(): void
    {
        $this->actingAsRole('hr')
            ->post('/app/attendance-admin/wfh-policy', ['late_grace_minutes' => 0])
            ->assertRedirect()->assertSessionHas('ok');

        $this->assertSame(0, $this->tenant->fresh()->late_grace_minutes);
    }

    /**
     * A cleared Lateness box posts late_grace_minutes='', which ConvertEmptyStringsToNull
     * turns into null. Without a 'required' rule that null would validate as 'nullable' and
     * zero every employee's grace. Must be rejected, and the stored value must not move.
     */
    public function test_clearing_the_late_grace_is_rejected(): void
    {
        $this->tenant->update(['late_grace_minutes' => 20]);

        $this->actingAsRole('hr')
            ->post('/app/attendance-admin/wfh-policy', ['late_grace_minutes' => ''])
            ->assertSessionHasErrors(['late_grace_minutes']);

        $this->assertSame(20, $this->tenant->fresh()->late_grace_minutes);
    }

    /**
     * The grace control now posts on its own, from its own form, without the WFH hours
     * beside it. This is a standing regression guard: a grace-only post to this shared
     * endpoint must never touch the company's work-from-home hours.
     */
    public function test_saving_the_grace_alone_leaves_the_wfh_hours_untouched(): void
    {
        $this->tenant->update([
            'wfh_work_start' => '10:00',
            'wfh_work_end' => '16:00',
            'wfh_min_hours' => 6,
        ]);

        $this->actingAsRole('hr')
            ->post('/app/attendance-admin/wfh-policy', ['late_grace_minutes' => 20])
            ->assertRedirect()->assertSessionHas('ok');

        $t = $this->tenant->fresh();
        $this->assertSame(20, $t->late_grace_minutes);
        // DB time format differs by driver (SQLite 'HH:MM' / MySQL 'HH:MM:SS'); compare on HH:MM.
        $this->assertSame('10:00', substr((string) $t->wfh_work_start, 0, 5));
        $this->assertSame('16:00', substr((string) $t->wfh_work_end, 0, 5));
        $this->assertEquals(6.0, (float) $t->wfh_min_hours);
    }

    public function test_wfh_follows_company_hours_not_the_staffs_own_branch(): void
    {
        // Staff belongs to a branch with its own hours...
        $branch = Branch::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Seremban 2', 'state' => 'Negeri Sembilan',
            'work_start' => '08:00', 'work_end' => '17:00', 'min_hours' => 9,
        ]);
        // ...but the company WFH standard wins on home days.
        $this->tenant->update([
            'wfh_work_start' => '10:00', 'wfh_work_end' => '16:00', 'wfh_min_hours' => 6,
        ]);
        $e = Employee::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $branch->id,
            'name' => 'Home Dev', 'status' => 'active', 'workload' => 'green',
            'work_arrangement' => 'wfh',
        ]);

        $spec = app(ScheduleResolver::class)->resolve($e->fresh(), now());

        $this->assertSame('home', $spec->type);
        $this->assertSame('10:00', $spec->workStart);   // company WFH, not Seremban 2's 08:00
        $this->assertSame('16:00', $spec->workEnd);
        $this->assertSame(6.0, $spec->minHours);
    }

    public function test_hybrid_home_days_also_follow_company_hours(): void
    {
        $branch = Branch::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Klang', 'state' => 'Selangor',
            'work_start' => '08:00', 'work_end' => '17:00',
        ]);
        $this->tenant->update(['wfh_work_start' => '09:30', 'wfh_work_end' => '18:30']);
        $e = Employee::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $branch->id,
            'name' => 'Hybrid Dev', 'status' => 'active', 'workload' => 'green',
            'work_arrangement' => 'hybrid', 'hybrid_office_days' => [1], // office Mon only
        ]);

        // Pick a Tuesday (a home day) so the resolver returns the home site.
        $tuesday = now()->next(Carbon::TUESDAY);
        $spec = app(ScheduleResolver::class)->resolve($e->fresh(), $tuesday);

        $this->assertSame('home', $spec->type);
        $this->assertSame('09:30', $spec->workStart);
    }

    public function test_wfh_falls_back_to_branch_hours_when_company_policy_blank(): void
    {
        $branch = Branch::create([
            'tenant_id' => $this->tenant->id, 'name' => 'KL', 'state' => 'WP',
            'work_start' => '09:00', 'work_end' => '18:00', 'min_hours' => 8,
        ]);
        $e = Employee::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $branch->id,
            'name' => 'Home Dev', 'status' => 'active', 'workload' => 'green',
            'work_arrangement' => 'wfh',
        ]);

        $spec = app(ScheduleResolver::class)->resolve($e->fresh(), now());

        // No company policy set → borrow the staff's branch hours.
        $this->assertSame('09:00', $spec->workStart);
    }

    public function test_plain_employee_cannot_set_the_wfh_policy(): void
    {
        $this->actingAsRole('employee')
            ->post('/app/attendance-admin/wfh-policy', ['wfh_min_hours' => 6])
            ->assertForbidden();

        $this->assertNull($this->tenant->fresh()->wfh_min_hours);
    }

    // --- Reverse a punch -----------------------------------------------------

    private function punchedRecord(Employee $employee, bool $withClockOut, ?array $extra = null): AttendanceRecord
    {
        return AttendanceRecord::create(array_merge([
            'tenant_id' => $employee->tenant_id,
            'employee_id' => $employee->id,
            'date' => Carbon::today(),
            'clock_in' => '09:00:00',
            'clock_out' => $withClockOut ? '18:00:00' : null,
            'latitude' => 3.0,
            'longitude' => 101.0,
            'flags' => $withClockOut ? ['out_of_radius_out'] : [],
            'worked_minutes' => $withClockOut ? 540 : null,
        ], $extra ?? []));
    }

    public function test_hr_reverses_a_clock_out_and_the_employee_can_clock_out_again(): void
    {
        Storage::fake('local');
        $this->actingAsRole('hr');
        $e = $this->staff('office');
        $record = $this->punchedRecord($e, withClockOut: true, extra: ['clock_out_photo_path' => 'attendance-photos/out.jpg']);
        Storage::disk('local')->put('attendance-photos/out.jpg', 'x');

        $this->post("/app/attendance-admin/records/{$record->id}/reverse")
            ->assertRedirect()->assertSessionHas('ok');

        $record->refresh();
        $this->assertNotNull($record->clock_in);
        $this->assertNull($record->clock_out);
        $this->assertNull($record->worked_minutes);
        $this->assertNotContains('out_of_radius_out', $record->flags ?? []);
        Storage::disk('local')->assertMissing('attendance-photos/out.jpg');
    }

    /** Reversing an auto-closed punch takes the sweep's mark with the time it stamped. */
    public function test_hr_reverses_an_auto_clock_out_and_the_flag_goes_with_it(): void
    {
        $this->actingAsRole('hr');
        $e = $this->staff('office');
        $record = $this->punchedRecord($e, withClockOut: true, extra: ['flags' => ['auto_out', 'short_hours']]);

        $this->post("/app/attendance-admin/records/{$record->id}/reverse")
            ->assertRedirect()->assertSessionHas('ok');

        $record->refresh();
        $this->assertNull($record->clock_out);
        $this->assertNotContains('auto_out', $record->flags ?? []);
    }

    public function test_hr_reverses_a_site_visit_clock_out_and_clears_the_mode(): void
    {
        $this->actingAsRole('hr');
        $e = $this->staff('office');
        // Seeded the way ClockService actually writes a site visit now: clock_out_work_mode
        // carries it, and no flag is written at all.
        $record = $this->punchedRecord($e, withClockOut: true, extra: [
            'clock_out_work_mode' => 'site_visit',
            'flags' => [],
        ]);

        $this->post("/app/attendance-admin/records/{$record->id}/reverse")
            ->assertRedirect()->assertSessionHas('ok');

        $record->refresh();
        $this->assertNull($record->clock_out);
        // Left behind, the day partial keeps taking the site-visit branch on a null
        // clock-out — see Finding 3 of the 2026-08-19 final review.
        $this->assertNull($record->clock_out_work_mode);
    }

    public function test_hr_reverses_a_clock_in_and_deletes_the_record(): void
    {
        $this->actingAsRole('hr');
        $e = $this->staff('office');
        $record = $this->punchedRecord($e, withClockOut: false);

        $this->post("/app/attendance-admin/records/{$record->id}/reverse")
            ->assertRedirect()->assertSessionHas('ok');

        $this->assertModelMissing($record);
    }

    public function test_director_can_reverse_a_punch(): void
    {
        $this->actingAsRole('director');
        $e = $this->staff('office');
        $record = $this->punchedRecord($e, withClockOut: true);

        $this->post("/app/attendance-admin/records/{$record->id}/reverse")->assertRedirect()->assertSessionHas('ok');
    }

    public function test_plain_management_cannot_reverse_a_punch(): void
    {
        $this->actingAsRole('management');
        $e = $this->staff('office');
        $record = $this->punchedRecord($e, withClockOut: true);

        $this->post("/app/attendance-admin/records/{$record->id}/reverse")->assertForbidden();

        $this->assertNotNull($record->fresh()->clock_out);
    }

    public function test_manager_cannot_reverse_a_punch(): void
    {
        $this->actingAsRole('manager');
        $e = $this->staff('office');
        $record = $this->punchedRecord($e, withClockOut: true);

        $this->post("/app/attendance-admin/records/{$record->id}/reverse")->assertForbidden();
    }

    public function test_super_admin_observer_can_reverse_a_punch(): void
    {
        $user = User::create(['name' => 'Root', 'email' => 'root@example.com', 'password' => Hash::make('password')]);
        $user->forceFill(['is_super_admin' => true])->save();
        $this->actingAs($user)->withSession(['current_tenant' => $this->tenant->id]);
        $e = $this->staff('office');
        $record = $this->punchedRecord($e, withClockOut: true);

        $this->post("/app/attendance-admin/records/{$record->id}/reverse")->assertRedirect()->assertSessionHas('ok');
    }

    public function test_hr_cannot_reverse_a_punch_for_another_tenants_staff(): void
    {
        $other = Tenant::create(['slug' => 'other2', 'name' => 'Other2', 'initials' => 'O2']);
        $foreign = $this->staff('office', $other->id);
        $record = $this->punchedRecord($foreign, withClockOut: true);

        $this->actingAsRole('hr')
            ->post("/app/attendance-admin/records/{$record->id}/reverse")
            ->assertForbidden();

        $this->assertNotNull($record->fresh()->clock_out);
    }

    // --- Amend a missing clock-out ------------------------------------------

    /** A record with a clock-in and the clock-out somebody forgot. */
    private function openRecord(): AttendanceRecord
    {
        return $this->punchedRecord($this->staff('office'), withClockOut: false);
    }

    public function test_hr_can_set_a_missing_clock_out(): void
    {
        $this->actingAsRole('hr');
        $record = $this->openRecord();

        $this->post(route('attendance.admin.records.amend', $record), ['time' => '18:00'])
            ->assertRedirect()->assertSessionHas('ok');

        $record->refresh();

        $this->assertSame('18:00', substr((string) $record->clock_out, 0, 5));
        $this->assertSame(540, $record->worked_minutes);
        $this->assertContains('amended', $record->flags ?? [], 'a typed time is not a punch');
    }

    public function test_a_short_amended_day_picks_up_the_short_hours_flag(): void
    {
        $this->actingAsRole('hr');
        $record = $this->openRecord();

        $this->post(route('attendance.admin.records.amend', $record), ['time' => '12:00']);

        $this->assertContains('short_hours', $record->refresh()->flags ?? []);
    }

    public function test_a_clock_out_before_the_clock_in_is_rejected(): void
    {
        $this->actingAsRole('hr');
        $record = $this->openRecord();

        $this->from('/app/attendance-report')
            ->post(route('attendance.admin.records.amend', $record), ['time' => '08:00'])
            ->assertSessionHasErrors('time');

        $this->assertNull($record->refresh()->clock_out);
    }

    public function test_a_record_that_already_has_a_clock_out_is_refused(): void
    {
        $this->actingAsRole('hr');
        $record = $this->punchedRecord($this->staff('office'), withClockOut: true);

        $this->post(route('attendance.admin.records.amend', $record), ['time' => '19:00'])
            ->assertStatus(422);

        $this->assertSame('18:00', substr((string) $record->refresh()->clock_out, 0, 5),
            'reverse the clock-out first; this endpoint only fills a hole');
    }

    public function test_a_manager_cannot_amend_a_clock_out(): void
    {
        $this->actingAsRole('manager');
        $record = $this->openRecord();

        $this->post(route('attendance.admin.records.amend', $record), ['time' => '18:00'])
            ->assertForbidden();

        $this->assertNull($record->refresh()->clock_out);
    }

    public function test_hr_cannot_amend_a_clock_out_for_another_tenants_staff(): void
    {
        $other = Tenant::create(['slug' => 'other3', 'name' => 'Other3', 'initials' => 'O3']);
        $record = $this->punchedRecord($this->staff('office', $other->id), withClockOut: false);

        $this->actingAsRole('hr')
            ->post(route('attendance.admin.records.amend', $record), ['time' => '18:00'])
            ->assertForbidden();

        $this->assertNull($record->fresh()->clock_out);
    }

    public function test_the_amendment_is_recorded_in_the_audit_trail(): void
    {
        $this->actingAsRole('hr');
        $record = $this->openRecord();

        $this->post(route('attendance.admin.records.amend', $record), ['time' => '18:00']);

        $this->assertDatabaseHas('audit_logs', ['action' => 'Amended clock-out']);
    }

    public function test_reversing_an_amended_clock_out_drops_the_amended_mark(): void
    {
        $this->actingAsRole('hr');
        $record = $this->openRecord();

        $this->post(route('attendance.admin.records.amend', $record), ['time' => '18:00']);
        $this->post(route('attendance.admin.records.reverse', $record));

        $record->refresh();

        $this->assertNull($record->clock_out);
        $this->assertNotContains('amended', $record->flags ?? [],
            'the typed time is gone, so the mark that described it must go too');
    }
}
