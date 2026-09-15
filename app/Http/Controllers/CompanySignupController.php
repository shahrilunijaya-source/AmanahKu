<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CompanyInvite;
use App\Models\User;
use App\Services\CompanyProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View as ViewContract;

/**
 * Invite-link company signup. Replaces Fortify's registration: there is no way to
 * create an account here without a live CompanyInvite token, and the person who
 * signs up becomes the HR admin of the company they just named.
 */
class CompanySignupController extends Controller
{
    /** The signup form, or the reason the link no longer works. */
    public function show(Request $request): ViewContract|Response
    {
        $invite = $this->inviteOr404((string) $request->query('invite', ''));

        if ($request->user()?->isSuperAdmin()) {
            return response()->view('auth.register', ['invite' => $invite, 'state' => 'superadmin'], 403);
        }

        if (! $invite->isUsable()) {
            return response()->view('auth.register', ['invite' => $invite, 'state' => $invite->status()], 404);
        }

        // Someone whose email already has an account is told to sign in; bring them
        // straight back to this link afterwards (Fortify's LoginResponse honours intended).
        $request->session()->put('url.intended', $request->fullUrl());

        return view('auth.register', ['invite' => $invite, 'state' => 'form']);
    }

    /**
     * Create the company, its HQ / General structure and its HR admin in one
     * transaction, spend the invite, and drop the new admin into Launch Center.
     * A signed-in member is attached as-is; a guest gets a new account with the
     * password they chose (no forced rotation, no verification mail: the link was
     * the verification).
     */
    public function store(Request $request): RedirectResponse
    {
        $invite = $this->inviteOr404((string) $request->input('invite', ''));

        abort_if($request->user()?->isSuperAdmin(), 403, 'You already see every company.');

        $member = $request->user();

        $rules = ['company_name' => ['required', 'string', 'max:120']];
        if ($member === null) {
            $rules += [
                'name' => ['required', 'string', 'max:120'],
                'email' => ['required', 'email', 'max:160', Rule::unique('users', 'email')],
                'password' => ['required', 'string', Password::default(), 'confirmed'],
            ];
        }

        $data = $request->validate($rules, [
            'email.unique' => 'That email already has an account. Sign in first, then open this link again.',
        ]);

        $tenant = DB::transaction(function () use ($invite, $member, $data) {
            // Two people (or one double-click) racing on the same link: the row lock
            // serialises them and the second one sees used_at set.
            $locked = CompanyInvite::whereKey($invite->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isUsable()) {
                throw ValidationException::withMessages([
                    'invite' => $locked->status() === 'expired' ? 'This link has expired.' : 'This link has already been used.',
                ]);
            }

            $tenant = app(CompanyProvisioner::class)->provision(
                company: ['name' => $data['company_name']],
                category: $locked->category,
                structure: ['branch_name' => 'HQ', 'department_name' => 'General'],
                admin: $member ?? [
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'password' => $data['password'],
                    'password_change_required' => false,
                    'email_verified_at' => now(),
                ],
                auditAction: 'Company self-registered',
            );

            $locked->forceFill(['used_at' => now(), 'used_by_tenant_id' => $tenant->id])->save();

            return $tenant;
        });

        $member ??= User::where('email', $data['email'])->firstOrFail();

        Auth::login($member);
        $request->session()->regenerate();
        $request->session()->forget('url.intended');
        // Same two keys AppController::enterTenant sets, so the shell opens on this company.
        $request->session()->put(['current_tenant' => $tenant->id, 'persona' => 'hr']);

        return redirect()->route('app.screen', 'setup');
    }

    private function inviteOr404(string $token): CompanyInvite
    {
        abort_if($token === '', 404);

        $invite = CompanyInvite::forToken($token)->with('category')->first();
        abort_if($invite === null, 404);

        return $invite;
    }
}
