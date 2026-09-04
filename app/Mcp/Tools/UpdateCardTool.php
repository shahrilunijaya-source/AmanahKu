<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\PreviewsWrites;
use App\Mcp\Tools\Concerns\ResolvesEmployeeNames;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Scopes\ParentOnly;
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
#[Description('Preview editing a board card\'s fields (title, description, type, priority, due date, project, timesheet effort type, labels, links, participants). timesheet_category_id sets the effort type the card is costed as once it reaches a timesheet — a card with none set never turns up on the timesheet screen. Name participants with `participants` — the nicknames people actually say, e.g. [\'Nabil\'] — or with participant_ids if you already have them. Only the card owner, its assigner, or a manager covering the owner may edit it. Requires board:write. Returns a summary and a confirm_token — nothing changes until confirm_write is called.')]
class UpdateCardTool extends Tool
{
    use PreviewsWrites;
    use ResolvesEmployeeNames;

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
            $item = WorkItem::withoutGlobalScope(ParentOnly::class)->whereKey($args['work_item_id'])->where('tenant_id', $tid)->first();
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
                'timesheet_category_id' => ['sometimes', 'nullable', 'integer', Rule::exists('timesheet_categories', 'id')->where('tenant_id', $tid)],
                'labels' => ['sometimes', 'array'],
                'labels.*' => ['string', Rule::in(array_keys(WorkItem::LABELS))],
                'links' => ['sometimes', 'array', 'max:12'],
                'links.*.label' => ['required_with:links', 'string', 'max:60'],
                'links.*.url' => ['required_with:links', 'url', 'max:2000'],
                'participant_ids' => ['sometimes', 'array'],
                'participant_ids.*' => ['integer'],
                'participants' => ['sometimes', 'array', 'prohibits:participant_ids'],
                'participants.*' => ['string', 'max:80'],
            ]);

            abort_if($data === [], 422, 'No fields to update.');

            $names = $this->namesToParticipantIds($data, $tid);

            $this->boardRules->assertDueDateRetained($item, $data);

            return ['item' => $item, 'data' => $data, 'participant_names' => $names];
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

        // dropProjectTheCategoryDisallows() at confirm time can silently null project_id
        // as a SIDE EFFECT of a project_id or timesheet_category_id edit — a change the
        // human approving this preview would otherwise never see (or, if they DID send
        // a project_id themselves, would see a value that will not actually stick). Same
        // invariant SaveTimesheetDraftTool leans on throughout: preview and confirm must
        // never disagree about what a write does.
        if (BoardRules::wouldDropProject($item, $data)) {
            $changes['project_id'] = ['from' => $item->project_id, 'to' => null];
        }

        // The whole point of naming participants is that the approver reads names.
        // Echoing back the ids we just resolved would undo that.
        if ($result['participant_names'] !== null) {
            $changes['participant_ids'] = [
                'from' => $item->participants()->get()->map(fn (Employee $e) => $e->display_name)->values()->all(),
                'to' => $result['participant_names'],
            ];
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
            $item = WorkItem::withoutGlobalScope(ParentOnly::class)->whereKey($payload['work_item_id'])->where('tenant_id', $tenantId)->first();
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

            // Changing either half of the pair can leave the other one stranded — see
            // WorkItemController::update()'s call to the same guard (commit 9558b39).
            if (array_key_exists('project_id', $data) || array_key_exists('timesheet_category_id', $data)) {
                BoardRules::dropProjectTheCategoryDisallows($item);
            }

            AuditLog::record('Updated board card'.$this->keySuffix($httpRequest), $item->title);

            return ['ok' => true, 'card' => ['id' => $item->id, 'title' => $item->title]];
        });
    }

    /**
     * Turns a `participants` list of spoken names into the `participant_ids` the
     * rest of the tool already understands, in place. Every name has to resolve to
     * exactly one active person or the whole edit is refused — a half-applied
     * participant list would silently drop somebody off the card.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>|null The resolved display names, or null if no names were sent.
     */
    private function namesToParticipantIds(array &$data, int $tenantId): ?array
    {
        if (! array_key_exists('participants', $data)) {
            return null;
        }

        $names = [];
        $ids = [];
        $errors = [];

        foreach ($data['participants'] as $needle) {
            $found = $this->resolveByName($needle, $tenantId);

            if (is_string($found)) {
                $errors[] = $found;

                continue;
            }

            $ids[] = $found->id;
            $names[] = $found->display_name;
        }

        abort_if($errors !== [], 422, implode(' ', $errors));

        unset($data['participants']);
        $data['participant_ids'] = $ids;

        return $names;
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
            'project_id' => $schema->integer()->description('New project, or omit to leave unchanged. Dropped again if it does not match the card\'s current (or newly-set) timesheet_category_id.'),
            'timesheet_category_id' => $schema->integer()->description('The effort type this card is costed as on a timesheet, or omit to leave unchanged. Call timesheet_options to see valid ids. Changing it can drop project_id if the new category does not offer the card\'s current project.'),
            'labels' => $schema->array()->items($schema->string())->description('Label slugs: '.implode(', ', array_keys(WorkItem::LABELS)).'.'),
            'links' => $schema->array()->items($schema->object(['label' => $schema->string(), 'url' => $schema->string()]))->description('Full replacement list of links: [{label, url}].'),
            'participant_ids' => $schema->array()->items($schema->integer())->description('Full replacement list of participant employee ids. Prefer `participants` unless a name came back ambiguous.'),
            'participants' => $schema->array()->items($schema->string())->description('Full replacement list of participants, by the nicknames people actually say (["Nabil", "Kus"]) or full names. Pass this or participant_ids, not both. A name matching nobody, or more than one person, refuses the whole edit with the candidates listed rather than guessing.'),
        ];
    }
}
