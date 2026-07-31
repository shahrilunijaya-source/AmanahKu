<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Employee;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MessageAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    private Employee $employee;

    private Employee $other;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->user = User::create(['name' => 'Demo', 'email' => 'demo@example.com', 'password' => Hash::make('password')]);
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
            'name' => 'Demo', 'status' => 'active', 'workload' => 'green',
        ]);
        $this->other = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Colleague', 'status' => 'active', 'workload' => 'green',
        ]);
    }

    private function actingInTenant(?User $as = null): self
    {
        $this->actingAs($as ?? $this->user)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    public function test_message_has_many_attachments_and_isimage_detects_images(): void
    {
        $c = Conversation::create([
            'tenant_id' => $this->tenant->id,
            'employee_low_id' => min($this->employee->id, $this->other->id),
            'employee_high_id' => max($this->employee->id, $this->other->id),
            'last_message_at' => now(),
        ]);
        $msg = Message::create(['tenant_id' => $this->tenant->id, 'conversation_id' => $c->id, 'sender_id' => $this->employee->id, 'body' => 'hi']);

        $img = $msg->attachments()->create(['tenant_id' => $this->tenant->id, 'path' => 'message-attachments/x.png', 'name' => 'x.png', 'mime' => 'image/png', 'size' => 10]);
        $doc = $msg->attachments()->create(['tenant_id' => $this->tenant->id, 'path' => 'message-attachments/y.pdf', 'name' => 'y.pdf', 'mime' => 'application/pdf', 'size' => 20]);

        $this->assertSame(2, $msg->attachments()->count());
        $this->assertTrue($img->isImage());
        $this->assertFalse($doc->isImage());
    }

    public function test_sending_with_a_file_stores_it_and_creates_a_row(): void
    {
        $this->actingInTenant()->post('/app/messages/send', [
            'to' => $this->other->id,
            'body' => 'see attached',
            'attachments' => [UploadedFile::fake()->image('photo.png')],
        ])->assertRedirect();

        $msg = Message::first();
        $this->assertNotNull($msg);
        $this->assertSame(1, MessageAttachment::count());
        $att = MessageAttachment::first();
        $this->assertSame($msg->id, $att->message_id);
        $this->assertSame('photo.png', $att->name);
        Storage::disk('local')->assertExists($att->path);
    }

    public function test_image_only_message_is_allowed(): void
    {
        $this->actingInTenant()->post('/app/messages/send', [
            'to' => $this->other->id,
            'attachments' => [UploadedFile::fake()->image('snap.jpg')],
        ])->assertRedirect();

        $this->assertSame(1, Message::count());
        $this->assertSame(1, MessageAttachment::count());
    }

    public function test_empty_body_with_no_file_is_rejected(): void
    {
        $this->actingInTenant()->post('/app/messages/send', ['to' => $this->other->id, 'body' => ''])
            ->assertSessionHasErrors('body');
        $this->assertSame(0, Message::count());
    }

    public function test_oversize_file_is_rejected(): void
    {
        $this->actingInTenant()->post('/app/messages/send', [
            'to' => $this->other->id,
            'body' => 'big',
            'attachments' => [UploadedFile::fake()->create('huge.pdf', 9000, 'application/pdf')], // 9000 KB > 8192
        ])->assertSessionHasErrors('attachments.0');
        $this->assertSame(0, Message::count());
        $this->assertSame(0, MessageAttachment::count());
    }

    public function test_a_participant_can_stream_an_attachment(): void
    {
        $this->actingInTenant()->post('/app/messages/send', [
            'to' => $this->other->id, 'body' => 'x',
            'attachments' => [UploadedFile::fake()->image('p.png')],
        ])->assertRedirect();
        $att = MessageAttachment::first();

        $this->actingInTenant()->get("/app/messages/attachments/{$att->id}")->assertOk();
    }

    public function test_a_non_participant_cannot_stream_an_attachment(): void
    {
        $this->actingInTenant()->post('/app/messages/send', [
            'to' => $this->other->id, 'body' => 'x',
            'attachments' => [UploadedFile::fake()->image('p.png')],
        ])->assertRedirect();
        $att = MessageAttachment::first();

        $intruderUser = User::create(['name' => 'Nosy', 'email' => 'nosy@example.com', 'password' => Hash::make('password')]);
        $intruderUser->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        Employee::create(['tenant_id' => $this->tenant->id, 'user_id' => $intruderUser->id, 'name' => 'Nosy', 'status' => 'active', 'workload' => 'green']);

        $this->actingInTenant($intruderUser)->get("/app/messages/attachments/{$att->id}")->assertForbidden();
    }

    public function test_thread_json_includes_attachment_metadata(): void
    {
        $this->actingInTenant()->post('/app/messages/send', [
            'to' => $this->other->id, 'body' => 'x',
            'attachments' => [UploadedFile::fake()->image('p.png')],
        ])->assertRedirect();
        $c = Conversation::first();

        $this->actingInTenant()->get("/app/messages/thread/{$c->id}")
            ->assertOk()
            ->assertJsonFragment(['name' => 'p.png', 'isImage' => true]);
    }

    public function test_screen_snippet_marks_attachment_only_messages(): void
    {
        // Image-only message → empty body → snippet must not be blank.
        $this->actingInTenant()->post('/app/messages/send', [
            'to' => $this->other->id,
            'attachments' => [UploadedFile::fake()->image('p.png')],
        ])->assertRedirect();

        $this->actingInTenant()->get('/app/messages')
            ->assertOk()
            ->assertSee('📎 Attachment');
    }
}
