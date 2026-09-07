<?php

namespace Tests\Acceptance;

use App\Ports\CalendarPort;
use App\Ports\Data\CalendarEvent;
use App\Ports\Data\MailMessage;
use App\Ports\Data\TrackProject;
use App\Ports\MailPort;
use App\Ports\PortResult;
use App\Ports\TrackPort;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * Acceptance for docs/build/contracts/ports.md (session S07, ports and stubs). The
 * contract is the spec: it has no numbered Acceptance section, so the items below are
 * its paragraphs in order: interfaces, binding, outbox, stub behaviour, never-throw, and
 * nothing leaving the app.
 */
class PortsTest extends TestCase
{
    use AlwaysChecks;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Mail::fake();
        Notification::fake();
        app(CurrentTenant::class)->set($this->tenant());
    }

    #[Test]
    public function test_acceptance_1_the_three_port_interfaces_and_their_value_objects_exist_with_the_contract_methods(): void
    {
        $expected = [
            CalendarPort::class => ['upsertEvent', 'deleteEvent', 'pullChanges'],
            TrackPort::class => ['pullProjects', 'pushComment', 'withdrawComment'],
            MailPort::class => ['send'],
        ];

        foreach ($expected as $interface => $methods) {
            $this->assertTrue(interface_exists($interface), "{$interface} is not an interface");
            $reflection = new ReflectionClass($interface);
            foreach ($methods as $method) {
                $this->assertTrue($reflection->hasMethod($method), "{$interface}::{$method} missing");
                $return = $reflection->getMethod($method)->getReturnType();
                $this->assertInstanceOf(ReflectionNamedType::class, $return, "{$interface}::{$method} has no return type");
                $this->assertSame(PortResult::class, $return->getName(), "{$interface}::{$method} must return PortResult");
            }
        }

        foreach ([CalendarEvent::class, TrackProject::class, MailMessage::class] as $vo) {
            $this->assertTrue(class_exists($vo), "{$vo} missing");
            $this->assertTrue((new ReflectionClass($vo))->isReadOnly(), "{$vo} must be a readonly class");
        }

        $result = new PortResult(ok: true, externalId: 'x', payload: ['a' => 1], outboxId: 7);
        $this->assertTrue($result->ok);
        $this->assertSame('x', $result->externalId);
        $this->assertSame(['a' => 1], $result->payload);
        $this->assertSame(7, $result->outboxId);
    }

    #[Test]
    public function test_acceptance_2_every_port_is_bound_to_the_stub_driver_by_default_and_the_google_client_is_not_bound(): void
    {
        foreach (['calendar', 'track', 'mail'] as $port) {
            $this->assertSame('stub', config("ports.driver.{$port}"), "ports.driver.{$port} must default to stub");
        }

        foreach ([CalendarPort::class, TrackPort::class, MailPort::class] as $interface) {
            $bound = app($interface);
            $this->assertInstanceOf($interface, $bound);
            $this->assertStringStartsWith('App\\Ports\\Stub\\', $bound::class, $interface.' resolved to '.$bound::class.', not a stub');
        }

        if (class_exists('App\\Ports\\Adapters\\GoogleCalendarAdapter')) {
            $this->assertNotInstanceOf('App\\Ports\\Adapters\\GoogleCalendarAdapter', app(CalendarPort::class));
        }

        $this->assertTrue(class_exists('App\\Providers\\PortsServiceProvider'), 'PortsServiceProvider missing');
    }

    #[Test]
    public function test_acceptance_3_a_calendar_call_writes_one_outbox_row_first_and_the_stub_marks_it_sent_with_a_synthetic_id(): void
    {
        $for = $this->person('Emysha');
        $card = $this->card($for, ['title' => 'Sprint review', 'due_at' => '2026-10-01']);
        $event = new CalendarEvent(
            title: 'Sprint review',
            startsAt: CarbonImmutable::parse('2026-10-01 10:00'),
            endsAt: CarbonImmutable::parse('2026-10-01 11:00'),
            description: 'Card 1',
            subject: $card,
        );

        $result = app(CalendarPort::class)->upsertEvent($for, $event);

        $this->assertTrue($result->ok);
        $row = \DB::table('port_outbox')->find($result->outboxId);
        $this->assertNotNull($row, 'no outbox row');
        $this->assertSame('calendar', $row->port);
        $this->assertSame('upsertEvent', $row->method);
        $this->assertSame($this->tenant()->id, (int) $row->tenant_id);
        $this->assertSame($card->getMorphClass(), $row->subject_type);
        $this->assertSame($card->id, (int) $row->subject_id);
        $this->assertSame('sent', $row->status);
        $this->assertSame(1, (int) $row->attempts);
        $this->assertNotNull($row->sent_at);
        $this->assertNull($row->error);
        $this->assertSame("stub-calendar-{$row->id}", $row->external_id);
        $this->assertSame($row->external_id, $result->externalId);
        $payload = json_decode($row->payload, true);
        $this->assertSame('Sprint review', $payload['title']);
        $this->assertSame($for->id, (int) $payload['for_employee_id']);

        $delete = app(CalendarPort::class)->deleteEvent($for, $result->externalId);
        $this->assertTrue($delete->ok);
        $this->assertDatabaseHas('port_outbox', ['id' => $delete->outboxId, 'port' => 'calendar', 'method' => 'deleteEvent', 'status' => 'sent']);

        $pull = app(CalendarPort::class)->pullChanges($for, CarbonImmutable::parse('2026-09-01'));
        $this->assertTrue($pull->ok);
        $this->assertSame([], $pull->payload, 'the stub pulls nothing');
        $this->assertDatabaseHas('port_outbox', ['id' => $pull->outboxId, 'port' => 'calendar', 'method' => 'pullChanges', 'status' => 'sent']);

        $this->assertSame(3, \DB::table('port_outbox')->count(), 'one row per call, no more');
    }

    #[Test]
    public function test_acceptance_4_track_calls_write_outbox_rows_and_the_stub_answers_with_an_empty_project_list(): void
    {
        $by = $this->person('Emysha');
        $port = app(TrackPort::class);

        $projects = $port->pullProjects();
        $this->assertTrue($projects->ok);
        $this->assertSame([], $projects->payload);
        $this->assertDatabaseHas('port_outbox', ['id' => $projects->outboxId, 'port' => 'track', 'method' => 'pullProjects', 'status' => 'sent', 'external_id' => "stub-track-{$projects->outboxId}"]);

        $comment = $port->pushComment('TRK-42', 'Done on our side', $by);
        $this->assertTrue($comment->ok);
        $this->assertSame("stub-track-{$comment->outboxId}", $comment->externalId);
        $row = \DB::table('port_outbox')->find($comment->outboxId);
        $payload = json_decode($row->payload, true);
        $this->assertSame('TRK-42', $payload['track_ref']);
        $this->assertSame('Done on our side', $payload['body']);
        $this->assertSame($by->id, (int) $payload['by_employee_id']);

        $withdraw = $port->withdrawComment('TRK-42', $comment->externalId);
        $this->assertTrue($withdraw->ok);
        $this->assertDatabaseHas('port_outbox', ['id' => $withdraw->outboxId, 'port' => 'track', 'method' => 'withdrawComment', 'status' => 'sent']);

        $this->assertSame(3, \DB::table('port_outbox')->count());
    }

    #[Test]
    public function test_acceptance_5_mail_goes_through_the_port_into_the_outbox_and_nothing_leaves_the_app(): void
    {
        $message = new MailMessage(
            to: ['emysha@example.com'],
            subject: 'Your TOT session is tomorrow',
            bodyEn: 'See you at 9.',
            bodyMs: 'Jumpa jam 9.',
            kind: 'tot_reminder',
        );

        $result = app(MailPort::class)->send($message);

        $this->assertTrue($result->ok);
        $row = \DB::table('port_outbox')->find($result->outboxId);
        $this->assertSame('mail', $row->port);
        $this->assertSame('send', $row->method);
        $this->assertSame('sent', $row->status);
        $this->assertSame("stub-mail-{$row->id}", $row->external_id);
        $payload = json_decode($row->payload, true);
        $this->assertSame(['emysha@example.com'], $payload['to']);
        $this->assertSame('Your TOT session is tomorrow', $payload['subject']);
        $this->assertSame('See you at 9.', $payload['body_en']);
        $this->assertSame('Jumpa jam 9.', $payload['body_ms']);
        $this->assertSame('tot_reminder', $payload['kind']);

        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
        Http::assertNothingSent();
    }

    #[Test]
    public function test_acceptance_6_a_port_call_never_throws_a_failure_is_a_result_with_ok_false_and_the_error_on_the_row(): void
    {
        $nobody = new MailMessage(to: [], subject: 'Lost', bodyEn: 'x', bodyMs: 'x', kind: 'probe');

        $result = app(MailPort::class)->send($nobody);

        $this->assertFalse($result->ok);
        $this->assertNull($result->externalId);
        $row = \DB::table('port_outbox')->find($result->outboxId);
        $this->assertNotNull($row, 'a failed call must still leave its outbox row');
        $this->assertSame('failed', $row->status);
        $this->assertSame(1, (int) $row->attempts);
        $this->assertNotEmpty($row->error);
        $this->assertNull($row->sent_at);

        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    #[Test]
    public function test_acceptance_7_no_feature_code_or_port_code_calls_out_directly(): void
    {
        $offenders = [];
        foreach ($this->phpFilesUnder(app_path('Ports')) as $file) {
            $source = file_get_contents($file);
            foreach (['Http::', 'Mail::', 'Notification::', 'curl_', 'GuzzleHttp', 'Google\\', 'Brevo', 'file_get_contents(\'http', 'fsockopen'] as $needle) {
                if (str_contains($source, $needle) && ! str_contains($file, 'Adapters')) {
                    $offenders[] = basename($file).' contains '.$needle;
                }
            }
        }

        $this->assertSame([], $offenders, 'the stub side of app/Ports must not reach outside the app');
    }

    #[Test]
    public function test_always_the_four_cross_cutting_checks(): void
    {
        $this->assertDueDateLocked();
        $this->assertAuditLogImmutable();
        $this->assertDashboardUnchanged();
        $this->assertKeepItPlainHonoured();
    }

    /** @return list<string> */
    private function phpFilesUnder(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
