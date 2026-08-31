<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\Timesheet;
use App\Models\TimesheetEntry;
use App\Support\ApiCaller;
use App\Timesheet\BoardSuggestions;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * One week's timesheets, scoped like GET /api/v1/timesheet-effort's caller checks:
 * requires the timesheets:read scope, then a privileged caller (management|hr) sees
 * every employee's timesheet for the week, everyone else sees only their own.
 */
#[IsReadOnly]
#[Description('Get one week\'s timesheets (status and daily entries: date, project, category, hours, percentage). Privileged callers (management/HR) see the whole tenant; everyone else sees only their own timesheet. The response also carries `suggested` — the board cards BoardSuggestions would prefill for the caller\'s OWN week (mirrors the timesheet screen), so a caller can see what still needs logging before calling save_timesheet_draft. `suggested` is always the caller\'s own board cards, never anybody else\'s, even when a privileged caller\'s `timesheets` list carries other people\'s weeks too. Omitted only when the caller has no employee profile in this workspace.')]
class TimesheetWeekTool extends Tool
{
    public function handle(Request $request): Response
    {
        $httpRequest = request();

        if (! ApiCaller::can($httpRequest, 'timesheets:read')) {
            return Response::error('This token lacks the timesheets:read scope.');
        }

        $data = $request->validate([
            'week_start' => ['required', 'date'],
        ]);

        $weekStart = Carbon::parse($data['week_start']);
        if (! $weekStart->isMonday()) {
            return Response::error("week_start must be a Monday; {$weekStart->toDateString()} is a {$weekStart->format('l')}.");
        }

        $query = Timesheet::query()->with(['employee:id,name', 'entries.category:id,name', 'entries.projectRef:id,code,name'])
            ->forWeek($weekStart);

        $employee = ApiCaller::employee($httpRequest);

        if (! ApiCaller::isPrivileged($httpRequest)) {
            if (! $employee) {
                return Response::json(['week_start' => $weekStart->toDateString(), 'timesheets' => []]);
            }
            $query->where('employee_id', $employee->id);
        }

        // Suggestions are keyed to the CALLER's own Employee record, never to whichever
        // employees the `timesheets` list happens to include. HR and management have
        // their own timesheets to fill too, and are exactly the people likely to draft
        // one over MCP — excluding them here left every privileged caller with no
        // suggestions at all. There is no leak in always computing this: a privileged
        // caller only ever sees their OWN board cards, the same as anyone else. A caller
        // with no employee profile (a machine token, say) still gets no `suggested` key.
        $suggested = $employee ? app(BoardSuggestions::class)->forWeek($employee, $weekStart) : null;

        $timesheets = $query->get()->map($this->timesheetRow(...));

        return Response::json(array_filter([
            'week_start' => $weekStart->toDateString(),
            'timesheets' => $timesheets,
            'suggested' => $suggested,
        ], fn ($v) => $v !== null));
    }

    /**
     * @return array{employee: ?string, status: string, entries: list<array{date: string, project: ?string, category: ?string, hours: ?float, percentage: float}>}
     */
    private function timesheetRow(Timesheet $timesheet): array
    {
        return [
            'employee' => $timesheet->employee?->name,
            'status' => $timesheet->status,
            'entries' => $timesheet->entries->map($this->entryRow(...))->all(),
        ];
    }

    /**
     * @return array{date: string, project: ?string, category: ?string, hours: ?float, percentage: float}
     */
    private function entryRow(TimesheetEntry $entry): array
    {
        return [
            'date' => $entry->entry_date->toDateString(),
            'project' => $entry->projectRef?->name,
            'category' => $entry->category?->name,
            'hours' => $entry->hours !== null ? (float) $entry->hours : null,
            'percentage' => (float) $entry->percentage,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'week_start' => $schema->string()
                ->description('The Monday that starts the week, as YYYY-MM-DD. Must be a Monday.')
                ->required(),
        ];
    }
}
