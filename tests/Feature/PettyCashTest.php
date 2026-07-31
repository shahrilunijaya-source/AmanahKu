<?php

namespace Tests\Feature;

use App\Http\Controllers\PettyCashController;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\PettyCashFloat;
use App\Models\PettyCashTxn;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Feature coverage for the Petty Cash / Float module.
 *
 * Drives the real DatabaseSeeder (like OrgChartTest) so the Unijaya tenant has
 * branches and the HR persona (Aisyah) to act as. Privileged = ['management','hr'].
 */
class PettyCashTest extends TestCase
{
    use RefreshDatabase;

    private User $hr;

    private Tenant $tenant;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        $this->hr = User::where('email', 'aisyah.rahman@unijaya.example')->firstOrFail();
        $this->tenant = Tenant::where('slug', 'unijaya')->firstOrFail();
        $this->branch = Branch::where('tenant_id', $this->tenant->id)->orderBy('id')->firstOrFail();
    }

    private function actingHr(): self
    {
        $this->actingAs($this->hr)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    /** A plain employee in the same tenant — read-only, must never mutate. */
    private function plainEmployee(): User
    {
        $user = User::create(['name' => 'Plain', 'email' => 'plain@unijaya.example', 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => 'Plain', 'status' => 'active', 'workload' => 'green',
        ]);

        return $user;
    }

    private function makeFloat(float $opening = 2000, float $balance = 2000): PettyCashFloat
    {
        return PettyCashFloat::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Test Float',
            'opening_balance' => $opening,
            'balance' => $balance,
            'is_active' => true,
        ]);
    }

    public function test_privileged_user_opens_a_float(): void
    {
        // Act
        $response = $this->actingHr()->post('/app/pettycash', [
            'name' => 'Test HQ Float 9001',
            'branch_id' => $this->branch->id,
            'opening_balance' => 2000.00,
        ]);

        // Assert
        $response->assertRedirect();
        $float = PettyCashFloat::where('name', 'Test HQ Float 9001')->first();
        $this->assertNotNull($float);
        $this->assertSame('2000.00', (string) $float->balance);
        $this->assertSame('2000.00', (string) $float->opening_balance);
    }

    public function test_disburse_decrements_balance_and_logs_a_txn(): void
    {
        // Arrange
        $float = $this->makeFloat();

        // Act
        $response = $this->actingHr()->post("/app/pettycash/{$float->id}/disburse", [
            'amount' => 150.00,
            'payee' => 'Speedmart 99',
            'purpose' => 'Pantry supplies',
        ]);

        // Assert
        $response->assertRedirect();
        $fresh = $float->fresh();
        $this->assertSame('1850.00', (string) $fresh->balance);
        $this->assertSame(1, $fresh->txns()->count());
        $this->assertSame('disbursement', $fresh->txns()->first()->type);
    }

    public function test_disburse_over_balance_is_blocked_and_balance_unchanged(): void
    {
        // Arrange
        $float = $this->makeFloat(2000, 100);

        // Act
        $response = $this->actingHr()->post("/app/pettycash/{$float->id}/disburse", [
            'amount' => 250.00,
            'payee' => 'Someone',
            'purpose' => 'Too much',
        ]);

        // Assert
        $response->assertSessionHasErrors('amount');
        $fresh = $float->fresh();
        $this->assertSame('100.00', (string) $fresh->balance);
        $this->assertSame(0, $fresh->txns()->count());
    }

    public function test_replenish_increments_balance(): void
    {
        // Arrange
        $float = $this->makeFloat(2000, 500);

        // Act
        $response = $this->actingHr()->post("/app/pettycash/{$float->id}/replenish", [
            'amount' => 300.00,
            'note' => 'Top-up from finance',
        ]);

        // Assert
        $response->assertRedirect();
        $fresh = $float->fresh();
        $this->assertSame('800.00', (string) $fresh->balance);
        $this->assertSame('replenishment', $fresh->txns()->first()->type);
    }

    public function test_plain_employee_cannot_open_a_float(): void
    {
        // Arrange
        $plain = $this->plainEmployee();

        // Act
        $response = $this->actingAs($plain)->withSession(['current_tenant' => $this->tenant->id])
            ->post('/app/pettycash', [
                'name' => 'Sneaky Float',
                'branch_id' => $this->branch->id,
                'opening_balance' => 1000.00,
            ]);

        // Assert
        $response->assertForbidden();
        $this->assertNull(PettyCashFloat::where('name', 'Sneaky Float')->first());
    }

    public function test_plain_employee_cannot_disburse(): void
    {
        // Arrange
        $plain = $this->plainEmployee();
        $float = $this->makeFloat();

        // Act
        $response = $this->actingAs($plain)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/pettycash/{$float->id}/disburse", [
                'amount' => 50.00, 'payee' => 'X', 'purpose' => 'Y',
            ]);

        // Assert
        $response->assertForbidden();
        $this->assertSame('2000.00', (string) $float->fresh()->balance);
        $this->assertSame(0, $float->fresh()->txns()->count());
    }

    public function test_recent_transactions_are_limited_per_float_not_globally(): void
    {
        // Arrange — two floats, each with 12 transactions. A naive `take(10)` inside
        // the eager-load closure would cap the COMBINED set at 10, starving one float.
        $floatA = $this->makeFloat(9000, 9000);
        $floatB = $this->makeFloat(9000, 9000);
        foreach ([$floatA, $floatB] as $f) {
            for ($i = 0; $i < 12; $i++) {
                $f->txns()->create([
                    'tenant_id' => $this->tenant->id, 'type' => 'disbursement',
                    'amount' => 1.00, 'payee' => 'P', 'purpose' => 'x',
                    'txn_date' => now()->subDays($i)->toDateString(),
                ]);
            }
        }

        // Act — build screenData as HR.
        $request = Request::create('/app/pettycash', 'GET');
        $request->attributes->set('tenantRole', 'hr');
        app(CurrentTenant::class)->set($this->tenant);
        $employee = Employee::where('user_id', $this->hr->id)->firstOrFail();
        $data = (new PettyCashController)->screenData($request, $employee);

        // Assert — each float shows its OWN 10 most recent, not a shared global 10.
        $byId = $data['floats']->keyBy('id');
        $this->assertSame(10, $byId[$floatA->id]->txns->count());
        $this->assertSame(10, $byId[$floatB->id]->txns->count());
    }

    public function test_balance_stays_consistent_across_a_sequence(): void
    {
        // Arrange
        $float = $this->makeFloat(2000, 2000);

        // Act — pay out, pay out, top up.
        $this->actingHr()->post("/app/pettycash/{$float->id}/disburse", ['amount' => 300.00, 'payee' => 'A', 'purpose' => 'P1'])->assertRedirect();
        $this->actingHr()->post("/app/pettycash/{$float->id}/disburse", ['amount' => 200.00, 'payee' => 'B', 'purpose' => 'P2'])->assertRedirect();
        $this->actingHr()->post("/app/pettycash/{$float->id}/replenish", ['amount' => 100.00, 'note' => 'Top-up'])->assertRedirect();

        // Assert — 2000 - 300 - 200 + 100 = 1600.
        $fresh = $float->fresh();
        $this->assertSame('1600.00', (string) $fresh->balance);
        $this->assertSame(3, $fresh->txns()->count());
    }

    public function test_privileged_user_deletes_a_float_and_its_txns_cascade(): void
    {
        // Arrange — a float with one disbursement on record.
        $float = $this->makeFloat();
        $this->actingHr()->post("/app/pettycash/{$float->id}/disburse", [
            'amount' => 35.00, 'payee' => 'Kedai', 'purpose' => 'Air',
        ])->assertRedirect();
        $this->assertSame(1, $float->fresh()->txns()->count());

        // Act
        $response = $this->actingHr()->post("/app/pettycash/{$float->id}/delete");

        // Assert — float gone and its txns removed via the FK cascade.
        $response->assertRedirect();
        $this->assertNull(PettyCashFloat::find($float->id));
        $this->assertSame(0, PettyCashTxn::where('petty_cash_float_id', $float->id)->count());
    }

    public function test_plain_employee_cannot_delete_a_float(): void
    {
        // Arrange
        $plain = $this->plainEmployee();
        $float = $this->makeFloat();

        // Act
        $response = $this->actingAs($plain)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/pettycash/{$float->id}/delete");

        // Assert
        $response->assertForbidden();
        $this->assertNotNull(PettyCashFloat::find($float->id));
    }
}
