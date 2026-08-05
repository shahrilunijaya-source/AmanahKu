<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->enum('category', ['IT', 'Facilities', 'HR', 'Other', 'Bug', 'Idea'])
                ->default('IT')->change();
            // Feedback allowed a report from someone with no employee record; tickets did not.
            // Relaxed so the Task 12 fold cannot hit a NOT NULL violation mid-deploy.
            $table->foreignId('employee_id')->nullable()->change();
            $table->string('page_url', 500)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('page_url');
            $table->foreignId('employee_id')->nullable(false)->change();
            $table->enum('category', ['IT', 'Facilities', 'HR', 'Other'])->default('IT')->change();
        });
    }
};
