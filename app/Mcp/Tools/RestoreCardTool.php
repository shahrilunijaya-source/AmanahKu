<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\PreviewsWrites;
use App\Models\AuditLog;
use App\Models\Scopes\ParentOnly;
use App\Models\WorkItem;
use App\Support\ApiCaller;
use App\Support\BoardRules;
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
 * Preview-only: brings an archived card back to the board at To Do, the same
 * gate (BoardRules::canManage) WorkItemController::restore() uses. Requires
 * board:write.
 */
#[Name('restore_card')]
#[IsReadOnly]
#[Description('Preview restoring an archived board card back onto the board, at To Do. Only the card\'s owner, assigner, or a manager over the owner may restore it. Requires board:write. Returns a summary and a confirm_token — nothing changes until confirm_write is called.')]
class RestoreCardTool extends Tool
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

        $tid = app(CurrentTenant::class)->id();
        $data = $request->validate(['work_item_id' => ['required', 'integer']]);

        $result = $this->guarded(function () use ($httpRequest, $employee, $data, $tid) {
            $item = WorkItem::withoutGlobalScope(ParentOnly::class)->whereKey($data['work_item_id'])->where('tenant_id', $tid)->first();
            abort_unless($item !== null, 404, 'Card not found.');

            $this->boardRules->authorizeManage($httpRequest, $item, $employee);

            return ['item' => $item];
        });

        if (isset($result['error'])) {
            return Response::error($result['error']);
        }

        $item = $result['item'];

        return $this->preview(
            $httpRequest,
            ['work_item_id' => $item->id],
            "Restore '".$item->title."' back onto the board, at To Do.",
            ['card' => $item->title, 'action' => 'restore'],
        );
    }

    /**
     * @return array{error: string}|array{ok: true}
     */
    public function applyConfirmed(array $payload, HttpRequest $httpRequest, int $tenantId): array
    {
        return $this->guarded(function () use ($payload, $httpRequest, $tenantId) {
            $item = WorkItem::withoutGlobalScope(ParentOnly::class)->whereKey($payload['work_item_id'])->where('tenant_id', $tenantId)->first();
            abort_unless($item !== null, 404, 'Card not found.');

            $employee = ApiCaller::employee($httpRequest);
            abort_unless($employee !== null, 403, 'No employee profile in this workspace.');

            $this->boardRules->authorizeManage($httpRequest, $item, $employee);

            $item->update([
                'archived_at' => null,
                'status' => 'todo',
                'sort_order' => (int) $employee->workItems()->where('status', 'todo')->max('sort_order') + 1,
            ]);
            $item->children()->update(['archived_at' => null]);

            AuditLog::record('Restored board card'.$this->keySuffix($httpRequest), $item->title);

            return ['ok' => true];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'work_item_id' => $schema->integer()->description('The archived card to restore.')->required(),
        ];
    }
}
