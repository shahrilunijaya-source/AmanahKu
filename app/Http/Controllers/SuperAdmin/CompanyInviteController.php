<?php

declare(strict_types=1);

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\CompanyInvite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Signup links for new companies. A super-admin mints one, sends it however they like,
 * and the person in charge creates the company themselves (CompanySignupController).
 * Reachable only behind the super.admin guard.
 */
class CompanyInviteController extends Controller
{
    /** Mint a one-use, seven-day link and flash it so it can be copied once. */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:160'],
            'company_category_id' => ['required', 'exists:company_categories,id'],
        ]);

        $invite = CompanyInvite::create([
            'token' => Str::random(40),
            'note' => $data['note'] ?? null,
            'company_category_id' => $data['company_category_id'],
            'expires_at' => now()->addDays(7),
            'created_by_user_id' => $request->user()->id,
        ]);

        return redirect()->route('superadmin.companies.index')
            ->with('inviteUrl', $invite->url())
            ->with('inviteNote', $invite->note);
    }

    /** Revoke a link that has not been used. Used rows stay: they point at the company. */
    public function destroy(CompanyInvite $invite): RedirectResponse
    {
        abort_if($invite->used_at !== null, 403, 'A used link cannot be revoked.');

        $invite->delete();

        return redirect()->route('superadmin.companies.index')->with('ok', 'Signup link removed.');
    }
}
