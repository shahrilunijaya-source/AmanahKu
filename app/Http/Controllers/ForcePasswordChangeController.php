<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Fortify\PasswordValidationRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * First-sign-in password rotation for invited members (I-008). Reachable only while
 * the user's password_change_required flag is set; ForcePasswordChange middleware
 * funnels every other route here until the rotation completes.
 */
class ForcePasswordChangeController extends Controller
{
    use PasswordValidationRules;

    public function show()
    {
        // Already rotated (or never required) — nothing to do here.
        if (! Auth::user()->password_change_required) {
            return redirect('/tenant');
        }

        return view('auth.force-password-change');
    }

    public function update(Request $request)
    {
        $request->validate([
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => $this->passwordRules(),
        ], [
            'current_password.current_password' => 'The temporary password is incorrect.',
        ]);

        $user = Auth::user();

        $user->forceFill([
            'password' => Hash::make($request->input('password')),
            'password_change_required' => false,
        ])->save();

        return redirect('/tenant')->with('ok', 'Password updated. Welcome to Amanahku.');
    }
}
