<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DataScope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The person drawer: one employee's period, with the selfie, the typed remark, the
 * map and the write actions a one-line table row has no room for.
 *
 * Frozen at Wed 15 Jul 2026, so 'week' spans Mon 13 – Wed 15 Jul.
 */
class AttendanceReportDrawerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $hrUser;

    private Employee $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-07-15 10:00:00'));

        $this->tenant = Tenant::create(['slug' => 'alpha', 'name' => 'Alpha', 'initials' => 'AL']);

        $this->hrUser = User::create([
            'name' => 'HR Manager', 'email' => 'hr@example.com', 'password' => Hash::make('password'),
        ]);
        $this->hrUser->tenants()->attach($this->tenant->id, ['role' => 'hr']);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->hrUser->id,
            'name' => 'HR Manager', 'status' => 'active', 'workload' => 'green',
        ]);

        $this->subject = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Punched Staff',
            'status' => 'active', 'workload' => 'green',
        ]);
    }

    private function actAsHr(): self
    {
        return $this->actingAs($this->hrUser)->withSession([
            'current_tenant' => $this->tenant->id, 'persona' => 'hr',
        ]);
    }

    private function actAsManager(): self
    {
        $user = User::create([
            'name' => 'Line Manager', 'email' => 'manager@example.com', 'password' => Hash::make('password'),
        ]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'manager']);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => 'Line Manager', 'status' => 'active', 'workload' => 'green',
        ]);

        return $this->actingAs($user)->withSession([
            'current_tenant' => $this->tenant->id, 'persona' => 'manager',
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function recordFor(string $date, array $extra = []): AttendanceRecord
    {
        return AttendanceRecord::create(array_merge([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->subject->id,
            'date' => $date, 'clock_in' => '09:00:00', 'clock_out' => '18:00:00',
            'status' => 'on_time', 'worked_minutes' => 540, 'flags' => [],
        ], $extra));
    }

    private function open(string $query = ''): TestResponse
    {
        return $this->actAsHr()
            ->get('/app/attendance-report?gran=week&emp='.$this->subject->id.$query);
    }

    public function test_the_drawer_lists_the_persons_days(): void
    {
        $this->recordFor('2026-07-14');

        $this->open()
            ->assertOk()
            ->assertSee('uj-ar-drawer', false)
            ->assertSee('Punched Staff');
    }

    public function test_no_drawer_renders_without_an_emp(): void
    {
        $this->actAsHr()->get('/app/attendance-report?gran=week')
            ->assertOk()
            ->assertDontSee('uj-ar-drawer', false);
    }

    public function test_hr_sees_a_reverse_control_in_the_drawer(): void
    {
        $this->recordFor('2026-07-14');

        $this->open()
            ->assertSee('attendance-admin/records/', false)
            ->assertSee('Reverse clock-out');
    }

    public function test_a_record_with_no_clock_out_offers_to_reverse_the_clock_in(): void
    {
        // reversePunch() deletes the whole record in that case, so the control must
        // not claim it only clears the clock-out.
        $this->recordFor('2026-07-14', ['clock_out' => null, 'worked_minutes' => null]);

        $this->open()
            ->assertSee('Reverse clock-in')
            ->assertDontSee('Reverse clock-out');
    }

    public function test_a_manager_does_not_see_a_reverse_control(): void
    {
        $this->recordFor('2026-07-14');
        $this->subject->update(['reports_to_id' => Employee::where('name', 'Line Manager')->value('id')]);

        $this->actAsManager()
            ->get('/app/attendance-report?gran=week&emp='.$this->subject->id)
            ->assertOk()
            ->assertDontSee('attendance-admin/records/', false);
    }

    public function test_a_missing_clock_out_offers_the_amend_form(): void
    {
        $record = $this->recordFor('2026-07-14', ['clock_out' => null, 'worked_minutes' => null]);

        $this->open()
            ->assertSee('Add clock-out')
            ->assertSee(route('attendance.admin.records.amend', $record->id), false);
    }

    public function test_a_fix_deep_link_arrives_with_that_day_already_open(): void
    {
        $this->recordFor('2026-07-14', ['clock_out' => null, 'worked_minutes' => null]);

        $plain = $this->open()->getContent();
        $linked = $this->open('&day=2026-07-14')->getContent();

        // Alpine seeds each day row's `open` from openDay, so the deep link and a
        // plain click land on the same screen rather than forking into two paths.
        $this->assertStringNotContainsString('open: true', $plain);
        $this->assertSame(1, substr_count($linked, 'open: true'));
    }

    public function test_the_drawer_shows_the_remark_the_employee_typed(): void
    {
        $this->recordFor('2026-07-14', ['clock_in_justification' => 'Stuck on the Federal Highway.']);

        $this->open()->assertSee('Stuck on the Federal Highway.');
    }

    public function test_the_drawer_offers_the_photo_when_one_was_captured(): void
    {
        $this->recordFor('2026-07-14', ['photo_path' => 'attendance-photos/in.jpg']);

        $this->open()->assertSee('uj-ar-shot', false);
    }

    public function test_a_record_with_no_photo_offers_no_thumbnail(): void
    {
        $this->recordFor('2026-07-14');

        $this->open()->assertDontSee('uj-ar-shot', false);
    }

    public function test_a_drawer_for_someone_outside_scope_renders_nothing(): void
    {
        $other = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Not Yours',
            'status' => 'active', 'workload' => 'green',
        ]);

        $this->mock(DataScope::class, function ($mock) {
            $mock->shouldReceive('visibleEmployeeIds')->andReturn([$this->subject->id]);
        });

        $this->actAsHr()->get('/app/attendance-report?emp='.$other->id)
            ->assertOk()
            ->assertDontSee('Not Yours')
            ->assertDontSee('uj-ar-drawer', false);
    }

    public function test_an_archived_person_cannot_be_opened(): void
    {
        $gone = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Left The Company',
            'status' => 'resigned', 'workload' => 'green', 'archived_at' => now(),
        ]);

        $this->actAsHr()->get('/app/attendance-report?emp='.$gone->id)
            ->assertOk()
            ->assertDontSee('uj-ar-drawer', false);
    }
}
