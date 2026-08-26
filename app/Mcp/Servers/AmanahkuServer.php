<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\ArchiveCardTool;
use App\Mcp\Tools\AssignTaskTool;
use App\Mcp\Tools\ConfirmWriteTool;
use App\Mcp\Tools\CreateCardTool;
use App\Mcp\Tools\CreateExternalTotEventTool;
use App\Mcp\Tools\MoveCardTool;
use App\Mcp\Tools\RestoreCardTool;
use App\Mcp\Tools\SaveTimesheetDraftTool;
use App\Mcp\Tools\TimesheetWeekTool;
use App\Mcp\Tools\TotSessionsTool;
use App\Mcp\Tools\UpdateCardTool;
use App\Mcp\Tools\WorkItemsTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * MCP server over AmanahKu: timesheets, the work board, and TOT sessions.
 * Every tool runs behind the same bearer-token stack as the REST API
 * (routes/ai.php: auth:sanctum + api.tenant), so it only ever sees the one
 * tenant the caller's token is bound to, and each tool checks its own scope
 * before doing anything.
 *
 * Reads are direct: TimesheetWeekTool, WorkItemsTool and TotSessionsTool
 * return data straight away, gated by timesheets:read / board:read / tot:read.
 *
 * Writes are two-step. Every other tool except ConfirmWriteTool is a PREVIEW:
 * it validates and authorizes a change, describes exactly what would happen,
 * and returns a confirm_token — it writes nothing. ConfirmWriteTool is the
 * only tool that actually changes anything, and only when handed a token a
 * preview tool just returned. Writes are gated by board:write, timesheets:write
 * or tot:write, separate from the read scopes above.
 */
#[Name('Amanahku Server')]
#[Version('0.2.0')]
#[Instructions(<<<'MARKDOWN'
    This server gives access to one AmanahKu tenant's HR data: weekly timesheets,
    work board (kanban) cards, and TOT (Transfer of Training) sessions.

    READS are direct and require their own scope (timesheets:read, board:read,
    tot:read), refusing with a clear message naming the scope if missing. A
    privileged caller (management or HR role) sees the whole tenant. Everyone else
    sees only their own timesheet and only board cards assigned to them or
    unassigned. TOT sessions are company-wide and are never narrowed by role.

    WRITES are always two steps, and require their own scope (board:write,
    timesheets:write, tot:write) — separate from the read scopes:

      1. Call the preview tool for the change you want (create_card, update_card,
         move_card, archive_card, restore_card, assign_task,
         save_timesheet_draft, create_external_tot_event). It validates and
         authorizes the change and returns {summary, changes, confirm_token}.
         NOTHING IS WRITTEN by a preview tool.
      2. Show the summary and changes to the user in plain language and WAIT for
         their explicit approval. Do not assume approval, and do not call
         confirm_write on your own initiative.
      3. Only after the user approves, call confirm_write with that confirm_token
         to actually apply the change. A token is single-use and expires after
         10 minutes; if it expires, run the preview again.

    save_timesheet_draft only ever merges into a draft week — a day you don't
    mention keeps whatever is already saved, and a submitted week is refused.
    assign_task and its confirm step WILL email and in-app notify the assignee;
    the preview says so plainly. create_external_tot_event never tags anyone,
    on any run, so it never sends a "you're required to attend" email.
    MARKDOWN
)]
class AmanahkuServer extends Server
{
    protected array $tools = [
        TimesheetWeekTool::class,
        WorkItemsTool::class,
        TotSessionsTool::class,
        CreateCardTool::class,
        UpdateCardTool::class,
        MoveCardTool::class,
        ArchiveCardTool::class,
        RestoreCardTool::class,
        AssignTaskTool::class,
        SaveTimesheetDraftTool::class,
        CreateExternalTotEventTool::class,
        ConfirmWriteTool::class,
    ];

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];
}
