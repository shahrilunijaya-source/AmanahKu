<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;

class SecurityController extends Controller
{
    /** Fixed name so "replace the previous key" has one unambiguous row to find. */
    public const AI_KEY_NAME = 'claude-code';

    /** Read-only: the same three scopes the MCP server's read tools check. */
    public const AI_KEY_ABILITIES = ['timesheets:read', 'board:read', 'tot:read'];

    /**
     * Added on top of AI_KEY_ABILITIES when the user ticks "allow_writes" — the same
     * three scopes the MCP server's write tools (and ConfirmWriteTool) check. Every
     * write behind these still goes through the preview/confirm flow and asks before
     * doing anything; ticking this only makes the write tools reachable at all.
     */
    public const AI_KEY_WRITE_ABILITIES = ['board:write', 'timesheets:write', 'tot:write'];

    /**
     * Turn off an active 2FA enrolment. Re-entering the current password guards against
     * a hijacked session silently removing the second factor (Fortify's manage routes
     * run with confirmPassword=false for the QR/recovery UX, so we protect disable here).
     */
    public function disableTwoFactor(Request $request, DisableTwoFactorAuthentication $disable): RedirectResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);

        $disable($request->user());

        AuditLog::record('Disabled two-factor authentication');

        return back()->with('ok', 'Two-factor authentication turned off.');
    }

    /**
     * Mint (or replace) the user's self-service AI access key for the MCP server.
     *
     * One key per user per tenant: any previous claude-code token for this tenant is
     * deleted first. Requires the current password, same guard as disableTwoFactor above.
     * The plaintext is flashed through the session so it can be shown exactly once, on
     * the page the redirect lands on, and never again after that.
     *
     * `allow_writes` is unticked by default. Ticked, the key also carries
     * AI_KEY_WRITE_ABILITIES, reaching the MCP server's write tools (still gated by
     * the preview/confirm flow — this only makes them reachable, it does not skip
     * asking). Regenerating with the box unticked mints a read-only key again: the
     * delete-above-then-mint already drops whatever the previous key carried.
     */
    public function generateAiKey(Request $request, CurrentTenant $currentTenant): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['required', 'current_password'],
            'allow_writes' => ['nullable', 'boolean'],
        ]);

        $tenant = $currentTenant->get();
        abort_unless($tenant !== null, 403);

        $user = $request->user();
        $user->tokens()->where('tenant_id', $tenant->id)->where('name', self::AI_KEY_NAME)->delete();

        $abilities = self::AI_KEY_ABILITIES;
        if ($data['allow_writes'] ?? false) {
            $abilities = [...$abilities, ...self::AI_KEY_WRITE_ABILITIES];
        }

        $token = $user->mintApiToken($tenant, self::AI_KEY_NAME, $abilities);

        AuditLog::record('Generated an AI access key'.(($data['allow_writes'] ?? false) ? ' (with write access)' : ''));

        $command = sprintf(
            'claude mcp add --transport http amanahku %s --header "Authorization: Bearer %s"',
            url('/mcp/amanahku'),
            $token->plainTextToken
        );

        return back()
            ->with('aiKeyPlaintext', $token->plainTextToken)
            ->with('aiKeyCommand', $command);
    }

    /** Delete the user's AI access key for the current tenant, if any. */
    public function revokeAiKey(Request $request, CurrentTenant $currentTenant): RedirectResponse
    {
        $tenant = $currentTenant->get();
        abort_unless($tenant !== null, 403);

        $request->user()->tokens()->where('tenant_id', $tenant->id)->where('name', self::AI_KEY_NAME)->delete();

        AuditLog::record('Revoked AI access key');

        return back()->with('ok', 'AI access key revoked.');
    }
}
