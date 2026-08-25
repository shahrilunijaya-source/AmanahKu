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
        // The mode cannot be derived from the presenter count after all: a team nobody has
        // been picked for yet and a solo slot nobody has been picked for yet both hold zero
        // presenters, and the board has to say "Team" for the first one. So it is stored.
        Schema::table('tot_sessions', function (Blueprint $table) {
            $table->string('presenter_mode', 8)->default('solo')->after('presenter_name');
        });

        // Anything already carrying more than one presenter is a team by definition.
        DB::table('tot_sessions')
            ->whereIn('id', DB::table('tot_session_presenter')
                ->select('session_id')
                ->groupBy('session_id')
                ->havingRaw('count(*) > 1'))
            ->update(['presenter_mode' => 'team']);
    }

    public function down(): void
    {
        Schema::table('tot_sessions', function (Blueprint $table) {
            $table->dropColumn('presenter_mode');
        });
    }
};
