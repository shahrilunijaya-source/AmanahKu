<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Attendance\ClockService;
use App\Models\AttendanceRecord;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttendanceController extends Controller
{
    /** Managers/HR may view a team member's clock selfie; anyone else only their own. */
    private const PRIVILEGED_ROLES = ['manager', 'management', 'hr'];

    /** Clock-in/out selfies live on the private disk — never a public URL (AK-SEC-05). */
    private const PHOTO_DISK = 'local';

    public function __construct(private ClockService $clock) {}

    /** Clock in or out for today. Geofence + punctuality rules live in ClockService. */
    public function clock(Request $request): RedirectResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');

        $validated = $request->validate([
            'action' => ['required', 'in:in,out'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'photo' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:4096'],
            'justification' => ['nullable', 'string', 'max:500'],
        ]);

        $lat = isset($validated['latitude']) ? (float) $validated['latitude'] : null;
        $lng = isset($validated['longitude']) ? (float) $validated['longitude'] : null;
        $justification = $validated['justification'] ?? null;
        $now = Carbon::now();

        // Optional selfie — captured on either clock-in (arrival proof) or clock-out
        // (departure proof). Stored on the PRIVATE disk (with GPS + timestamp, this is
        // sensitive biometric/location data) and served only through the auth-gated
        // photo() action below — never a public, permanent URL.
        $photoPath = $request->hasFile('photo')
            ? $request->file('photo')->store('attendance-photos', self::PHOTO_DISK)
            : null;

        if ($validated['action'] === 'in') {
            $result = $this->clock->clockIn($employee, $lat, $lng, $justification, $photoPath, $now);
        } else {
            $result = $this->clock->clockOut($employee, $lat, $lng, $justification, $photoPath, $now);
        }

        // Backstop: server insists on a reason for an out-of-radius / early clock event.
        // The screen re-opens the justification field from this flash + error bag.
        if ($result['status'] === 'needs_justification') {
            return back()
                ->withInput()
                ->with('attendance_justify', $validated['action'])
                ->withErrors(['justification' => $result['message']]);
        }

        return back()->with('ok', $result['message']);
    }

    /**
     * Stream a stored clock selfie through an auth-gated action (never a public URL).
     * `$slot` selects the clock-in ('in') or clock-out ('out') photo. Access: the record
     * owner, or a manager/HR in the same workspace. Tenant is asserted explicitly because
     * route-model binding runs before the tenant scope is active (see AK-SEC-04).
     */
    public function photo(Request $request, AttendanceRecord $record, string $slot): StreamedResponse
    {
        abort_unless(in_array($slot, ['in', 'out'], true), 404);
        abort_unless($record->tenant_id === app(CurrentTenant::class)->id(), 403);

        $employee = $request->attributes->get('employee');
        $isOwner = $employee && $record->employee_id === $employee->id;
        if (! $isOwner) {
            $this->authorizeTenantRole($request, self::PRIVILEGED_ROLES);
        }

        $path = $slot === 'in' ? $record->photo_path : $record->clock_out_photo_path;
        abort_unless($path && Storage::disk(self::PHOTO_DISK)->exists($path), 404);

        return Storage::disk(self::PHOTO_DISK)->download($path);
    }
}
