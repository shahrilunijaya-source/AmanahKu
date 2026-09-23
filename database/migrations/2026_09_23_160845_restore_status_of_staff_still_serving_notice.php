<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Resigning used to mark staff 'resigned' on the spot, even with weeks of notice left.
     * Anyone still before their last working day gets back the status their timeline showed
     * just before the resignation (the latest earlier progression row's snapshot), or 'active'
     * when there is no such row. staff:archive-departed sets 'resigned' once that day passes.
     */
    public function up(): void
    {
        $serving = DB::table('employees')
            ->where('status', 'resigned')
            ->whereNull('archived_at')
            ->whereDate('last_working_day', '>=', now()->toDateString())
            ->get(['id']);

        foreach ($serving as $employee) {
            $resignedRowId = DB::table('employee_progressions')->where('employee_id', $employee->id)
                ->where('type', 'resigned')->max('id');
            $before = DB::table('employee_progressions')->where('employee_id', $employee->id)
                ->when($resignedRowId, fn ($q) => $q->where('id', '<', $resignedRowId))
                ->orderByDesc('id')->value('snapshot');
            $status = json_decode((string) $before, true)['status'] ?? null;

            DB::table('employees')->where('id', $employee->id)->update([
                'status' => in_array($status, ['active', 'probation', 'on_leave'], true) ? $status : 'active',
            ]);
        }
    }

    public function down(): void
    {
        // Nothing to undo: the old status was wrong, and the daily job restores 'resigned' on time.
    }
};
