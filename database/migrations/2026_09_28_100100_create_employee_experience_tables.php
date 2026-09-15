<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Worksy Experience tab: previous employment, education, certificates, awards, languages. */
    public function up(): void
    {
        $base = function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
        };
        $attachment = fn (Blueprint $table) => $table->foreignId('document_id')->nullable()->constrained('employee_documents')->nullOnDelete();

        Schema::create('employee_work_histories', function (Blueprint $table) use ($base) {
            $base($table);
            $table->string('company', 160);
            $table->string('address', 255)->nullable();
            $table->date('joined_on')->nullable();
            $table->string('joined_as', 120)->nullable();
            $table->date('resigned_on')->nullable();
            $table->string('position_held', 120)->nullable();
            $table->decimal('last_drawn_salary', 12, 2)->nullable();
            $table->string('salary_type', 10)->nullable(); // monthly, weekly, daily
            $table->string('industry', 120)->nullable();
            $table->string('reason_to_leave', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('employee_educations', function (Blueprint $table) use ($base, $attachment) {
            $base($table);
            $table->string('qualification_type', 16); // high_school, vocational, associate, bachelor, master, doctorate
            $table->string('major', 160)->nullable();
            $table->string('institute', 160)->nullable();
            $table->unsignedSmallInteger('from_year')->nullable();
            $table->unsignedSmallInteger('to_year')->nullable();
            $table->string('honours', 8)->nullable(); // first, second, third, none
            $table->decimal('cgpa', 3, 2)->nullable();
            $table->string('remark', 500)->nullable();
            $attachment($table);
            $table->timestamps();
        });

        Schema::create('employee_certificates', function (Blueprint $table) use ($base, $attachment) {
            $base($table);
            $table->string('name', 160);
            $table->string('category', 120)->nullable();
            $table->date('awarded_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->string('awarded_by', 160)->nullable();
            $table->string('remark', 500)->nullable();
            $attachment($table);
            $table->timestamps();
        });

        Schema::create('employee_awards', function (Blueprint $table) use ($base, $attachment) {
            $base($table);
            $table->string('title', 160);
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('remark', 500)->nullable();
            $attachment($table);
            $table->timestamps();
        });

        Schema::create('employee_languages', function (Blueprint $table) use ($base) {
            $base($table);
            $table->string('language', 80);
            $table->string('speaking', 12)->nullable(); // basic, intermediate, fluent, native
            $table->string('reading', 12)->nullable();
            $table->string('writing', 12)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['employee_languages', 'employee_awards', 'employee_certificates', 'employee_educations', 'employee_work_histories'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
