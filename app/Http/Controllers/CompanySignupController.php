<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CompanyInvite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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

    /** Filled in by the next task. */
    public function store(Request $request): RedirectResponse
    {
        abort(501);
    }

    private function inviteOr404(string $token): CompanyInvite
    {
        abort_if($token === '', 404);

        $invite = CompanyInvite::forToken($token)->with('category')->first();
        abort_if($invite === null, 404);

        return $invite;
    }
}
