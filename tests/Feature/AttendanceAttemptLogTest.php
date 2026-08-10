<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AttendanceAttempt;
use App\Models\Employee;
use App\Models\ErrorEvent;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The diagnostic trail behind a punch.
 *
 * A refused punch answers 302 with a message in the error bag, which is ordinary HTTP
 * traffic: no log line, no captured fault, nothing an error tracker would ever see. So
 * "I cannot clock in on my phone" arrived with no evidence at all. These tests hold that
 * evidence in place — the outcome, the device, and the size of what was sent.
 */
class AttendanceAttemptLogTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE_AGENT = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';

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
     * Punches as a phone, because the device is the whole point of the table.
     *
     * @param  array<string, mixed>  $payload
     */
    private function punch(array $payload, bool $withFix = true): TestResponse
    {
        $fix = $withFix ? ['latitude' => 3.1073, 'longitude' => 101.6067] : [];

        return $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->withHeader('User-Agent', self::PHONE_AGENT)
            ->post('/app/attendance/clock', $payload + $fix);
    }

    // ── A. Every outcome is recorded ──────────────────────────────

    public function test_a_successful_punch_is_recorded_with_its_device(): void
    {
        $this->punch(['action' => 'in']);

        $attempt = AttendanceAttempt::sole();

        $this->assertSame('ok', $attempt->outcome);
        $this->assertSame('in', $attempt->action);
        $this->assertSame($this->employee->id, $attempt->employee_id);
        $this->assertSame($this->tenant->id, $attempt->tenant_id);
        $this->assertSame('iPhone', $attempt->deviceLabel());
        $this->assertTrue($attempt->has_location);
        $this->assertFalse($attempt->has_photo);
    }

    /**
     * The successes matter as much as the refusals: "every refusal was a phone and every
     * success was a desktop" is the comparison that names the cause, and it needs both.
     */
    public function test_a_refused_punch_is_recorded_with_the_reason_the_staff_member_saw(): void
    {
        $response = $this->punch(['action' => 'in'], withFix: false);

        $attempt = AttendanceAttempt::sole();

        $this->assertSame('needs_justification', $attempt->outcome);
        $this->assertFalse($attempt->has_location);
        $this->assertStringContainsString('location could not be read', (string) $attempt->message);
        // The recorded sentence is the one the screen shows, not a paraphrase of it.
        $response->assertSessionHasErrors(['justification' => $attempt->message]);
    }

    public function test_a_second_clock_in_is_recorded_as_a_noop(): void
    {
        $this->punch(['action' => 'in']);
        $this->punch(['action' => 'in']);

        $this->assertSame(
            ['ok', 'noop'],
            AttendanceAttempt::orderBy('id')->pluck('outcome')->all(),
        );
    }

    // ── B. The mobile case: a selfie the app refuses ──────────────

    /**
     * The most likely cause of "works on desktop, fails on my phone". A desktop selfie is
     * drawn through a canvas and is small; a phone camera photo is 3-8MB and trips the
     * 4MB rule. Before this row existed the failure was a bare 302 and nothing else.
     */
    public function test_an_oversized_selfie_is_recorded_as_invalid_with_its_size(): void
    {
        $response = $this->punch([
            'action' => 'in',
            'photo' => UploadedFile::fake()->image('selfie.jpg')->size(5000),
        ]);

        $response->assertSessionHasErrors('photo');

        $attempt = AttendanceAttempt::sole();

        $this->assertSame('invalid', $attempt->outcome);
        $this->assertSame('in', $attempt->action);
        $this->assertTrue($attempt->has_photo);
        // 5000 KB as sent. Recording the size is what separates "too big" from "no file".
        $this->assertSame(5000 * 1024, $attempt->photo_bytes);
        $this->assertSame('iPhone', $attempt->deviceLabel());
    }

    /**
     * The accepted selfie is measured too, not only the refused one. On this path the file
     * has already been stored by the time the attempt is recorded, and reading its size
     * after the store still works — store() streams from the temp file rather than moving
     * it away. Worth pinning: if that ever changes, every successful punch quietly records
     * a null size and the comparison this table exists for loses one half.
     */
    public function test_an_accepted_selfie_records_its_size_too(): void
    {
        $this->punch([
            'action' => 'in',
            'photo' => UploadedFile::fake()->image('selfie.jpg')->size(900),
        ]);

        $attempt = AttendanceAttempt::sole();

        $this->assertSame('ok', $attempt->outcome);
        $this->assertTrue($attempt->has_photo);
        $this->assertSame(900 * 1024, $attempt->photo_bytes);
    }

    /**
     * The unit has to switch. A punch with no selfie sends under a kilobyte, and printing
     * that as "0MB" reads as a broken column rather than a small request.
     */
    public function test_payload_sizes_read_in_the_unit_they_belong_in(): void
    {
        $this->assertSame('—', AttendanceAttempt::humanBytes(null));
        $this->assertSame('820B', AttendanceAttempt::humanBytes(820));
        $this->assertSame('5KB', AttendanceAttempt::humanBytes(5120));
        $this->assertSame('4.88MB', AttendanceAttempt::humanBytes(5120000));
    }

    // ── C. The table stays a diagnostic, not a second location log ─

    /**
     * has_location, never the coordinates. The attendance record already stores the fix
     * for a punch that succeeded; a debugging table has no business holding a second copy
     * of where somebody was standing.
     */
    public function test_coordinates_are_never_stored_on_an_attempt(): void
    {
        $this->punch(['action' => 'in']);

        $columns = array_keys(AttendanceAttempt::sole()->getAttributes());

        $this->assertNotContains('latitude', $columns);
        $this->assertNotContains('longitude', $columns);
    }

    // ── D. What never reaches the controller ──────────────────────

    /**
     * A punch stopped by the route's own rate limit throws ThrottleRequestsException,
     * which Laravel does not report — so report() never fires and, without the hook in
     * bootstrap/app.php, the punch failed leaving no trace anywhere. stopIgnoring() cannot
     * fix this: it drops exact class names, and the HttpException entry that also matches
     * stays in the list.
     */
    public function test_a_rate_limited_punch_is_captured_as_a_fault(): void
    {
        config(['app.debug' => false]);
        ErrorEvent::forgetCurrentReference();

        // The route allows 20 a minute; the 21st is refused by the limiter.
        for ($i = 0; $i < 20; $i++) {
            $this->punch(['action' => $i === 0 ? 'in' : 'out']);
        }

        $this->punch(['action' => 'out'])->assertStatus(429);

        $fault = ErrorEvent::sole();

        $this->assertSame(ThrottleRequestsException::class, $fault->exception_class);
        $this->assertSame(self::PHONE_AGENT, $fault->user_agent);
    }

    // ── E. Who may read it ───────────────────────────────────────

    public function test_the_console_lists_refusals_for_a_super_admin(): void
    {
        $this->punch(['action' => 'in'], withFix: false);

        $superAdmin = User::create(['name' => 'Platform', 'email' => 'super@example.com', 'password' => Hash::make('password')]);
        $superAdmin->forceFill(['is_super_admin' => true])->save();

        $this->actingAs($superAdmin)
            ->get(route('superadmin.attendance-attempts.index'))
            ->assertOk()
            ->assertSee('needs_justification')
            ->assertSee('iPhone');
    }

    public function test_an_ordinary_member_cannot_read_the_console(): void
    {
        $this->actingAs($this->user)
            ->get(route('superadmin.attendance-attempts.index'))
            ->assertForbidden();
    }
}
