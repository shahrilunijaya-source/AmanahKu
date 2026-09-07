<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PortOutbox;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use App\Ports\Adapters\GoogleCalendarAdapter;
use App\Ports\CalendarPort;
use App\Ports\Data\CalendarEvent;
use App\Ports\Stub\StubCalendarPort;
use App\Ports\TrackPort;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * S07 details the acceptance file does not pin: an env driver that is not enabled still
 * resolves to the stub, and the unbound Google scaffold fails closed without calling out.
 */
class PortsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        app(CurrentTenant::class)->set($this->tenant);
    }

    private function person(string $name): Employee
    {
        $user = User::create(['name' => $name, 'email' => strtolower($name).'@example.com', 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'employee']);

        return Employee::create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'name' => $name, 'status' => 'active', 'workload' => 'green']);
    }

    #[Test]
    public function a_driver_that_is_not_enabled_falls_back_to_the_stub(): void
    {
        config(['ports.driver.calendar' => 'google']);
        $this->app->forgetInstance(CalendarPort::class);

        $this->assertInstanceOf(StubCalendarPort::class, app(CalendarPort::class));
    }

    #[Test]
    public function the_google_scaffold_fails_closed_when_nothing_is_configured_and_calls_nothing(): void
    {
        config(['services.google_calendar' => ['client_id' => null, 'client_secret' => null]]);
        $for = $this->person('Emysha');
        $card = WorkItem::create(['tenant_id' => $this->tenant->id, 'employee_id' => $for->id, 'title' => 'Card', 'type' => 'task', 'priority' => 'low', 'status' => 'todo', 'progress' => 0, 'due_at' => '2026-10-01']);
        $adapter = app(GoogleCalendarAdapter::class);

        $result = $adapter->upsertEvent($for, new CalendarEvent('Card', CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-02'), subject: $card));
        $this->assertFalse($result->ok);
        $row = PortOutbox::find($result->outboxId);
        $this->assertSame('failed', $row->status);
        $this->assertStringContainsString('not configured', $row->error);
        $this->assertSame($card->id, (int) $row->subject_id);

        $pull = $adapter->pullChanges($for, CarbonImmutable::parse('2026-09-01'));
        $this->assertFalse($pull->ok);
        $this->assertStringContainsString('deferred', PortOutbox::find($pull->outboxId)->error);

        Http::assertNothingSent();
    }

    #[Test]
    public function a_call_with_no_tenant_anywhere_still_does_not_throw(): void
    {
        app(CurrentTenant::class)->set(null);
        $port = app(TrackPort::class);

        $result = $port->pullProjects();

        $this->assertFalse($result->ok);
        $this->assertSame(0, $result->outboxId);
        $this->assertSame(0, PortOutbox::count());
    }
}
