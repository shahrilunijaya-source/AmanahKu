<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeProgression;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * "Upload leaver dates" in the directory's Archived view: Check file previews and writes
 * nothing, Apply writes only the rows the server still finds ready, and only people who
 * can archive staff get in. The rules themselves are covered in BackfillLeaversTest.
 */
class LeaverBackfillScreenTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
    }

    /** @param array<string, mixed> $attrs */
    private function emp(string $name, array $attrs = []): Employee
    {
        return Employee::create(array_merge([
            'tenant_id' => $this->tenant->id, 'name' => $name, 'status' => 'resigned', 'workload' => 'green',
            'joined_at' => '2025-03-03',
        ], $attrs));
    }

    private function actor(string $role): static
    {
        $user = User::create(['name' => ucfirst($role), 'email' => "{$role}@example.com", 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => $role]);
        $this->emp(ucfirst($role).' Person', ['user_id' => $user->id, 'status' => 'active']);

        return $this->actingAs($user)->withSession(['current_tenant' => $this->tenant->id]);
    }

    private function csv(string ...$rows): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('leavers.csv', "name,last_working_day,resigned_on,joined_on\n".implode("\n", $rows)."\n");
    }

    public function test_check_file_previews_each_row_and_writes_nothing(): void
    {
        $leaver = $this->emp('Alya Leaver');

        $this->actor('hr')->followingRedirects()
            ->post('/app/leavers/preview', ['file' => $this->csv('Alya Leaver,2026-02-06,2026-01-05,', 'Nobody Here,2026-02-06,,')])
            ->assertOk()
            ->assertSee('Upload leaver dates')
            ->assertSee('Record resignation, archive')
            ->assertSee('No staff record with this exact name')
            ->assertSee('Apply to 1 person');

        $this->assertNull($leaver->fresh()->archived_at);
        $this->assertSame(0, EmployeeProgression::where('employee_id', $leaver->id)->count());
    }

    public function test_apply_writes_the_ready_rows_and_skips_the_rest(): void
    {
        $leaver = $this->emp('Alya Leaver');
        $current = $this->emp('Still Working', ['status' => 'active']);

        $this->actor('hr')->post('/app/leavers/apply', ['rows' => [
            ['name' => 'Alya Leaver', 'last_working_day' => '2026-02-06', 'resigned_on' => '2026-01-05', 'joined_on' => ''],
            ['name' => 'Still Working', 'last_working_day' => '2026-02-06', 'resigned_on' => '', 'joined_on' => ''],
        ]])->assertRedirect('/app/directory?view=archived')->assertSessionHas('ok', 'Leaving dates recorded and archived for 1 person.');

        $leaver->refresh();
        $this->assertSame('2026-02-06', $leaver->archived_at->toDateString());
        $this->assertSame('2026-01-05', $leaver->resigned_at->toDateString());
        $this->assertSame(1, EmployeeProgression::where('employee_id', $leaver->id)->where('type', 'resigned')->count());
        $this->assertNull($current->fresh()->archived_at);
    }

    /**
     * A super admin sits in the company as an observer (not a member). They can run the
     * upload, by choice even though AuditLog writes no row for observers; the timeline
     * entry still says the dates were backfilled.
     */
    public function test_a_super_admin_observer_can_apply(): void
    {
        $leaver = $this->emp('Alya Leaver');
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => Hash::make('password')]);
        $admin->forceFill(['is_super_admin' => true])->save();

        $this->actingAs($admin)->withSession(['current_tenant' => $this->tenant->id])
            ->post('/app/leavers/apply', ['rows' => [['name' => 'Alya Leaver', 'last_working_day' => '2026-02-06']]])
            ->assertRedirect('/app/directory?view=archived');

        $this->assertSame('2026-02-06', $leaver->fresh()->archived_at->toDateString());
    }

    public function test_staff_who_cannot_archive_are_refused(): void
    {
        $leaver = $this->emp('Alya Leaver');

        $this->actor('employee')->post('/app/leavers/preview', ['file' => $this->csv('Alya Leaver,2026-02-06,,')])->assertForbidden();
        $this->post('/app/leavers/apply', ['rows' => [['name' => 'Alya Leaver', 'last_working_day' => '2026-02-06']]])->assertForbidden();

        $this->assertNull($leaver->fresh()->archived_at);
    }
}
