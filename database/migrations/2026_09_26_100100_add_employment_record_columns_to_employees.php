<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->date('confirmed_at')->nullable()->after('joined_at');
            $table->date('resigned_at')->nullable()->after('confirmed_at');
            $table->date('last_working_day')->nullable()->after('resigned_at');
            $table->unsignedTinyInteger('probation_months')->nullable()->after('last_working_day');
            $table->unsignedTinyInteger('probation_days')->nullable()->after('probation_months');
            $table->unsignedTinyInteger('resign_notice_months')->nullable()->after('probation_days');
            $table->unsignedTinyInteger('resign_notice_days')->nullable()->after('resign_notice_months');
            $table->unsignedTinyInteger('short_notice_months')->nullable()->after('resign_notice_days');
            $table->unsignedTinyInteger('short_notice_days')->nullable()->after('short_notice_months');
            $table->string('pay_mode', 8)->default('monthly')->after('salary');          // monthly | daily | hourly
            $table->string('payment_term', 8)->default('monthly')->after('pay_mode');    // daily | weekly | biweekly | monthly
            $table->string('payment_method', 8)->default('bank')->after('payment_term'); // cash | bank | cheque
            $table->string('division', 80)->nullable()->after('payment_method');
            $table->string('section', 80)->nullable()->after('division');
            $table->string('job_grade', 40)->nullable()->after('section');
            $table->string('category', 40)->nullable()->after('job_grade');
            $table->string('line', 40)->nullable()->after('category');
            $table->text('employment_remark')->nullable()->after('line');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['confirmed_at', 'resigned_at', 'last_working_day', 'probation_months', 'probation_days',
                'resign_notice_months', 'resign_notice_days', 'short_notice_months', 'short_notice_days',
                'pay_mode', 'payment_term', 'payment_method', 'division', 'section', 'job_grade', 'category', 'line', 'employment_remark']);
        });
    }
};
