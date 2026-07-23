<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Employee;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessageAttachmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_message_has_many_attachments_and_isimage_detects_images(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $a = Employee::create(['tenant_id' => $tenant->id, 'name' => 'A', 'status' => 'active', 'workload' => 'green']);
        $b = Employee::create(['tenant_id' => $tenant->id, 'name' => 'B', 'status' => 'active', 'workload' => 'green']);
        $c = Conversation::create([
            'tenant_id' => $tenant->id,
            'employee_low_id' => min($a->id, $b->id),
            'employee_high_id' => max($a->id, $b->id),
            'last_message_at' => now(),
        ]);
        $msg = Message::create(['tenant_id' => $tenant->id, 'conversation_id' => $c->id, 'sender_id' => $a->id, 'body' => 'hi']);

        $img = $msg->attachments()->create([
            'tenant_id' => $tenant->id, 'path' => 'message-attachments/x.png', 'name' => 'x.png', 'mime' => 'image/png', 'size' => 10,
        ]);
        $doc = $msg->attachments()->create([
            'tenant_id' => $tenant->id, 'path' => 'message-attachments/y.pdf', 'name' => 'y.pdf', 'mime' => 'application/pdf', 'size' => 20,
        ]);

        $this->assertSame(2, $msg->attachments()->count());
        $this->assertTrue($img->isImage());
        $this->assertFalse($doc->isImage());
    }
}
