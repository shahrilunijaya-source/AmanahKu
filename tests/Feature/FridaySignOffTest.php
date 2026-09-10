<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Support\DashboardWidgets;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Acceptance\AlwaysChecks;
use Tests\TestCase;

/**
 * S25 / CR-29: coverage the frozen CR29Test (tests/Acceptance) does not
 * exercise directly — tenant isolation for a person who belongs to more than
 * one tenant, the week_of rollover at the Saturday/Monday edges, and the
 * receipt table's structural inability to be joined back to a mood.
 */
class FridaySignOffTest extends TestCase
{
    use AlwaysChecks;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function test_a_person_in_two_tenants_signs_off_independently_and_data_never_crosses(): void
    {
        Carbon::setTestNow('2026-09-11 15:00:00');

        $bravo = Tenant::create(['slug' => 'bravo', 'name' => 'Bravo', 'initials' => 'BR']);

        $acme = $this->person('Dual Tenant');
        // Same underlying user, a second membership + employee row in Bravo — the
        // receipt formula hashes only "<user id>:<week_of>", no tenant_id, so this
        // is the one case where two receipts could coincide if tenant scoping ever
        // slipped.
        $acme->user->tenants()->attach($bravo->id, ['role' => 'employee']);
        $dualInBravo = Employee::create([
            'tenant_id' => $bravo->id, 'user_id' => $acme->user_id,
            'name' => 'Dual Tenant', 'status' => 'active', 'workload' => 'green',
        ]);

        $this->actingAs($acme->user)->withSession(['current_tenant' => $this->tenant()->id])
            ->postJson('/app/friday-signoff', ['mood' => 'productive'])
            ->assertOk();

        // Signing off in Bravo is NOT blocked by the Acme receipt, despite the
        // identical hash input.
        $this->actingAs($acme->user)->withSession(['current_tenant' => $bravo->id])
            ->postJson('/app/friday-signoff', ['mood' => 'chaotic'])
            ->assertOk();

        $this->assertSame(1, DB::table('friday_moods')->where('tenant_id', $this->tenant()->id)->count());
        $this->assertSame(1, DB::table('friday_moods')->where('tenant_id', $bravo->id)->count());
        $this->assertSame('productive', DB::table('friday_moods')->where('tenant_id', $this->tenant()->id)->value('mood'));
        $this->assertSame('chaotic', DB::table('friday_moods')->where('tenant_id', $bravo->id)->value('mood'));

        // A viewer in Bravo never sees Acme's headcount: with 4 more Bravo sign-offs
        // (5 total) the mood must reflect only Bravo's answers.
        foreach (range(1, 4) as $i) {
            $bravoUser = User::create([
                'name' => "Bravo Person {$i}", 'email' => "bravo{$i}@example.com", 'password' => bcrypt('password'),
            ]);
            $bravoUser->tenants()->attach($bravo->id, ['role' => 'employee']);
            $p = Employee::create([
                'tenant_id' => $bravo->id, 'user_id' => $bravoUser->id,
                'name' => "Bravo Person {$i}", 'status' => 'active', 'workload' => 'green',
            ]);
            $this->actingAs($p->user)->withSession(['current_tenant' => $bravo->id])
                ->postJson('/app/friday-signoff', ['mood' => 'chaotic'])->assertOk();
        }

        Carbon::setTestNow('2026-09-11 17:00:00');
        $bravoHtml = $this->actingAs($dualInBravo->user)->withSession(['current_tenant' => $bravo->id])
            ->get('/app/dash')->assertOk()->getContent();
        $this->assertStringContainsString('data-friday-mood', $bravoHtml);
        $this->assertMatchesRegularExpression('/5 signed off/', $bravoHtml);
    }

    #[Test]
    public function test_week_of_rolls_back_to_friday_on_saturday_sunday_and_monday_before_nine(): void
    {
        $this->assertSame('2026-09-11', DashboardWidgets::fridayWeekOf(CarbonImmutable::parse('2026-09-11 16:00:00')));
        $this->assertSame('2026-09-11', DashboardWidgets::fridayWeekOf(CarbonImmutable::parse('2026-09-12 10:00:00'))); // Sat
        $this->assertSame('2026-09-11', DashboardWidgets::fridayWeekOf(CarbonImmutable::parse('2026-09-13 10:00:00'))); // Sun
        $this->assertSame('2026-09-11', DashboardWidgets::fridayWeekOf(CarbonImmutable::parse('2026-09-14 08:59:00'))); // Mon before 9

        $who = $this->person('Rollover Person');

        // Monday 08:59: still open, attributed to last Friday.
        Carbon::setTestNow('2026-09-14 08:59:00');
        $this->actingInTenantAs($who)->postJson('/app/friday-signoff', ['mood' => 'survived'])->assertOk();
        $this->assertSame(1, DB::table('friday_moods')->where('week_of', '2026-09-11')->count());

        // Monday 09:00 sharp: window closed, refused, no row written.
        Carbon::setTestNow('2026-09-14 09:00:00');
        $other = $this->person('Too Late Person');
        $this->actingInTenantAs($other)->postJson('/app/friday-signoff', ['mood' => 'survived'])->assertStatus(422);
        $this->assertSame(1, DB::table('friday_moods')->count());
    }

    #[Test]
    public function test_the_receipt_table_cannot_be_joined_back_to_a_mood(): void
    {
        foreach (['employee_id', 'user_id', 'mood', 'created_at'] as $col) {
            $this->assertFalse(Schema::hasColumn('friday_receipts', $col), "friday_receipts.{$col} would let a mood be traced back to a person");
        }

        Carbon::setTestNow('2026-09-11 15:00:00');
        $who = $this->person('Receipt Person');
        $this->actingInTenantAs($who)->postJson('/app/friday-signoff', ['mood' => 'chaotic'])->assertOk();

        // The only thing that connects a mood row to a person is a receipt computed
        // fresh from the formula — nothing stored anywhere does it for you.
        $receipt = DB::table('friday_receipts')->sole();
        $expected = hash_hmac('sha256', $who->user_id.':2026-09-11', config('app.key'));
        $this->assertSame($expected, $receipt->receipt);
        $this->assertSame(['id', 'tenant_id', 'week_of', 'receipt'], array_keys((array) $receipt));

        // The unique constraint is enforced by the schema itself, not just the
        // controller's exists() check — a raw duplicate insert still fails.
        $threw = false;
        try {
            DB::table('friday_receipts')->insert([
                'tenant_id' => $this->tenant()->id, 'week_of' => '2026-09-11', 'receipt' => $receipt->receipt,
            ]);
        } catch (QueryException) {
            $threw = true;
        }
        $this->assertTrue($threw, 'a duplicate (tenant_id, week_of, receipt) was accepted at the database level');
    }
}
