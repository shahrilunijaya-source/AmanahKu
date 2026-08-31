<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\Project;
use App\Models\SubPillar;
use App\Models\TimesheetCategory;
use App\Services\FeatureManager;
use App\Support\ApiCaller;
use App\Tenancy\CurrentTenant;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Reference data — categories, projects and sub-pillars — sourced exactly the
 * way TimesheetController::screenData() builds the capture screen's pickers
 * (categoryOptions(), projectOptions()), so a model never invents an id that
 * the screen itself wouldn't offer.
 *
 * Board-first (commit 84dc9cf): a timesheet row names a board card
 * (work_item_id), not a category/project pair — save_timesheet_draft reads
 * both off the card, it does not accept them here. sub_pillar_id is the one
 * thing a caller still picks per row. Categories and projects stay useful for
 * two things this tool still serves: reading a report intelligibly (what does
 * category X mean), and setting a card's own timesheet_category_id via
 * create_card / update_card — but never for building a timesheet row.
 *
 * This is reference data, not personal data: every employee already picks
 * from these same lists in the app, so unlike TimesheetWeekTool this is never
 * narrowed by role.
 */
#[Name('timesheet_options')]
#[IsReadOnly]
#[Description('List the timesheet categories, projects and sub-pillars this tenant uses — the same options the timesheet screen and the board drawer offer. Categories and projects are reference/lookup data: useful for reading a timesheet report, or for choosing timesheet_category_id when calling create_card/update_card. They are NOT for building a timesheet row — save_timesheet_draft takes a board card (work_item_id) and reads its category and project off the card itself. sub_pillar_id is the only field here a timesheet row still picks. A full day is percentage 100.')]
class TimesheetOptionsTool extends Tool
{
    public function handle(Request $request): Response
    {
        $httpRequest = request();

        if (! ApiCaller::can($httpRequest, 'timesheets:read')) {
            return Response::error('This token lacks the timesheets:read scope.');
        }

        $tenant = app(CurrentTenant::class)->get();
        $leaveModuleOn = app(FeatureManager::class)->enabled($tenant, 'module.leave');

        $categories = TimesheetCategory::where('is_active', true)->orderBy('sort')->orderBy('name')->get()
            ->map(fn (TimesheetCategory $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'requires_project' => (bool) $c->requires_project,
            ])
            ->reject(fn (array $c) => $leaveModuleOn && in_array($c['name'], ['On Leave', 'Public Holiday'], true))
            ->values();

        $projects = Project::where('is_active', true)->orderBy('sort')->orderBy('name')->get()
            ->map(fn (Project $p) => [
                'id' => $p->id,
                'code' => $p->code,
                'name' => $p->name,
            ])->values();

        $subPillars = SubPillar::where('is_active', true)->orderBy('sort')->orderBy('name')->get()
            ->map(fn (SubPillar $s) => ['id' => $s->id, 'name' => $s->name])->values();

        return Response::json([
            'categories' => $categories,
            'projects' => $projects,
            'sub_pillars' => $subPillars,
        ]);
    }
}
