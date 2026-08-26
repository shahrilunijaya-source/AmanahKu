<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\WorkItem;
use App\Support\ApiCaller;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

/**
 * Board cards (work items). Privileged callers (management|hr) see the whole
 * tenant's board; everyone else sees only cards assigned to them or unassigned —
 * the same "own records" rule as the rest of the read API, applied to employee_id.
 */
#[Description('Search the board for work item cards (title, status, priority, labels, due date, assignee, project). Privileged callers (management/HR) see the whole board; everyone else sees only cards assigned to them or unassigned.')]
class WorkItemsTool extends Tool
{
    public function handle(Request $request): Response
    {
        $httpRequest = request();

        if (! ApiCaller::can($httpRequest, 'board:read')) {
            return Response::error('This token lacks the board:read scope.');
        }

        $args = $request->validate([
            'status' => ['sometimes', 'string', 'in:todo,prog,review,done'],
            'assignee' => ['sometimes', 'string'],
            'project' => ['sometimes', 'string'],
            'include_archived' => ['sometimes', 'boolean'],
        ]);

        $query = WorkItem::query()->with(['employee:id,name', 'projectRef:id,code,name']);

        if (! ($args['include_archived'] ?? false)) {
            $query->whereNull('archived_at');
        }

        if (isset($args['status'])) {
            $query->where('status', $args['status']);
        }

        if (isset($args['assignee'])) {
            $query->whereHas('employee', fn ($q) => $q->where('name', 'like', '%'.$args['assignee'].'%'));
        }

        if (isset($args['project'])) {
            $query->whereHas('projectRef', fn ($q) => $q->where('code', 'like', '%'.$args['project'].'%')
                ->orWhere('name', 'like', '%'.$args['project'].'%'));
        }

        if (! ApiCaller::isPrivileged($httpRequest)) {
            $employee = ApiCaller::employee($httpRequest);
            $employeeId = $employee?->id;
            $query->where(fn ($q) => $q->where('employee_id', $employeeId)->orWhereNull('employee_id'));
        }

        $cards = $query->get()->map($this->cardRow(...));

        return Response::json(['work_items' => $cards]);
    }

    /**
     * @return array{title: string, status: string, priority: ?string, labels: array<int, string>, due_date: ?string, assignee: ?string, project: ?string}
     */
    private function cardRow(WorkItem $item): array
    {
        return [
            'title' => $item->title,
            'status' => $item->status,
            'priority' => $item->priority,
            'labels' => $item->labels ?? [],
            'due_date' => $item->due_at?->toDateString(),
            'assignee' => $item->employee?->name,
            'project' => $item->projectRef?->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->enum(['todo', 'prog', 'review', 'done'])
                ->description('Filter to one board column.'),
            'assignee' => $schema->string()
                ->description('Filter to cards whose assignee name contains this fragment.'),
            'project' => $schema->string()
                ->description('Filter to cards whose project code or name contains this fragment.'),
            'include_archived' => $schema->boolean()
                ->description('Include archived cards. Defaults to false.'),
        ];
    }
}
