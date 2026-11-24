<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeProgression;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * staff:backfill-leavers: records the real leaving dates for staff who were marked
 * resigned without ever going through the resignation flow, then archives them dated
 * their last working day so year-end forms (Form E, EA, CP8D) see the right year.
 */
class BackfillLeaversTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        app(CurrentTenant::class)->set($this->tenant);
    }

    /** @param array<string, mixed> $attrs */
    private function emp(string $name, array $attrs = []): Employee
    {
        return Employee::create(array_merge([
            'tenant_id' => $this->tenant->id, 'name' => $name, 'status' => 'resigned', 'workload' => 'green',
            'joined_at' => '2026-09-30',
        ], $attrs));
    }

    /** @param list<string> $rows */
    private function csv(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'leavers').'.csv';
        file_put_contents($path, "name,last_working_day,resigned_on,joined_on\n".implode("\n", $rows)."\n");

        return $path;
    }

    public function test_apply_records_the_dates_and_archives_on_the_last_working_day(): void
    {
        // Arrange
        $leaver = $this->emp('Alya Leaver');
        $report = $this->emp('Still Here', ['status' => 'active', 'joined_at' => '2025-01-01', 'reports_to_id' => $leaver->id]);
        $file = $this->csv(['Alya Leaver,2026-02-06,2026-01-05,2025-03-03']);

        // Act
        $this->artisan('staff:backfill-leavers', ['file' => $file, '--tenant' => 'acme', '--apply' => true])->assertSuccessful();

        // Assert
        $leaver->refresh();
        $this->assertSame('2025-03-03', $leaver->joined_at->toDateString());
        $this->assertSame('2026-01-05', $leaver->resigned_at->toDateString());
        $this->assertSame('2026-02-06', $leaver->last_working_day->toDateString());
        $this->assertSame('2026-02-06', $leaver->archived_at->toDateString());
        $this->assertSame(1, EmployeeProgression::where('employee_id', $leaver->id)->where('type', 'resigned')->count());
        $this->assertNull($report->fresh()->reports_to_id, 'archiving hands their reports up the chain');
    }

    public function test_without_apply_nothing_changes(): void
    {
        $leaver = $this->emp('Alya Leaver');

        $this->artisan('staff:backfill-leavers', ['file' => $this->csv(['Alya Leaver,2026-02-06,,2025-03-03']), '--tenant' => 'acme'])
            ->assertSuccessful();

        $leaver->refresh();
        $this->assertNull($leaver->last_working_day);
        $this->assertNull($leaver->archived_at);
        $this->assertSame(0, EmployeeProgression::where('employee_id', $leaver->id)->count());
    }

    public function test_running_twice_does_not_duplicate_or_move_the_archive_date(): void
    {
        $leaver = $this->emp('Alya Leaver');
        $file = $this->csv(['Alya Leaver,2026-02-06,,2025-03-03']);

        $this->artisan('staff:backfill-leavers', ['file' => $file, '--tenant' => 'acme', '--apply' => true])->assertSuccessful();
        $this->artisan('staff:backfill-leavers', ['file' => $file, '--tenant' => 'acme', '--apply' => true])->assertSuccessful();

        $this->assertSame(1, EmployeeProgression::where('employee_id', $leaver->id)->where('type', 'resigned')->count());
        $this->assertSame('2026-02-06', $leaver->fresh()->archived_at->toDateString());
    }

    public function test_a_leaver_who_already_has_a_resignation_on_record_is_only_archived(): void
    {
        $leaver = $this->emp('Badrul Leaver', ['joined_at' => '2021-04-12', 'resigned_at' => '2026-06-01', 'last_working_day' => '2026-08-14']);
        EmployeeProgression::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $leaver->id, 'type' => 'resigned',
            'effective_on' => '2026-06-01', 'snapshot' => [], 'changed_fields' => [],
        ]);

        $this->artisan('staff:backfill-leavers', ['file' => $this->csv(['Badrul Leaver,2026-08-14,,']), '--tenant' => 'acme', '--apply' => true])
            ->assertSuccessful();

        $leaver->refresh();
        $this->assertSame('2021-04-12', $leaver->joined_at->toDateString());
        $this->assertSame('2026-08-14', $leaver->archived_at->toDateString());
        $this->assertSame(1, EmployeeProgression::where('employee_id', $leaver->id)->where('type', 'resigned')->count());
    }

    public function test_rows_it_cannot_trust_are_skipped_and_the_rest_still_run(): void
    {
        $current = $this->emp('Current Person', ['status' => 'active', 'joined_at' => '2025-01-01']);
        $tooEarly = $this->emp('Join After Leave');
        $good = $this->emp('Good Row');
        $file = $this->csv([
            'Nobody By This Name,2026-02-06,,',
            'Current Person,2026-02-06,,',
            'Join After Leave,2026-02-06,,',
            'Good Row,31/01/2026,,',
            'Good Row,2026-01-31,,2025-11-01',
        ]);

        $this->artisan('staff:backfill-leavers', ['file' => $file, '--tenant' => 'acme', '--apply' => true])
            ->expectsOutputToContain('Nobody By This Name: no staff record with this exact name')
            ->expectsOutputToContain('Current Person: not marked resigned')
            ->expectsOutputToContain('Join After Leave: last working day is before their join date')
            ->expectsOutputToContain('Good Row: dates must be YYYY-MM-DD')
            ->assertSuccessful();

        $this->assertNull($current->fresh()->archived_at);
        $this->assertNull($tooEarly->fresh()->archived_at);
        $this->assertSame('2026-01-31', $good->fresh()->archived_at->toDateString());
    }
}
