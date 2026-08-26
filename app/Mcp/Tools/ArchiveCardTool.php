<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\PreviewsWrites;
use App\Models\AuditLog;
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
 * Preview-only: archives a Done card off the board, the same gate
 * (BoardRules::canManage) and same "must be Done" rule WorkItemController::archive()
 * uses. Requires board:write.
 */
#[Name('archive_card')]
#[IsReadOnly]
#[Description('Preview archiving a Done board card. Only the card\'s owner, assigner, or a manager over the owner may archive it, and it must already be in the Done column. Requires board:write. Returns a summary and a confirm_token — nothing changes until confirm_write is called.')]
class ArchiveCardTool extends Tool
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
            $item = WorkItem::query()->whereKey($data['work_item_id'])->where('tenant_id', $tid)->first();
            abort_unless($item !== null, 404, 'Card not found.');

            $this->boardRules->authorizeManage($httpRequest, $item, $employee);
            abort_unless($item->status === 'done', 422, 'Only a Done card can be archived.');

            return ['item' => $item];
        });

        if (isset($result['error'])) {
            return Response::error($result['error']);
        }

        $item = $result['item'];

        return $this->preview(
            $httpRequest,
            ['work_item_id' => $item->id],
            "Archive '".$item->title."' off the board.",
            ['card' => $item->title, 'action' => 'archive'],
        );
    }

    /**
     * @return array{error: string}|array{ok: true}
     */
    public function applyConfirmed(array $payload, HttpRequest $httpRequest, int $tenantId): array
    {
        return $this->guarded(function () use ($payload, $httpRequest, $tenantId) {
            $item = WorkItem::query()->whereKey($payload['work_item_id'])->where('tenant_id', $tenantId)->first();
            abort_unless($item !== null, 404, 'Card not found.');

            $employee = ApiCaller::employee($httpRequest);
            abort_unless($employee !== null, 403, 'No employee profile in this workspace.');

            $this->boardRules->authorizeManage($httpRequest, $item, $employee);
            abort_unless($item->status === 'done', 422, 'Only a Done card can be archived.');

            $item->update(['archived_at' => now()]);

            AuditLog::record('Archived board card'.$this->keySuffix($httpRequest), $item->title);

            return ['ok' => true];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'work_item_id' => $schema->integer()->description('The Done card to archive.')->required(),
        ];
    }
}
