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
 * Reference data for composing a timesheet entry — categories, projects and
 * sub-pillars — sourced exactly the way TimesheetController::screenData()
 * builds the capture screen's pickers (categoryOptions(), projectOptions()),
 * so a model never invents an id that the screen itself wouldn't offer.
 *
 * This is reference data, not personal data: every employee already picks
 * from these same lists in the app, so unlike TimesheetWeekTool this is never
 * narrowed by role.
 */
#[Name('timesheet_options')]
#[IsReadOnly]
#[Description('List the categories, projects and sub-pillars available to build timesheet entries with — the same options the timesheet screen itself offers. Call this before drafting a week that has no existing entries to copy the shape from. A full day is percentage 100.')]
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
