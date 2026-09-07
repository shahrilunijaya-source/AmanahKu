<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Employee;
use App\Models\Flower;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * CR-23 "Caught Being Brilliant": flowers on the profile Wall and the
 * dashboard widget. 3 per person per month, 1 per recipient per month, no
 * approval. HR can hide (moderation); nobody else can.
 */
class FlowerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->travelTo(CarbonImmutable::parse('2026-09-08 09:00'));
    }

    private function signIn(string $role = 'employee', string $name = 'Emysha'): Employee
    {
        $user = User::create(['name' => $name, 'email' => strtolower($name).$role.'@acme.test', 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => $role]);
        $employee = Employee::create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'name' => $name, 'status' => 'active', 'workload' => 'green']);
        $this->actingAs($user)->withSession(['current_tenant' => $this->tenant->id]);

        return $employee;
    }

    private function colleague(string $name, string $status = 'active'): Employee
    {
        $user = User::create(['name' => $name, 'email' => strtolower(str_replace(' ', '', $name)).'@acme.test', 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'employee']);

        return Employee::create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'name' => $name, 'status' => $status, 'workload' => 'green']);
    }

    public function test_giving_a_flower_stores_it_and_notifies_the_recipient(): void
    {
        $giver = $this->signIn('employee', 'Giver');
        $recipient = $this->colleague('Recipient');

        $this->postJson(route('flowers.store', $recipient), ['note' => 'Great work on the deploy'])
            ->assertOk()->assertJson(['ok' => true, 'left' => 2]);

        $this->assertDatabaseHas('flowers', [
            'giver_id' => $giver->id, 'recipient_id' => $recipient->id, 'note' => 'Great work on the deploy', 'month' => '2026-09',
        ]);

        $notification = AppNotification::where('user_id', $recipient->user_id)->first();
        $this->assertNotNull($notification);
        $this->assertStringContainsString('gave you a flower', $notification->title);
    }

    public function test_giver_cannot_give_self_a_flower(): void
    {
        $giver = $this->signIn('employee', 'Solo');

        $this->postJson(route('flowers.store', $giver), ['note' => 'Nice job me'])->assertStatus(422);
    }

    public function test_fourth_flower_in_the_same_month_is_refused(): void
    {
        $this->signIn('employee', 'Giver');
        $recipients = [$this->colleague('One'), $this->colleague('Two'), $this->colleague('Three'), $this->colleague('Four')];

        foreach (array_slice($recipients, 0, 3) as $r) {
            $this->postJson(route('flowers.store', $r), ['note' => 'Good work'])->assertOk();
        }

        $this->postJson(route('flowers.store', $recipients[3]), ['note' => 'Good work'])->assertStatus(422);
        $this->assertSame(3, Flower::count());
    }

    public function test_second_flower_to_the_same_recipient_in_the_month_is_refused(): void
    {
        $this->signIn('employee', 'Giver');
        $recipient = $this->colleague('Recipient');

        $this->postJson(route('flowers.store', $recipient), ['note' => 'First'])->assertOk();
        $this->postJson(route('flowers.store', $recipient), ['note' => 'Second'])->assertStatus(422);
        $this->assertSame(1, Flower::where('recipient_id', $recipient->id)->count());
    }

    public function test_new_month_resets_both_caps(): void
    {
        $giver = $this->signIn('employee', 'Giver');
        $recipient = $this->colleague('Recipient');

        $this->postJson(route('flowers.store', $recipient), ['note' => 'September'])->assertOk();

        $this->travelTo(CarbonImmutable::parse('2026-10-01 09:00'));
        $this->postJson(route('flowers.store', $recipient), ['note' => 'October'])
            ->assertOk()->assertJson(['left' => 2]);

        $this->assertSame(2, Flower::where('giver_id', $giver->id)->count());
    }

    public function test_cross_tenant_recipient_is_refused(): void
    {
        $this->signIn('employee', 'Giver');

        $otherTenant = Tenant::create(['slug' => 'other', 'name' => 'Other Co', 'initials' => 'OC']);
        $otherUser = User::create(['name' => 'Outsider', 'email' => 'outsider@other.test', 'password' => Hash::make('password')]);
        $otherUser->tenants()->attach($otherTenant->id, ['role' => 'employee']);
        $outsider = Employee::create(['tenant_id' => $otherTenant->id, 'user_id' => $otherUser->id, 'name' => 'Outsider', 'status' => 'active', 'workload' => 'green']);

        $this->postJson(route('flowers.store', $outsider), ['note' => 'Hi'])->assertStatus(404);

        // Route-model binding isn't tenant-scoped (see docs/binding-not-tenant-scoped),
        // so flowers.hide must reject a Flower id belonging to another tenant too.
        $otherGiverUser = User::create(['name' => 'Outsider Giver', 'email' => 'outsidergiver@other.test', 'password' => Hash::make('password')]);
        $otherGiverUser->tenants()->attach($otherTenant->id, ['role' => 'employee']);
        $otherGiver = Employee::create(['tenant_id' => $otherTenant->id, 'user_id' => $otherGiverUser->id, 'name' => 'Outsider Giver', 'status' => 'active', 'workload' => 'green']);
        $otherFlower = Flower::create(['tenant_id' => $otherTenant->id, 'giver_id' => $otherGiver->id, 'recipient_id' => $outsider->id, 'note' => 'Elsewhere', 'month' => '2026-09']);
        $this->signIn('hr', 'HR Person');
        $this->postJson(route('flowers.hide', $otherFlower))->assertStatus(404);
    }

    public function test_hr_can_hide_a_flower_and_a_non_hr_giver_cannot(): void
    {
        $giver = $this->signIn('employee', 'Giver');
        $recipient = $this->colleague('Recipient');
        $this->postJson(route('flowers.store', $recipient), ['note' => 'Nice'])->assertOk();
        $flower = Flower::firstOrFail();

        $this->postJson(route('flowers.hide', $flower))->assertStatus(403);

        $this->signIn('hr', 'HR Person');
        $this->postJson(route('flowers.hide', $flower))->assertOk();

        $this->assertNotNull($flower->fresh()->hidden_at);
    }

    public function test_hidden_flower_is_absent_from_the_wall_and_the_widget(): void
    {
        $giver = $this->signIn('employee', 'Giver');
        $recipient = $this->colleague('Recipient');
        $this->postJson(route('flowers.store', $recipient), ['note' => 'Great teamwork'])->assertOk();

        $this->get('/app/dash')->assertOk()->assertSee('Flowers')->assertSee('Great teamwork');

        // canViewFull only holds for your own record, management/HR/director, or a
        // senior/reporting-line viewer (BuildsPeopleData::profileData) — a plain
        // colleague gets the slim public card with no Wall, same rule
        // BirthdayWishesTest works around. View the recipient's own profile instead.
        $this->actingAs($recipient->user)->withSession(['current_tenant' => $this->tenant->id]);
        $this->get('/app/profile')->assertOk()->assertSee('Great teamwork');

        $this->signIn('hr', 'HR Person');
        $flower = Flower::firstOrFail();
        $this->postJson(route('flowers.hide', $flower))->assertOk();

        $this->get('/app/dash')->assertOk()->assertDontSee('Great teamwork');
        $this->actingAs($recipient->user)->withSession(['current_tenant' => $this->tenant->id]);
        $this->get('/app/profile')->assertOk()->assertDontSee('Great teamwork');
    }

    public function test_flowers_widget_is_absent_from_the_dashboard_with_no_flowers(): void
    {
        $this->signIn('employee', 'NoFlowers');

        $response = $this->get('/app/dash')->assertOk();
        $this->assertArrayNotHasKey('flowers', $response->viewData('widgets'));
        $response->assertDontSee('No flowers yet this month');
    }
}
