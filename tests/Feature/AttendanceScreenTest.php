<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AttendanceScreenTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-07-15 10:00:00'));

        $this->tenant = Tenant::create([
            'slug' => 'alpha',
            'name' => 'Alpha',
            'initials' => 'AL',
        ]);

        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => Hash::make('password'),
        ]);

        $this->user->tenants()->attach($this->tenant->id, ['role' => 'employee']);

        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'name' => 'Test User',
            'status' => 'active',
            'workload' => 'green',
        ]);
    }

    public function test_screen_renders_the_shelf_and_week_list_for_an_employee(): void
    {
        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'date' => now()->toDateString(),
            'status' => 'on_time',
            'clock_in' => '09:00:00',
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance');

        $response->assertOk();
        $response->assertSee('uj-at-shelf');
        $response->assertSee('uj-at-day');
    }

    public function test_week_list_shows_only_current_week_rows(): void
    {
        $thisWeekRecord = AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'date' => now()->startOfWeek()->addDay()->toDateString(), // 2026-07-14 (Tue)
            'status' => 'on_time',
        ]);

        $olderRecord = AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'date' => now()->startOfWeek()->subDays(5)->toDateString(), // 2026-07-08 (Wed)
            'status' => 'on_time',
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance');

        $response->assertOk();

        // Assert the older record is present in the `earlierRecords` view data, not `weekRecords`
        $weekRecords = $response->viewData('weekRecords');
        $earlierRecords = $response->viewData('earlierRecords');
        $this->assertTrue(
            $weekRecords->contains(fn ($r) => $r->id === $thisWeekRecord->id),
            'The current week record must be in weekRecords.'
        );
        $this->assertFalse(
            $weekRecords->contains(fn ($r) => $r->id === $olderRecord->id),
            'The older record must not be in weekRecords.'
        );
        $this->assertTrue(
            $earlierRecords->contains(fn ($r) => $r->id === $olderRecord->id),
            'The older record must be present in earlierRecords view data.'
        );

        // Assert day 14 (this week) appears in a tile
        $response->assertSee('<span class="d">14</span>', false);
    }

    public function test_empty_week_shows_the_empty_state(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance');

        $response->assertOk();
        $response->assertSee('uj-at-empty');
        $response->assertDontSee('uj-at-day');
    }

    public function test_see_all_link_is_gated_on_permission(): void
    {
        // Plain employee should NOT see the see-all link (attendance-report)
        $responseEmp = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance');

        $responseEmp->assertOk();
        $responseEmp->assertDontSee('attendance-report');

        // HR user SHOULD see the see-all link
        $hrUser = User::create([
            'name' => 'HR User',
            'email' => 'hr@example.com',
            'password' => Hash::make('password'),
        ]);

        $hrUser->tenants()->attach($this->tenant->id, ['role' => 'hr']);

        Employee::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $hrUser->id,
            'name' => 'HR User',
            'status' => 'active',
            'workload' => 'green',
        ]);

        $responseHr = $this->actingAs($hrUser)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance');

        $responseHr->assertOk();
        $responseHr->assertSee('attendance-report');
    }

    public function test_clock_form_keeps_its_post_contract(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance');

        $response->assertOk();
        $response->assertSee('action="'.route('attendance.clock').'"', false);
        $response->assertSee('name="action"', false);
        $response->assertSee('name="latitude"', false);
        $response->assertSee('name="longitude"', false);
        $response->assertSee('name="photo"', false);
        $response->assertSee('name="justification"', false);
    }

    /**
     * Smoke check only: it proves the accuracy-aware locator is wired into the screen, not
     * that it picks the better fix — that logic is browser-side and PHPUnit cannot exercise
     * it. A cold GPS reports a coarse network fix first and refines seconds later, so the
     * screen must not punch on the first fix or on a cached one; verify that behaviour by
     * hand against the geofence, per the sequence in the release notes.
     */
    public function test_screen_ships_the_accuracy_aware_locator(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance');

        $response->assertOk();
        $response->assertSee('bestFix(', false);
        $response->assertSee('maximumAge: 0', false);
    }

    /**
     * Smoke check only, same limit as the locator test above: it proves both selfie paths go
     * through the one capped draw rather than onto the form at full size. The size they come
     * out at is browser-side and PHPUnit cannot measure it — a 4032x3024 camera JPEG
     * re-encodes to well under 1MB in a real browser, verified by hand.
     *
     * Production runs stock PHP, so upload_max_filesize is 2MB there and a raw phone photo
     * never survives it. The camera path is capped for the same reason: an unconstrained
     * getUserMedia stream can hand back 4K.
     */
    public function test_both_selfie_paths_are_capped_before_they_reach_the_form(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance');

        $response->assertOk();
        $response->assertSee('@change="attachFile($event.target.files[0])"', false);
        $response->assertSee('this.drawScaled(img, img.width, img.height)', false);
        $response->assertSee('this.drawScaled(v, v.videoWidth, v.videoHeight)', false);
        $response->assertSee('Math.min(1, 1600 / Math.max(width, height))', false);
    }

    /**
     * The clock-without-location punch is a from-anywhere punch, so it stays out of reach
     * until a real attempt has failed: `attempted` starts false, and the punch button only
     * becomes the no-location one through `noLoc`, which needs both a real attempt and a
     * location error. It is never a second button sitting beside the ordinary one.
     */
    public function test_no_location_punch_is_gated_behind_an_actual_attempt(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance');

        $response->assertOk();
        $response->assertSee('attempted: false', false);
        $response->assertSee('get noLoc()', false);
        $response->assertSee('noLoc ? submitWithoutLocation() : submit()', false);
    }

    /** One punch button, whose label always names what a tap actually does. */
    public function test_the_screen_has_a_single_punch_button_per_layout(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance');

        $response->assertOk();
        // One in the shelf (desktop) and one in the dock (mobile); CSS shows exactly one.
        $this->assertSame(2, substr_count($response->getContent(), 'x-text="goLabel($store.ui.lang)"'));
    }

    public function test_status_tone_is_correct_on_every_row(): void
    {
        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'date' => now()->startOfWeek()->toDateString(),
            'status' => 'on_time',
        ]);

        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'date' => now()->startOfWeek()->addDay()->toDateString(),
            'status' => 'late',
        ]);

        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'date' => now()->startOfWeek()->addDays(2)->toDateString(),
            'status' => 'on_time',
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance');

        $response->assertOk();
        $content = (string) $response->getContent();
        $this->assertSame(
            2,
            substr_count($content, 'class="uj-at-status" data-tone="amber"'),
            'Every row status pill must carry its own tone; a shared variable overwritten in the loop makes later rows fall back to muted.'
        );
        $this->assertSame(
            4,
            substr_count($content, 'class="uj-at-status" data-tone="success"'),
            'Every row status pill must carry its own tone; a shared variable overwritten in the loop makes later rows fall back to muted.'
        );
    }

    public function test_geofence_sentence_is_not_claimed_without_evidence(): void
    {
        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'date' => now()->startOfWeek()->toDateString(),
            'in_radius' => null,
            'out_radius' => null,
            'flags' => [],
            'location' => null,
            'status' => 'on_time',
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance');

        $response->assertOk();
        $response->assertSee('Location was not recorded.');
        $response->assertDontSee('inside the expected geofence');
    }

    public function test_geofence_sentence_reports_inside_when_both_punches_were_inside(): void
    {
        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'date' => now()->startOfWeek()->toDateString(),
            'in_radius' => true,
            'out_radius' => true,
            'flags' => [],
            'status' => 'on_time',
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance');

        $response->assertOk();
        $response->assertSee('Both punches landed inside the expected geofence');
        $response->assertDontSee('outside the expected geofence');
    }

    public function test_site_visit_sentence_names_the_declared_destination(): void
    {
        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'date' => '2026-07-15',
            'status' => 'on_time',
            'clock_in' => '09:00:00',
            'work_mode' => 'site_visit',
            'clock_in_justification' => 'Customer ABC, Shah Alam',
            'clock_out_justification' => 'left early, traffic',
            'in_radius' => false,
            'flags' => ['site_visit_in'],
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance');

        $response->assertOk();
        $response->assertSee('Site visit: Customer ABC, Shah Alam.');
        $response->assertDontSee('outside the expected geofence');
    }

    /**
     * A day that starts as a normal (possibly late) office clock-in and ends as a
     * declared site visit carries two remarks. clock_in_justification belongs to
     * the clock-in reason, not the destination, so the sentence must name the
     * clock-out remark, never fall back to whichever one happens to be non-empty.
     */
    public function test_site_visit_sentence_on_clock_out_names_the_clock_out_destination_not_the_clock_in_remark(): void
    {
        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'date' => '2026-07-15',
            'status' => 'late',
            'clock_in' => '09:30:00',
            'clock_out' => '18:00:00',
            'work_mode' => 'office_home',
            'clock_in_justification' => 'delayed due to traffic',
            'clock_out_work_mode' => 'site_visit',
            'clock_out_justification' => 'Client X, KL',
            'flags' => ['late'],
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance');

        $response->assertOk();
        $response->assertSee('Site visit: Client X, KL.');
        $response->assertDontSee('Site visit: delayed due to traffic.');
    }

    public function test_earlier_days_does_not_link_to_the_staff_report(): void
    {
        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'date' => now()->startOfWeek()->toDateString(),
            'status' => 'on_time',
        ]);

        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'date' => now()->startOfWeek()->subDays(5)->toDateString(),
            'status' => 'on_time',
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance');

        $response->assertOk();
        $response->assertDontSee(route('app.screen', 'attendance-report'));
    }

    /**
     * A day row carries exactly one status pill, rendered twice so the stylesheet can show
     * the mobile copy or the desktop copy. The row used to print one pill per flag plus the
     * status on top, which is what buried the punch button under five things to read.
     */
    public function test_day_row_carries_one_pill_however_many_flags_the_day_has(): void
    {
        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'date' => now()->toDateString(),
            'status' => 'late',
            'clock_in' => '11:05:00',
            'clock_out' => '11:45:00',
            'flags' => ['late', 'no_location', 'early_out', 'short_hours'],
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance');

        $response->assertOk();
        $this->assertSame(
            2,
            substr_count($response->getContent(), 'uj-at-status'),
            'One day row must render one pill, twice — once for each breakpoint.'
        );
    }

    public function test_a_multi_flag_day_counts_every_flag_and_lists_them_on_expand(): void
    {
        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'date' => now()->toDateString(),
            'status' => 'late',
            'clock_in' => '11:05:00',
            'clock_out' => '11:45:00',
            'flags' => ['late', 'no_location', 'early_out', 'short_hours'],
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance');

        $response->assertOk();
        // The count must cover 'late' too: this pill replaces the status pill rather than
        // sitting beside it, so a number that skipped 'late' would not match the list below.
        $response->assertSee('4 flags');
        $response->assertSee('uj-at-flags');
        foreach (['Late', 'No location', 'Left early', 'Short hours'] as $label) {
            $response->assertSee($label);
        }
    }

    public function test_a_single_flag_day_names_the_flag_instead_of_counting_it(): void
    {
        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'date' => now()->toDateString(),
            'status' => 'on_time',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
            'flags' => ['out_of_radius_in'],
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance');

        $response->assertOk();
        $response->assertSee('Off-site clock-in');
        $response->assertDontSee('1 flags');
    }

    public function test_an_unflagged_day_falls_back_to_its_status_pill(): void
    {
        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'date' => now()->toDateString(),
            'status' => 'on_time',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
            'flags' => [],
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance');

        $response->assertOk();
        $response->assertSee('On time');
        // No count pill and no flag list — the day has nothing to enumerate.
        $response->assertDontSee('uj-at-flags');
    }

    /**
     * A day flagged only for lateness is amber, not red: red is reserved for the punches
     * that need a manager to look at them (off-site, early out, no location).
     */
    public function test_a_late_only_day_stays_amber(): void
    {
        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'date' => now()->toDateString(),
            'status' => 'late',
            'clock_in' => '11:00:00',
            'clock_out' => '19:00:00',
            'flags' => ['late'],
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance');

        $response->assertOk();
        $response->assertSee('uj-at-status" data-tone="amber"', false);
        $response->assertDontSee('uj-at-status" data-tone="red"', false);
    }

    public function test_status_card_carries_the_punched_flag_right_after_a_successful_punch(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id, 'clock_ok' => 'Clocked in at 09:02.'])
            ->get('/app/attendance');

        $response->assertSee('justPunched: true', false);
    }

    public function test_status_card_does_not_carry_the_punched_flag_on_a_plain_visit(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance');

        $response->assertSee('justPunched: false', false);
    }

    /**
     * A shift clocked in at 23:00 leaves its open record dated *yesterday*, so the shelf's
     * "today" lookup found nothing after midnight and offered "Clock in" to someone who was
     * mid-shift — which posted action=in and opened a second record for the same shift.
     * Same rationale, and the same yesterday-or-today bound, as ClockService::clockOut().
     */
    public function test_shelf_shows_the_open_overnight_punch_after_midnight(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-16 01:30:00'));

        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'date' => '2026-07-15',
            'status' => 'late',
            'clock_in' => '23:00:00',
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance');

        $response->assertOk();
        $response->assertSee('in since <b>23:00</b>', false);
        $response->assertSee('name="action" value="out"', false);
        $response->assertSee('clockInWasYesterday: true', false);
    }

    /**
     * The sidebar quick-action dock reads the same overnight punch wrong: its record is
     * dated *yesterday*, so an "on today" lookup showed "not clocked in" and offered
     * "Clock in" to someone mid-shift. Same yesterday-or-today bound as the shelf.
     */
    public function test_quick_action_dock_shows_the_open_overnight_punch_after_midnight(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-16 01:30:00'));

        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'date' => '2026-07-15',
            'status' => 'late',
            'clock_in' => '23:00:00',
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance');

        $response->assertOk();
        $response->assertSee('<span class="uj-sb-clock">23:00</span>', false);
        $response->assertSee("'clocked in' : 'sudah masuk'", false);
        $response->assertSee("'Clock out' : 'Clock-out'", false);
        $response->assertDontSee("'not clocked in' : 'belum masuk'", false);
    }

    public function test_the_screen_offers_the_work_mode_pill(): void
    {
        $html = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance')->assertOk()->getContent();

        $this->assertStringContainsString('uj-at-mode', $html);
        $this->assertStringContainsString('name="work_mode"', $html);
        $this->assertStringContainsString('Site visit', $html);
        $this->assertStringContainsString('Lawatan tapak', $html);
    }

    public function test_the_inline_remark_drawer_is_gone(): void
    {
        $html = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance')->assertOk()->getContent();

        $this->assertStringNotContainsString('data-notebtn', $html);
        $this->assertStringNotContainsString('uj-at-note', $html);
        $this->assertStringNotContainsString('attendance-remarks', $html);
    }

    /**
     * The reason box that posts must be exactly one box. Two would mean the drawer survived;
     * zero would mean the name attribute was deleted along with the drawer, and every typed
     * reason would vanish on submit while the screen still looked correct.
     */
    public function test_a_reason_typed_in_the_sheet_reaches_the_server(): void
    {
        $html = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'name="justification"'));
        $this->assertStringContainsString('attendance-sheet-reason', $html);

        // And the attribute is on the sheet's textarea, not somewhere else on the page.
        $this->assertMatchesRegularExpression(
            '/id="attendance-sheet-reason"[^>]*name="justification"|name="justification"[^>]*id="attendance-sheet-reason"/',
            $html
        );
    }

    public function test_the_sheet_carries_site_visit_copy_in_both_languages(): void
    {
        $html = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance')->assertOk()->getContent();

        // The site_visit branch exists in each of the sheet's four copy methods.
        $this->assertStringContainsString('Where are you going?', $html);
        $this->assertStringContainsString('Ke mana anda pergi?', $html);
        $this->assertStringContainsString('Tell your manager where you are going', $html);
        // Every sibling reason kind (off_site, no_location, late, early) appears twice per
        // method too, once in the `en` map and once in the `ms` map, so 4 methods land on 8,
        // not 4; confirmed against the existing `off_site:` count in the same file.
        $this->assertSame(
            8,
            substr_count($html, 'site_visit:'),
            'sheetTitle, sheetBody, sheetReasonLabel and sheetReasonPlaceholder each need a site_visit case in both language maps'
        );
    }

    /**
     * x-teleport moves the sheet (and its reason textarea) out of the <form> element and into
     * <body>, which strips native form association from any control inside it. The browser
     * silently drops the field from submission, so a typed reason never reaches the server no
     * matter how it looks in the DOM. The `form` attribute is the fix: it re-associates a
     * control with a form elsewhere in the document by id, regardless of DOM position.
     */
    public function test_the_teleported_reason_box_stays_associated_with_the_clock_form(): void
    {
        $html = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance')->assertOk()->getContent();

        $this->assertStringContainsString('id="attendance-clock-form"', $html);
        $this->assertStringContainsString('form="attendance-clock-form"', $html);
    }

    public function test_the_location_badge_is_tappable_and_has_a_failure_state(): void
    {
        $html = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance')->assertOk()->getContent();

        $this->assertStringContainsString('recheckFence()', $html);
        $this->assertStringContainsString('Location not found', $html);
        $this->assertStringContainsString('Lokasi tidak dijumpai', $html);
    }
}
