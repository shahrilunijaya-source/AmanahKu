<?php

namespace Tests\Feature;

use App\Http\Controllers\CalendarSyncController;
use App\Jobs\CalendarFullSyncJob;
use App\Models\CompanyEvent;
use App\Models\Employee;
use App\Models\GoogleCalendarConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkItemCalendarCopy;
use App\Ports\CalendarPort;
use App\Support\Calendar\CalendarReconciler;
use App\Support\Calendar\CalendarSyncProgress;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** The board's Google Calendar control: status, Sync now (rate limited), Retry. */
class CalendarSyncControlTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private Employee $me;

    private Employee $colleague;

    private RecordingCalendarPort $port;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        config(['services.google_calendar.client_id' => 'id', 'services.google_calendar.client_secret' => 'secret']);
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->user = User::create(['name' => 'Me', 'email' => 'me@example.com', 'password' => Hash::make('password')]);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->me = Employee::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id, 'name' => 'Me', 'status' => 'active', 'workload' => 'green']);
        $other = User::create(['name' => 'Col', 'email' => 'col@example.com', 'password' => Hash::make('password')]);
        $other->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->colleague = Employee::create(['tenant_id' => $this->tenant->id, 'user_id' => $other->id, 'name' => 'Col', 'status' => 'active', 'workload' => 'green']);
        $this->port = new RecordingCalendarPort;
        $this->app->instance(CalendarPort::class, $this->port);
        RateLimiter::clear(CalendarSyncController::limiterKey($this->user->id));
    }

    protected function tearDown(): void
    {
        app(CurrentTenant::class)->set(null);
        parent::tearDown();
    }

    private function connect(array $attrs = []): GoogleCalendarConnection
    {
        return GoogleCalendarConnection::create($attrs + ['user_id' => $this->user->id, 'access_token' => 't', 'refresh_token' => 'r', 'expires_at' => now()->addHour()]);
    }

    private function as(): self
    {
        return $this->actingAs($this->user)->withSession(['current_tenant' => $this->tenant->id]);
    }

    /** Cards are created before the connection exists, so nothing is pushed yet. */
    private function cardFor(Employee $owner, array $attrs = []): WorkItem
    {
        app(CurrentTenant::class)->set($this->tenant);
        $card = $owner->workItems()->create($attrs + [
            'tenant_id' => $this->tenant->id, 'title' => 'Card '.uniqid(), 'type' => 'task',
            'priority' => 'medium', 'status' => 'todo', 'progress' => 0, 'due_at' => '2026-10-05',
        ]);
        app(CurrentTenant::class)->set(null);

        return $card;
    }

    public function test_status_says_off_when_not_connected(): void
    {
        $this->as()->getJson(route('calendar-sync.status'))
            ->assertOk()
            ->assertJson(['configured' => true, 'state' => 'off', 'issues' => [], 'retry_after' => 0]);
    }

    public function test_status_says_expired_when_revoked(): void
    {
        $this->connect(['revoked_at' => now()]);

        $this->as()->getJson(route('calendar-sync.status'))->assertJson(['state' => 'expired']);
    }

    public function test_status_lists_failed_owner_cards_and_failed_copies(): void
    {
        $mine = $this->cardFor($this->me);
        WorkItem::withoutGlobalScopes()->where('id', $mine->id)->update(['calendar_sync_error' => 'boom']);
        $theirs = $this->cardFor($this->colleague);
        WorkItemCalendarCopy::create(['tenant_id' => $this->tenant->id, 'work_item_id' => $theirs->id, 'employee_id' => $this->me->id, 'sync_error' => 'nope']);
        $this->connect();

        $issues = $this->as()->getJson(route('calendar-sync.status'))->assertJson(['state' => 'connected'])->json('issues');

        $this->assertEqualsCanonicalizing([$mine->id, $theirs->id], array_column($issues, 'id'));
    }

    public function test_sync_now_pushes_owned_and_tagged_cards_and_reports_progress(): void
    {
        $owned = $this->cardFor($this->me);
        $tagged = $this->cardFor($this->colleague);
        DB::table('work_item_participant')->insert(['work_item_id' => $tagged->id, 'employee_id' => $this->me->id, 'role' => 'fyi']);
        $this->cardFor($this->me, ['status' => 'done']);
        $this->cardFor($this->colleague);
        $this->connect();

        $this->as()->postJson(route('calendar-sync.sync'))->assertStatus(202);

        $this->assertSame([$this->me->id, $this->me->id], $this->port->pushedTo());
        $this->assertNotNull($owned->fresh()->google_event_id);
        $this->assertDatabaseHas('work_item_calendar_copies', ['work_item_id' => $tagged->id, 'employee_id' => $this->me->id]);
        $progress = $this->as()->getJson(route('calendar-sync.status'))->json('progress');
        $this->assertSame(['state' => 'done', 'total' => 2, 'done' => 2, 'failed' => 0], array_intersect_key($progress, array_flip(['state', 'total', 'done', 'failed'])));
    }

    public function test_sync_now_counts_failures_and_keeps_going(): void
    {
        $this->cardFor($this->me);
        $this->cardFor($this->me);
        $this->connect();
        $this->port->fail = true;

        $this->as()->postJson(route('calendar-sync.sync'))->assertStatus(202);

        $progress = $this->as()->getJson(route('calendar-sync.status'))->json('progress');
        $this->assertSame(2, $progress['failed']);
        $this->assertSame(2, WorkItem::withoutGlobalScopes()->whereNotNull('calendar_sync_error')->count());
    }

    public function test_second_sync_within_two_minutes_is_refused_with_the_wait(): void
    {
        $this->connect();
        $this->as()->postJson(route('calendar-sync.sync'))->assertStatus(202);

        $response = $this->as()->postJson(route('calendar-sync.sync'))->assertStatus(429);

        $this->assertGreaterThan(100, $response->json('retry_after'));
        $this->assertLessThanOrEqual(120, $response->json('retry_after'));
    }

    public function test_the_limit_resets_after_two_minutes(): void
    {
        $this->connect();
        $this->as()->postJson(route('calendar-sync.sync'))->assertStatus(202);

        $this->travel(121)->seconds();

        $this->as()->postJson(route('calendar-sync.sync'))->assertStatus(202);
    }

    public function test_sync_needs_a_live_connection(): void
    {
        $this->as()->postJson(route('calendar-sync.sync'))->assertStatus(409);
        $this->connect(['revoked_at' => now()]);
        $this->as()->postJson(route('calendar-sync.sync'))->assertStatus(409);
    }

    public function test_retry_resends_my_card_and_shares_the_limit(): void
    {
        $card = $this->cardFor($this->me);
        WorkItem::withoutGlobalScopes()->where('id', $card->id)->update(['calendar_sync_error' => 'boom']);
        $this->connect();

        $this->as()->postJson(route('calendar-sync.retry', $card))->assertOk();

        $this->assertNull($card->fresh()->calendar_sync_error);
        $this->assertSame([$this->me->id], $this->port->pushedTo());
        $this->as()->postJson(route('calendar-sync.sync'))->assertStatus(429);
    }

    public function test_retry_resends_my_tagged_copy(): void
    {
        $card = $this->cardFor($this->colleague);
        DB::table('work_item_participant')->insert(['work_item_id' => $card->id, 'employee_id' => $this->me->id, 'role' => 'helper']);
        WorkItemCalendarCopy::create(['tenant_id' => $this->tenant->id, 'work_item_id' => $card->id, 'employee_id' => $this->me->id, 'sync_error' => 'nope']);
        $this->connect();

        $this->as()->postJson(route('calendar-sync.retry', $card))->assertOk();

        $this->assertNull(WorkItemCalendarCopy::first()->sync_error);
        $this->assertSame([$this->me->id], $this->port->pushedTo());
    }

    public function test_retry_refuses_a_card_i_am_not_on(): void
    {
        $card = $this->cardFor($this->colleague);
        $this->connect();

        $this->as()->postJson(route('calendar-sync.retry', $card))->assertForbidden();
    }

    public function test_retry_refuses_a_card_from_another_company(): void
    {
        $otherTenant = Tenant::create(['slug' => 'other', 'name' => 'Other', 'initials' => 'OT']);
        $otherOwnerUser = User::create(['name' => 'Stranger', 'email' => 'stranger@example.com', 'password' => Hash::make('password')]);
        $otherOwnerUser->tenants()->attach($otherTenant->id, ['role' => 'employee']);
        $otherOwner = Employee::create(['tenant_id' => $otherTenant->id, 'user_id' => $otherOwnerUser->id, 'name' => 'Stranger', 'status' => 'active', 'workload' => 'green']);
        app(CurrentTenant::class)->set($otherTenant);
        $foreignCard = $otherOwner->workItems()->create([
            'tenant_id' => $otherTenant->id, 'title' => 'Foreign card', 'type' => 'task',
            'priority' => 'medium', 'status' => 'todo', 'progress' => 0, 'due_at' => '2026-10-05',
        ]);
        app(CurrentTenant::class)->set(null);
        $this->connect();

        $this->as()->postJson(route('calendar-sync.retry', $foreignCard))->assertForbidden();

        $this->assertSame([], $this->port->pushedTo());
    }

    /** In production the queue is async: a running sync must not be re-dispatched or rate-limited. */
    public function test_sync_now_while_already_running_reports_progress_without_hitting_the_limiter(): void
    {
        Queue::fake();
        $this->connect();
        CalendarSyncProgress::start($this->user->id, 5);

        $this->as()->postJson(route('calendar-sync.sync'))->assertStatus(202);

        $progress = CalendarSyncProgress::get($this->user->id);
        $this->assertSame('running', $progress['state']);
        $this->assertSame(5, $progress['total']);
        Queue::assertNothingPushed();

        // The limiter was never hit, so a second call right after is still not 429.
        $this->as()->postJson(route('calendar-sync.sync'))->assertStatus(202);
    }

    public function test_a_pull_failure_still_leaves_progress_finished(): void
    {
        $this->cardFor($this->me);
        $this->connect();
        $this->port->pullThrows = true;

        try {
            (new CalendarFullSyncJob($this->user->id))->handle(
                app(CurrentTenant::class), $this->port, app(CalendarReconciler::class),
            );
            $this->fail('expected the pull failure to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('pull failed', $e->getMessage());
        }

        $progress = CalendarSyncProgress::get($this->user->id);
        $this->assertSame('done', $progress['state']);
    }

    public function test_retry_records_the_error_again_when_the_push_fails(): void
    {
        $card = $this->cardFor($this->me);
        WorkItem::withoutGlobalScopes()->where('id', $card->id)->update(['calendar_sync_error' => 'boom']);
        $this->connect();
        $this->port->fail = true;

        $this->as()->postJson(route('calendar-sync.retry', $card))->assertOk();

        $this->assertNotNull($card->fresh()->calendar_sync_error);
    }

    public function test_routes_404_when_google_is_not_configured(): void
    {
        config(['services.google_calendar.client_id' => null]);

        $this->as()->getJson(route('calendar-sync.status'))->assertNotFound();
        $this->as()->postJson(route('calendar-sync.sync'))->assertNotFound();
    }

    public function test_the_board_shows_the_calendar_control_when_configured(): void
    {
        $this->as()->get(route('app.screen', 'board'))
            ->assertOk()
            ->assertSee('data-testid="calendar-sync"', escape: false)
            ->assertSee(route('calendar-sync.sync'), escape: false);
    }

    public function test_the_board_hides_it_when_google_is_not_configured(): void
    {
        config(['services.google_calendar.client_id' => null]);

        $this->as()->get(route('app.screen', 'board'))
            ->assertOk()
            ->assertDontSee('data-testid="calendar-sync"', escape: false);
    }

    public function test_profile_no_longer_has_the_calendar_section(): void
    {
        $this->as()->get(route('app.screen', 'profile'))
            ->assertOk()
            ->assertDontSee(route('google-calendar.redirect'), escape: false);
    }

    public function test_a_full_sync_killed_on_the_queue_leaves_progress_finished(): void
    {
        CalendarSyncProgress::start($this->user->id, 3);

        (new CalendarFullSyncJob($this->user->id))->failed(new \RuntimeException('timed out'));

        $this->assertSame('done', CalendarSyncProgress::get($this->user->id)['state']);
    }

    public function test_status_leaves_out_issues_from_my_other_company(): void
    {
        $otherTenant = Tenant::create(['slug' => 'other', 'name' => 'Other', 'initials' => 'OT']);
        $this->user->tenants()->attach($otherTenant->id, ['role' => 'employee']);
        $meThere = Employee::create(['tenant_id' => $otherTenant->id, 'user_id' => $this->user->id, 'name' => 'Me', 'status' => 'active', 'workload' => 'green']);
        app(CurrentTenant::class)->set($otherTenant);
        $elsewhere = $meThere->workItems()->create([
            'tenant_id' => $otherTenant->id, 'title' => 'Elsewhere', 'type' => 'task',
            'priority' => 'medium', 'status' => 'todo', 'progress' => 0, 'due_at' => '2026-10-05',
        ]);
        app(CurrentTenant::class)->set(null);
        WorkItem::withoutGlobalScopes()->where('id', $elsewhere->id)->update(['calendar_sync_error' => 'far away']);
        $mine = $this->cardFor($this->me);
        WorkItem::withoutGlobalScopes()->where('id', $mine->id)->update(['calendar_sync_error' => 'boom']);
        $this->connect();

        $issues = $this->as()->getJson(route('calendar-sync.status'))->json('issues');

        $this->assertSame([$mine->id], array_column($issues, 'id'));
    }

    public function test_status_lists_a_card_once_even_with_an_owner_and_a_copy_error(): void
    {
        $mine = $this->cardFor($this->me);
        WorkItem::withoutGlobalScopes()->where('id', $mine->id)->update(['calendar_sync_error' => 'boom']);
        WorkItemCalendarCopy::create(['tenant_id' => $this->tenant->id, 'work_item_id' => $mine->id, 'employee_id' => $this->me->id, 'sync_error' => 'stale']);
        $this->connect();

        $issues = $this->as()->getJson(route('calendar-sync.status'))->json('issues');

        $this->assertSame([$mine->id], array_column($issues, 'id'));
        $this->assertSame('boom', $issues[0]['message']);
    }

    public function test_full_sync_skips_company_event_cards_i_own(): void
    {
        $plain = $this->cardFor($this->me);
        $eventCard = $this->cardFor($this->me, ['type' => 'event']);
        $companyEvent = CompanyEvent::create(['tenant_id' => $this->tenant->id, 'title' => 'Family Day', 'type' => 'social', 'event_date' => '2026-10-05']);
        WorkItem::withoutGlobalScopes()->where('id', $eventCard->id)->update(['company_event_id' => $companyEvent->id]);
        $this->connect();

        (new CalendarFullSyncJob($this->user->id))->handle(app(CurrentTenant::class), $this->port, app(CalendarReconciler::class));

        $this->assertSame([$plain->id], array_map(fn ($u) => $u['event']->subject->id, $this->port->upserts));
    }
}
