<?php

declare(strict_types=1);

use App\Models\TotSlot;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CR-09 scope item 6: a TOT session used to be one title with one presenter. From here
     * on a session may hold several ordered slots (Pembentangan / Demonstrasi / Sambungan),
     * each with its own presenter team. The legacy `title`, `presenter_employee_id`,
     * `presenter_name`, `presenter_mode` columns and `tot_session_presenter` pivot on
     * tot_sessions stay exactly as they are — nothing here drops or renames them — so three
     * years of imported history stays readable.
     *
     * Every session that already has a title and no slot yet is backfilled with one slot
     * (position 1, kind pembentangan) carrying that title, description, presenter_mode and
     * presenter list, so a legacy single-topic session reads as a one-slot session from now
     * on instead of silently losing its slot list.
     */
    public function up(): void
    {
        Schema::create('tot_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('session_id')->constrained('tot_sessions')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('title', 200);
            $table->string('kind', 14); // pembentangan|demonstrasi|sambungan
            $table->string('format', 8)->nullable(); // slide|demo
            $table->string('status', 8)->nullable(); // rasmi|ujian
            $table->string('presenter_mode', 4)->default('solo'); // solo|team
            $table->text('summary')->nullable();
            $table->timestamps();

            $table->index(['session_id', 'position']);
        });

        // Same pivot shape as tot_session_presenter (2026_08_25_193404_create_tot_session_presenter_table),
        // one row per slot per presenter, plus a support flag for a team's sokongan member.
        Schema::create('tot_slot_presenter', function (Blueprint $table) {
            $table->foreignId('slot_id')->constrained('tot_slots')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->boolean('support')->default(false);
            $table->timestamps();
            $table->unique(['slot_id', 'employee_id']);
        });

        TotSlot::backfillLegacySessions();
    }

    public function down(): void
    {
        Schema::dropIfExists('tot_slot_presenter');
        Schema::dropIfExists('tot_slots');
    }
};
