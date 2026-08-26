<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\PreviewsWrites;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Support\ApiCaller;
use App\Support\BoardRules;
use App\Support\Permissions;
use App\Tenancy\CurrentTenant;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Preview-only: assigns an adhoc task onto another staff member's board, the
 * same role gate (BoardRules::ASSIGNER_ROLES) and rules
 * WorkItemController::assign() uses — a due date is always required, and an
 * archived employee is always refused. Requires board:write.
 *
 * Confirming this ALWAYS emails and in-app notifies the assignee — the same
 * AppNotification::send(..., mail: true) the browser form triggers. The
 * preview must say so plainly; this is not a silent write.
 */
#[Name('assign_task')]
#[IsReadOnly]
#[Description("Preview assigning an adhoc task onto a staff member's board. Restricted to manager/management/hr roles. A due date is required, and the staff member must be active (not archived). Requires board:write. Confirming this WILL email and in-app notify the assignee. Returns a summary and a confirm_token — nothing is created and no notification is sent until confirm_write is called.")]
class AssignTaskTool extends Tool
{
    use PreviewsWrites;

    public function handle(Request $request): Response
    {
        $httpRequest = request();

        if (! ApiCaller::can($httpRequest, 'board:write')) {
            return Response::error('This token lacks the board:write scope.');
        }

        $assigner = ApiCaller::employee($httpRequest);
        if (! $assigner) {
            return Response::error('No employee profile in this workspace.');
        }

        $role = Permissions::effectiveRole($httpRequest->attributes->get('tenantRole', 'employee'));
        if (! in_array($role, BoardRules::ASSIGNER_ROLES, true)) {
            return Response::error('Your role cannot assign tasks.');
        }

        $tid = app(CurrentTenant::class)->id();

        $data = $request->validate([
            'employee_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:160'],
            'type' => ['required', 'in:assignment,task,adhoc'],
            'priority' => ['required', 'in:high,medium,low'],
            'due_at' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:5000'],
        ]);

        $employee = Employee::query()->whereKey($data['employee_id'])->where('tenant_id', $tid)->first();
        if (! $employee) {
            return Response::error('That employee was not found in this tenant.');
        }
        if ($employee->isArchived()) {
            return Response::error('You cannot assign a task to an archived staff member.');
        }

        // The + operator keeps the left array's value on a key collision, so this
        // overrides $data's own 'employee_id' (the raw, unvalidated-as-a-model id)
        // with the row we just loaded and checked.
        $payload = ['assigner_id' => $assigner->id, 'employee_id' => $employee->id] + $data;

        return $this->preview(
            $httpRequest,
            $payload,
            "Assign '".$data['title']."' to ".$employee->display_name.', due '.$data['due_at'].
                '. This WILL email and notify '.$employee->display_name.'.',
            [
                'assignee' => $employee->display_name,
                'title' => $data['title'],
                'type' => $data['type'],
                'priority' => $data['priority'],
                'due_at' => $data['due_at'],
                'notifies' => $employee->display_name.' (email + in-app)',
            ],
        );
    }

    /**
     * @return array{error: string}|array{ok: true, card: array<string, mixed>}
     */
    public function applyConfirmed(array $payload, HttpRequest $httpRequest, int $tenantId): array
    {
        return $this->guarded(function () use ($payload, $httpRequest, $tenantId) {
            $assigner = Employee::query()->whereKey($payload['assigner_id'])->where('tenant_id', $tenantId)->first();
            abort_unless($assigner !== null, 422, 'That assigner no longer exists in this tenant.');

            $role = Permissions::effectiveRole($httpRequest->attributes->get('tenantRole', 'employee'));
            abort_unless(in_array($role, BoardRules::ASSIGNER_ROLES, true), 403, 'Your role cannot assign tasks.');

            $employee = Employee::query()->whereKey($payload['employee_id'])->where('tenant_id', $tenantId)->first();
            abort_unless($employee !== null, 422, 'That employee no longer exists in this tenant.');
            abort_if($employee->isArchived(), 422, 'You cannot assign a task to an archived staff member.');

            $item = $employee->workItems()->create([
                'title' => $payload['title'],
                'type' => $payload['type'],
                'priority' => $payload['priority'],
                'due_at' => $payload['due_at'],
                'description' => $payload['description'] ?? null,
                'links' => [],
                'status' => 'todo',
                'progress' => 0,
                'assigned_by_id' => $assigner->id,
                'assigned_at' => now(),
                'sort_order' => (int) $employee->workItems()->where('status', 'todo')->max('sort_order') + 1,
            ]);

            AppNotification::send(
                $employee->user_id,
                $assigner->display_name.' assigned you a task',
                $item->title,
                route('app.screen', 'board'),
                mail: true,
            );

            AuditLog::record('Assigned task'.$this->keySuffix($httpRequest), $item->title.' -> '.$employee->display_name);

            return ['ok' => true, 'card' => ['id' => $item->id, 'title' => $item->title, 'assignee' => $employee->display_name]];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'employee_id' => $schema->integer()->description('The staff member to assign this to.')->required(),
            'title' => $schema->string()->required(),
            'type' => $schema->string()->enum(['assignment', 'task', 'adhoc'])->required(),
            'priority' => $schema->string()->enum(['high', 'medium', 'low'])->required(),
            'due_at' => $schema->string()->description('Required. YYYY-MM-DD.')->required(),
            'description' => $schema->string(),
        ];
    }
}
