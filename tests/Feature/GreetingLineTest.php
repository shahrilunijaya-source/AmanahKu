<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\GreetingLine;
use App\Models\Tenant;
use App\Models\User;
use App\Support\GreetingBank;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * CR-33 rotating dashboard greeting: default bank seeding, the bucket-priority
 * picker, the plain-pref fallback, and the HR curation + employee suggestion
 * routes.
 */
class GreetingLineTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
    }

    private User $signedInUser;

    private function signIn(string $role, string $name = 'Aminah'): Employee
    {
        $user = User::create(['name' => $name, 'email' => strtolower($name).$role.'@acme.test', 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => $role]);
        $employee = Employee::create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'name' => $name, 'status' => 'active', 'workload' => 'green']);
        $this->actingAs($user)->withSession(['current_tenant' => $this->tenant->id]);
        $this->signedInUser = $user;

        return $employee;
    }

    public function test_seed_gives_a_tenant_60_or_more_approved_lines(): void
    {
        GreetingBank::seed($this->tenant->id);

        $lines = GreetingLine::where('tenant_id', $this->tenant->id)->get();

        $this->assertGreaterThanOrEqual(60, $lines->count());
        $this->assertTrue($lines->every(fn (GreetingLine $l) => $l->approved_at !== null));
    }

    public function test_seed_is_a_no_op_once_the_tenant_already_has_lines(): void
    {
        GreetingBank::seed($this->tenant->id);
        $countAfterFirstSeed = GreetingLine::where('tenant_id', $this->tenant->id)->count();

        GreetingBank::seed($this->tenant->id);

        $this->assertSame($countAfterFirstSeed, GreetingLine::where('tenant_id', $this->tenant->id)->count());
    }

    public function test_picker_prefers_personal_bucket_over_time_bucket(): void
    {
        $personal = GreetingLine::factory()->for($this->tenant)->create([
            'bucket' => 'personal', 'trigger' => 'birthday', 'text_en' => 'Happy birthday, {name}!', 'text_ms' => 'Selamat hari lahir, {name}!',
        ]);
        GreetingLine::factory()->for($this->tenant)->create([
            'bucket' => 'time', 'trigger' => 'morning', 'text_en' => 'Morning, {name}.', 'text_ms' => 'Pagi, {name}.',
        ]);

        $picked = GreetingBank::pick($this->tenant->id, ['birthday', 'morning'], null);

        $this->assertSame($personal->id, $picked?->id);
    }

    public function test_picker_never_repeats_the_last_id_when_two_or_more_candidates_match(): void
    {
        $a = GreetingLine::factory()->for($this->tenant)->create(['bucket' => 'time', 'trigger' => 'morning', 'text_en' => 'A {name}', 'text_ms' => 'A {name}']);
        $b = GreetingLine::factory()->for($this->tenant)->create(['bucket' => 'time', 'trigger' => 'morning', 'text_en' => 'B {name}', 'text_ms' => 'B {name}']);

        $picked = GreetingBank::pick($this->tenant->id, ['morning'], (string) $a->id);

        $this->assertSame($b->id, $picked?->id);
    }

    public function test_plain_preference_falls_back_to_the_original_static_greeting(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-08 09:00', 'Asia/Kuala_Lumpur')); // Tuesday
        $employee = $this->signIn('employee', 'Aminah');
        GreetingBank::seed($this->tenant->id);
        // User has no $fillable (guarded ['*']) — a plain update() silently drops
        // dashboard_prefs, so this must go through forceFill like the app's own
        // dashboard-prefs endpoint does.
        $this->signedInUser->forceFill(['dashboard_prefs' => ['dash' => ['plain' => true, 'hidden' => [], 'order' => []]]])->save();

        $head = $this->get('/app/dash')->assertOk()->viewData('head');

        $this->assertSame('Good morning, Aminah.', $head['h1']);
        $this->assertSame('Selamat pagi, Aminah.', $head['h1_ms']);
    }

    public function test_dashboard_renders_h1_with_name_placeholder_replaced(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-08 09:00', 'Asia/Kuala_Lumpur')); // Tuesday, plain morning window
        $employee = $this->signIn('employee', 'Aminah');
        GreetingBank::seed($this->tenant->id);

        $head = $this->get('/app/dash')->assertOk()->viewData('head');

        $this->assertStringContainsString('Aminah', $head['h1']);
        $this->assertStringNotContainsString('{name}', $head['h1']);
        $this->assertStringNotContainsString('{name}', $head['h1_ms']);
    }

    public function test_hr_can_add_update_approve_and_delete_a_line(): void
    {
        $this->signIn('hr');

        $this->post(route('admin.greetings.store'), ['trigger' => 'monday', 'text_en' => 'New week, {name}.', 'text_ms' => 'Minggu baru, {name}.'])->assertRedirect();
        $line = GreetingLine::where('tenant_id', $this->tenant->id)->where('trigger', 'monday')->firstOrFail();
        $this->assertNotNull($line->approved_at);

        $this->post(route('admin.greetings.update', $line), ['trigger' => 'monday', 'text_en' => 'Updated, {name}.', 'text_ms' => 'Dikemas kini, {name}.'])->assertRedirect();
        $this->assertSame('Updated, {name}.', $line->fresh()->text_en);

        $pending = GreetingLine::factory()->for($this->tenant)->pending()->create(['bucket' => 'day', 'trigger' => 'friday']);
        $this->post(route('admin.greetings.update', $pending), ['approve' => '1'])->assertRedirect();
        $this->assertNotNull($pending->fresh()->approved_at);

        $this->post(route('admin.greetings.delete', $line))->assertRedirect();
        $this->assertNull(GreetingLine::find($line->id));
    }

    public function test_employee_gets_403_on_admin_greeting_routes(): void
    {
        $this->signIn('employee');

        $line = GreetingLine::factory()->for($this->tenant)->create(['bucket' => 'day', 'trigger' => 'monday']);

        $this->post(route('admin.greetings.store'), ['trigger' => 'monday', 'text_en' => 'x', 'text_ms' => 'y'])->assertForbidden();
        $this->post(route('admin.greetings.update', $line), ['trigger' => 'monday', 'text_en' => 'x', 'text_ms' => 'y'])->assertForbidden();
        $this->post(route('admin.greetings.delete', $line))->assertForbidden();
    }

    public function test_employee_suggestion_creates_a_pending_row(): void
    {
        $employee = $this->signIn('employee', 'Faiz');

        $this->post(route('greetings.suggest'), ['trigger' => 'friday', 'text_en' => 'TGIF, {name}.', 'text_ms' => 'TGIF, {name}.'])->assertRedirect();

        $line = GreetingLine::where('tenant_id', $this->tenant->id)->where('trigger', 'friday')->firstOrFail();
        $this->assertNull($line->approved_at);
        $this->assertSame($employee->id, $line->suggested_by);
    }

    public function test_settings_screen_renders_the_greetings_card_for_hr(): void
    {
        $this->signIn('hr');
        GreetingLine::factory()->for($this->tenant)->create(['bucket' => 'day', 'trigger' => 'friday', 'text_en' => 'TGIF line', 'text_ms' => 'Baris Jumaat']);
        GreetingLine::factory()->for($this->tenant)->pending()->create(['bucket' => 'day', 'trigger' => 'monday']);

        $this->get('/app/settings')->assertOk()->assertSee('TGIF line')->assertSee('Dashboard greetings');
    }

    public function test_cross_tenant_update_is_refused(): void
    {
        $other = Tenant::create(['slug' => 'globex', 'name' => 'Globex', 'initials' => 'GX']);
        $foreignLine = GreetingLine::factory()->for($other)->create(['bucket' => 'day', 'trigger' => 'monday']);

        $this->signIn('hr');

        $this->post(route('admin.greetings.update', $foreignLine), ['trigger' => 'monday', 'text_en' => 'x', 'text_ms' => 'y'])
            ->assertStatus(403);
    }
}
