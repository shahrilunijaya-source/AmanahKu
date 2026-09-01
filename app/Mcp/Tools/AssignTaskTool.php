<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\PreviewsWrites;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Project;
use App\Models\TimesheetCategory;
use App\Models\WorkItem;
use App\Support\ApiCaller;
use App\Support\BoardRules;
use App\Support\Permissions;
use App\Tenancy\CurrentTenant;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
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
 * archived employee is always refused. The staff member is named either by
 * employee_id or, because no caller has a staff directory to hand, by the
 * nickname people actually say ("Nabil") — see matchByName(). Also accepts timesheet_category_id and
 * project_id, and applies the same BoardRules::dropProjectTheCategoryDisallows()
 * guard assign() does: a card handed to someone else is still work that has to
 * be costed, and an assigned card whose category nobody set produces no
 * timesheet row at all — the assignee would have to open it on their own board
 * to find out why their week will not add up. Requires board:write.
 *
 * Confirming this ALWAYS emails and in-app notifies the assignee — the same
 * AppNotification::send(..., mail: true) the browser form triggers. The
 * preview must say so plainly; this is not a silent write.
 */
#[Name('assign_task')]
#[IsReadOnly]
#[Description("Preview assigning an adhoc task onto a staff member's board. Restricted to manager/management/hr roles. Name the staff member with `employee` — the nickname people actually say, e.g. 'Nabil' — or with employee_id if you already have it. A name matching nobody, or more than one person, is refused with the candidates listed. A due date is required, and the staff member must be active (not archived). timesheet_category_id sets the effort type the assigned card is costed as once it reaches the assignee's timesheet — without it the card never turns up there. Requires board:write. Confirming this WILL email and in-app notify the assignee. Returns a summary and a confirm_token — nothing is created and no notification is sent until confirm_write is called.")]
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
            'employee_id' => ['required_without:employee', 'nullable', 'integer'],
            'employee' => ['required_without:employee_id', 'nullable', 'string', 'max:80'],
            'title' => ['required', 'string', 'max:160'],
            'type' => ['required', 'in:assignment,task,adhoc'],
            'priority' => ['required', 'in:high,medium,low'],
            'due_at' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:5000'],
            // Same rule WorkItemController::assign() applies, and for the same reason —
            // see its docblock: an assigned card whose category nobody set produces no
            // timesheet row at all for the assignee.
            'timesheet_category_id' => ['nullable', 'integer', Rule::exists('timesheet_categories', 'id')->where('tenant_id', $tid)],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->where('tenant_id', $tid)],
        ]);

        if (isset($data['employee_id'])) {
            $employee = Employee::query()->whereKey($data['employee_id'])->where('tenant_id', $tid)->first();
            if (! $employee) {
                return Response::error('That employee was not found in this tenant.');
            }
            if ($employee->isArchived()) {
                return Response::error('You cannot assign a task to an archived staff member.');
            }
        } else {
            $matches = $this->matchByName($data['employee'], $tid);

            if ($matches->isEmpty()) {
                return Response::error("No active staff member here matches '".$data['employee']."'.");
            }
            // Never guess between people — a task assigned to the wrong person emails
            // the wrong person. Hand the ambiguity back with the ids to choose from.
            if ($matches->count() > 1) {
                return Response::error("'".$data['employee']."' matches ".$matches->count().' people: '.
                    $matches->map(fn (Employee $e) => $e->display_name.' (employee_id '.$e->id.')')->join(', ').
                    '. Re-run naming the employee_id you mean.');
            }

            $employee = $matches->first();
        }

        // The + operator keeps the left array's value on a key collision, so this
        // overrides $data's own 'employee_id' (the raw, unvalidated-as-a-model id)
        // with the row we just loaded and checked.
        $payload = ['assigner_id' => $assigner->id, 'employee_id' => $employee->id] + $data;

        $changes = [
            'assignee' => $employee->display_name,
            'title' => $data['title'],
            'type' => $data['type'],
            'priority' => $data['priority'],
            'due_at' => $data['due_at'],
            // Names, not raw ids — the preview is what a human approves, and an id means
            // nothing to them.
            'timesheet_category' => TimesheetCategory::find($data['timesheet_category_id'] ?? null)?->name,
            'project' => Project::find($data['project_id'] ?? null)?->name,
            'notifies' => $employee->display_name.' (email + in-app)',
        ];

        // Mirrors UpdateCardTool: say so in the preview rather than letting the pairing
        // silently drop the project at confirm time. A fresh, unpersisted WorkItem
        // stands in for "the card about to be created" — there is no existing row yet
        // to overlay the edit onto, unlike UpdateCardTool's probe.
        if (BoardRules::wouldDropProject(new WorkItem, $data)) {
            $changes['project'] = null;
            $changes['project_dropped'] = 'timesheet_category_id does not offer this project — project_id will not be saved.';
        }

        return $this->preview(
            $httpRequest,
            $payload,
            "Assign '".$data['title']."' to ".$employee->display_name.', due '.$data['due_at'].
                '. This WILL email and notify '.$employee->display_name.'.',
            $changes,
        );
    }

    /**
     * Active staff whose nickname or full name contains $needle. An exact hit on
     * either wins outright, so "Nabil" resolves cleanly even when a "Nabilah" also
     * contains it; anything short of that stays ambiguous on purpose and is handed
     * back to the caller rather than guessed at.
     *
     * Archived staff are excluded here rather than matched and then refused — they
     * are not assignable at all, so a name that only matches an archived person
     * reads as "nobody", which is what it means.
     *
     * @return Collection<int, Employee>
     */
    private function matchByName(string $needle, int $tenantId): Collection
    {
        $rows = Employee::query()->active()->where('tenant_id', $tenantId)
            ->where(fn ($q) => $q->where('nickname', 'like', '%'.$needle.'%')->orWhere('name', 'like', '%'.$needle.'%'))
            ->orderBy('name')->get();

        $exact = $rows->filter(fn (Employee $e) => strcasecmp((string) $e->nickname, $needle) === 0
            || strcasecmp($e->name, $needle) === 0);

        return $exact->isNotEmpty() ? $exact->values() : $rows;
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
                'timesheet_category_id' => $payload['timesheet_category_id'] ?? null,
                'project_id' => $payload['project_id'] ?? null,
                'status' => 'todo',
                'progress' => 0,
                'assigned_by_id' => $assigner->id,
                'assigned_at' => now(),
                'sort_order' => (int) $employee->workItems()->where('status', 'todo')->max('sort_order') + 1,
            ]);

            // Same guard WorkItemController::assign() runs: a project the chosen
            // category does not offer never sticks, however the card was created.
            BoardRules::dropProjectTheCategoryDisallows($item);

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
            'employee' => $schema->string()->description('The staff member to assign this to, by the nickname people actually say ("Nabil") or by full name. Pass this or employee_id. A name nobody matches, or one that matches more than one person, is refused with the candidates listed rather than guessed at.'),
            'employee_id' => $schema->integer()->description('The staff member to assign this to, by id. Only needed when `employee` came back ambiguous.'),
            'title' => $schema->string()->required(),
            'type' => $schema->string()->enum(['assignment', 'task', 'adhoc'])->required(),
            'priority' => $schema->string()->enum(['high', 'medium', 'low'])->required(),
            'due_at' => $schema->string()->description('Required. YYYY-MM-DD.')->required(),
            'description' => $schema->string(),
            'timesheet_category_id' => $schema->integer()->description('The effort type this card is costed as once it reaches the assignee\'s timesheet. Call timesheet_options to see valid ids. A category that does not require a project drops project_id if it was sent; one that does only keeps project_id when that project is tagged with it.'),
            'project_id' => $schema->integer()->description('Project this card is planned under, if any. Dropped if it does not match timesheet_category_id — see that field.'),
        ];
    }
}
