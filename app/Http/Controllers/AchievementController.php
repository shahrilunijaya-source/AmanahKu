<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Achievement;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AchievementController extends Controller
{
    /** Managers and above may record recognition for a team member. */
    private const PRIVILEGED_ROLES = ['manager', 'management', 'hr'];

    public function store(Request $request): RedirectResponse
    {
        abort_unless(
            $this->hasTenantRole($request, self::PRIVILEGED_ROLES),
            403,
            'Only managers and HR can record recognition.'
        );

        $tenantId = app(CurrentTenant::class)->id();

        $data = $request->validate([
            // Tenant-scoped existence: a recipient from another workspace fails validation.
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->where('tenant_id', $tenantId)],
            'title' => ['required', 'string', 'max:160'],
            'category' => ['required', 'in:Recognition,Milestone,Award,Spot Award'],
            'points' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ]);

        // Defense in depth behind the tenant-scoped validation rule above.
        $employee = Employee::find($data['employee_id']);
        abort_unless($employee && $employee->tenant_id === $tenantId, 403);

        Achievement::create([
            'tenant_id' => $tenantId,
            'employee_id' => $employee->id,
            'who' => $employee->name,
            'title' => $data['title'],
            'category' => $data['category'],
            'icon' => $this->iconFor($data['category']),
            'points' => $data['points'] ?? 0,
            'date' => now()->toDateString(),
        ]);

        AuditLog::record('Recorded recognition', $employee->name.' — '.$data['title']);
        AppNotification::send(
            $employee->user_id,
            'You were recognised',
            $data['category'].' · '.$data['title'],
            route('app.screen', 'achievements'),
        );

        return back()->with('ok', 'Recognition recorded for '.$employee->name.'.');
    }

    private function iconFor(string $category): string
    {
        return [
            'Recognition' => 'star',
            'Milestone' => 'medal',
            'Award' => 'trophy',
            'Spot Award' => 'zap',
        ][$category] ?? 'star';
    }
}
