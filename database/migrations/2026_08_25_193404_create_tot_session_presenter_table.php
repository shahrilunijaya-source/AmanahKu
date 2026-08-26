<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A TOT slot can be presented by a team, not only one person. This pivot is the
        // canonical presenter list from here on; a solo slot is simply a slot with one row.
        // There is no "mode" column because the mode is derivable from the count — storing
        // it would let the two disagree.
        //
        // tot_sessions.presenter_employee_id is kept and backfilled from, not dropped: it
        // still carries three years of imported history, and TotSession::presenter() reads
        // it as the legacy fallback alongside presenter_name.
        Schema::create('tot_session_presenter', function (Blueprint $table) {
            $table->foreignId('session_id')->constrained('tot_sessions')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['session_id', 'employee_id']);
        });

        DB::table('tot_sessions')
            ->whereNotNull('presenter_employee_id')
            ->orderBy('id')
            ->chunkById(200, function ($sessions) {
                DB::table('tot_session_presenter')->insertOrIgnore(
                    $sessions->map(fn ($session) => [
                        'session_id' => $session->id,
                        'employee_id' => $session->presenter_employee_id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ])->all()
                );
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('tot_session_presenter');
    }
};
