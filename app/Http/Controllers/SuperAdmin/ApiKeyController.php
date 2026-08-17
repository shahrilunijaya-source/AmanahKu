<?php

declare(strict_types=1);

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use App\Models\AuditLog;
use App\Models\PersonalAccessToken;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View as ViewContract;

/**
 * Issue and revoke machine API keys for one company.
 *
 * The plaintext is rendered exactly once, on the redirect straight after minting.
 * Only its sha256 hash is stored, so there is no second chance and no recovery flow:
 * a lost key is revoked and replaced, same as a leaked one.
 *
 * Sits behind super.admin alongside the feature matrix. This is the one screen that
 * makes an integration possible on production, where there is no shell.
 */
class ApiKeyController extends Controller
{
    /** Every key issued for this company, with its scopes and last use. */
    public function index(Tenant $tenant): ViewContract
    {
        return view('superadmin.companies.api-keys', [
            'company' => $tenant,
            // ->has('tokens') excludes clients left behind with zero live tokens (revoke()
            // deletes only the token row, not the client), which would otherwise reach the
            // view as a non-empty $clients whose inner @foreach renders nothing — a blank
            // page instead of the "no keys issued" empty state.
            'clients' => ApiClient::where('tenant_id', $tenant->id)
                ->has('tokens')
                ->with(['creator:id,name', 'tokens'])
                ->orderBy('name')
                ->get(),
            'scopes' => ApiClient::SCOPES,
        ]);
    }

    /** Create a client and mint its one key, flashing the plaintext for a single render. */
    public function store(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in(array_keys(ApiClient::SCOPES))],
        ]);

        $client = ApiClient::create([
            'tenant_id' => $tenant->id,
            'name' => $data['name'],
            'created_by' => $request->user()?->id,
        ]);

        $plain = $client->mintKey(array_values($data['scopes']))->plainTextToken;

        AuditLog::create([
            'tenant_id' => $tenant->id,
            'user_id' => auth()->id(),
            'actor_name' => auth()->user()->name ?? 'Super Admin',
            'action' => 'Issued API key',
            'target' => $client->name.' ('.implode(', ', $data['scopes']).')',
        ]);

        return back()
            ->with('ok', $client->name.' can now read the API.')
            ->with('newKey', $plain)
            ->with('newKeyName', $client->name);
    }

    /**
     * Revoke one key. Route-model binding resolves a token across every tenant
     * (SubstituteBindings runs before any tenant is active), so the token's own
     * tenant_id is checked here rather than assumed from the URL.
     */
    public function revoke(Request $request, Tenant $tenant, PersonalAccessToken $token): RedirectResponse
    {
        abort_unless($token->tenant_id === $tenant->id, 404);

        $name = $token->name;
        $token->delete();

        AuditLog::create([
            'tenant_id' => $tenant->id,
            'user_id' => auth()->id(),
            'actor_name' => auth()->user()->name ?? 'Super Admin',
            'action' => 'Revoked API key',
            'target' => $name,
        ]);

        return back()->with('ok', $name.' can no longer read the API.');
    }
}
