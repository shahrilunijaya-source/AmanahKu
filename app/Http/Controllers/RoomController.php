<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\MeetingRoom;
use App\Models\RoomBooking;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RoomController extends Controller
{
    /** Only HR/management may manage rooms or cancel any booking. */
    private const PRIVILEGED_ROLES = ['management', 'hr'];

    /**
     * Everyone sees active rooms with their CONFIRMED bookings for the selected day
     * (defaults to today), plus their own upcoming confirmed bookings. Privileged roles
     * additionally receive a room-management flag + the full rooms list for the add form.
     * Tenant isolation is automatic via BelongsToTenant.
     *
     * @return array<string, mixed>
     */
    public function screenData(Request $request, ?Employee $employee): array
    {
        $privileged = $this->isPrivileged($request);

        // Selected day for the per-room schedule view; default to today.
        $selectedDate = $this->resolveDate($request->query('date'));

        $rooms = MeetingRoom::where('active', true)->orderBy('name')->get();

        // Confirmed bookings for every active room on the selected day, grouped per room.
        $dayBookings = RoomBooking::with('employee')
            ->where('status', 'confirmed')
            ->whereDate('date', $selectedDate)
            ->orderBy('start_time')
            ->get()
            ->groupBy('meeting_room_id');

        // The employee's own upcoming confirmed bookings (today onward).
        $myBookings = $employee
            ? RoomBooking::with('room')
                ->where('employee_id', $employee->id)
                ->where('status', 'confirmed')
                ->whereDate('date', '>=', now()->toDateString())
                ->orderBy('date')
                ->orderBy('start_time')
                ->get()
            : new Collection;

        return [
            'privileged' => $privileged,
            'canBook' => (bool) $employee,
            'rooms' => $rooms,
            'dayBookings' => $dayBookings,
            'myBookings' => $myBookings,
            'selectedDate' => $selectedDate,
        ];
    }

    /** Any employee may book an available room+slot. Overlaps are rejected gracefully. */
    public function store(Request $request): RedirectResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');

        $tenantId = app(CurrentTenant::class)->id();

        $data = $request->validate([
            'meeting_room_id' => ['required', 'integer', Rule::exists('meeting_rooms', 'id')->where('tenant_id', $tenantId)->where('active', true)],
            'date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'title' => ['required', 'string', 'max:160'],
        ]);

        // Conflict check + insert run in one locked transaction so two concurrent
        // bookings can't both pass the overlap check and double-book. Overlap iff
        // existing.start < new.end AND existing.end > new.start (half-open interval).
        $booking = DB::transaction(function () use ($data, $employee, $tenantId) {
            $conflict = RoomBooking::where('meeting_room_id', $data['meeting_room_id'])
                ->where('status', 'confirmed')
                ->whereDate('date', $data['date'])
                ->where('start_time', '<', $data['end_time'])
                ->where('end_time', '>', $data['start_time'])
                ->lockForUpdate()
                ->exists();

            if ($conflict) {
                return null;
            }

            return RoomBooking::create([
                'tenant_id' => $tenantId,
                'meeting_room_id' => $data['meeting_room_id'],
                'employee_id' => $employee->id,
                'date' => $data['date'],
                'start_time' => $data['start_time'],
                'end_time' => $data['end_time'],
                'title' => $data['title'],
                'status' => 'confirmed',
            ]);
        });

        if (! $booking) {
            return back()
                ->withInput()
                ->withErrors(['booking' => 'That room is already booked for an overlapping time. Pick another slot or room.']);
        }

        $roomName = MeetingRoom::find($data['meeting_room_id'])?->name ?? 'room';
        AuditLog::record('Booked meeting room', $roomName.' · '.Carbon::parse($data['date'])->format('D, j M').' · '.$data['start_time'].'–'.$data['end_time']);

        return back()->with('ok', 'Room booked: '.$booking->title.'.');
    }

    /** Owner OR privileged (management/hr) may cancel a confirmed booking. */
    public function cancel(Request $request, RoomBooking $booking): RedirectResponse
    {
        abort_unless($booking->tenant_id === app(CurrentTenant::class)->id(), 403);

        $employee = $request->attributes->get('employee');
        $owns = $employee && $booking->employee_id === $employee->id;
        abort_unless($owns || $this->isPrivileged($request), 403);
        abort_unless($booking->status !== 'cancelled', 422);

        $booking->update(['status' => 'cancelled']);
        AuditLog::record('Cancelled room booking', ($booking->room?->name ?? 'room').' · '.$booking->date->format('D, j M'));

        return back()->with('ok', 'Booking cancelled.');
    }

    /** Privileged-only (management/hr): add a bookable meeting room. */
    public function storeRoom(Request $request): RedirectResponse
    {
        $this->authorizePrivileged($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'location' => ['nullable', 'string', 'max:160'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $room = MeetingRoom::create([
            'tenant_id' => app(CurrentTenant::class)->id(),
            'name' => $data['name'],
            'location' => $data['location'] ?? null,
            'capacity' => $data['capacity'] ?? null,
            'active' => true,
        ]);

        AuditLog::record('Added meeting room', $room->name);

        return back()->with('ok', 'Room "'.$room->name.'" added.');
    }

    private function isPrivileged(Request $request): bool
    {
        return $this->hasTenantRole($request, self::PRIVILEGED_ROLES);
    }

    private function authorizePrivileged(Request $request): void
    {
        abort_unless($this->isPrivileged($request), 403, 'Only HR and management can manage rooms.');
    }

    /** Parse a YYYY-MM-DD query value, falling back to today on anything invalid. */
    private function resolveDate(?string $value): string
    {
        if (! $value) {
            return now()->toDateString();
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)->toDateString();
        } catch (\Throwable) {
            return now()->toDateString();
        }
    }
}
