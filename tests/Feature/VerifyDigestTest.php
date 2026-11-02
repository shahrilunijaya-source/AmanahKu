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
 * approvals:verify-digest sends each manager and final approver ONE email listing every
 * leave and claim waiting on them, instead of one email per request.
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
            $kinds = array_column($n->items()['verify'], 'kind');

            return $channels === ['mail'] && $kinds === ['Leave', 'Leave', 'Claim'] && $n->items()['approve'] === [];
        });
    }

    public function test_dotted_line_manager_also_gets_it(): void
    {
        Notification::fake();

        $this->artisan('approvals:verify-digest')->assertSuccessful();

        Notification::assertSentTo($this->dottedUser, VerifyDigest::class, fn (VerifyDigest $n) => count($n->items()['verify']) === 3);
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

        Notification::assertSentTo($this->managerUser, VerifyDigest::class, fn (VerifyDigest $n) => count($n->items()['verify']) === 3);
        Notification::assertSentTo($otherManagerUser, VerifyDigest::class, fn (VerifyDigest $n) => count($n->items()['verify']) === 1);
    }

    public function test_final_approvers_get_verified_items_only(): void
    {
        app(CurrentTenant::class)->set($this->tenant);
        [$hrUser] = $this->person('Hr', role: 'hr');
        app(CurrentTenant::class)->set(null);
        Notification::fake();

        $this->artisan('approvals:verify-digest')->assertSuccessful();

        Notification::assertSentTo($hrUser, VerifyDigest::class, function (VerifyDigest $n) {
            return $n->items()['verify'] === []
                && array_column($n->items()['approve'], 'kind') === ['Leave', 'Claim'];
        });
    }

    public function test_approver_does_not_get_their_own_request_or_one_they_verified(): void
    {
        app(CurrentTenant::class)->set($this->tenant);
        [$directorUser, $director] = $this->person('Director', role: 'director');
        [, $other] = $this->person('Other', role: 'hr');
        LeaveRequest::query()->where('status', 'verified')->update(['verified_by_id' => $director->id]);
        $this->claim($director, 'verified');
        app(CurrentTenant::class)->set(null);
        Notification::fake();

        $this->artisan('approvals:verify-digest')->assertSuccessful();

        // Only the worker's verified claim is left: the leave they verified and their own claim drop out.
        Notification::assertSentTo($directorUser, VerifyDigest::class, fn (VerifyDigest $n) => count($n->items()['approve']) === 1
            && $n->items()['approve'][0]['who'] === 'Worker');
    }

    public function test_someone_who_verifies_and_approves_gets_one_email_with_both(): void
    {
        app(CurrentTenant::class)->set($this->tenant);
        $this->tenant->users()->updateExistingPivot($this->managerUser->id, ['role' => 'director']);
        app(CurrentTenant::class)->set(null);
        Notification::fake();

        $this->artisan('approvals:verify-digest')->assertSuccessful();

        Notification::assertSentToTimes($this->managerUser, VerifyDigest::class, 1);
        Notification::assertSentTo($this->managerUser, VerifyDigest::class, fn (VerifyDigest $n) => count($n->items()['verify']) === 3
            && count($n->items()['approve']) === 2);
    }

    public function test_email_lists_the_items(): void
    {
        $mail = (new VerifyDigest([
            ['kind' => 'Leave', 'who' => 'Worker', 'what' => 'Annual · 1 Jul (1d)', 'since' => now()],
            ['kind' => 'Claim', 'who' => 'Worker', 'what' => 'Taxi · RM 100.00', 'since' => now()],
        ]))->toMail($this->managerUser);

        $this->assertSame('2 requests awaiting your verification', $mail->subject);
        $this->assertSame(
            '1 request awaiting your approval',
            (new VerifyDigest([], [['kind' => 'Claim', 'who' => 'W', 'what' => 'Taxi', 'since' => now()]]))->toMail($this->managerUser)->subject,
        );
        $this->assertStringContainsString('Taxi · RM 100.00', implode("\n", $mail->introLines));
        // Both kinds: two matching buttons instead of one button plus a text link.
        $html = (string) $mail->render();
        $this->assertNull($mail->actionUrl);
        $this->assertStringContainsString('href="'.route('app.screen', 'leave').'"', $html);
        $this->assertStringContainsString('href="'.route('app.screen', 'claims').'"', $html);

        // One kind only: the normal single button.
        $single = (new VerifyDigest([['kind' => 'Claim', 'who' => 'W', 'what' => 'Taxi', 'since' => now()]]))->toMail($this->managerUser);
        $this->assertSame(route('app.screen', 'claims'), $single->actionUrl);
    }

    private function makeTenant(string $slug): Tenant
    {
        return Tenant::create(['name' => ucfirst($slug), 'slug' => $slug, 'initials' => 'AC', 'color' => '#123456']);
    }

    /** @return array{User, Employee} */
    private function person(string $name, ?Employee $reportsTo = null, ?Tenant $tenant = null, ?string $role = null): array
    {
        $tenant ??= $this->tenant;
        $user = User::create(['name' => $name, 'email' => str($name)->slug().'@'.$tenant->slug.'.test', 'password' => Hash::make('password')]);
        $user->tenants()->attach($tenant->id, ['role' => $role ?? ($reportsTo ? 'employee' : 'manager')]);

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
