<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Project;
use App\Models\SubPillar;
use App\Models\Tenant;
use App\Models\Timesheet;
use App\Models\TimesheetCategory;
use App\Models\TimesheetEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The Projects register: everyone reads it, manager / management / HR write to
 * it. Projects used to be created on one screen (manager-facing, ungated write)
 * and edited on another (management/HR only, buried in Timesheet Setup).
 */
class ProjectScreenTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
    }

    /**
     * This screen is the source of truth for which categories a project falls under, so
     * retagging it has to reach the board cards booked to it. A card left holding a
     * category the project no longer offers would be invisible — its drawer stops
     * offering that value — while still costing every timesheet line the card produces.
     */
    public function test_retagging_a_project_clears_card_categories_it_no_longer_offers(): void
    {
        $dev = TimesheetCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Development', 'requires_project' => true]);
        $sales = TimesheetCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Sales', 'requires_project' => true]);
        $project = Project::create(['tenant_id' => $this->tenant->id, 'name' => 'SPA: IRIS', 'is_active' => true]);
        $project->categories()->sync([$dev->id, $sales->id]);

        $employee = Employee::where('user_id', $this->actorWithRole('manager')->id)->sole();
        $stale = $employee->workItems()->create([
            'tenant_id' => $this->tenant->id, 'title' => 'Sold work', 'type' => 'task',
            'priority' => 'low', 'status' => 'prog', 'progress' => 0,
            'project_id' => $project->id, 'timesheet_category_id' => $sales->id,
        ]);
        $kept = $employee->workItems()->create([
            'tenant_id' => $this->tenant->id, 'title' => 'Built work', 'type' => 'task',
            'priority' => 'low', 'status' => 'prog', 'progress' => 0,
            'project_id' => $project->id, 'timesheet_category_id' => $dev->id,
        ]);

        $this->actingAsRole('manager')
            ->post(route('projects.update', $project), ['name' => 'SPA: IRIS', 'categories' => [$dev->id]])
            ->assertRedirect();

        $this->assertNull($stale->fresh()->timesheet_category_id);
        $this->assertSame($dev->id, $kept->fresh()->timesheet_category_id);
    }

    /** Untagging a project says nothing, not "none" — its cards keep what they had. */
    public function test_untagging_a_project_leaves_its_cards_alone(): void
    {
        $dev = TimesheetCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Development', 'requires_project' => true]);
        $project = Project::create(['tenant_id' => $this->tenant->id, 'name' => 'SPA: IRIS', 'is_active' => true]);
        $project->categories()->sync([$dev->id]);

        $employee = Employee::where('user_id', $this->actorWithRole('manager')->id)->sole();
        $card = $employee->workItems()->create([
            'tenant_id' => $this->tenant->id, 'title' => 'Built work', 'type' => 'task',
            'priority' => 'low', 'status' => 'prog', 'progress' => 0,
            'project_id' => $project->id, 'timesheet_category_id' => $dev->id,
        ]);

        $this->actingAsRole('manager')
            ->post(route('projects.update', $project), ['name' => 'SPA: IRIS'])
            ->assertRedirect();

        $this->assertSame($dev->id, $card->fresh()->timesheet_category_id);
    }

    /** Idempotent: a test may act as the same role more than once in one case. */
    private function actorWithRole(string $role): User
    {
        if ($existing = User::where('email', $role.'@example.com')->first()) {
            return $existing;
        }

        $user = User::create([
            'name' => ucfirst($role),
            'email' => $role.'@example.com',
            'password' => Hash::make('password'),
        ]);
        $user->tenants()->attach($this->tenant->id, ['role' => $role]);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => ucfirst($role), 'status' => 'active', 'workload' => 'green',
        ]);

        return $user;
    }

    private function actingAsRole(string $role): self
    {
        $this->actingAs($this->actorWithRole($role))->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    public function test_a_manager_can_create_a_project(): void
    {
        $this->actingAsRole('manager')
            ->post(route('projects.store'), ['name' => 'KPT: RMS', 'code' => 'KPT'])
            ->assertRedirect();

        $this->assertDatabaseHas('projects', [
            'tenant_id' => $this->tenant->id, 'name' => 'KPT: RMS', 'is_active' => true,
        ]);
    }

    public function test_an_employee_cannot_create_a_project(): void
    {
        $this->actingAsRole('employee')
            ->post(route('projects.store'), ['name' => 'KPT: RMS'])
            ->assertForbidden();

        $this->assertDatabaseMissing('projects', ['name' => 'KPT: RMS']);
    }

    public function test_an_employee_cannot_create_a_sub_pillar(): void
    {
        $this->actingAsRole('employee')
            ->post(route('sub-pillars.store'), ['name' => 'Technical'])
            ->assertForbidden();

        $this->assertDatabaseMissing('sub_pillars', ['name' => 'Technical']);
    }

    public function test_a_manager_can_add_and_rename_a_sub_pillar_and_remove_an_unused_one(): void
    {
        $this->actingAsRole('manager')
            ->post(route('sub-pillars.store'), ['name' => 'Technical'])
            ->assertRedirect();

        $sub = SubPillar::where('name', 'Technical')->firstOrFail();

        $this->actingAsRole('manager')
            ->post(route('sub-pillars.update', $sub), ['name' => 'Technical work', 'is_active' => 1])
            ->assertRedirect();

        $this->assertSame('Technical work', $sub->fresh()->name);

        $this->actingAsRole('manager')
            ->post(route('sub-pillars.delete', $sub))
            ->assertRedirect();

        $this->assertDatabaseMissing('sub_pillars', ['id' => $sub->id]);
    }

    public function test_a_sub_pillar_in_use_is_deactivated_rather_than_deleted(): void
    {
        $sub = SubPillar::create(['tenant_id' => $this->tenant->id, 'name' => 'Technical']);
        $project = Project::create(['tenant_id' => $this->tenant->id, 'name' => 'KPT: RMS']);
        $category = TimesheetCategory::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Development', 'requires_project' => true,
        ]);
        $booker = $this->actorWithRole('employee');
        $employee = Employee::where('user_id', $booker->id)->firstOrFail();
        $timesheet = Timesheet::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $employee->id,
            'week_start' => '2026-06-15', 'status' => 'draft', 'total_hours' => 8,
        ]);
        TimesheetEntry::create([
            'tenant_id' => $this->tenant->id, 'timesheet_id' => $timesheet->id,
            'entry_date' => '2026-06-15', 'category_id' => $category->id,
            'project_id' => $project->id, 'sub_pillar_id' => $sub->id, 'percentage' => 100,
        ]);

        $this->actingAsRole('hr')->post(route('sub-pillars.delete', $sub))->assertRedirect();

        // The row survives so old timesheets and reports keep their label.
        $this->assertDatabaseHas('sub_pillars', ['id' => $sub->id, 'is_active' => false]);
    }

    public function test_a_project_in_use_keeps_its_categories_when_edited(): void
    {
        $retired = TimesheetCategory::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Retired Category',
            'requires_project' => true, 'is_active' => false,
        ]);
        $project = Project::create(['tenant_id' => $this->tenant->id, 'name' => 'KPT: RMS']);
        $project->categories()->sync([$retired->id]);

        $this->actingAsRole('manager')
            ->post(route('projects.update', $project), [
                'name' => 'KPT: RMS', 'categories' => [$retired->id],
            ])->assertRedirect();

        $this->assertSame([$retired->id], $project->fresh()->categories->pluck('id')->all());
    }

    public function test_a_project_in_use_is_deactivated_rather_than_deleted(): void
    {
        $project = Project::create(['tenant_id' => $this->tenant->id, 'name' => 'KPT: RMS']);
        $category = TimesheetCategory::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Development', 'requires_project' => true,
        ]);
        $booker = $this->actorWithRole('employee');
        $employee = Employee::where('user_id', $booker->id)->firstOrFail();
        $timesheet = Timesheet::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $employee->id,
            'week_start' => '2026-06-15', 'status' => 'draft', 'total_hours' => 8,
        ]);
        TimesheetEntry::create([
            'tenant_id' => $this->tenant->id, 'timesheet_id' => $timesheet->id,
            'entry_date' => '2026-06-15', 'category_id' => $category->id,
            'project_id' => $project->id, 'percentage' => 100,
        ]);

        $this->actingAsRole('hr')->post(route('projects.delete', $project))->assertRedirect();

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'is_active' => false]);
    }

    public function test_a_manager_can_archive_and_restore_a_project(): void
    {
        $project = Project::create(['tenant_id' => $this->tenant->id, 'name' => 'KPT: RMS']);

        $this->actingAsRole('manager')
            ->post(route('projects.archive', $project))
            ->assertRedirect();

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'is_active' => false]);

        $this->actingAsRole('manager')
            ->post(route('projects.archive', $project))
            ->assertRedirect();

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'is_active' => true]);
    }

    public function test_an_employee_cannot_archive_a_project(): void
    {
        $project = Project::create(['tenant_id' => $this->tenant->id, 'name' => 'KPT: RMS']);

        $this->actingAsRole('employee')
            ->post(route('projects.archive', $project))
            ->assertForbidden();

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'is_active' => true]);
    }

    public function test_project_ajax_add_returns_a_rendered_row(): void
    {
        $res = $this->actingAsRole('hr')->postJson(route('projects.store'), [
            'name' => 'KPT: RMS', 'code' => 'KPT', 'sort' => 0,
        ]);

        $res->assertOk()->assertJsonStructure(['html', 'count_sel']);
        $this->assertStringContainsString('KPT: RMS', $res->json('html'));
        $this->assertSame('#ts-proj-count', $res->json('count_sel'));
    }

    public function test_sub_pillar_ajax_add_returns_a_rendered_row_and_bumps_its_own_count(): void
    {
        $res = $this->actingAsRole('hr')->postJson(route('sub-pillars.store'), ['name' => 'Technical']);

        $res->assertOk();
        $this->assertStringContainsString('Technical', $res->json('html'));
        $this->assertSame('#ts-sub-count', $res->json('count_sel'));
    }

    public function test_project_categories_are_synced_on_store_and_update(): void
    {
        $dev = TimesheetCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Development', 'requires_project' => true]);
        $maint = TimesheetCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Maintenance', 'requires_project' => true]);

        $this->actingAsRole('hr')->postJson(route('projects.store'), [
            'name' => 'KPT: RMS', 'categories' => [$dev->id],
        ])->assertOk();

        $project = Project::where('name', 'KPT: RMS')->firstOrFail();
        $this->assertSame([$dev->id], $project->categories->pluck('id')->all());

        $this->actingAsRole('hr')->post(route('projects.update', $project), [
            'name' => 'KPT: RMS', 'categories' => [$maint->id],
        ])->assertRedirect();

        $this->assertSame([$maint->id], $project->fresh()->categories->pluck('id')->all());
    }

    public function test_a_validation_error_returns_422_json_not_a_redirect(): void
    {
        $this->actingAsRole('hr')->postJson(route('projects.store'), ['name' => ''])
            ->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_a_project_name_must_be_unique_within_the_tenant(): void
    {
        Project::create(['tenant_id' => $this->tenant->id, 'name' => 'KPT: RMS', 'is_active' => true]);

        $this->actingAsRole('management')
            ->post(route('projects.store'), ['name' => 'KPT: RMS'])
            ->assertSessionHasErrors('name');
    }

    public function test_an_employee_sees_the_register_with_no_write_controls(): void
    {
        Project::create(['tenant_id' => $this->tenant->id, 'name' => 'JKDM: MyStods']);
        SubPillar::create(['tenant_id' => $this->tenant->id, 'name' => 'Technical']);

        $response = $this->actingAsRole('employee')->get('/app/projects');

        $response->assertOk()
            ->assertSee('JKDM: MyStods')
            ->assertSee('Technical')
            // Not a bare assertDontSee(route('projects.store')): that URL is also the
            // screen's own GET path, so the sidebar's nav link to this very page would
            // make the assertion fail regardless of whether an add form renders. Pin
            // it to the <form action="..."> attribute so it only matches a real control.
            ->assertDontSee('action="'.route('projects.store').'"', false)
            ->assertDontSee(route('sub-pillars.store'));
    }

    public function test_a_manager_sees_the_add_forms(): void
    {
        $response = $this->actingAsRole('manager')->get('/app/projects');

        $response->assertOk()
            // See the comment above — the bare URL also matches the page's own nav
            // link, so this would have passed even with no add form rendered at all.
            ->assertSee('action="'.route('projects.store').'"', false)
            ->assertSee(route('sub-pillars.store'));
    }

    public function test_the_submit_button_label_differs_between_add_and_edit_mode(): void
    {
        Project::create(['tenant_id' => $this->tenant->id, 'name' => 'KPT: RMS']);

        $response = $this->actingAsRole('manager')->get('/app/projects');

        $response->assertOk()
            ->assertSee("\$store.ui.lang==='en' ? 'Add project' : 'Tambah projek'", false)
            ->assertSee("\$store.ui.lang==='en' ? 'Save changes' : 'Simpan perubahan'", false);
    }

    public function test_the_add_form_offers_active_categories_only(): void
    {
        TimesheetCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Development', 'requires_project' => true]);
        TimesheetCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Retired Category', 'requires_project' => true, 'is_active' => false]);

        // No project exists, so the only category chips on the page are the add form's.
        $this->actingAsRole('manager')->get('/app/projects')
            ->assertOk()
            ->assertSee('Development')
            ->assertDontSee('Retired Category');
    }

    public function test_the_register_offers_a_category_filter_chip_per_project_category_and_colours_the_row_pills(): void
    {
        $dev = TimesheetCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Development', 'requires_project' => true]);
        $sales = TimesheetCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Sales', 'requires_project' => true]);
        $project = Project::create(['tenant_id' => $this->tenant->id, 'name' => 'KPT: RMS']);
        $project->categories()->sync([$dev->id, $sales->id]);

        $html = $this->actingAsRole('employee')->get('/app/projects')->assertOk()->getContent();

        // One toggle chip per category, and each category's own colour on the row pill —
        // Sales no longer shares Development's blue now that both are project categories.
        $this->assertSame(2, substr_count($html, 'data-cat-chip'));
        $this->assertStringContainsString('cats.includes('.$dev->id.')', $html);
        $this->assertStringContainsString('color:var(--info);">Development', $html);
        $this->assertStringContainsString('color:#8a4bdb;">Sales', $html);
    }

    public function test_everyone_sees_the_projects_nav_link(): void
    {
        $this->actingAsRole('employee')
            ->get(route('app.screen', 'dash'))
            ->assertOk()
            ->assertSee(route('app.screen', ['screen' => 'projects']));
    }

    public function test_the_old_new_project_link_still_lands_on_the_register(): void
    {
        Project::create(['tenant_id' => $this->tenant->id, 'name' => 'JKDM: MyStods']);

        $this->actingAsRole('manager')
            ->get('/app/project-quick-create')
            ->assertOk()
            ->assertSee('JKDM: MyStods');
    }

    /**
     * The register used to print "18 timesheet lines · 3 board cards" under every
     * project and sub-pillar. It was noise on a screen whose job is naming projects,
     * so it went. Kept as a test rather than deleted: the counts are cheap to add back
     * by reflex, and this says the absence was a decision.
     */
    public function test_the_register_does_not_print_usage_counts(): void
    {
        $project = Project::create(['tenant_id' => $this->tenant->id, 'name' => 'JKDM: MyStods']);
        $category = TimesheetCategory::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Development', 'requires_project' => true,
        ]);
        $user = $this->actorWithRole('employee');
        $employee = Employee::where('user_id', $user->id)->firstOrFail();
        $timesheet = Timesheet::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $employee->id,
            'week_start' => '2026-06-15', 'status' => 'draft', 'total_hours' => 8,
        ]);
        TimesheetEntry::create([
            'tenant_id' => $this->tenant->id, 'timesheet_id' => $timesheet->id,
            'entry_date' => '2026-06-15', 'category_id' => $category->id,
            'project_id' => $project->id, 'percentage' => 100,
        ]);

        $this->actingAsRole('hr')->get('/app/projects')
            ->assertOk()
            ->assertSee('JKDM: MyStods')
            ->assertDontSee('timesheet lines')
            ->assertDontSee('board cards')
            ->assertDontSee('not used yet');
    }
}
