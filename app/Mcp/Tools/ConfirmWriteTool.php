<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\PendingWrite;
use App\Support\ApiCaller;
use App\Tenancy\CurrentTenant;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

/**
 * The second step of every write in this server. A preview tool
 * (App\Mcp\Tools\{CreateCard,UpdateCard,MoveCard,ArchiveCard,RestoreCard,
 * AssignTask,SaveTimesheetDraft,CreateExternalTotEvent}Tool) validates and
 * authorizes a change, then stashes it via App\Mcp\PendingWrite and hands
 * back a token instead of writing anything. This tool redeems that token
 * exactly once and actually applies the change — re-checking the required
 * scope and re-running the same authorization the preview ran, because the
 * token can sit for up to ten minutes and the world can change underneath it
 * (a card deleted, a role changed, a week submitted from the browser).
 *
 * Deliberately NOT annotated #[IsReadOnly] — this is the one tool in the
 * server that writes.
 *
 * Throttle: every tool on this server shares one JSON-RPC route
 * (routes/ai.php: POST /mcp/amanahku, throttle:60,1) because the MCP
 * protocol multiplexes every tool call through that single endpoint — the
 * tool being called only appears inside the POST body, not the URL, so
 * route-level `throttle:<name>` middleware (the pattern this codebase
 * otherwise uses, see FortifyServiceProvider) cannot single out writes from
 * reads without a second physical route and a second URL for people to
 * configure. A write's actual effect (an email, a changed card, a changed
 * timesheet) also warrants a tighter cap than a read regardless. So the
 * write budget is enforced here in code instead, the one place every write
 * in the server actually passes through: WRITE_LIMIT_PER_MINUTE per caller,
 * well under the route's shared 60/min. Preview tools are unmetered — they
 * only read and stash a cache entry, same cost as the read tools.
 */
#[Name('confirm_write')]
#[Description('Apply a write that was already previewed by one of the other tools. Pass the confirm_token that tool returned. Only call this AFTER the user has seen the preview\'s summary and changes and explicitly approved it — never on your own initiative. The token is single-use and expires after 10 minutes.')]
class ConfirmWriteTool extends Tool
{
    /** Which scope each preview tool's write requires, checked again here. */
    private const SCOPE_BY_TOOL = [
        'create_card' => 'board:write',
        'update_card' => 'board:write',
        'move_card' => 'board:write',
        'archive_card' => 'board:write',
        'restore_card' => 'board:write',
        'assign_task' => 'board:write',
        'save_timesheet_draft' => 'timesheets:write',
        'create_external_tot_event' => 'tot:write',
    ];

    /** Which class actually knows how to apply each tool's stashed payload. */
    private const CLASS_BY_TOOL = [
        'create_card' => CreateCardTool::class,
        'update_card' => UpdateCardTool::class,
        'move_card' => MoveCardTool::class,
        'archive_card' => ArchiveCardTool::class,
        'restore_card' => RestoreCardTool::class,
        'assign_task' => AssignTaskTool::class,
        'save_timesheet_draft' => SaveTimesheetDraftTool::class,
        'create_external_tot_event' => CreateExternalTotEventTool::class,
    ];

    /** Tighter than the route's shared 60/min — see the class docblock. */
    private const WRITE_LIMIT_PER_MINUTE = 20;

    public function handle(Request $request): Response
    {
        $httpRequest = request();

        $data = $request->validate(['token' => ['required', 'string']]);

        $userId = $httpRequest->user()?->getAuthIdentifier();
        $tenantId = app(CurrentTenant::class)->id();

        if (! $userId || ! $tenantId) {
            return Response::error('No active session for this token.');
        }

        // Keyed per caller (not per tenant), so one busy AI key can't starve writes for
        // everyone else at the same company. Checked before consuming the token, so a
        // rate-limited call doesn't burn the caller's single-use confirm_token — the
        // same token still works once the window clears.
        $limitKey = 'mcp-write:'.$userId;
        if (RateLimiter::tooManyAttempts($limitKey, self::WRITE_LIMIT_PER_MINUTE)) {
            $seconds = RateLimiter::availableIn($limitKey);

            return Response::error("Too many writes — try again in {$seconds}s. Writes are limited to ".self::WRITE_LIMIT_PER_MINUTE.' per minute per caller.');
        }
        RateLimiter::hit($limitKey, 60);

        $entry = app(PendingWrite::class)->consume($data['token'], (int) $userId, (int) $tenantId);

        if ($entry === null) {
            return Response::error('This confirm_token is invalid, already used, or has expired (tokens last 10 minutes). Run the preview tool again.');
        }

        $toolName = $entry['tool'];
        $scope = self::SCOPE_BY_TOOL[$toolName] ?? null;
        $applierClass = self::CLASS_BY_TOOL[$toolName] ?? null;

        if ($scope === null || $applierClass === null) {
            return Response::error('This token was stashed for a tool this server no longer recognises.');
        }

        if (! ApiCaller::can($httpRequest, $scope)) {
            return Response::error("This token lacks the {$scope} scope.");
        }

        $result = app($applierClass)->applyConfirmed($entry['payload'], $httpRequest, (int) $tenantId);

        if (isset($result['error'])) {
            return Response::error($result['error']);
        }

        return Response::json($result);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'token' => $schema->string()
                ->description('The confirm_token returned by a preview tool.')
                ->required(),
        ];
    }
}
