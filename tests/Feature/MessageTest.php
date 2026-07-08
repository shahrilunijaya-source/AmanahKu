<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Employee;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Feature coverage for in-app 1-to-1 direct messaging.
 * Harness (setUp / actingInTenant) copied from HelpdeskTest / CoreWritePathsTest.
 */
class MessageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    private Employee $employee;

    private Employee $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create(['name' => 'Demo', 'email' => 'demo@example.com', 'password' => Hash::make('password')]);
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
            'name' => 'Demo', 'status' => 'active', 'workload' => 'green',
        ]);
        $this->other = Employee::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Colleague', 'status' => 'active', 'workload' => 'green',
        ]);
    }

    private function actingInTenant(?User $as = null): self
    {
        $this->actingAs($as ?? $this->user)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    /** A conversation row keyed to the canonical low/high pair, with a message in it. */
    private function seedThread(Employee $sender, Employee $recipient, string $body = 'ping'): Conversation
    {
        $c = Conversation::create([
            'tenant_id' => $this->tenant->id,
            'employee_low_id' => min($sender->id, $recipient->id),
            'employee_high_id' => max($sender->id, $recipient->id),
            'last_message_at' => now(),
        ]);
        Message::create([
            'tenant_id' => $this->tenant->id, 'conversation_id' => $c->id,
            'sender_id' => $sender->id, 'body' => $body,
        ]);

        return $c;
    }

    // ── Sending ───────────────────────────────────────────────────

    public function test_sending_a_message_creates_a_canonical_conversation(): void
    {
        $this->actingInTenant()->post('/app/messages/send', [
            'to' => $this->other->id,
            'body' => 'Hi there',
        ])->assertRedirect();

        $this->assertDatabaseHas('conversations', [
            'tenant_id' => $this->tenant->id,
            'employee_low_id' => min($this->employee->id, $this->other->id),
            'employee_high_id' => max($this->employee->id, $this->other->id),
        ]);
        $this->assertDatabaseHas('messages', [
            'tenant_id' => $this->tenant->id,
            'sender_id' => $this->employee->id,
            'body' => 'Hi there',
        ]);
    }

    public function test_a_second_message_reuses_the_same_conversation(): void
    {
        $this->actingInTenant()->post('/app/messages/send', ['to' => $this->other->id, 'body' => 'one'])->assertRedirect();
        $this->actingInTenant()->post('/app/messages/send', ['to' => $this->other->id, 'body' => 'two'])->assertRedirect();

        $this->assertSame(1, Conversation::count());
        $this->assertSame(2, Message::count());
    }

    public function test_replying_into_an_existing_conversation_by_id(): void
    {
        $c = $this->seedThread($this->other, $this->employee);

        $this->actingInTenant()->post('/app/messages/send', [
            'conversation_id' => $c->id,
            'body' => 'thanks!',
        ])->assertRedirect();

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $c->id, 'sender_id' => $this->employee->id, 'body' => 'thanks!',
        ]);
    }

    public function test_message_body_is_required(): void
    {
        $this->actingInTenant()->post('/app/messages/send', ['to' => $this->other->id, 'body' => ''])
            ->assertSessionHasErrors('body');
        $this->assertSame(0, Message::count());
    }

    public function test_cannot_message_yourself(): void
    {
        $this->actingInTenant()->post('/app/messages/send', ['to' => $this->employee->id, 'body' => 'note to self'])
            ->assertStatus(422);
        $this->assertSame(0, Conversation::count());
    }

    public function test_recipient_must_be_an_active_same_tenant_employee(): void
    {
        $otherTenant = Tenant::create(['slug' => 'zeta', 'name' => 'Zeta', 'initials' => 'ZE']);
        $foreign = Employee::create([
            'tenant_id' => $otherTenant->id, 'name' => 'Outsider', 'status' => 'active', 'workload' => 'green',
        ]);

        $this->actingInTenant()->post('/app/messages/send', ['to' => $foreign->id, 'body' => 'hi'])
            ->assertSessionHasErrors('to');
        $this->assertSame(0, Conversation::count());
    }

    // ── Read state + unread badge ─────────────────────────────────

    public function test_recipient_marks_incoming_messages_read(): void
    {
        $c = $this->seedThread($this->other, $this->employee);

        $this->actingInTenant()->post("/app/messages/{$c->id}/read")
            ->assertOk()
            ->assertJson(['ok' => true, 'unread' => 0]);

        $this->assertNotNull($c->messages()->first()->read_at);
    }

    public function test_unread_endpoint_counts_only_incoming_unread(): void
    {
        // One thread, two messages: one incoming (unread) + one of my own (never counts).
        $c = $this->seedThread($this->other, $this->employee);
        Message::create([
            'tenant_id' => $this->tenant->id, 'conversation_id' => $c->id,
            'sender_id' => $this->employee->id, 'body' => 'mine',
        ]);

        $this->actingInTenant()->get('/app/messages/unread')
            ->assertOk()
            ->assertJson(['unread' => 1]);
    }

    // ── Isolation guards ──────────────────────────────────────────

    public function test_non_participant_cannot_read_a_thread(): void
    {
        $intruderUser = User::create(['name' => 'Nosy', 'email' => 'nosy@example.com', 'password' => Hash::make('password')]);
        $intruderUser->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $intruderUser->id,
            'name' => 'Nosy', 'status' => 'active', 'workload' => 'green',
        ]);

        $c = $this->seedThread($this->employee, $this->other);

        $this->actingInTenant($intruderUser)->get("/app/messages/thread/{$c->id}")->assertForbidden();
        $this->actingInTenant($intruderUser)->post("/app/messages/{$c->id}/read")->assertForbidden();
    }

    public function test_thread_json_returns_messages_for_a_participant(): void
    {
        $c = $this->seedThread($this->other, $this->employee, 'hello you');

        $this->actingInTenant()->get("/app/messages/thread/{$c->id}")
            ->assertOk()
            ->assertJson(['ok' => true, 'conversationId' => $c->id])
            ->assertJsonFragment(['body' => 'hello you']);
    }
}
