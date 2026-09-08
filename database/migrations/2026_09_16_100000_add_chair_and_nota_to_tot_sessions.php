<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CR-09: the session gains a chair (Pengerusi), a dedicated Nota Perbincangan link and
     * a next-month agenda field, separate from the legacy free-form `links` json which still
     * carries its own moderator-labelled row (TotSession::MODERATOR_LINK_LABEL) for material
     * uploaded before this session. The two are not merged: nota_url is what the year screen
     * now shows under "Nota Perbincangan", the old links row is untouched history.
     */
    public function up(): void
    {
        Schema::table('tot_sessions', function (Blueprint $table) {
            $table->foreignId('chair_employee_id')->nullable()->after('presenter_mode')->constrained('employees')->nullOnDelete();
            $table->string('nota_url', 500)->nullable()->after('chair_employee_id');
            $table->text('next_agenda')->nullable()->after('nota_url');
        });
    }

    public function down(): void
    {
        Schema::table('tot_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('chair_employee_id');
            $table->dropColumn(['nota_url', 'next_agenda']);
        });
    }
};
