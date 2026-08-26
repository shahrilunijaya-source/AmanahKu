<?php

use App\Mcp\Servers\AmanahkuServer;
use Laravel\Mcp\Facades\Mcp;

/*
|--------------------------------------------------------------------------
| MCP Server (read-only, token-authed)
|--------------------------------------------------------------------------
|
| Same two middleware as routes/api.php, and for the same reason: auth:sanctum
| resolves the caller from the bearer token, then api.tenant activates the one
| tenant that token is bound to, so the BelongsToTenant global scope isolates
| every query a tool makes. Without api.tenant the scope falls open and a tool
| would read every tenant's rows — it is load-bearing, not decoration.
|
| Each person configures this server in their own Claude Code with their own
| token, so the tools see a real user and the role checks apply per person.
|
*/

Mcp::web('/mcp/amanahku', AmanahkuServer::class)
    ->middleware(['auth:sanctum', 'api.tenant', 'throttle:60,1']);
