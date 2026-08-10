<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The clock endpoint itself, as opposed to the rules in ClockService. Covers what a punch
 * the server refuses does to the flash tone, to the uploaded selfie, and to the record.
 */
class AttendanceClockEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->travelTo(CarbonImmutable::parse('2026-07-15 10:00:00'));

        $this->tenant = Tenant::create(['slug' => 'alpha', 'name' => 'Alpha', 'initials' => 'AL']);

        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => Hash::make('password'),
        ]);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'employee']);

        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'name' => 'Test User',
            'status' => 'active',
            'workload' => 'green',
        ]);
    }

    /**
     * Coordinates are supplied by default: a punch with none is a separate case that costs
     * a reason and a selfie, and would mask the flash tone these tests are about.
     *
     * @param  array<string, mixed>  $payload
     */
    private function punch(array $payload): TestResponse
    {
        return $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->post('/app/attendance/clock', $payload + ['latitude' => 3.1073, 'longitude' => 101.6067]);
    }

    public function test_first_clock_in_is_a_success(): void
    {
        $this->punch(['action' => 'in'])->assertSessionHas('clock_ok');

        $this->assertNotNull($this->employee->attendanceRecords()->first()?->clock_in);
    }

    /**
     * A second clock-in is understood and declined, never performed. Flashing it as a
     * success painted a green tick that read as "punched again", which is how staff came to
     * believe they could clock in many times a day.
     */
    public function test_second_clock_in_is_declined_without_claiming_success(): void
    {
        $this->punch(['action' => 'in'])->assertSessionHas('clock_ok');
        $firstPunch = $this->employee->attendanceRecords()->first()->clock_in;

        $this->travel(30)->minutes();
        $response = $this->punch(['action' => 'in']);

        $response->assertSessionMissing('clock_ok');
        $response->assertSessionHas('info', 'Already clocked in today.');
        $this->assertSame($firstPunch, $this->employee->attendanceRecords()->first()->clock_in);
        $this->assertSame(1, $this->employee->attendanceRecords()->count());
    }

    public function test_clock_out_before_any_clock_in_is_declined_without_claiming_success(): void
    {
        $response = $this->punch(['action' => 'out']);

        $response->assertSessionMissing('ok');
        $response->assertSessionHas('info', 'You have not clocked in yet today.');
    }

    /**
     * The selfie is stored before ClockService decides, so a refused punch used to leave a
     * file on the private disk that no record ever pointed at — up to 4MB, forever, on
     * every repeat tap.
     */
    public function test_a_declined_punch_leaves_no_orphan_selfie_on_the_disk(): void
    {
        $this->punch(['action' => 'in'])->assertSessionHas('clock_ok');

        $this->punch([
            'action' => 'in',
            'photo' => UploadedFile::fake()->image('selfie.jpg'),
        ])->assertSessionHas('info');

        $this->assertEmpty(Storage::disk('local')->allFiles('attendance-photos'));
    }

    /** An accepted punch keeps its selfie — the cleanup must only bite refused punches. */
    public function test_an_accepted_punch_keeps_its_selfie(): void
    {
        $this->punch([
            'action' => 'in',
            'photo' => UploadedFile::fake()->image('selfie.jpg'),
        ])->assertSessionHas('clock_ok');

        $path = $this->employee->attendanceRecords()->first()->photo_path;

        $this->assertNotNull($path);
        Storage::disk('local')->assertExists($path);
    }

    /**
     * A photo the server refused (too large, wrong format) lands in the same `photo` error
     * key as a demand for one, so the screen used to read either as "no selfie attached".
     * Staff who had attached one were told to attach one, took the same oversized photo
     * again, and never acted on the size message sitting underneath. The flash, not the
     * error bag, now marks which of the two it is.
     */
    public function test_a_refused_photo_is_not_reported_as_a_missing_one(): void
    {
        $response = $this->punchOntoTheScreen([
            'action' => 'in',
            'latitude' => 3.1073,
            'longitude' => 101.6067,
            'photo' => UploadedFile::fake()->image('IMG_0421.jpg')->size(5000),
        ]);

        $response->assertOk();
        $response->assertSee('4096 kilobytes', false);
        $response->assertDontSee('so a selfie is required', false);
    }

    /** The other side: a genuine demand for a selfie keeps the bilingual instruction. */
    public function test_a_demanded_selfie_is_reported_as_a_demand(): void
    {
        $response = $this->punchOntoTheScreen([
            'action' => 'in',
            'justification' => 'Client site, no signal.',
        ]);

        $response->assertOk();
        $response->assertSee('so a selfie is required', false);
        $response->assertSee('selfie diperlukan', false);
        $response->assertDontSee('4096 kilobytes', false);
    }

    /**
     * A punch followed all the way back onto the attendance screen. The error bag only
     * reaches a view through the redirect that carries it, so the two tests above have to
     * land on the rendered screen rather than read the session.
     *
     * @param  array<string, mixed>  $payload
     */
    private function punchOntoTheScreen(array $payload): TestResponse
    {
        return $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->from('/app/attendance')
            ->followingRedirects()
            ->post('/app/attendance/clock', $payload);
    }

    /** Each post accepts a 4MB upload, so an unthrottled endpoint is a way to fill the disk. */
    public function test_the_clock_endpoint_is_throttled(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->punch(['action' => 'in'])->assertStatus(302);
        }

        $this->punch(['action' => 'in'])->assertStatus(429);
    }
}
