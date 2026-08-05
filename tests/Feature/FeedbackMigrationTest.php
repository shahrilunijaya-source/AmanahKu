<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The one-off migration that folds feedback_items/feedback_attachments into
 * tickets/ticket_attachments has already run (and dropped the old tables) by the time
 * RefreshDatabase finishes migrating for this test. To exercise its logic we recreate the
 * old tables by hand, seed pre-merge-shape rows, then invoke the migration file's up()
 * a second time and assert the result — the migration itself is idempotent-safe to call
 * again because it only ever reads feedback_items/feedback_attachments and both are empty
 * the second time regardless (this test recreates them with rows first).
 */
class FeedbackMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function recreateOldFeedbackTables(): void
    {
        Schema::create('feedback_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('type', ['bug', 'idea']);
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('page_url', 500)->nullable();
            $table->enum('status', ['open', 'reviewing', 'resolved', 'declined'])->default('open');
            $table->timestamps();
        });

        Schema::create('feedback_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // Deliberately NOT ->constrained() here, unlike the real retired table. The
            // rollback test below has to insert an attachment whose parent item does not
            // exist, which is the only way to make the migration's count guard disagree
            // with reality. The FK contributes nothing to what these tests check.
            $table->unsignedBigInteger('feedback_item_id')->index();
            $table->string('path');
            $table->string('name');
            $table->string('mime')->nullable();
            $table->unsignedInteger('size')->default(0);
            $table->timestamps();
        });
    }

    public function test_feedback_rows_and_attachments_migrate_into_tickets(): void
    {
        // Arrange — real tenant/user/employee (FK targets), then hand-seed old-shape rows.
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $user = User::create(['name' => 'Demo', 'email' => 'demo@example.com', 'password' => bcrypt('password')]);
        $employee = Employee::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id,
            'name' => 'Demo', 'status' => 'active', 'workload' => 'green',
        ]);

        $this->recreateOldFeedbackTables();

        $itemId = DB::table('feedback_items')->insertGetId([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'employee_id' => $employee->id,
            'type' => 'bug', 'title' => 'Old bug report', 'description' => 'It broke.',
            'page_url' => 'http://localhost/app/dash', 'status' => 'resolved',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('feedback_attachments')->insert([
            'tenant_id' => $tenant->id, 'feedback_item_id' => $itemId,
            'path' => 'feedback-attachments/shot.png', 'name' => 'shot.png',
            'mime' => 'image/png', 'size' => 1024,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // A report whose author has no employee record. feedback_items.employee_id was
        // nullable and tickets.employee_id was not until the Task 1 migration relaxed it;
        // without that, this row alone would abort the whole fold on a real host.
        DB::table('feedback_items')->insert([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'employee_id' => null,
            'type' => 'idea', 'title' => 'Orphaned idea', 'description' => null,
            'page_url' => null, 'status' => 'reviewing',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // A Bug ticket raised through the new UI *before* this migration runs. The count
        // guard must ignore it — it is not part of the fold.
        Ticket::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'employee_id' => $employee->id,
            'category' => 'Bug', 'priority' => 'medium', 'subject' => 'Raised after deploy',
            'description' => 'x', 'status' => 'open',
        ]);

        // Act — run the migration's up() directly.
        $migration = require base_path('database/migrations/2026_08_05_000003_migrate_feedback_items_to_tickets.php');
        $migration->up();

        // Assert — ticket exists with mapped fields, attachment copied, old tables gone.
        $this->assertDatabaseHas('tickets', [
            'tenant_id' => $tenant->id, 'employee_id' => $employee->id,
            'category' => 'Bug', 'priority' => 'medium', 'subject' => 'Old bug report',
            'description' => 'It broke.', 'page_url' => 'http://localhost/app/dash',
            'status' => 'resolved',
        ]);
        $ticket = Ticket::withoutGlobalScopes()->where('subject', 'Old bug report')->first();
        $this->assertSame(1, $ticket->attachments()->count());
        $this->assertSame('feedback-attachments/shot.png', $ticket->attachments->first()->path);

        // The null-employee report survived, with its status remapped reviewing → in_progress
        // and its null description defaulted to '' (tickets.description is NOT NULL).
        $this->assertDatabaseHas('tickets', [
            'tenant_id' => $tenant->id, 'employee_id' => null,
            'category' => 'Idea', 'subject' => 'Orphaned idea',
            'description' => '', 'status' => 'in_progress',
        ]);

        // The pre-existing Bug ticket is untouched and did not trip the count guard.
        $this->assertSame(1, Ticket::withoutGlobalScopes()->where('subject', 'Raised after deploy')->count());

        $this->assertFalse(Schema::hasTable('feedback_items'));
        $this->assertFalse(Schema::hasTable('feedback_attachments'));
    }

    public function test_a_failed_verification_rolls_back_every_inserted_row(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $user = User::create(['name' => 'Demo', 'email' => 'demo@example.com', 'password' => bcrypt('password')]);

        $this->recreateOldFeedbackTables();
        DB::table('feedback_items')->insert([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'employee_id' => null,
            'type' => 'bug', 'title' => 'Will not survive', 'description' => 'x',
            'page_url' => null, 'status' => 'open',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Force a mismatch: an extra attachment row pointing at no feedback item inflates
        // $expectedAttachments, so nothing will be copied for it and verification throws.
        DB::table('feedback_attachments')->insert([
            'tenant_id' => $tenant->id, 'feedback_item_id' => 9999,
            'path' => 'x', 'name' => 'x', 'mime' => null, 'size' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration = require base_path('database/migrations/2026_08_05_000003_migrate_feedback_items_to_tickets.php');

        try {
            $migration->up();
            $this->fail('Expected the count verification to throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('attachments', $e->getMessage());
        }

        // The transaction rolled the insert back, and the old tables still stand — so a
        // re-run after the operator fixes the data starts clean instead of double-inserting.
        $this->assertSame(0, Ticket::withoutGlobalScopes()->count());
        $this->assertTrue(Schema::hasTable('feedback_items'));
    }
}
