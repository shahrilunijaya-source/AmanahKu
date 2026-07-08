<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Vehicle;
use App\Models\VehicleBooking;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class VehicleController extends Controller
{
    /** Only HR/management may manage the vehicle fleet or cancel any booking. */
    private const PRIVILEGED_ROLES = ['management', 'hr'];

    /**
     * Everyone sees active vehicles with their upcoming CONFIRMED bookings, their own
     * upcoming bookings, and the full upcoming booking list. Privileged roles additionally
     * receive a fleet-management flag for the add-vehicle form. Tenant isolation is
     * automatic via BelongsToTenant.
     *
     * @return array<string, mixed>
     */
    public function screenData(Request $request, ?Employee $employee): array
    {
        $privileged = $this->isPrivileged($request);

        $vehicles = Vehicle::where('is_active', true)->orderBy('name')->get();

        // Upcoming confirmed bookings for every active vehicle, grouped per vehicle.
        $upcoming = VehicleBooking::with(['employee', 'vehicle'])
            ->where('status', 'confirmed')
            ->where('ends_at', '>=', now())
            ->orderBy('starts_at')
            ->get();

        $vehicleBookings = $upcoming->groupBy('vehicle_id');

        // The employee's own upcoming confirmed bookings.
        $myBookings = $employee
            ? $upcoming->where('employee_id', $employee->id)->values()
            : new Collection();

        return [
            'privileged' => $privileged,
            'canBook' => (bool) $employee,
            'vehicles' => $vehicles,
            'vehicleBookings' => $vehicleBookings,
            'myBookings' => $myBookings,
            'allUpcoming' => $upcoming,
        ];
    }

    /** Any employee may book an available vehicle for a window. Overlaps are rejected gracefully. */
    public function store(Request $request): RedirectResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');

        $tenantId = app(CurrentTenant::class)->id();

        $data = $request->validate([
            'vehicle_id' => ['required', 'integer', Rule::exists('vehicles', 'id')->where('tenant_id', $tenantId)->where('is_active', true)],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'purpose' => ['required', 'string', 'max:160'],
            'destination' => ['nullable', 'string', 'max:160'],
            'odometer_out' => ['nullable', 'integer', 'min:0'],
        ]);

        // Normalise to a canonical datetime string so the boundary comparison is
        // correct on both MySQL and SQLite (SQLite compares datetimes as strings,
        // so '12:00:00' vs an unpadded '12:00' would wrongly flag a back-to-back slot).
        $startsAt = Carbon::parse($data['starts_at'])->format('Y-m-d H:i:s');
        $endsAt = Carbon::parse($data['ends_at'])->format('Y-m-d H:i:s');

        // Conflict check + insert run in one locked transaction so two concurrent
        // bookings can't both pass the overlap check and double-book the vehicle.
        // Overlap iff existing.start < new.end AND existing.end > new.start (half-open).
        $booking = DB::transaction(function () use ($data, $employee, $tenantId, $startsAt, $endsAt) {
            $conflict = VehicleBooking::where('vehicle_id', $data['vehicle_id'])
                ->where('status', 'confirmed')
                ->where('starts_at', '<', $endsAt)
                ->where('ends_at', '>', $startsAt)
                ->lockForUpdate()
                ->exists();

            if ($conflict) {
                return null;
            }

            return VehicleBooking::create([
                'tenant_id' => $tenantId,
                'vehicle_id' => $data['vehicle_id'],
                'employee_id' => $employee->id,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'purpose' => $data['purpose'],
                'destination' => $data['destination'] ?? null,
                'odometer_out' => $data['odometer_out'] ?? null,
                'status' => 'confirmed',
            ]);
        });

        if (! $booking) {
            return back()
                ->withInput()
                ->withErrors(['booking' => 'That vehicle is already booked for an overlapping time. Pick another window or vehicle.']);
        }

        $vehicleName = Vehicle::find($data['vehicle_id'])?->name ?? 'vehicle';
        AuditLog::record('Booked vehicle', $vehicleName.' · '.Carbon::parse($data['starts_at'])->format('D, j M g:ia').' – '.Carbon::parse($data['ends_at'])->format('g:ia'));

        return back()->with('ok', 'Vehicle booked: '.$booking->purpose.'.');
    }

    /** Owner OR privileged (management/hr) may cancel a confirmed booking. */
    public function cancel(Request $request, VehicleBooking $booking): RedirectResponse
    {
        abort_unless($booking->tenant_id === app(CurrentTenant::class)->id(), 403);

        $employee = $request->attributes->get('employee');
        $owns = $employee && $booking->employee_id === $employee->id;
        abort_unless($owns || $this->isPrivileged($request), 403);
        abort_unless($booking->status !== 'cancelled', 422);

        $booking->update(['status' => 'cancelled']);
        AuditLog::record('Cancelled vehicle booking', ($booking->vehicle?->name ?? 'vehicle').' · '.$booking->starts_at->format('D, j M'));

        return back()->with('ok', 'Booking cancelled.');
    }

    /** Privileged-only (management/hr): add a bookable vehicle to the fleet. */
    public function storeVehicle(Request $request): RedirectResponse
    {
        $this->authorizePrivileged($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'registration_no' => ['required', 'string', 'max:40'],
            'type' => ['required', Rule::in(['car', 'van', 'truck', 'motorcycle'])],
            'seats' => ['nullable', 'integer', 'min:1', 'max:120'],
        ]);

        $vehicle = Vehicle::create([
            'tenant_id' => app(CurrentTenant::class)->id(),
            'name' => $data['name'],
            'registration_no' => $data['registration_no'],
            'type' => $data['type'],
            'seats' => $data['seats'] ?? null,
            'is_active' => true,
        ]);

        AuditLog::record('Added vehicle', $vehicle->name.' ('.$vehicle->registration_no.')');

        return back()->with('ok', 'Vehicle "'.$vehicle->name.'" added.');
    }

    private function isPrivileged(Request $request): bool
    {
        return in_array($request->attributes->get('tenantRole', 'employee'), self::PRIVILEGED_ROLES, true);
    }

    private function authorizePrivileged(Request $request): void
    {
        abort_unless($this->isPrivileged($request), 403, 'Only HR and management can manage the vehicle fleet.');
    }
}
