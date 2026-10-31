<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Claim;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\VerifyDigest;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * approvals:verify-digest sends each manager ONE email listing every leave and claim
 * still waiting for their verification, instead of one email per request.
 */
class VerifyDigestTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $managerUser;

    private User $dottedUser;

    private User $idleManagerUser;

    private User $workerUser;

    private Employee $worker;

    private LeaveType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->makeTenant('acme');
        app(CurrentTenant::class)->set($this->tenant);

        [$this->managerUser, $manager] = $this->person('Manager');
        [$this->dottedUser, $dotted] = $this->person('Dotted');
        [$this->idleManagerUser] = $this->person('Idle');
        [$this->workerUser, $this->worker] = $this->person('Worker', $manager);
        $this->worker->additionalManagers()->attach($dotted->id);

        $this->type = LeaveType::create(['tenant_id' => $this->tenant->id, 'name' => 'Annual', 'entitlement' => 18]);

        // 2 pending leave + 1 pending claim count; verified/approved ones must not.
        foreach (['submitted', 'submitted', 'verified', 'approved'] as $status) {
            $this->leave($this->worker, $status);
        }
        $this->claim($this->worker, 'submitted');
        $this->claim($this->worker, 'verified');

        app(CurrentTenant::class)->set(null);
    }

    protected function tearDown(): void
    {
        app(CurrentTenant::class)->set(null);

        parent::tearDown();
    }

    public function test_manager_gets_one_email_listing_every_pending_item(): void
    {
        Notification::fake();

        $this->artisan('approvals:verify-digest')->assertSuccessful();

        Notification::assertSentToTimes($this->managerUser, VerifyDigest::class, 1);
        Notification::assertSentTo($this->managerUser, VerifyDigest::class, function (VerifyDigest $n, array $channels) {
            $kinds = array_column($n->items(), 'kind');

            return $channels === ['mail'] && $kinds === ['Leave', 'Leave', 'Claim'];
        });
    }

    public function test_dotted_line_manager_also_gets_it(): void
    {
        Notification::fake();

        $this->artisan('approvals:verify-digest')->assertSuccessful();

        Notification::assertSentTo($this->dottedUser, VerifyDigest::class, fn (VerifyDigest $n) => count($n->items()) === 3);
    }

    public function test_nobody_without_pending_items_is_emailed(): void
    {
        Notification::fake();

        $this->artisan('approvals:verify-digest')->assertSuccessful();

        Notification::assertNotSentTo($this->idleManagerUser, VerifyDigest::class);
        Notification::assertNotSentTo($this->workerUser, VerifyDigest::class);
    }

    public function test_archived_requester_is_left_out(): void
    {
        $this->worker->update(['archived_at' => now()]);
        Notification::fake();

        $this->artisan('approvals:verify-digest')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_other_tenant_items_do_not_leak_in(): void
    {
        $other = $this->makeTenant('beta');
        app(CurrentTenant::class)->set($other);
        [$otherManagerUser, $otherManager] = $this->person('Other manager', null, $other);
        [, $otherWorker] = $this->person('Other worker', $otherManager, $other);
        $this->claim($otherWorker, 'submitted', $other);
        app(CurrentTenant::class)->set(null);
        Notification::fake();

        $this->artisan('approvals:verify-digest')->assertSuccessful();

        Notification::assertSentTo($this->managerUser, VerifyDigest::class, fn (VerifyDigest $n) => count($n->items()) === 3);
        Notification::assertSentTo($otherManagerUser, VerifyDigest::class, fn (VerifyDigest $n) => count($n->items()) === 1);
    }

    public function test_email_lists_the_items(): void
    {
        $mail = (new VerifyDigest([
            ['kind' => 'Leave', 'who' => 'Worker', 'what' => 'Annual · 1 Jul (1d)', 'since' => now()],
            ['kind' => 'Claim', 'who' => 'Worker', 'what' => 'Taxi · RM 100.00', 'since' => now()],
        ]))->toMail($this->managerUser);

        $this->assertSame('2 requests awaiting your verification', $mail->subject);
        $this->assertStringContainsString('Taxi · RM 100.00', implode("\n", $mail->introLines));
        $this->assertSame(route('app.screen', 'leave'), $mail->actionUrl);
    }

    private function makeTenant(string $slug): Tenant
    {
        return Tenant::create(['name' => ucfirst($slug), 'slug' => $slug, 'initials' => 'AC', 'color' => '#123456']);
    }

    /** @return array{User, Employee} */
    private function person(string $name, ?Employee $reportsTo = null, ?Tenant $tenant = null): array
    {
        $tenant ??= $this->tenant;
        $user = User::create(['name' => $name, 'email' => str($name)->slug().'@'.$tenant->slug.'.test', 'password' => Hash::make('password')]);
        $user->tenants()->attach($tenant->id, ['role' => $reportsTo ? 'employee' : 'manager']);

        $employee = Employee::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'name' => $name,
            'status' => 'active', 'workload' => 'green', 'reports_to_id' => $reportsTo?->id,
        ]);

        return [$user, $employee];
    }

    private function leave(Employee $employee, string $status): void
    {
        LeaveRequest::create([
            'tenant_id' => $employee->tenant_id, 'employee_id' => $employee->id, 'leave_type_id' => $this->type->id,
            'date_from' => '2026-07-01', 'date_to' => '2026-07-02', 'days' => 2, 'status' => $status,
        ]);
    }

    private function claim(Employee $employee, string $status, ?Tenant $tenant = null): void
    {
        Claim::create([
            'tenant_id' => ($tenant ?? $this->tenant)->id, 'employee_id' => $employee->id,
            'type' => 'travel', 'title' => 'Taxi', 'amount' => 100, 'date' => '2026-06-21', 'status' => $status,
        ]);
    }
}
