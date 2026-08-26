<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\TimesheetWeekTool;
use App\Mcp\Tools\TotSessionsTool;
use App\Mcp\Tools\WorkItemsTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * Read-only MCP server over AmanahKu: timesheets, the work board, and TOT
 * sessions. Every tool runs behind the same bearer-token stack as the REST
 * API (routes/ai.php: auth:sanctum + api.tenant), so it only ever sees the
 * one tenant the caller's token is bound to, and each tool checks its own
 * scope before returning anything.
 */
#[Name('Amanahku Server')]
#[Version('0.1.0')]
#[Instructions(<<<'MARKDOWN'
    This server gives read-only access to one AmanahKu tenant's HR data: weekly
    timesheets, work board (kanban) cards, and TOT (Transfer of Training) sessions.

    It never writes anything — there are no create/update/delete tools here. Each
    tool requires its own scope (timesheets:read, board:read, tot:read) on the
    caller's token, and refuses with a clear message naming the scope if missing.

    A privileged caller (management or HR role) sees the whole tenant. Everyone
    else sees only their own timesheet and only board cards assigned to them or
    unassigned. TOT sessions are company-wide and are never narrowed by role.
    MARKDOWN
)]
class AmanahkuServer extends Server
{
    protected array $tools = [
        TimesheetWeekTool::class,
        WorkItemsTool::class,
        TotSessionsTool::class,
    ];

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];
}
