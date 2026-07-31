<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View as ViewContract;

/**
 * Account activation via a signed link (the alternative to the one-time-password
 * flow). A freshly-invited member clicks the link in their invite email and sets
 * their own password — no temporary credential to relay. The link is a signed,
 * expiring URL; activation only works while the account still requires a password
 * change, so the link cannot be reused once the account is active.
 */
class ActivationController extends Controller
{
    public function show(Request $request, User $user): ViewContract|RedirectResponse
    {
        // Already activated → nothing to do, send them to sign in.
        if (! $user->password_change_required) {
            return redirect('/login')->with('ok', 'This account is already active — please sign in.');
        }

        return view('auth.activate', ['user' => $user]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->password_change_required, 410, 'This activation link has already been used.');

        $data = $request->validate([
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user->forceFill([
            'password' => Hash::make($data['password']),
            'password_change_required' => false,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        Auth::login($user);

        return redirect()->route('tenant.select')->with('ok', 'Your account is now active.');
    }
}
