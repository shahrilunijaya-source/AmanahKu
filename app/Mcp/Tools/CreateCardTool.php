<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\PreviewsWrites;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\WorkItem;
use App\Support\ApiCaller;
use App\Support\BoardRules;
use App\Tenancy\CurrentTenant;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Preview-only: adds a card to the caller's own board, the same fields
 * WorkItemController::store() accepts. Requires board:write. Nothing is
 * written here — see PreviewsWrites::preview() and ConfirmWriteTool.
 */
#[Name('create_card')]
#[IsReadOnly]
#[Description('Preview creating a new board card on your OWN board (mirrors the "add work item" form). timesheet_category_id sets the effort type the card is costed as once it reaches a timesheet — without it BoardSuggestions holds the card\'s rows back and it never turns up on the timesheet screen. Requires board:write. Returns a summary and a confirm_token — nothing is created until confirm_write is called with that token.')]
class CreateCardTool extends Tool
{
    use PreviewsWrites;

    public function __construct(private BoardRules $boardRules) {}

    public function handle(Request $request): Response
    {
        $httpRequest = request();

        if (! ApiCaller::can($httpRequest, 'board:write')) {
            return Response::error('This token lacks the board:write scope.');
        }

        $employee = ApiCaller::employee($httpRequest);
        if (! $employee) {
            return Response::error('No employee profile in this workspace — there is no board to add a card to.');
        }

        $tid = app(CurrentTenant::class)->id();

        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'type' => ['required_without:parent_id', 'in:assignment,task,adhoc'],
            'priority' => ['required', 'in:high,medium,low'],
            'status' => ['nullable', 'in:todo,prog,review,done'],
            'due_label' => ['nullable', 'string', 'max:60'],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->where('tenant_id', $tid)],
            'timesheet_category_id' => ['nullable', 'integer', Rule::exists('timesheet_categories', 'id')->where('tenant_id', $tid)],
            // Default (ParentOnly) scope on purpose: a subtask's id is not found, which
            // is what refuses a grandchild.
            'parent_id' => ['nullable', 'integer', Rule::exists('work_items', 'id')->where('tenant_id', $tid)->whereNull('parent_id')],
        ]);

        if (! empty($data['parent_id'])) {
            return $this->previewChild($request, $httpRequest, $employee, $data);
        }

        $payload = ['employee_id' => $employee->id] + $data;

        $changes = [
            'board' => 'your own',
            'title' => $data['title'],
            'type' => $data['type'],
            'priority' => $data['priority'],
            'status' => $data['status'] ?? 'todo',
            'due_label' => $data['due_label'] ?? null,
            'project_id' => $data['project_id'] ?? null,
            'timesheet_category_id' => $data['timesheet_category_id'] ?? null,
        ];

        return $this->preview(
            $httpRequest,
            $payload,
            "Create a new '".$data['title']."' card on your board, in the ".($data['status'] ?? 'todo').' column.',
            $changes,
        );
    }

    /**
     * A subtask lands on the PARENT's board, whoever adds it, under the same grant as
     * the browser (WorkItemController::storeChild): anyone who can open the parent.
     * Type, project and category come from the parent; the subtask starts open.
     *
     * @param  array<string, mixed>  $data
     */
    private function previewChild(Request $request, HttpRequest $httpRequest, Employee $employee, array $data): Response
    {
        $result = $this->guarded(function () use ($httpRequest, $employee, $data) {
            $parent = WorkItem::query()->find($data['parent_id']);
            abort_unless($parent !== null, 404, 'Parent card not found.');
            $this->boardRules->authorizeAccess($httpRequest, $parent, $employee);

            return ['parent' => $parent];
        });

        if (isset($result['error'])) {
            return Response::error($result['error']);
        }

        $parent = $result['parent'];
        $payload = [
            'employee_id' => $parent->employee_id,
            'parent_id' => $parent->id,
            'title' => $data['title'],
            'priority' => $data['priority'],
            'due_label' => $data['due_label'] ?? null,
        ];

        return $this->preview(
            $httpRequest,
            $payload,
            "Create subtask '".$data['title']."' under '".$parent->title."'.",
            ['board' => "under '".$parent->title."'", 'title' => $data['title'], 'priority' => $data['priority'], 'due_label' => $data['due_label'] ?? null],
        );
    }

    /**
     * Re-validated at confirm time: the employee row and its tenant must still match.
     *
     * @return array{error: string}|array{ok: true, card: array<string, mixed>}
     */
    public function applyConfirmed(array $payload, HttpRequest $httpRequest, int $tenantId): array
    {
        if (! empty($payload['parent_id'])) {
            return $this->applyChild($payload, $httpRequest, $tenantId);
        }

        return $this->guarded(function () use ($payload, $httpRequest, $tenantId) {
            $employee = Employee::query()->whereKey($payload['employee_id'])->where('tenant_id', $tenantId)->first();
            abort_unless($employee !== null, 422, 'That employee no longer exists in this tenant.');

            $status = $payload['status'] ?? 'todo';

            $item = $employee->workItems()->create([
                'title' => $payload['title'],
                'type' => $payload['type'],
                'priority' => $payload['priority'],
                'due_label' => $payload['due_label'] ?? null,
                'project_id' => $payload['project_id'] ?? null,
                'timesheet_category_id' => $payload['timesheet_category_id'] ?? null,
                'status' => $status,
                'progress' => 0,
                'done_at' => $status === 'done' ? now() : null,
                'sort_order' => (int) $employee->workItems()->where('status', $status)->max('sort_order') + 1,
            ]);

            // Same guard WorkItemController::store() runs: a project the chosen category
            // does not offer never sticks, however the card was created.
            BoardRules::dropProjectTheCategoryDisallows($item);

            AuditLog::record('Created board card'.$this->keySuffix($httpRequest), $item->title);

            return ['ok' => true, 'card' => ['id' => $item->id, 'title' => $item->title, 'status' => $item->status]];
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{error: string}|array{ok: true, card: array<string, mixed>}
     */
    private function applyChild(array $payload, HttpRequest $httpRequest, int $tenantId): array
    {
        return $this->guarded(function () use ($payload, $httpRequest, $tenantId) {
            $parent = WorkItem::query()->whereKey($payload['parent_id'])->where('tenant_id', $tenantId)->first();
            abort_unless($parent !== null, 404, 'Parent card no longer exists.');

            $employee = ApiCaller::employee($httpRequest);
            abort_unless($employee !== null, 403, 'No employee profile in this workspace.');
            $this->boardRules->authorizeAccess($httpRequest, $parent, $employee);

            $item = $parent->children()->create([
                'tenant_id' => $parent->tenant_id,
                'employee_id' => $parent->employee_id,
                'title' => $payload['title'],
                'type' => $parent->type,
                'priority' => $payload['priority'],
                'due_label' => $payload['due_label'] ?? null,
                'project_id' => $parent->project_id,
                'timesheet_category_id' => $parent->timesheet_category_id,
                'status' => 'todo',
                'progress' => 0,
                'sort_order' => (int) $parent->children()->max('sort_order') + 1,
            ]);

            AuditLog::record('Created board subtask'.$this->keySuffix($httpRequest), $item->title);

            return ['ok' => true, 'card' => ['id' => $item->id, 'title' => $item->title, 'status' => $item->status, 'parent_id' => $parent->id]];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('Card title.')->required(),
            'type' => $schema->string()->enum(['assignment', 'task', 'adhoc'])->description('Required unless parent_id is given (a subtask copies its parent\'s type).'),
            'priority' => $schema->string()->enum(['high', 'medium', 'low'])->required(),
            'status' => $schema->string()->enum(['todo', 'prog', 'review', 'done'])->description('Defaults to todo.'),
            'due_label' => $schema->string()->description('Free-text due label (not a real date).'),
            'parent_id' => $schema->integer()->description('Make this card a subtask of that card. The subtask lands on the parent\'s board, copies its type, project and category, and is only ever todo or done. A subtask cannot itself be a parent.'),
            'project_id' => $schema->integer()->description('Project this card is planned under, if any. Dropped if it does not match timesheet_category_id — see that field.'),
            'timesheet_category_id' => $schema->integer()->description('The effort type this card is costed as on a timesheet. Call timesheet_options to see valid ids. A category that does not require a project (e.g. HR & Admin) drops project_id if it was sent; a category that does (e.g. Development) only keeps project_id when that project is tagged with it.'),
        ];
    }
}
