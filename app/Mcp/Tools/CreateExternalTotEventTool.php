<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\PreviewsWrites;
use App\Models\AuditLog;
use App\Models\CompanyEvent;
use App\Models\Employee;
use App\Support\ApiCaller;
use App\Support\Permissions;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Preview-only: posts an external event (a training/workshop hosted outside the
 * company) onto the Events screen, the same role gate EventController::store()
 * uses (manager/management/hr). Requires tot:write. Kept as its own class and
 * tool key — "create_external_tot_event" — even though the feature now lives
 * on company_events; renaming would ripple through AmanahkuServer,
 * ConfirmWriteTool's map, docs/mcp.blade.php and their tests for no gain.
 *
 * host is always required here: CompanyEvent::isExternal() reads `host !== null`,
 * so the host IS the "External -- hosted outside the company" tick the Events
 * screen shows. Without it an outside training would be posted as an internal
 * event, offering RSVP instead of the organiser's sign-up link.
 *
 * The scope stays tot:write rather than moving to an events-shaped one even
 * though the feature no longer lives on TOT: every AI key already issued carries
 * tot:write, and a new scope would silently stop working for all of them until
 * each person regenerated their key.
 *
 * Always creates with tagged_employee_ids EMPTY — this tool never tags
 * anyone, on purpose, so it can never trigger store()'s "You're required to
 * attend" email. Filling title/host/event_date/etc. from a pasted invite is
 * the entire point of this tool; tagging staff to summon them is not
 * something an AI key should ever do unattended.
 */
#[Name('create_external_tot_event')]
#[IsReadOnly]
#[Description("Preview posting an EXTERNAL event (a training, workshop or briefing hosted outside the company) onto the Events screen, from details like a pasted invite. External TOT is no longer its own screen -- these are company events carrying the 'External' mark. host is required and is what marks the event external; without it the event would post as an ordinary internal one. Restricted to manager/management/hr roles. Always posts with NO tagged employees — this tool never tags or summons anyone, so no 'required to attend' email is ever sent from it. Requires tot:write. Returns a summary and a confirm_token — nothing is posted until confirm_write is called.")]
class CreateExternalTotEventTool extends Tool
{
    use PreviewsWrites;

    private const PRIVILEGED_ROLES = ['manager', 'management', 'hr'];

    public function handle(Request $request): Response
    {
        $httpRequest = request();

        if (! ApiCaller::can($httpRequest, 'tot:write')) {
            return Response::error('This token lacks the tot:write scope.');
        }

        $poster = ApiCaller::employee($httpRequest);
        if (! $poster) {
            return Response::error('No employee profile in this workspace.');
        }

        $role = Permissions::effectiveRole($httpRequest->attributes->get('tenantRole', 'employee'));
        if (! in_array($role, self::PRIVILEGED_ROLES, true)) {
            return Response::error('Your role cannot post an External TOT event.');
        }

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            // Required, unlike EventController::store()'s own rule, because host is
            // what MARKS an event external: CompanyEvent::isExternal() is
            // `host !== null`. The browser form cannot post a hostless external
            // event -- ticking "External -- hosted outside the company" reveals the
            // field as `:required="external"` -- but this tool has no tick to
            // enforce, so omitting host here would quietly file an outside training
            // as an ordinary internal one, with RSVP instead of a sign-up link.
            'host' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'event_date' => ['required', 'date'],
            'time_label' => ['nullable', 'string', 'max:60'],
            'venue' => ['nullable', 'string', 'max:200'],
            'venue_map_url' => ['nullable', 'url', 'max:2000'],
            'registration_url' => ['nullable', 'url', 'max:2000'],
        ]);

        $payload = ['poster_id' => $poster->id] + $data;

        return $this->preview(
            $httpRequest,
            $payload,
            "Post the external event '".$data['title']."' on ".$data['event_date'].'. No employees will be tagged or notified.',
            $data + ['tagged_employee_ids' => [], 'notifies' => 'nobody — this tool never tags anyone'],
        );
    }

    /**
     * @return array{error: string}|array{ok: true, event: array<string, mixed>}
     */
    public function applyConfirmed(array $payload, HttpRequest $httpRequest, int $tenantId): array
    {
        return $this->guarded(function () use ($payload, $httpRequest, $tenantId) {
            $poster = Employee::query()->whereKey($payload['poster_id'])->where('tenant_id', $tenantId)->first();
            abort_unless($poster !== null, 422, 'That poster no longer exists in this tenant.');

            $role = Permissions::effectiveRole($httpRequest->attributes->get('tenantRole', 'employee'));
            abort_unless(in_array($role, self::PRIVILEGED_ROLES, true), 403, 'Your role cannot post an External TOT event.');

            $event = CompanyEvent::create([
                'title' => $payload['title'],
                'type' => 'training',
                'host' => $payload['host'],
                'description' => $payload['description'] ?? null,
                'event_date' => $payload['event_date'],
                'start_time' => $payload['time_label'] ?? null,
                'location' => $payload['venue'] ?? null,
                'venue_map_url' => $payload['venue_map_url'] ?? null,
                'registration_url' => $payload['registration_url'] ?? null,
                // Always empty — see the class docblock. Never tagged from this path.
                'tagged_employee_ids' => [],
                'tenant_id' => $tenantId,
                'created_by_employee_id' => $poster->id,
            ]);

            AuditLog::record('Posted external event'.$this->keySuffix($httpRequest), $event->title);

            return ['ok' => true, 'event' => ['id' => $event->id, 'title' => $event->title]];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required(),
            'host' => $schema->string()->description('The outside organisation running it. Required: this is what marks the event External rather than an internal company one.')->required(),
            'description' => $schema->string(),
            'event_date' => $schema->string()->description('YYYY-MM-DD.')->required(),
            'time_label' => $schema->string(),
            'venue' => $schema->string(),
            'venue_map_url' => $schema->string(),
            'registration_url' => $schema->string(),
        ];
    }
}
