<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\PreviewsWrites;
use App\Models\AppNotification;
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
 * Preview-only: edits an existing card's fields, gated by the same
 * BoardRules::canManage() the detail drawer's autosave uses. Requires
 * board:write. Nothing is written here — see ConfirmWriteTool.
 */
#[Name('update_card')]
#[IsReadOnly]
#[Description('Preview editing a board card\'s fields (title, description, type, priority, due date, project, labels, links, participants). Only the card owner, its assigner, or a manager covering the owner may edit it. Requires board:write. Returns a summary and a confirm_token — nothing changes until confirm_write is called.')]
class UpdateCardTool extends Tool
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
            return Response::error('No employee profile in this workspace.');
        }

        $args = $request->validate(['work_item_id' => ['required', 'integer']]);
        $tid = app(CurrentTenant::class)->id();

        $result = $this->guarded(function () use ($request, $httpRequest, $employee, $args, $tid) {
            $item = WorkItem::query()->whereKey($args['work_item_id'])->where('tenant_id', $tid)->first();
            abort_unless($item !== null, 404, 'Card not found.');

            $this->boardRules->authorizeManage($httpRequest, $item, $employee);

            $data = $request->validate([
                'title' => ['sometimes', 'required', 'string', 'max:160'],
                'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
                'type' => ['sometimes', 'required', 'in:assignment,task,adhoc'],
                'priority' => ['sometimes', 'required', 'in:high,medium,low'],
                'due_at' => ['sometimes', 'nullable', 'date'],
                'due_label' => ['sometimes', 'nullable', 'string', 'max:60'],
                'project_id' => ['sometimes', 'nullable', 'integer', Rule::exists('projects', 'id')->where('tenant_id', $tid)],
                'labels' => ['sometimes', 'array'],
                'labels.*' => ['string', Rule::in(array_keys(WorkItem::LABELS))],
                'links' => ['sometimes', 'array', 'max:12'],
                'links.*.label' => ['required_with:links', 'string', 'max:60'],
                'links.*.url' => ['required_with:links', 'url', 'max:2000'],
                'participant_ids' => ['sometimes', 'array'],
                'participant_ids.*' => ['integer'],
            ]);

            abort_if($data === [], 422, 'No fields to update.');

            $this->boardRules->assertDueDateRetained($item, $data);

            return ['item' => $item, 'data' => $data];
        });

        if (isset($result['error'])) {
            return Response::error($result['error']);
        }

        $item = $result['item'];
        $data = $result['data'];

        $payload = ['work_item_id' => $item->id, 'data' => $data];

        $changes = ['card' => $item->title];
        foreach ($data as $field => $value) {
            $changes[$field] = ['from' => $item->getAttribute($field), 'to' => $value];
        }

        return $this->preview(
            $httpRequest,
            $payload,
            "Update '".$item->title."': ".implode(', ', array_keys($data)).'.',
            $changes,
        );
    }

    /**
     * @return array{error: string}|array{ok: true, card: array<string, mixed>}
     */
    public function applyConfirmed(array $payload, HttpRequest $httpRequest, int $tenantId): array
    {
        return $this->guarded(function () use ($payload, $httpRequest, $tenantId) {
            $item = WorkItem::query()->whereKey($payload['work_item_id'])->where('tenant_id', $tenantId)->first();
            abort_unless($item !== null, 404, 'Card not found.');

            $employee = ApiCaller::employee($httpRequest);
            abort_unless($employee !== null, 403, 'No employee profile in this workspace.');

            $this->boardRules->authorizeManage($httpRequest, $item, $employee);

            $data = $payload['data'];
            $this->boardRules->assertDueDateRetained($item, $data);

            if (array_key_exists('participant_ids', $data)) {
                $this->syncParticipants($item, $data['participant_ids'], $employee);
                unset($data['participant_ids']);
            }

            $item->update($data);

            AuditLog::record('Updated board card'.$this->keySuffix($httpRequest), $item->title);

            return ['ok' => true, 'card' => ['id' => $item->id, 'title' => $item->title]];
        });
    }

    /** Mirrors WorkItemController::syncParticipants() — never the owner, active tenant employees only. */
    private function syncParticipants(WorkItem $item, array $ids, Employee $actor): void
    {
        $target = Employee::active()
            ->whereIn('id', array_filter($ids))
            ->where('id', '!=', $item->employee_id)
            ->pluck('id');

        $before = $item->participants()->pluck('employees.id');
        $item->participants()->sync($target);

        foreach ($target->diff($before) as $addedId) {
            AppNotification::send(
                Employee::find($addedId)?->user_id,
                $actor->display_name.' added you to a task',
                $item->title,
                route('app.screen', 'board'),
                mail: true,
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'work_item_id' => $schema->integer()->description('The card to edit.')->required(),
            'title' => $schema->string()->description('New title.'),
            'description' => $schema->string()->description('New description.'),
            'type' => $schema->string()->enum(['assignment', 'task', 'adhoc']),
            'priority' => $schema->string()->enum(['high', 'medium', 'low']),
            'due_at' => $schema->string()->description('New due date, YYYY-MM-DD. A card shared with anyone else needs one.'),
            'due_label' => $schema->string()->description('Free-text due label.'),
            'project_id' => $schema->integer()->description('New project, or omit to leave unchanged.'),
            'labels' => $schema->array()->items($schema->string())->description('Label slugs: '.implode(', ', array_keys(WorkItem::LABELS)).'.'),
            'links' => $schema->array()->items($schema->object(['label' => $schema->string(), 'url' => $schema->string()]))->description('Full replacement list of links: [{label, url}].'),
            'participant_ids' => $schema->array()->items($schema->integer())->description('Full replacement list of participant employee ids.'),
        ];
    }
}
