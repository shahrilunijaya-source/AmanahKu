<?php

namespace Tests\Feature;

use App\Models\Claim;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Mileage claims carry the trip (vehicle, from, to, km, toll, parking) and the server
 * works the amount out at Unijaya's rates: RM 0.60/km by car, RM 0.30/km by motorcycle.
 * A person's month of claims downloads as the Borang Tuntutan Perjalanan PDF.
 */
class ClaimMileageFormTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
    }

    private function member(string $role, string $name, ?int $reportsToId = null, ?Tenant $tenant = null): Employee
    {
        $tenant ??= $this->tenant;
        $this->seq++;
        $user = User::create(['name' => $name, 'email' => "user{$this->seq}@example.com", 'password' => Hash::make('password')]);
        $user->tenants()->attach($tenant->id, ['role' => $role]);

        return Employee::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id,
            'name' => $name, 'status' => 'active', 'workload' => 'green',
            'reports_to_id' => $reportsToId, 'staff_id' => 'UJ'.$this->seq,
        ]);
    }

    private function actingAsEmployee(Employee $e, ?Tenant $tenant = null): self
    {
        $this->actingAs($e->user)->withSession(['current_tenant' => ($tenant ?? $this->tenant)->id]);

        return $this;
    }

    /** @param array<string, mixed> $overrides */
    private function fileMileage(Employee $e, array $overrides = []): TestResponse
    {
        return $this->actingAsEmployee($e)->post('/app/claims', [
            'type' => 'mileage', 'title' => 'Client visit', 'amount' => 999, 'date' => '2026-09-10',
            'vehicle' => 'car', 'distance_km' => 100, 'trip_from' => 'Office', 'trip_to' => 'Klang',
            'receipt' => UploadedFile::fake()->create('toll.pdf', 40, 'application/pdf'),
            ...$overrides,
        ]);
    }

    public function test_form_offers_car_and_motorcycle(): void
    {
        $employee = $this->member('employee', 'Azman');

        $this->actingAsEmployee($employee)->get('/app/claims')->assertOk()
            ->assertSee('Motorcycle')->assertSee('name="distance_km"', false);
    }

    public function test_server_works_out_the_amount_by_vehicle_ignoring_the_form(): void
    {
        $manager = $this->member('manager', 'Manager');
        $employee = $this->member('employee', 'Azman', $manager->id);

        $this->fileMileage($employee, ['vehicle' => 'motorcycle', 'amount' => 60])->assertSessionHasNoErrors();
        $this->fileMileage($employee, ['vehicle' => 'car', 'distance_km' => 50, 'toll' => 4.2, 'parking' => 3, 'title' => 'Car trip'])->assertSessionHasNoErrors();

        $moto = Claim::where('title', 'Client visit')->firstOrFail();
        $this->assertSame(30.0, $moto->amount);
        $this->assertSame('motorcycle', $moto->vehicle);
        $this->assertSame(100.0, $moto->distance_km);

        // 50 km × RM 0.60 = RM 30.00, plus RM 4.20 toll and RM 3.00 parking.
        $this->assertSame(37.2, Claim::where('title', 'Car trip')->firstOrFail()->amount);
    }

    public function test_km_is_kept_to_one_decimal_like_the_form(): void
    {
        $employee = $this->member('employee', 'Azman');

        // The form rounds 12.35 km to 12.4 before the rate, same as the server: 12.4 × 0.60 = 7.44.
        $this->fileMileage($employee, ['distance_km' => 12.35, 'toll' => 0.1, 'parking' => 0.2])->assertSessionHasNoErrors();

        $claim = Claim::firstOrFail();
        $this->assertSame(12.4, $claim->distance_km);
        $this->assertSame(7.74, $claim->amount);
    }

    public function test_mileage_without_distance_or_vehicle_is_refused(): void
    {
        $employee = $this->member('employee', 'Azman');

        $this->fileMileage($employee, ['distance_km' => null])->assertSessionHasErrors('distance_km');
        $this->fileMileage($employee, ['vehicle' => 'lorry'])->assertSessionHasErrors('vehicle');
        $this->fileMileage($employee, ['trip_to' => ''])->assertSessionHasErrors('trip_to');
        $this->assertSame(0, Claim::count());
    }

    public function test_other_claim_types_store_no_trip(): void
    {
        $employee = $this->member('employee', 'Azman');

        $this->fileMileage($employee, ['type' => 'expense', 'amount' => 25])->assertSessionHasNoErrors();

        $claim = Claim::firstOrFail();
        $this->assertSame(25.0, $claim->amount);
        $this->assertNull($claim->vehicle);
        $this->assertNull($claim->distance_km);
    }

    public function test_owner_and_superior_download_the_month_as_pdf(): void
    {
        $manager = $this->member('manager', 'Manager');
        $employee = $this->member('employee', 'Azman', $manager->id);
        $this->fileMileage($employee)->assertSessionHasNoErrors();

        $own = $this->actingAsEmployee($employee)->get("/app/claims/form/{$employee->id}?month=2026-09")->assertOk();
        $this->assertSame('application/pdf', $own->headers->get('Content-Type'));
        $this->assertStringContainsString('borang-tuntutan-UJ2-2026-09.pdf', $own->headers->get('Content-Disposition'));

        $this->actingAsEmployee($manager)->get("/app/claims/form/{$employee->id}?month=2026-09")->assertOk();
    }

    public function test_pdf_lists_the_months_claims_with_old_mileage_left_blank(): void
    {
        $employee = $this->member('employee', 'Azman');
        $this->fileMileage($employee, ['vehicle' => 'motorcycle'])->assertSessionHasNoErrors();
        // A mileage claim from before trip details existed: amount only.
        $employee->claims()->create(['tenant_id' => $this->tenant->id, 'type' => 'mileage', 'title' => 'Old trip', 'amount' => 12, 'date' => '2026-09-02', 'status' => 'approved']);
        $employee->claims()->create(['tenant_id' => $this->tenant->id, 'type' => 'expense', 'title' => 'Rejected', 'amount' => 5, 'date' => '2026-09-03', 'status' => 'rejected']);
        $employee->claims()->create(['tenant_id' => $this->tenant->id, 'type' => 'expense', 'title' => 'August', 'amount' => 5, 'date' => '2026-08-30', 'status' => 'approved']);

        $html = view('pdf.claim-form', [
            'employee' => $employee,
            'claims' => $employee->claims()->whereNotIn('status', ['rejected', 'cancelled'])->whereBetween('date', ['2026-09-01', '2026-09-30'])->orderBy('date')->get(),
            'month' => now()->setDate(2026, 9, 1),
            'rates' => Claim::MILEAGE_RATES,
        ])->render();

        $this->assertStringContainsString('BORANG TUNTUTAN PERJALANAN', $html);
        $this->assertStringContainsString('Old trip', $html);
        $this->assertStringContainsString('RM 42.00', $html);
        $this->assertStringNotContainsString('Rejected', $html);
    }

    public function test_pdf_prints_the_company_logo_when_one_is_uploaded(): void
    {
        Storage::fake('public');
        $employee = $this->member('employee', 'Azman');
        $render = fn () => view('pdf.claim-form', [
            'employee' => $employee->fresh(), 'claims' => collect(), 'month' => now(), 'rates' => Claim::MILEAGE_RATES,
        ])->render();

        $this->assertStringNotContainsString('class="logo"', $render());

        UploadedFile::fake()->image('logo.png')->storeAs('logos', 'logo.png', 'public');
        $this->tenant->update(['logo_path' => 'logos/logo.png']);

        $this->assertStringContainsString('class="logo"', $render());
    }

    public function test_the_export_only_counts_that_month_and_skips_rejected(): void
    {
        $employee = $this->member('employee', 'Azman');
        $employee->claims()->create(['tenant_id' => $this->tenant->id, 'type' => 'expense', 'title' => 'Kept', 'amount' => 10, 'date' => '2026-09-05', 'status' => 'submitted']);
        $employee->claims()->create(['tenant_id' => $this->tenant->id, 'type' => 'expense', 'title' => 'Rejected', 'amount' => 5, 'date' => '2026-09-06', 'status' => 'rejected']);
        $employee->claims()->create(['tenant_id' => $this->tenant->id, 'type' => 'expense', 'title' => 'October', 'amount' => 7, 'date' => '2026-10-01', 'status' => 'submitted']);

        $pdf = $this->actingAsEmployee($employee)->get("/app/claims/form/{$employee->id}?month=2026-09")->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_bad_month_is_refused(): void
    {
        $employee = $this->member('employee', 'Azman');

        $this->actingAsEmployee($employee)->get("/app/claims/form/{$employee->id}?month=septembre")->assertSessionHasErrors('month');
    }

    public function test_hr_and_a_director_can_download_anyones_form(): void
    {
        $employee = $this->member('employee', 'Azman');

        $this->actingAsEmployee($this->member('hr', 'HR'))->get("/app/claims/form/{$employee->id}")->assertOk();
        $this->actingAsEmployee($this->member('director', 'Shahril'))->get("/app/claims/form/{$employee->id}")->assertOk();
    }

    public function test_a_colleague_cannot_download_someone_elses_form(): void
    {
        $employee = $this->member('employee', 'Azman');
        $colleague = $this->member('employee', 'Siti');

        $this->actingAsEmployee($colleague)->get("/app/claims/form/{$employee->id}")->assertForbidden();
    }

    public function test_another_tenant_cannot_download_the_form(): void
    {
        $employee = $this->member('employee', 'Azman');
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'initials' => 'OT']);
        $outsider = $this->member('hr', 'Outsider', null, $other);

        $status = $this->actingAsEmployee($outsider, $other)->get("/app/claims/form/{$employee->id}")->status();
        $this->assertContains($status, [403, 404]);
    }
}
