<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Governance edges CR34Test does not pin: two tenants swept by the same scheduler run
 * never mix their cards or mail, an archived person (whether PM/PE or role-qualified)
 * gets nothing, and the settings screen is gated the same as its own POST route.
 */
class ManagementMeetingTest extends TestCase
{
    use RefreshDatabase;

    private const FRIDAY = '2026-09-11';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::FRIDAY.' 08:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function person(Tenant $tenant, string $name, string $role = 'employee', array $attrs = []): Employee
    {
        $user = User::create(['name' => $name, 'email' => strtolower(preg_replace('/\W+/', '', $name)).uniqid().'@example.com', 'password' => Hash::make('password')]);
        $user->tenants()->attach($tenant->id, ['role' => $role]);

        return Employee::create(array_merge(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'name' => $name, 'status' => 'active', 'workload' => 'green'], $attrs));
    }

    private function actingIn(Tenant $tenant, Employee $employee): static
    {
        $this->actingAs($employee->user)->withSession(['current_tenant' => $tenant->id]);

        return $this;
    }

    #[Test]
    public function the_two_commands_never_mix_up_two_tenants(): void
    {
        $tenantA = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $tenantB = Tenant::create(['slug' => 'other', 'name' => 'Other Co', 'initials' => 'OC']);

        $managerA = $this->person($tenantA, 'Manager A', 'manager');
        Project::create(['tenant_id' => $tenantA->id, 'code' => 'A1', 'name' => 'A Project', 'pm_id' => $managerA->id]);

        $managerB = $this->person($tenantB, 'Manager B', 'manager');
        Project::create(['tenant_id' => $tenantB->id, 'code' => 'B1', 'name' => 'B Project', 'pm_id' => $managerB->id]);

        $this->artisan('management:meeting-tasks')->run();
        $this->artisan('management:meeting-reminder')->run();

        $cardsA = WorkItem::where('tenant_id', $tenantA->id)->where('source', 'management_meeting')->get();
        $cardsB = WorkItem::where('tenant_id', $tenantB->id)->where('source', 'management_meeting')->get();
        $this->assertSame([$managerA->id], $cardsA->pluck('employee_id')->all(), 'tenant A got the wrong cards');
        $this->assertSame([$managerB->id], $cardsB->pluck('employee_id')->all(), 'tenant B got the wrong cards');

        $rowA = DB::table('port_outbox')->where('tenant_id', $tenantA->id)->where('payload', 'like', '%management_meeting_reminder%')->first();
        $rowB = DB::table('port_outbox')->where('tenant_id', $tenantB->id)->where('payload', 'like', '%management_meeting_reminder%')->first();
        $this->assertNotNull($rowA);
        $this->assertNotNull($rowB);
        $toA = json_decode($rowA->payload, true)['to'];
        $toB = json_decode($rowB->payload, true)['to'];
        $this->assertSame([$managerA->user->email], $toA, 'tenant A reminder leaked to tenant B');
        $this->assertSame([$managerB->user->email], $toB, 'tenant B reminder leaked to tenant A');
    }

    #[Test]
    public function an_archived_person_gets_no_card_and_no_reminder_even_as_pm_or_by_role(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $active = $this->person($tenant, 'Active PM', 'manager');
        $archivedPm = $this->person($tenant, 'Archived PM', 'manager', ['archived_at' => '2026-08-01 00:00:00']);
        $archivedHr = $this->person($tenant, 'Archived HR', 'hr', ['archived_at' => '2026-08-01 00:00:00']);
        Project::create(['tenant_id' => $tenant->id, 'code' => 'P1', 'name' => 'P Project', 'pm_id' => $active->id]);
        Project::create(['tenant_id' => $tenant->id, 'code' => 'P2', 'name' => 'Old PM Project', 'pm_id' => $archivedPm->id]);

        $this->artisan('management:meeting-tasks')->run();
        $this->artisan('management:meeting-reminder')->run();

        $cards = WorkItem::where('tenant_id', $tenant->id)->where('source', 'management_meeting')->get();
        $this->assertSame([$active->id], $cards->pluck('employee_id')->all(), 'an archived PM or role holder still got a card');

        $row = DB::table('port_outbox')->where('tenant_id', $tenant->id)->where('payload', 'like', '%management_meeting_reminder%')->first();
        $this->assertNotNull($row);
        $to = json_decode($row->payload, true)['to'];
        $this->assertSame([$active->user->email], $to, 'an archived person still received the reminder');
        $this->assertNotContains($archivedPm->user->email, $to);
        $this->assertNotContains($archivedHr->user->email, $to);
    }

    #[Test]
    public function the_settings_screen_is_gated_the_same_as_its_post_route(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $manager = $this->person($tenant, 'Manager', 'manager');
        $hr = $this->person($tenant, 'HR', 'hr');
        $director = $this->person($tenant, 'Director', 'director');

        $this->actingIn($tenant, $manager)->get('/app/management-meeting')->assertStatus(403);
        $this->actingIn($tenant, $hr)->get('/app/management-meeting')->assertOk()
            ->assertSee('name="meeting_day"', false);
        $this->actingIn($tenant, $director)->get('/app/management-meeting')->assertOk()
            ->assertSee('name="meeting_day"', false);
    }
}
