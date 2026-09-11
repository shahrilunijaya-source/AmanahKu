<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ManagementMeetingSettings;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * CR-34 scope 6: reminder times, recipients and meeting day, editable by Director/Admin
 * only. One settings row per tenant, defaults from ManagementMeetingSettings::forTenant()
 * when none exists yet. Every change is audited by the model's own AuditsChanges trait.
 */
class ManagementMeetingController extends Controller
{
    /** @return array<string, mixed> */
    public function screenData(Request $request): array
    {
        $this->authorizeTenantRole($request, Permissions::FINAL_APPROVAL_ROLES);

        return ['meetingSettings' => ManagementMeetingSettings::forTenant()];
    }

    public function update(Request $request): RedirectResponse|JsonResponse
    {
        $this->authorizeTenantRole($request, Permissions::FINAL_APPROVAL_ROLES);

        $data = $request->validate([
            'meeting_day' => ['sometimes', 'integer', 'between:1,7'],
            'meeting_time' => ['sometimes', 'date_format:H:i'],
            'reminder_time' => ['sometimes', 'date_format:H:i'],
            'task_time' => ['sometimes', 'date_format:H:i'],
            'attendee_roles' => ['sometimes', 'array'],
            'attendee_roles.*' => ['string', 'in:employee,manager,hr,management,director'],
            'paused_until' => ['sometimes', 'nullable', 'date'],
        ]);

        $settings = ManagementMeetingSettings::forTenant();
        $settings->fill($data);
        $settings->save();

        return $request->expectsJson()
            ? response()->json(['ok' => true])
            : back()->with('ok', 'Management meeting settings saved.');
    }
}
