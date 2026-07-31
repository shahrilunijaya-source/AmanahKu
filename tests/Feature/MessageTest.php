<?php

namespace Tests\Feature;

use App\Http\Controllers\MessageController;
use App\Models\Conversation;
use App\Models\Employee;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

    // ── Screen payload: runs, day markers, read state, index buckets ──────────

    /** Call MessageController::screenData() directly with a faked query string. */
    private function screenData(array $query = []): array
    {
        $request = Request::create('/app/messages', 'GET', $query);
        $this->actingInTenant();
        app(CurrentTenant::class)->set($this->tenant);

        return app(MessageController::class)->screenData($request, $this->employee);
    }

    /** Add a message to an existing conversation at a given moment. */
    private function addMessage(Conversation $c, Employee $sender, string $body, ?string $at = null, bool $read = false): Message
    {
        $when = $at ? Carbon::parse($at) : now();

        return Message::create([
            'tenant_id' => $this->tenant->id, 'conversation_id' => $c->id,
            'sender_id' => $sender->id, 'body' => $body,
            'created_at' => $when, 'updated_at' => $when,
            'read_at' => $read ? $when : null,
        ]);
    }

    public function test_consecutive_messages_from_one_sender_fold_into_one_run(): void
    {
        $c = Conversation::create([
            'tenant_id' => $this->tenant->id,
            'employee_low_id' => min($this->employee->id, $this->other->id),
            'employee_high_id' => max($this->employee->id, $this->other->id),
            'last_message_at' => now(),
        ]);
        $this->addMessage($c, $this->other, 'first', '2026-07-29 09:00:00');
        $this->addMessage($c, $this->other, 'second', '2026-07-29 09:01:00');

        $runs = $this->screenData(['c' => $c->id])['msgActive']['runs'];
        $bursts = array_values(array_filter($runs, fn ($r) => ! isset($r['day'])));

        $this->assertCount(1, $bursts);
        $this->assertSame(['first', 'second'], $bursts[0]['bubbles']);
        // The stamp is the LAST message in the run, not the first.
        $this->assertSame('09:01', $bursts[0]['time']);
    }

    public function test_a_sender_change_starts_a_new_run(): void
    {
        $c = Conversation::create([
            'tenant_id' => $this->tenant->id,
            'employee_low_id' => min($this->employee->id, $this->other->id),
            'employee_high_id' => max($this->employee->id, $this->other->id),
            'last_message_at' => now(),
        ]);
        $this->addMessage($c, $this->other, 'theirs', '2026-07-29 09:00:00');
        $this->addMessage($c, $this->employee, 'mine', '2026-07-29 09:02:00');

        $bursts = array_values(array_filter($this->screenData(['c' => $c->id])['msgActive']['runs'], fn ($r) => ! isset($r['day'])));

        $this->assertCount(2, $bursts);
        $this->assertFalse($bursts[0]['mine']);
        $this->assertTrue($bursts[1]['mine']);
    }

    public function test_a_date_change_emits_a_day_marker_and_splits_the_run(): void
    {
        $c = Conversation::create([
            'tenant_id' => $this->tenant->id,
            'employee_low_id' => min($this->employee->id, $this->other->id),
            'employee_high_id' => max($this->employee->id, $this->other->id),
            'last_message_at' => now(),
        ]);
        $this->addMessage($c, $this->other, 'monday', '2026-07-20 09:00:00');
        $this->addMessage($c, $this->other, 'tuesday', '2026-07-21 09:00:00');

        $runs = $this->screenData(['c' => $c->id])['msgActive']['runs'];
        $days = array_values(array_filter($runs, fn ($r) => isset($r['day'])));
        $bursts = array_values(array_filter($runs, fn ($r) => ! isset($r['day'])));

        $this->assertCount(2, $days);
        $this->assertCount(2, $bursts, 'the same sender across two dates is still two runs');
        $this->assertSame('Monday, 20 July', $days[0]['day']);
        $this->assertSame('Isnin, 20 Julai', $days[0]['dayMs']);
    }

    public function test_day_marker_uses_relative_labels_for_today_and_yesterday(): void
    {
        $c = Conversation::create([
            'tenant_id' => $this->tenant->id,
            'employee_low_id' => min($this->employee->id, $this->other->id),
            'employee_high_id' => max($this->employee->id, $this->other->id),
            'last_message_at' => now(),
        ]);
        $this->addMessage($c, $this->other, 'old', now()->subDay()->format('Y-m-d H:i:s'));
        $this->addMessage($c, $this->other, 'new', now()->format('Y-m-d H:i:s'));

        $days = array_values(array_filter($this->screenData(['c' => $c->id])['msgActive']['runs'], fn ($r) => isset($r['day'])));

        $this->assertSame(['Yesterday', 'Today'], array_column($days, 'day'));
        $this->assertSame(['Semalam', 'Hari ini'], array_column($days, 'dayMs'));
    }

    public function test_a_run_is_read_only_when_every_message_in_it_is_read(): void
    {
        $c = Conversation::create([
            'tenant_id' => $this->tenant->id,
            'employee_low_id' => min($this->employee->id, $this->other->id),
            'employee_high_id' => max($this->employee->id, $this->other->id),
            'last_message_at' => now(),
        ]);
        $this->addMessage($c, $this->employee, 'seen', '2026-07-29 09:00:00', read: true);
        $this->addMessage($c, $this->employee, 'not seen yet', '2026-07-29 09:01:00', read: false);

        $bursts = array_values(array_filter($this->screenData(['c' => $c->id])['msgActive']['runs'], fn ($r) => ! isset($r['day'])));

        $this->assertCount(1, $bursts);
        $this->assertFalse($bursts[0]['read']);
    }

    public function test_a_fully_read_run_reports_read(): void
    {
        $c = Conversation::create([
            'tenant_id' => $this->tenant->id,
            'employee_low_id' => min($this->employee->id, $this->other->id),
            'employee_high_id' => max($this->employee->id, $this->other->id),
            'last_message_at' => now(),
        ]);
        $this->addMessage($c, $this->employee, 'seen', '2026-07-29 09:00:00', read: true);

        $bursts = array_values(array_filter($this->screenData(['c' => $c->id])['msgActive']['runs'], fn ($r) => ! isset($r['day'])));

        $this->assertTrue($bursts[0]['read']);
    }

    public function test_index_rows_carry_a_recency_bucket_and_a_short_stamp(): void
    {
        $today = $this->seedThread($this->other, $this->employee, 'today');
        $today->update(['last_message_at' => now()]);

        $third = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Third', 'status' => 'active', 'workload' => 'green',
        ]);
        $week = $this->seedThread($third, $this->employee, 'this week');
        $week->update(['last_message_at' => now()->subDays(3)]);

        $fourth = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Fourth', 'status' => 'active', 'workload' => 'green',
        ]);
        $old = $this->seedThread($fourth, $this->employee, 'ages ago');
        $old->update(['last_message_at' => now()->subDays(30)]);

        $rows = collect($this->screenData()['msgConversations'])->keyBy('id');

        $this->assertSame('today', $rows[$today->id]['bucket']);
        $this->assertSame('week', $rows[$week->id]['bucket']);
        $this->assertSame('earlier', $rows[$old->id]['bucket']);

        // Today shows a clock; anything older than the week shows a date.
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $rows[$today->id]['atShort']);
        $this->assertSame(now()->subDays(30)->format('j M'), $rows[$old->id]['atShort']);
    }

    public function test_an_attachment_only_message_still_produces_a_run(): void
    {
        $c = Conversation::create([
            'tenant_id' => $this->tenant->id,
            'employee_low_id' => min($this->employee->id, $this->other->id),
            'employee_high_id' => max($this->employee->id, $this->other->id),
            'last_message_at' => now(),
        ]);
        $this->addMessage($c, $this->other, '', '2026-07-29 09:00:00');

        $bursts = array_values(array_filter($this->screenData(['c' => $c->id])['msgActive']['runs'], fn ($r) => ! isset($r['day'])));

        $this->assertCount(1, $bursts);
        $this->assertSame([], $bursts[0]['bubbles']);
    }

    public function test_a_thread_with_no_messages_yet_returns_empty_runs(): void
    {
        $data = $this->screenData(['to' => $this->other->id]);

        $this->assertNull($data['msgActive']['conversationId']);
        $this->assertSame([], $data['msgActive']['runs']);
    }

    // ── The thread-pane fragment (swapped in instead of reloading the screen) ──

    public function test_pane_returns_the_thread_fragment_for_a_participant(): void
    {
        $c = $this->seedThread($this->other, $this->employee, 'hello you');

        $res = $this->actingInTenant()->get("/app/messages/pane?c={$c->id}")->assertOk();

        $res->assertSee('hello you');
        $res->assertSee('uj-msg-dock', false);
        // A fragment, not a page: no shell around it.
        $res->assertDontSee('<body', false);
    }

    public function test_pane_with_no_query_returns_the_empty_state(): void
    {
        $this->actingInTenant()->get('/app/messages/pane')
            ->assertOk()
            ->assertSee('uj-msg-empty', false)
            ->assertDontSee('uj-msg-dock', false);
    }

    public function test_pane_never_renders_a_conversation_the_viewer_is_not_in(): void
    {
        $a = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'A', 'status' => 'active', 'workload' => 'green',
        ]);
        $b = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'B', 'status' => 'active', 'workload' => 'green',
        ]);
        $theirs = $this->seedThread($a, $b, 'private between them');

        $this->actingInTenant()->get("/app/messages/pane?c={$theirs->id}")
            ->assertOk()
            ->assertDontSee('private between them')
            ->assertSee('uj-msg-empty', false);
    }

    public function test_pane_opens_a_blank_composer_for_a_colleague_with_no_thread_yet(): void
    {
        $res = $this->actingInTenant()->get("/app/messages/pane?to={$this->other->id}")->assertOk();

        $res->assertSee('uj-msg-dock', false);
        $res->assertSee('name="to" value="'.$this->other->id.'"', false);
        $res->assertDontSee('name="conversation_id"', false);
    }

    public function test_send_returns_json_with_the_new_message_for_the_in_place_swap(): void
    {
        $this->actingInTenant()
            ->postJson('/app/messages/send', ['to' => $this->other->id, 'body' => 'swap me in'])
            ->assertOk()
            ->assertJson(['ok' => true])
            ->assertJsonPath('message.body', 'swap me in')
            // `time` drives the index row's stamp without a second round trip.
            ->assertJsonPath('message.mine', true)
            ->assertJsonStructure(['conversationId', 'message' => ['id', 'time', 'date', 'read']]);
    }
}
