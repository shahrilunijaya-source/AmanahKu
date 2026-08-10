<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Attendance\ClockService;
use App\Models\AttendanceAttempt;
use App\Models\AttendanceRecord;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
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

        // Caught only to leave a trace, then rethrown untouched so the normal redirect and
        // error bag still reach the screen. Without this, the single most likely cause of
        // "I cannot clock in on my phone" — a camera selfie larger than the 4MB rule, or
        // larger than whatever PHP's own upload limit is on the host — produced no record
        // anywhere: a validation failure is an ordinary 302, so it reaches neither the
        // fault table nor any log line.
        try {
            $validated = $this->validateClock($request);
        } catch (ValidationException $e) {
            AttendanceAttempt::record(
                $employee,
                is_string($action = $request->input('action')) ? $action : null,
                'invalid',
                collect($e->errors())->flatten()->first(),
                $request->filled('latitude') && $request->filled('longitude'),
                $request->file('photo'),
            );

            throw $e;
        }

        $lat = isset($validated['latitude']) ? (float) $validated['latitude'] : null;
        $lng = isset($validated['longitude']) ? (float) $validated['longitude'] : null;
        $justification = $validated['justification'] ?? null;
        $now = Carbon::now();

        // Selfie — captured on either clock-in (arrival proof) or clock-out
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

        // A refused punch never reaches a record, so the selfie it carried would sit on the
        // private disk with nothing pointing at it — forever, and at up to 4MB a go. Drop
        // the file with the punch that was rejected.
        if ($result['status'] !== 'ok' && $photoPath !== null) {
            Storage::disk(self::PHOTO_DISK)->delete($photoPath);
        }

        // Every outcome, not only the refusals: a run of successful desktop punches next to
        // a run of refused phone punches is the comparison that names the cause, and it only
        // exists if the successes are recorded too. Two rows per employee per day.
        AttendanceAttempt::record(
            $employee,
            $validated['action'],
            $result['status'],
            $result['message'],
            $lat !== null && $lng !== null,
            $request->file('photo'),
        );

        // Understood and declined — a second clock-in on a day already punched. Not a
        // success: a green tick here reads as "punched again", which is the one thing it
        // did not do, and staff then believe they clocked twice.
        if ($result['status'] === 'noop') {
            return back()->with('info', $result['message']);
        }

        // Backstop: server insists on a reason for an out-of-radius / early clock event.
        // The screen re-opens the justification field from this flash + error bag.
        if ($result['status'] === 'needs_justification') {
            return back()
                ->withInput()
                ->with('attendance_justify', $validated['action'])
                ->withErrors(['justification' => $result['message']]);
        }

        // Same backstop for the mandatory off-site selfie. A file input cannot be refilled
        // from old input, so the screen asks for a fresh capture.
        if ($result['status'] === 'needs_photo') {
            return back()
                ->withInput()
                ->withErrors(['photo' => $result['message']]);
        }

        return back()->with('clock_ok', $result['message']);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validateClock(Request $request): array
    {
        return $request->validate([
            'action' => ['required', 'in:in,out'],
            // GPS is expected but not mandatory. Devices that cannot produce a fix at all
            // (a desk machine with no GPS chip and no usable network lookup) would otherwise
            // be locked out of clocking for good. ClockService charges a coordinate-less
            // punch a reason, a selfie and a permanent no_location flag instead, which keeps
            // the day on the record rather than pushing it into a hand-keyed entry that
            // carries no evidence at all.
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'photo' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:4096'],
            'justification' => ['nullable', 'string', 'max:500'],
        ]);
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
