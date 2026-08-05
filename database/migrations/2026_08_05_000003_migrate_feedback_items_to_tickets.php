<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('feedback_items')) {
            return;
        }

        $expectedTickets = DB::table('feedback_items')->count();
        $expectedAttachments = DB::table('feedback_attachments')->count();

        // Everything that copies rows runs inside one transaction: the verification below
        // throws on a mismatch, and without this the throw would leave half-migrated tickets
        // behind with feedback_items still present, so a re-run would double-insert. The two
        // DROPs stay outside — MySQL commits implicitly on DDL, so wrapping them buys nothing
        // and would only obscure where a failure actually happened.
        DB::transaction(function () use ($expectedTickets, $expectedAttachments) {
            $migratedTickets = 0;
            $migratedAttachments = 0;

            DB::table('feedback_items')->orderBy('id')->chunkById(100, function ($items) use (&$migratedTickets, &$migratedAttachments) {
                foreach ($items as $item) {
                    $ticketId = DB::table('tickets')->insertGetId([
                        'tenant_id' => $item->tenant_id,
                        // Nullable on both sides as of the Task 1 migration — a report whose
                        // author has no employee record still migrates rather than aborting.
                        'employee_id' => $item->employee_id,
                        'assignee_employee_id' => null,
                        'category' => ucfirst($item->type),
                        'priority' => 'medium',
                        'subject' => $item->title,
                        'description' => $item->description ?? '',
                        'page_url' => $item->page_url,
                        'status' => match ($item->status) {
                            'open' => 'open',
                            'reviewing' => 'in_progress',
                            'resolved' => 'resolved',
                            'declined' => 'closed',
                            default => 'open',
                        },
                        'resolution' => null,
                        'created_at' => $item->created_at,
                        'updated_at' => $item->updated_at,
                    ]);
                    $migratedTickets++;

                    DB::table('feedback_attachments')->where('feedback_item_id', $item->id)->orderBy('id')
                        ->get()->each(function ($att) use ($ticketId, &$migratedAttachments) {
                            DB::table('ticket_attachments')->insert([
                                'tenant_id' => $att->tenant_id,
                                'ticket_id' => $ticketId,
                                'path' => $att->path,
                                'name' => $att->name,
                                'mime' => $att->mime,
                                'size' => $att->size,
                                'created_at' => $att->created_at,
                                'updated_at' => $att->updated_at,
                            ]);
                            $migratedAttachments++;
                        });
                }
            });

            // Count what THIS migration wrote, not what the tables hold. A plain
            // tickets/ticket_attachments count would also sweep up any Bug/Idea ticket
            // raised through the UI between deploying Task 3 and running this migration,
            // and would then fail for a reason that has nothing to do with the fold.
            if ($migratedTickets !== $expectedTickets) {
                throw new RuntimeException("Feedback migration mismatch: expected {$expectedTickets} tickets, migrated {$migratedTickets}.");
            }

            if ($migratedAttachments !== $expectedAttachments) {
                throw new RuntimeException("Feedback migration mismatch: expected {$expectedAttachments} attachments, migrated {$migratedAttachments}.");
            }
        });

        Schema::dropIfExists('feedback_attachments');
        Schema::dropIfExists('feedback_items');
    }

    public function down(): void
    {
        // One-way data fold — not reversible. Recreate empty tables only so schema:down
        // doesn't hard-fail structurally; data is not restored.
        Schema::create('feedback_items', function ($table) {
            $table->id();
            $table->timestamps();
        });
        Schema::create('feedback_attachments', function ($table) {
            $table->id();
            $table->timestamps();
        });
    }
};
