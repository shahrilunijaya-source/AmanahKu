<?php

namespace Tests\Acceptance;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Acceptance for docs/specs/CR-21.md (session S14, Office Requests / Permintaan Pejabat).
 * Shapes fixed in OPEN "QA / CR-21 / shapes fixed by CR21Test": an `office_requests`
 * table with `office_request_votes`, routes under `/app/office-requests` (raise, board,
 * similar, upvote, admin-note, done, reopen, photo, insights), a T.A.A. card labelled
 * `office` owned by the active "Finance Manager" position holder (CR-18 resolution) with
 * every active employee of the "Admin" department tagged as `helper`, notifications as
 * `app_notifications` rows, a three-day reopen window, and a September insights page.
 */
class CR21Test extends TestCase
{
    use AlwaysChecks;
    use RefreshDatabase;

    private Employee $emysha;

    private Employee $adri;

    private Employee $mn;

    private Employee $director;

    /** @var list<Employee> */
    private array $adminStaff;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-01 09:00:00');
        Storage::fake('local');

        $fm = Position::create(['tenant_id' => $this->tenant()->id, 'title' => 'Finance Manager', 'status' => 'active']);
        $admin = Department::create(['tenant_id' => $this->tenant()->id, 'name' => 'Admin']);

        $this->mn = $this->person('MN', 'manager', ['position_id' => $fm->id]);
        $this->director = $this->person('Shahril', 'director');
        $this->adminStaff = [
            $this->person('Ain Akilah', 'employee', ['department_id' => $admin->id]),
            $this->person('Alya', 'employee', ['department_id' => $admin->id]),
            $this->person('Aminah', 'employee', ['department_id' => $admin->id]),
            $this->person('Hidayah', 'employee', ['department_id' => $admin->id]),
        ];
        $this->emysha = $this->person('Emysha');
        $this->adri = $this->person('Adri');
    }

    // ── 1. raise with photo, board + T.A.A. card ─────────────────────

    public function test_acceptance_1_emysha_raises_coffee_habis_with_photo_and_it_lands_on_the_board_and_mns_taa(): void
    {
        $cardsBefore = WorkItem::query()->count();

        $this->actingInTenantAs($this->emysha)
            ->post('/app/office-requests', [
                'category' => 'pantry',
                'title' => 'Coffee habis',
                'description' => 'Pantry coffee finished since Monday.',
                'location' => 'Level 2 pantry',
                'urgency' => 'normal',
                'photo' => UploadedFile::fake()->image('coffee.jpg', 640, 480),
            ])
            ->assertSessionHasNoErrors();

        $request = DB::table('office_requests')->where('title', 'Coffee habis')->first();
        $this->assertNotNull($request, 'office_requests row missing');
        $this->assertSame($this->emysha->id, (int) $request->employee_id);
        $this->assertSame('pantry', $request->category);
        $this->assertSame('open', $request->status);
        $this->assertNotNull($request->photo_path, 'photo not stored');
        $this->actingInTenantAs($this->adri)->get("/app/office-requests/{$request->id}/photo")->assertOk();

        // The requester counts as the first vote.
        $this->assertSame(1, DB::table('office_request_votes')->where('office_request_id', $request->id)->count());

        // Public board: the item sits under Open, the left panel carries the new entry.
        $this->actingInTenantAs($this->adri)->get('/app/office-requests')
            ->assertOk()
            ->assertSee('Coffee habis')
            ->assertSee('Office Requests')
            ->assertSee('Permintaan Pejabat');

        // One card on MN's T.A.A., labelled office, admin staff tagged as helpers.
        $this->assertSame($cardsBefore + 1, WorkItem::query()->count());
        $card = WorkItem::query()->findOrFail((int) $request->work_item_id);
        $this->assertSame('Coffee habis', $card->title);
        $this->assertSame($this->mn->id, $card->employee_id, 'card not owned by the Finance Manager');
        $this->assertContains('office', $card->labels ?? [], 'card missing the office label');
        $this->assertNull($card->archived_at);
        $helpers = $card->participants()->wherePivot('role', 'helper')->pluck('employees.id')->sort()->values()->all();
        $this->assertSame(collect($this->adminStaff)->pluck('id')->sort()->values()->all(), $helpers);

        // The T.A.A. card is visible on MN's board and on a helper's board.
        $this->actingInTenantAs($this->mn)->get('/app/board')->assertOk()->assertSee('Coffee habis');
        $this->actingInTenantAs($this->adminStaff[0])->get('/app/board')->assertOk()->assertSee('Coffee habis');

        // Global clause: raising a request is audited.
        $this->assertTrue(
            AuditLog::where('tenant_id', $this->tenant()->id)->where('subject_id', (int) $request->id)
                ->where('subject_type', 'like', '%OfficeRequest%')->exists(),
            'raise not audited'
        );
    }

    // ── 2. duplicate check and +1 ─────────────────────────────────────

    public function test_acceptance_2_adri_sees_the_existing_request_and_plus_ones_it_to_two_votes(): void
    {
        $id = $this->raise($this->emysha, 'Coffee habis');

        // The duplicate check names the existing open request before Adri submits.
        $similar = $this->actingInTenantAs($this->adri)
            ->getJson('/app/office-requests/similar?title='.urlencode('coffee habis'))
            ->assertOk()
            ->json();
        $this->assertSame([$id], array_map(fn ($r) => (int) $r['id'], $similar));
        $this->assertSame(1, (int) $similar[0]['votes']);

        $this->actingInTenantAs($this->adri)
            ->postJson("/app/office-requests/{$id}/upvote")
            ->assertOk()
            ->assertJson(['votes' => 2]);
        $this->assertSame(2, DB::table('office_request_votes')->where('office_request_id', $id)->count());

        // A second +1 by the same person does not double count.
        $this->actingInTenantAs($this->adri)->postJson("/app/office-requests/{$id}/upvote")->assertOk();
        $this->assertSame(2, DB::table('office_request_votes')->where('office_request_id', $id)->count());
        $this->assertSame(2, (int) DB::table('office_requests')->where('id', $id)->value('votes'));

        // No second request was raised.
        $this->assertSame(1, DB::table('office_requests')->where('title', 'Coffee habis')->count());
        $this->actingInTenantAs($this->adri)->get('/app/office-requests')->assertOk()->assertSee('Coffee habis');
    }

    // ── 3. admin note, Done, notifications, reopen window ────────────

    public function test_acceptance_3_admin_note_and_done_notify_emysha_and_adri_and_emysha_can_reopen_within_three_days(): void
    {
        $id = $this->raise($this->emysha, 'Coffee habis');
        $this->actingInTenantAs($this->adri)->postJson("/app/office-requests/{$id}/upvote")->assertOk();

        // Plain staff outside the admin team cannot note or close.
        $this->actingInTenantAs($this->adri)->postJson("/app/office-requests/{$id}/admin-note", ['note' => 'nope'])->assertStatus(403);
        $this->actingInTenantAs($this->adri)->postJson("/app/office-requests/{$id}/done", ['note' => 'nope'])->assertStatus(403);

        $admin = $this->adminStaff[1];
        $this->actingInTenantAs($admin)
            ->postJson("/app/office-requests/{$id}/admin-note", ['note' => 'Ordered, Thu'])
            ->assertOk();
        $this->assertSame('Ordered, Thu', DB::table('office_requests')->where('id', $id)->value('admin_note'));
        $this->actingInTenantAs($this->emysha)->get('/app/office-requests')->assertOk()->assertSee('Ordered, Thu');

        // Done needs a closing note; nothing auto-closes.
        $this->actingInTenantAs($admin)->postJson("/app/office-requests/{$id}/done", [])->assertStatus(422);

        Carbon::setTestNow('2026-09-03 15:00:00');
        $this->actingInTenantAs($admin)
            ->postJson("/app/office-requests/{$id}/done", ['note' => 'Restocked two tins.'])
            ->assertOk();

        $request = DB::table('office_requests')->where('id', $id)->first();
        $this->assertSame('done', $request->status);
        $this->assertSame('2026-09-03 15:00', Carbon::parse($request->done_at)->format('Y-m-d H:i'));
        $this->assertSame('Restocked two tins.', $request->closing_note);
        $this->assertSame('done', WorkItem::query()->findOrFail((int) $request->work_item_id)->status);

        // Requester and every upvoter are told.
        foreach ([$this->emysha, $this->adri] as $person) {
            $this->assertTrue(
                DB::table('app_notifications')->where('user_id', $person->user_id)->where('title', 'like', '%Coffee habis%')->exists(),
                "{$person->name} not notified"
            );
        }

        $this->assertTrue(
            AuditLog::where('tenant_id', $this->tenant()->id)->where('subject_id', $id)
                ->where('subject_type', 'like', '%OfficeRequest%')->where('action', 'like', '%done%')->exists(),
            'done not audited'
        );

        // Only the requester may reopen, and only within three days of Done.
        $this->actingInTenantAs($this->adri)->postJson("/app/office-requests/{$id}/reopen")->assertStatus(403);

        Carbon::setTestNow('2026-09-06 10:00:00');
        $this->actingInTenantAs($this->emysha)->postJson("/app/office-requests/{$id}/reopen")->assertOk();
        $request = DB::table('office_requests')->where('id', $id)->first();
        $this->assertSame('open', $request->status);
        $this->assertNull($request->done_at);
        $this->assertNotSame('done', WorkItem::query()->findOrFail((int) $request->work_item_id)->status);
        $this->assertSame(0, WorkItem::query()->where('title', 'Coffee habis')->where('id', '!=', (int) $request->work_item_id)->count(), 'reopen must reuse the card, not mint a second one');

        // Closed again, then reopen after the window is refused.
        $this->actingInTenantAs($admin)->postJson("/app/office-requests/{$id}/done", ['note' => 'Restocked again.'])->assertOk();
        Carbon::setTestNow('2026-09-10 10:00:00');
        $this->actingInTenantAs($this->emysha)->postJson("/app/office-requests/{$id}/reopen")->assertStatus(422);
        $this->assertSame('done', DB::table('office_requests')->where('id', $id)->value('status'));
    }

    // ── 4. urgent request notifies MN and the Director ───────────────

    public function test_acceptance_4_urgent_aircond_leaking_notifies_mn_and_the_director_immediately(): void
    {
        // Urgent needs a reason.
        $this->actingInTenantAs($this->emysha)
            ->postJson('/app/office-requests', [
                'category' => 'facilities', 'title' => 'Aircond leaking Level 3',
                'description' => 'Water dripping onto the desks.', 'location' => 'Level 3', 'urgency' => 'urgent',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['urgency_reason']);

        $this->actingInTenantAs($this->emysha)
            ->postJson('/app/office-requests', [
                'category' => 'facilities', 'title' => 'Aircond leaking Level 3',
                'description' => 'Water dripping onto the desks.', 'location' => 'Level 3',
                'urgency' => 'urgent', 'urgency_reason' => 'Water near power sockets.',
            ])
            ->assertSuccessful();

        $request = DB::table('office_requests')->where('title', 'Aircond leaking Level 3')->first();
        $this->assertNotNull($request);
        $this->assertSame('urgent', $request->urgency);
        $this->assertSame('Water near power sockets.', $request->urgency_reason);

        // Same request, no queue, no mail: the rows exist before the response returns.
        foreach ([$this->mn, $this->director] as $person) {
            $this->assertSame(
                1,
                DB::table('app_notifications')->where('user_id', $person->user_id)->where('title', 'like', '%Aircond leaking Level 3%')->count(),
                "{$person->name} not notified"
            );
        }
        $this->assertSame(0, DB::table('app_notifications')->where('user_id', $this->adri->user_id)->count(), 'bystander notified');

        // Urgent cards are high priority on MN's T.A.A.
        $card = WorkItem::query()->findOrFail((int) $request->work_item_id);
        $this->assertSame($this->mn->id, $card->employee_id);
        $this->assertSame('high', $card->priority);

        // A normal request does not page MN or the Director.
        $this->raise($this->adri, 'Printer toner low');
        $this->assertSame(1, DB::table('app_notifications')->where('user_id', $this->director->user_id)->count());
    }

    // ── 5. Insights for September ────────────────────────────────────

    public function test_acceptance_5_insights_shows_twelve_requests_in_september_and_an_average_of_one_point_eight_days_to_close(): void
    {
        $admin = $this->adminStaff[0];
        $categories = ['facilities', 'vehicle', 'pantry', 'it', 'cleaning', 'other'];

        // Twelve requests raised through September, closed 1.0 or 2.6 days later:
        // (6 × 1.0 + 6 × 2.6) / 12 = 1.8 days.
        for ($i = 0; $i < 12; $i++) {
            $raisedAt = Carbon::parse('2026-09-01 09:00:00')->addDays($i * 2);
            Carbon::setTestNow($raisedAt);
            $id = $this->raise($i % 2 ? $this->adri : $this->emysha, "September request {$i}", $categories[$i % 6]);
            Carbon::setTestNow($raisedAt->copy()->addMinutes($i % 2 ? 3744 : 1440));
            $this->actingInTenantAs($admin)->postJson("/app/office-requests/{$id}/done", ['note' => 'Sorted.'])->assertOk();
        }

        // Two upvotes on request 4 make it the top-voted item.
        $top = (int) DB::table('office_requests')->where('title', 'September request 4')->value('id');
        $this->actingInTenantAs($this->adri)->postJson("/app/office-requests/{$top}/upvote")->assertOk();
        $this->actingInTenantAs($admin)->postJson("/app/office-requests/{$top}/upvote")->assertOk();

        // An August request must not leak into September.
        Carbon::setTestNow('2026-08-20 09:00:00');
        $this->raise($this->emysha, 'August leftover');

        Carbon::setTestNow('2026-10-01 09:00:00');

        // Plain staff cannot read Insights; PM and above can.
        $this->actingInTenantAs($this->emysha)->get('/app/office-requests/insights?month=2026-09')->assertStatus(403);

        $json = $this->actingInTenantAs($this->mn)
            ->getJson('/app/office-requests/insights?month=2026-09')
            ->assertOk()
            ->json();
        $this->assertSame(12, (int) $json['requests']);
        $this->assertEqualsWithDelta(1.8, (float) $json['avg_days_to_close'], 0.01);
        $this->assertSame(['cleaning' => 2, 'facilities' => 2, 'it' => 2, 'other' => 2, 'pantry' => 2, 'vehicle' => 2], collect($json['by_category'])->map(fn ($n) => (int) $n)->sortKeys()->all());
        $this->assertSame('September request 4', $json['top_voted'][0]['title']);
        $this->assertSame(3, (int) $json['top_voted'][0]['votes']);

        $this->actingInTenantAs($this->director)
            ->get('/app/office-requests/insights?month=2026-09')
            ->assertOk()
            ->assertSee('12')
            ->assertSee('1.8')
            ->assertSee('September request 4');

        // Done requests count as ordinary completed cards (Done & Dusted reads work_items).
        $this->assertSame(12, WorkItem::query()->where('status', 'done')->whereJsonContains('labels', 'office')->count());
    }

    // ── every-session checks ─────────────────────────────────────────

    public function test_always_checks(): void
    {
        $this->assertDueDateLocked();
        $this->assertAuditLogImmutable();
        $this->assertDashboardUnchanged();
        $this->assertKeepItPlainHonoured();
    }

    // ── helpers ──────────────────────────────────────────────────────

    /** Raise a normal-urgency request and return its id. */
    private function raise(Employee $by, string $title, string $category = 'pantry'): int
    {
        $this->actingInTenantAs($by)
            ->postJson('/app/office-requests', [
                'category' => $category,
                'title' => $title,
                'description' => "{$title}, please.",
                'location' => 'Level 2',
                'urgency' => 'normal',
            ])
            ->assertSuccessful();

        return (int) DB::table('office_requests')->where('title', $title)->orderByDesc('id')->value('id');
    }
}
