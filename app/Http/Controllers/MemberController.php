<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\MemberInvited;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class MemberController extends Controller
{
    /** Adding workspace members (login accounts) is an HR / management responsibility. */
    private const PRIVILEGED_ROLES = ['management', 'hr'];

    /**
     * Add a member to the active workspace. If the email already belongs to a user
     * (multi-tenant: one login can span tenants) they are attached to this tenant;
     * otherwise a new login is created with a one-time temporary password.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorizeTenantRole($request, self::PRIVILEGED_ROLES);
        $tenant = app(CurrentTenant::class)->get();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:160'],
            // Invites create up to a manager. Elevating to management/HR is a separate,
            // audited step via Roles & Permissions — so a single privileged account
            // cannot silently manufacture more top-level admins.
            'role' => ['required', 'in:employee,manager'],
            'position' => ['nullable', 'string', 'max:120'],
        ]);

        // New accounts only. Attaching an *existing* login to this tenant without the
        // owner's consent would be a cross-tenant takeover vector, so it is refused here;
        // a genuine multi-workspace user must be provisioned by a system administrator.
        if (User::where('email', $data['email'])->exists()) {
            return back()->withInput()->withErrors([
                'email' => 'A user with this email already exists. Existing accounts cannot be added from here.',
            ]);
        }

        $tempPassword = Str::password(12);

        // Login + tenant link + directory record are one unit: a crash partway must
        // not leave an orphan login with no Employee row (it would block re-invite and
        // the un-linked account could never reach the app). The users.email unique
        // index backstops the exists() pre-check above against a concurrent invite.
        try {
            $user = DB::transaction(function () use ($data, $tenant, $tempPassword) {
                $user = User::create([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'password' => Hash::make($tempPassword),
                ]);
                // The one-time password must be rotated on first sign-in before the
                // member can reach any application route (I-008). Not in $fillable,
                // so force the flag on.
                $user->forceFill(['password_change_required' => true])->save();
                $user->tenants()->attach($tenant->id, ['role' => $data['role']]);

                Employee::create([
                    'tenant_id' => $tenant->id,
                    'user_id' => $user->id,
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'position' => $data['position'] ?? null,
                    'status' => 'active',
                    'workload' => 'green',
                    'workload_label' => 'Healthy',
                    'initials' => $this->initials($data['name']),
                    'avatar_color' => config('amanahku.avatar_color'),
                    'joined_at' => now()->toDateString(),
                ]);

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            return back()->withInput()->withErrors([
                'email' => 'A user with this email already exists. Existing accounts cannot be added from here.',
            ]);
        }

        // Email the one-time credentials so HR never has to relay them by hand.
        // After the commit — a rolled-back invite must never send a working password.
        $user->notify(new MemberInvited($tenant, $tempPassword, $data['role']));

        AuditLog::record('Added member', $data['name'].' ('.$data['role'].')');

        // Never echo the one-time password into the flash (renders in HTML, cached, logged).
        // The signed activation link + credential go out only in the invite email (AK-SEC-10).
        return back()->with('ok', $data['name'].' added and emailed an invite to activate their account and set a password.');
    }

    /**
     * Give every active directory record that has an email but no login a sign-in
     * account in one shot — for staff added via the directory or bulk CSV import,
     * which create directory records only. Each gets email-as-username + its own
     * random one-time password (never a shared default), emailed as an activation
     * invite, linked to the EXISTING Employee row (no duplicate), and must reset the
     * password on first sign-in. Emails already tied to an account are skipped (a
     * cross-tenant takeover guard).
     */
    public function provisionLogins(Request $request): RedirectResponse
    {
        $this->authorizeTenantRole($request, self::PRIVILEGED_ROLES);
        $tenant = app(CurrentTenant::class)->get();

        $candidates = Employee::active()->whereNull('user_id')->whereNotNull('email')->get();

        $created = 0;
        $skipped = 0;
        foreach ($candidates as $employee) {
            $this->provisionFor($employee, $tenant) === 'created' ? $created++ : $skipped++;
        }

        AuditLog::record('Provisioned staff logins', $created.' created');

        // Never echo a credential in the flash: each account gets its own random
        // password, emailed as an activation invite — nothing to display here.
        $msg = "$created login(s) created — each staff member has been emailed an invite to activate their account and set a password.";
        if ($skipped > 0) {
            $msg .= " $skipped skipped (an account already exists for that email).";
        }

        return back()->with($created > 0 ? 'ok' : 'error', $created > 0 ? $msg : 'No logins created. '.$msg);
    }

    /** Give a single directory record a login (per-row action from the profile). */
    public function createLogin(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorizeTenantRole($request, self::PRIVILEGED_ROLES);
        $tenant = app(CurrentTenant::class)->get();
        abort_unless($employee->tenant_id === $tenant->id, 403);

        $result = $this->provisionFor($employee, $tenant);

        if ($result === 'created') {
            AuditLog::record('Created login', $employee->name);

            return back()->with('ok', $employee->name.' has been emailed an invite to activate their account and set a password.');
        }

        return back()->with('error', match ($result) {
            'has_login' => $employee->name.' already has a login.',
            'no_email' => $employee->name.' has no email — add one first.',
            default => 'An account already exists for '.$employee->email.'.',
        });
    }

    /**
     * Reset a member's password (HR / management self-service, per-row from the
     * profile). Generates a fresh one-time password, forces a rotation on next
     * sign-in (password_change_required), and hands the credential back to the acting
     * HR user ONCE via a flash so they can relay it in person — the deliberate
     * exception to the never-echo-a-credential rule (AK-SEC-10), for the case where
     * an employee has forgotten their password and email is unreliable. The plaintext
     * is never written to the audit log. Existing two-factor enrolment is left intact,
     * so a reset alone cannot bypass 2FA.
     */
    public function resetPassword(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorizeTenantRole($request, self::PRIVILEGED_ROLES);
        $tenant = app(CurrentTenant::class)->get();
        abort_unless($employee->tenant_id === $tenant->id, 403);

        // Directory records without a login have nothing to reset — send HR to
        // "Create login" instead of silently doing nothing.
        if (! $employee->user_id) {
            return back()->with('error', $employee->name.' has no login yet — create one first.');
        }

        $user = User::find($employee->user_id);
        // The account row is gone (or somehow detached from this tenant) — refuse
        // rather than mint a credential for someone outside the workspace.
        abort_unless($user && $user->tenants->contains('id', $tenant->id), 403);
        // A tenant HR admin must not be able to reset a platform super-admin's global
        // login — that account operates above any single company.
        abort_if($user->isSuperAdmin(), 403);

        $tempPassword = Str::password(12);
        $user->forceFill([
            'password' => Hash::make($tempPassword),
            'password_change_required' => true,
        ])->save();

        // Record the action, never the credential.
        AuditLog::record('Reset password', $employee->name);

        // One-time display to the acting HR user. Flash (not the DB) so it survives a
        // single redirect and then evaporates.
        return back()->with('reset_password', [
            'name' => $employee->name,
            'password' => $tempPassword,
        ]);
    }

    /**
     * Create + link a login for one directory record using email-as-username and its
     * own random one-time password, emailed as an activation invite. Returns a status
     * string; never creates a duplicate Employee, and refuses an email that already
     * has an account.
     *
     * @return 'created'|'has_login'|'no_email'|'email_taken'
     */
    private function provisionFor(Employee $employee, Tenant $tenant, string $role = 'employee'): string
    {
        if ($employee->user_id) {
            return 'has_login';
        }
        if (! $employee->email) {
            return 'no_email';
        }
        if (User::where('email', $employee->email)->exists()) {
            return 'email_taken';
        }

        // Per-user one-time password — no shared default that a bystander could guess
        // for an unactivated account. Rotated on first sign-in (password_change_required).
        $tempPassword = Str::password(12);

        // The pre-check above is a fast path; the create-and-link runs in a transaction
        // and the users.email unique index is the real race backstop — two concurrent
        // provisions for the same email surface a clean 'email_taken', not a 500.
        try {
            $user = DB::transaction(function () use ($employee, $tenant, $role, $tempPassword) {
                $user = User::create([
                    'name' => $employee->name,
                    'email' => $employee->email,
                    'password' => Hash::make($tempPassword),
                ]);
                $user->forceFill(['password_change_required' => true])->save();
                $user->tenants()->attach($tenant->id, ['role' => $role]);
                $employee->update(['user_id' => $user->id]);

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            return 'email_taken';
        }

        // Email the activation link + one-time password after the commit — a rolled-back
        // provision must never send a working credential.
        $user->notify(new MemberInvited($tenant, $tempPassword, $role));

        return 'created';
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = mb_substr($parts[0] ?? '', 0, 1);
        $last = count($parts) > 1 ? mb_substr((string) end($parts), 0, 1) : '';

        return mb_strtoupper($first.$last) ?: 'NA';
    }
}
