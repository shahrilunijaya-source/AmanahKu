<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ErrorEvent;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Covers the production debugging path: an unhandled fault is written to the
 * database, the user is shown a reference for it, and a super-admin can read the
 * fault back from that reference. Production offers no shell and no log file, so
 * this chain is the only way a fault there is ever diagnosed.
 */
class ErrorEventCaptureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The debug page replaces errors/500.blade.php and would hide the reference.
        // Production runs with debug off, so the test must too.
        config(['app.debug' => false]);
        ErrorEvent::forgetCurrentReference();

        Route::middleware('web')->get('/__boom', function () {
            throw new RuntimeException('exploded on purpose');
        });
    }

    private function superAdmin(): User
    {
        $user = User::create(['name' => 'Platform', 'email' => 'super@example.com', 'password' => Hash::make('password')]);
        $user->forceFill(['is_super_admin' => true])->save();

        return $user;
    }

    // ── A. Capture ────────────────────────────────────────────────

    public function test_an_unhandled_fault_is_captured_with_its_context(): void
    {
        $this->get('/__boom')->assertStatus(500);

        $event = ErrorEvent::sole();

        $this->assertSame(RuntimeException::class, $event->exception_class);
        $this->assertSame('exploded on purpose', $event->message);
        $this->assertSame('GET', $event->method);
        $this->assertStringContainsString('/__boom', $event->url);
        $this->assertMatchesRegularExpression('/^[0-9A-F]{8}$/', $event->reference);
        $this->assertNotEmpty($event->trace);
    }

    public function test_the_signed_in_user_is_recorded_on_the_fault(): void
    {
        $user = $this->superAdmin();

        $this->actingAs($user)->get('/__boom')->assertStatus(500);

        $this->assertSame($user->id, ErrorEvent::sole()->user_id);
    }

    public function test_the_stack_carries_no_call_arguments(): void
    {
        Route::middleware('web')->get('/__boom-args', function () {
            $leak = function (string $password) {
                throw new RuntimeException('failed');
            };

            $leak('hunter2-in-the-clear');
        });

        $this->get('/__boom-args')->assertStatus(500);

        // getTraceAsString() would print this argument. Storing frames only is what
        // keeps a submitted password out of a table HR faults also land in.
        $this->assertStringNotContainsString('hunter2', ErrorEvent::sole()->trace);
    }

    public function test_a_missing_page_is_not_captured(): void
    {
        $this->get('/__no-such-page')->assertStatus(404);

        $this->assertSame(0, ErrorEvent::count());
    }

    public function test_a_failing_capture_does_not_break_the_error_page(): void
    {
        // The most likely production fault is the database being unreachable. If
        // capture threw from inside the exception handler, the user would get a blank
        // page instead of a readable one.
        Schema::drop('error_events');

        $this->get('/__boom')->assertStatus(500)->assertSee('Something went wrong');
    }

    // ── B. Reference reaches the user ─────────────────────────────

    public function test_the_error_page_prints_the_reference(): void
    {
        $this->get('/__boom')->assertStatus(500)->assertSee(ErrorEvent::sole()->reference);
    }

    public function test_a_json_caller_receives_the_reference(): void
    {
        $response = $this->getJson('/__boom')->assertStatus(500);

        $reference = ErrorEvent::sole()->reference;

        $response->assertJsonPath('reference', $reference)
            ->assertHeader('X-Error-Reference', $reference);
    }

    // ── C. Reading it back ────────────────────────────────────────

    public function test_a_super_admin_can_read_a_captured_fault(): void
    {
        $this->get('/__boom')->assertStatus(500);
        $event = ErrorEvent::sole();

        $this->actingAs($this->superAdmin())
            ->get(route('superadmin.errors.show', $event))
            ->assertOk()
            ->assertSee($event->reference)
            ->assertSee('exploded on purpose');
    }

    public function test_the_list_finds_a_fault_by_its_reference(): void
    {
        $this->get('/__boom')->assertStatus(500);
        $reference = ErrorEvent::sole()->reference;

        $this->actingAs($this->superAdmin())
            ->get(route('superadmin.errors.index', ['q' => strtolower($reference)]))
            ->assertOk()
            ->assertSee($reference)
            ->assertSee('Run self-test');
    }

    public function test_the_self_test_route_proves_the_whole_chain(): void
    {
        // The only way to answer "is capture working on this environment" from a
        // super-admin login alone, which on production is all a developer holds.
        $response = $this->actingAs($this->superAdmin())
            ->get(route('superadmin.errors.self-test'))
            ->assertStatus(500);

        $event = ErrorEvent::sole();

        $this->assertStringContainsString('self-test', $event->message);
        $response->assertSee($event->reference)->assertHeader('X-Error-Reference', $event->reference);
    }

    public function test_an_ordinary_user_cannot_run_the_self_test(): void
    {
        $staff = User::create(['name' => 'Staff', 'email' => 'nobody@example.com', 'password' => Hash::make('password')]);

        $this->actingAs($staff)
            ->get(route('superadmin.errors.self-test'))
            ->assertForbidden();

        $this->assertSame(0, ErrorEvent::count());
    }

    public function test_an_ordinary_user_cannot_read_captured_faults(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => Hash::make('password')]);
        $staff->tenants()->attach($tenant->id, ['role' => 'employee']);

        $this->actingAs($staff)
            ->get(route('superadmin.errors.index'))
            ->assertForbidden();
    }
}
