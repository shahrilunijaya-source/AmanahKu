<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\PreviewsWrites;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\Timesheet;
use App\Models\TimesheetCategory;
use App\Support\ApiCaller;
use App\Tenancy\CurrentTenant;
use App\Timesheet\WeekWriter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Preview-only: saves a change to one or more days of the caller's OWN
 * timesheet week as a draft, through WeekWriter — the exact pipeline
 * TimesheetController::store() uses. Never submits (submit_now is not a
 * thing this tool does), and only ever touches a draft — a submitted week
 * is refused, same as the browser.
 *
 * The preview always renders the FULL resulting week, day by day, after the
 * change is merged with whatever is already saved (WeekWriter::mergePartialIntoExisting())
 * and then run through the exact same WeekWriter::resolveWeek() pipeline
 * save() persists — locked-day filtering, the in-week date check, normalisation
 * and all — so the preview can never show a week that confirm then refuses or
 * silently stores differently. Days not mentioned in `entries` are shown
 * exactly as they are stored today; that is the whole point of the preview,
 * since a change to one day must never silently drop the rest of the week.
 * A typed row a locked day overrides is dropped from the resulting week, same
 * as save() would drop it, and named in `changes.dropped` so the model can
 * tell the user which days were overridden and why.
 */
#[Name('save_timesheet_draft')]
#[IsReadOnly]
#[Description('Preview saving a change to one or more days of YOUR OWN timesheet week, as a draft. Unmentioned days keep whatever is already saved — this tool merges, it never wipes the week. Only works on a draft week (a submitted week is refused). Never submits. Requires timesheets:write. Returns a summary and a confirm_token, with the FULL resulting week shown day by day — nothing is saved until confirm_write is called.')]
class SaveTimesheetDraftTool extends Tool
{
    use PreviewsWrites;

    public function __construct(private WeekWriter $weekWriter) {}

    public function handle(Request $request): Response
    {
        $httpRequest = request();

        if (! ApiCaller::can($httpRequest, 'timesheets:write')) {
            return Response::error('This token lacks the timesheets:write scope.');
        }

        $employee = ApiCaller::employee($httpRequest);
        if (! $employee) {
            return Response::error('No employee profile in this workspace.');
        }

        $tid = app(CurrentTenant::class)->id();

        $data = $request->validate([
            'week_start' => ['required', 'date'],
            'entries' => ['required', 'array', 'min:1'],
            'entries.*.entry_date' => ['required', 'date'],
            'entries.*.category_id' => ['required', 'integer', Rule::exists('timesheet_categories', 'id')->where('tenant_id', $tid)],
            'entries.*.project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->where('tenant_id', $tid)],
            'entries.*.sub_pillar_id' => ['nullable', 'integer', Rule::exists('sub_pillars', 'id')->where('tenant_id', $tid)],
            'entries.*.percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'entries.*.description' => ['nullable', 'string', 'max:10000'],
        ]);

        $weekStart = Carbon::parse($data['week_start']);
        if (! $weekStart->isMonday()) {
            return Response::error("week_start must be a Monday; {$weekStart->toDateString()} is a {$weekStart->format('l')}.");
        }

        $existing = Timesheet::where('employee_id', $employee->id)->where('week_start', $weekStart)->first();
        if ($existing && $existing->status !== 'draft') {
            return Response::error('This week has already been submitted and cannot be edited.');
        }

        $merged = $this->weekWriter->mergePartialIntoExisting($employee, $weekStart, $data['entries']);

        // resolveWeek() is the exact same pipeline save() runs (locked-day filtering,
        // the in-week date check, normalisation, the locked-row merge), so the preview
        // can never render a week that confirm then refuses or stores differently.
        $result = $this->guarded(fn () => $this->weekWriter->resolveWeek($employee, $weekStart, $merged));
        if (isset($result['error'])) {
            return Response::error($result['error']);
        }
        $resulting = $result['entries'];

        $payload = [
            'employee_id' => $employee->id,
            'week_start' => $weekStart->toDateString(),
            'week_label' => $existing?->week_label,
            'entries' => $merged,
        ];

        return $this->preview(
            $httpRequest,
            $payload,
            'Save '.$weekStart->toDateString().' as a draft — resulting week has '.count($resulting).' '.(count($resulting) === 1 ? 'entry' : 'entries').' across '.count($this->byDate($resulting)).' day(s).',
            [
                'week_start' => $weekStart->toDateString(),
                'resulting_week' => $this->renderByDate($resulting, $tid),
                'dropped' => $this->renderDropped($result['dropped'], $tid),
            ],
        );
    }

    /**
     * @return array{error: string}|array{ok: true, status: string}
     */
    public function applyConfirmed(array $payload, HttpRequest $httpRequest, int $tenantId): array
    {
        return $this->guarded(function () use ($payload, $httpRequest) {
            $employee = ApiCaller::employee($httpRequest);
            abort_unless($employee !== null, 403, 'No employee profile in this workspace.');
            abort_unless($employee->id === $payload['employee_id'], 403, 'You can only save your own timesheet.');

            $result = $this->weekWriter->save(
                $employee,
                $payload['week_start'],
                $payload['entries'],
                $payload['week_label'],
                false,
            );

            AuditLog::record('Saved timesheet draft'.$this->keySuffix($httpRequest), $payload['week_start'].' · '.count($result['entries']).' entries');

            return ['ok' => true, 'status' => $result['timesheet']->status, 'entries' => count($result['entries'])];
        });
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function byDate(array $entries): array
    {
        $byDate = [];
        foreach ($entries as $e) {
            $byDate[$e['entry_date']][] = $e;
        }
        ksort($byDate);

        return $byDate;
    }

    /** Human-readable day-by-day rendering of the resulting week, for the preview. */
    private function renderByDate(array $entries, int $tenantId): array
    {
        $categories = TimesheetCategory::whereIn('id', collect($entries)->pluck('category_id')->filter()->unique())->get()->keyBy('id');
        $projects = Project::whereIn('id', collect($entries)->pluck('project_id')->filter()->unique())->get()->keyBy('id');

        $out = [];
        foreach ($this->byDate($entries) as $date => $rows) {
            $out[$date] = array_map(fn (array $e) => [
                'category' => $categories->get($e['category_id'])?->name,
                // project_id is an OPTIONAL schema key — some categories don't take a
                // project, so a normal caller is entitled to omit it entirely rather
                // than send null.
                'project' => ($e['project_id'] ?? null) ? $projects->get($e['project_id'])?->name : null,
                'percentage' => (float) $e['percentage'],
                'description' => $e['description'] ?? null,
            ], $rows);
        }

        return $out;
    }

    /**
     * The typed rows a locked day (public holiday, whole-day leave) overrode, for the
     * preview to say why the resulting week differs from what was submitted — see
     * WeekWriter::resolveWeek(). These are raw, un-normalised rows straight from the
     * request, same as $entries in renderByDate() before normalisation.
     *
     * @param  array<int, array<string, mixed>>  $dropped
     * @return array<int, array<string, mixed>>
     */
    private function renderDropped(array $dropped, int $tenantId): array
    {
        if ($dropped === []) {
            return [];
        }

        $categories = TimesheetCategory::whereIn('id', collect($dropped)->pluck('category_id')->filter()->unique())->get()->keyBy('id');
        $projects = Project::whereIn('id', collect($dropped)->pluck('project_id')->filter()->unique())->get()->keyBy('id');

        return array_values(array_map(fn (array $e) => [
            'entry_date' => Carbon::parse($e['entry_date'])->toDateString(),
            'category' => $categories->get($e['category_id'])?->name,
            'project' => ($e['project_id'] ?? null) ? $projects->get($e['project_id'])?->name : null,
            'percentage' => (float) $e['percentage'],
            'reason' => 'Overridden by a locked day (public holiday or approved leave).',
        ], $dropped));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'week_start' => $schema->string()->description('The Monday that starts the week, YYYY-MM-DD.')->required(),
            'entries' => $schema->array()->items($schema->object([
                'entry_date' => $schema->string(),
                'category_id' => $schema->integer(),
                'project_id' => $schema->integer(),
                'sub_pillar_id' => $schema->integer(),
                'percentage' => $schema->number(),
                'description' => $schema->string(),
            ]))->description('The changed day(s) only. Any day not mentioned here keeps whatever is already saved for that day.')->required(),
        ];
    }
}
