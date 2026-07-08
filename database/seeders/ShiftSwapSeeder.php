<?php

namespace Database\Seeders;

use App\Models\Shift;
use App\Models\ShiftSwap;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class ShiftSwapSeeder extends Seeder
{
    /**
     * Seed 2-3 shift swap requests across statuses for the Unijaya tenant, referencing
     * real seeded shifts. Safe to re-run: skips entirely if the tenant already has swaps,
     * and bails if the tenant is missing or has no shifts (Roster owns shift creation).
     */
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'unijaya')->first();
        if (! $tenant) {
            return;
        }

        $tid = $tenant->id;

        // Idempotent-ish: global scope is inactive in seeders, so scope to the tenant.
        if (ShiftSwap::where('tenant_id', $tid)->exists()) {
            return;
        }

        // Read existing seeded shifts — do NOT create shifts (Roster owns those).
        $shifts = Shift::where('tenant_id', $tid)
            ->where('status', '!=', 'cancelled')
            ->orderBy('id')->take(3)->get();
        if ($shifts->count() < 1) {
            return;
        }

        // A requested giveaway (no counterpart yet) awaiting approval.
        $first = $shifts->get(0);
        ShiftSwap::create([
            'tenant_id' => $tid,
            'shift_id' => $first->id,
            'requester_employee_id' => $first->employee_id,
            'counterpart_employee_id' => null,
            'reason' => 'Family commitment — happy for anyone to take this shift',
            'status' => 'requested',
        ]);

        // An accepted swap (named counterpart already agreed) awaiting approval.
        $second = $shifts->get(1);
        if ($second) {
            // Pick a counterpart that is a different employee than the requester.
            $counterpart = $shifts->first(fn (Shift $s) => $s->employee_id !== $second->employee_id);

            ShiftSwap::create([
                'tenant_id' => $tid,
                'shift_id' => $second->id,
                'requester_employee_id' => $second->employee_id,
                'counterpart_employee_id' => $counterpart?->employee_id,
                'reason' => 'Medical appointment that morning',
                'status' => $counterpart ? 'accepted' : 'requested',
            ]);
        }

        // A previously rejected request, for history.
        $third = $shifts->get(2);
        if ($third) {
            ShiftSwap::create([
                'tenant_id' => $tid,
                'shift_id' => $third->id,
                'requester_employee_id' => $third->employee_id,
                'counterpart_employee_id' => null,
                'reason' => 'Wanted the day off — not approved',
                'status' => 'rejected',
                'decided_at' => now(),
                'decided_by_id' => $third->employee_id,
            ]);
        }
    }
}
