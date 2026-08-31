<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * External TOT moves onto the Events screen: company_events gains the four columns
     * an outside-hosted event needs (host, venue_map_url, registration_url,
     * tagged_employee_ids), every existing external_tot_events row is copied across as a
     * 'training'-typed event, and the old table goes away.
     */
    public function up(): void
    {
        Schema::table('company_events', function (Blueprint $table) {
            $table->string('host')->nullable()->after('type');
            $table->string('venue_map_url')->nullable()->after('location');
            $table->string('registration_url')->nullable()->after('venue_map_url');
            $table->json('tagged_employee_ids')->nullable()->after('description');
        });

        // Guarded because this is the only chance production gets to move its rows —
        // nobody has a shell there, so the copy has to be part of the deploy's migrate
        // step or the posts are simply lost. A host missing the table (a rebuild that
        // never had it) must add the columns and carry on, not abort the whole deploy.
        if (Schema::hasTable('external_tot_events')) {
            // Query builder, not Eloquent: CompanyEvent's BelongsToTenant global scope
            // would otherwise silently drop every tenant but the one currently active.
            DB::table('external_tot_events')->orderBy('id')->each(function (object $row) {
                DB::table('company_events')->insert([
                    'tenant_id' => $row->tenant_id,
                    'title' => $row->title,
                    'type' => 'training',
                    'host' => $row->host,
                    'event_date' => $row->event_date,
                    'start_time' => $row->time_label,
                    'location' => $row->venue,
                    'venue_map_url' => $row->venue_map_url,
                    'registration_url' => $row->registration_url,
                    'description' => $row->description,
                    'tagged_employee_ids' => $row->tagged_employee_ids,
                    'created_by_employee_id' => $row->posted_by,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ]);
            });
        }

        Schema::dropIfExists('external_tot_events');
    }

    /**
     * Reverse the migrations.
     *
     * Recreates the empty external_tot_events shell. The rows already migrated onto
     * company_events are NOT copied back — they now live on columns this rollback also
     * drops, so rolling back after the up() has run for real loses those events for good.
     */
    public function down(): void
    {
        Schema::create('external_tot_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('host')->nullable();
            $table->text('description')->nullable();
            $table->date('event_date');
            $table->string('time_label')->nullable();
            $table->string('venue')->nullable();
            $table->string('venue_map_url')->nullable();
            $table->string('registration_url')->nullable();
            $table->json('tagged_employee_ids')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'event_date']);
        });

        Schema::table('company_events', function (Blueprint $table) {
            $table->dropColumn(['host', 'venue_map_url', 'registration_url', 'tagged_employee_ids']);
        });
    }
};
