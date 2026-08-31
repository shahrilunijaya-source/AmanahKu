<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\NewAccessToken;

/**
 * An application that reads AmanahKu's API — Track, DevStage 01, SupportOS.
 *
 * Deliberately not a User. A key minted against a staff account inherits that
 * person's role (so it sees far more than the app needs) and dies when they are
 * archived. A client row has neither problem: its authorization is entirely the
 * scope list on its token, and nothing about a person can revoke it by accident.
 *
 * Implements Authenticatable because the token guard returns the tokenable as the
 * authenticated party. It has no password and no session; bearer tokens are the
 * only way in.
 *
 * Not BelongsToTenant: the row is resolved by Sanctum during authentication, before
 * any tenant context exists, so a global scope keyed on CurrentTenant would be inert
 * exactly where isolation matters. tenant_id is set and matched explicitly instead —
 * the same choice PersonalAccessToken documents.
 */
class ApiClient extends Model implements AuthenticatableContract
{
    use Authenticatable, HasApiTokens;

    /**
     * Every scope the API understands, in the order the issuing screen lists them.
     * The single source of truth: ApiController guards against these keys and the
     * super-admin screen renders its checkboxes from them.
     *
     * `payslips:read` is deliberately absent. GET /api/v1/payslips still exists and
     * still checks that ability, so a staff token minted ['*'] reaches it exactly as
     * before — but no application can be issued a key carrying it. None of the apps
     * that read this API has a reason to see anyone's pay, and a checkbox that should
     * never be ticked is better removed than labelled.
     *
     * `timesheets:read`, `board:read` and `tot:read` are also what the MCP server
     * (App\Mcp\Servers\AmanahkuServer and its tools) checks for reads — same
     * catalogue, same super-admin screen, one more caller.
     *
     * `board:write`, `timesheets:write` and `tot:write` gate the MCP server's write
     * tools (App\Mcp\Tools\{CreateCard,UpdateCard,...}Tool and ConfirmWriteTool) —
     * every write is a separate scope from its matching read, so a key can browse a
     * tenant without ever being able to change it. Not REST scopes: routes/api.php
     * stays entirely GET, these have no corresponding /api/v1 route. Only ever
     * granted alongside the self-service AI key flow (SecurityController::
     * generateAiKey()'s allow_writes flag) — see resources/views/screens/security.blade.php.
     *
     * @var array<string, string>
     */
    public const SCOPES = [
        'projects:read' => 'Projects and their categories',
        'employees:read' => 'Employee directory (names, emails, positions)',
        'positions:read' => 'Position bands (no salary)',
        'effort:read' => 'Weekly timesheet effort per band (no names, no salary)',
        'leave:read' => 'Leave requests',
        'timesheets:read' => 'Weekly timesheets and their entries',
        'board:read' => 'Board cards (work items)',
        'tot:read' => 'TOT sessions and participation',
        'board:write' => 'Create and edit board cards, assign tasks (MCP only)',
        'timesheets:write' => 'Save timesheet drafts (MCP only)',
        'tot:write' => 'Post external TOT events (MCP only)',
    ];

    protected $fillable = ['tenant_id', 'name', 'created_by'];

    protected static function booted(): void
    {
        // A client without its keys is a row nobody can use; leaving orphaned tokens
        // behind would leave a live key with no screen showing it.
        static::deleting(function (ApiClient $client): void {
            $client->tokens()->delete();
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Mint a key for this client. The plaintext is returned once via
     * NewAccessToken::plainTextToken and never persisted — only its sha256 hash is
     * stored. tenant_id is stamped on the token row so ApiTenant can activate the
     * right tenant without loading the client first.
     *
     * @param  list<string>  $scopes
     */
    public function mintKey(array $scopes): NewAccessToken
    {
        $token = $this->createToken($this->name, $scopes);
        $token->accessToken->forceFill(['tenant_id' => $this->tenant_id])->save();

        return $token;
    }
}
