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
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Preview-only: moves a card to a different board column, the same gate
 * (BoardRules::authorizeAccess — wider than canManage, a participant may
 * move too) WorkItemController::move() uses. Requires board:write.
 */
#[Name('move_card')]
#[IsReadOnly]
#[Description('Preview moving a board card to a different column (todo/prog/review/done). Anyone who can open the card (owner, assigner, participant, or a manager over the owner) may move it. Requires board:write. Returns a summary and a confirm_token — nothing changes until confirm_write is called.')]
class MoveCardTool extends Tool
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
        $data = $request->validate([
            'work_item_id' => ['required', 'integer'],
            'status' => ['required', 'in:todo,prog,review,done'],
        ]);

        $result = $this->guarded(function () use ($httpRequest, $employee, $data, $tid) {
            $item = WorkItem::query()->whereKey($data['work_item_id'])->where('tenant_id', $tid)->first();
            abort_unless($item !== null, 404, 'Card not found.');

            $this->boardRules->authorizeAccess($httpRequest, $item, $employee);

            return ['item' => $item];
        });

        if (isset($result['error'])) {
            return Response::error($result['error']);
        }

        $item = $result['item'];
        $payload = ['work_item_id' => $item->id, 'status' => $data['status']];

        return $this->preview(
            $httpRequest,
            $payload,
            "Move '".$item->title."' from ".$item->status.' to '.$data['status'].'.',
            ['card' => $item->title, 'status' => ['from' => $item->status, 'to' => $data['status']]],
        );
    }

    /**
     * @return array{error: string}|array{ok: true, status: string}
     */
    public function applyConfirmed(array $payload, HttpRequest $httpRequest, int $tenantId): array
    {
        return $this->guarded(function () use ($payload, $httpRequest, $tenantId) {
            $item = WorkItem::query()->whereKey($payload['work_item_id'])->where('tenant_id', $tenantId)->first();
            abort_unless($item !== null, 404, 'Card not found.');

            $employee = ApiCaller::employee($httpRequest);
            abort_unless($employee !== null, 403, 'No employee profile in this workspace.');

            $this->boardRules->authorizeAccess($httpRequest, $item, $employee);

            $status = $payload['status'];
            $wasDone = $item->status === 'done';

            $item->update([
                'status' => $status,
                'progress' => $status === 'done' ? 100 : $item->progress,
                'done_at' => (! $wasDone && $status === 'done') ? now() : $item->done_at,
            ]);

            if (! $wasDone && $status === 'done' && $item->assigned_by_id) {
                $assigner = Employee::find($item->assigned_by_id);
                AppNotification::send(
                    $assigner?->user_id,
                    $employee->display_name.' completed: '.$item->title,
                    null,
                    route('app.screen', 'board'),
                );
            }

            AuditLog::record('Moved board card'.$this->keySuffix($httpRequest), $item->title.' -> '.$status);

            return ['ok' => true, 'status' => $item->status];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'work_item_id' => $schema->integer()->description('The card to move.')->required(),
            'status' => $schema->string()->enum(['todo', 'prog', 'review', 'done'])->required(),
        ];
    }
}
