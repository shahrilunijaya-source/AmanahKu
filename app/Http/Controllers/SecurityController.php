<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;

class SecurityController extends Controller
{
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
}
