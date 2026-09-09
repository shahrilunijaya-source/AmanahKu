<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // CR-31: once-a-day-per-user gate. A row here means the egg for
        // (employee, kind) was already shown on that date — see
        // App\Support\EasterEggBank::showOnce().
        Schema::create('easter_egg_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->date('shown_on');
            $table->timestamps();

            $table->unique(['employee_id', 'kind', 'shown_on']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('easter_egg_views');
    }
};
