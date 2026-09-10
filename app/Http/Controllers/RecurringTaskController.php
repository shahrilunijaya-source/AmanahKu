<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Project;
use App\Models\RecurringTask;
use App\Models\RecurringTaskOccurrence;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * CR-18: HR and management set up recurring schedules on one screen. A schedule is
 * created, skipped for one period (with a reason), paused or resumed; each of those is a
 * Global Clause "recurring schedule" change and writes an audit row. Nothing here edits
 * a schedule in place: a wrong one is paused and a right one created, so the cards it
 * already made keep their history.
 */
class RecurringTaskController extends Controller
{
    private const ADMIN_ROLES = ['management', 'hr'];

    /** @return array<string, mixed> */
    public function screenData(Request $request): array
    {
        $this->authorizeTenantRole($request, self::ADMIN_ROLES);

        $schedules = RecurringTask::with(['owner', 'project', 'occurrences' => fn ($q) => $q->orderByDesc('period')->limit(6)])
            ->orderBy('title')->get();

        return [
            'schedules' => $schedules,
            'scheduleHolders' => $schedules->mapWithKeys(fn (RecurringTask $s) => [$s->id => $s->resolveOwner()?->display_name]),
            'schedulePeople' => Employee::active()->orderBy('name')->get(['id', 'name']),
            'schedulePositions' => Position::orderBy('title')->pluck('title')->unique()->values(),
            'scheduleProjects' => Project::orderBy('name')->get(['id', 'name']),
        ];
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $this->authorizeTenantRole($request, self::ADMIN_ROLES);
        $tenantId = app(CurrentTenant::class)->id();

        // The screen's form types subtasks one per line; the API sends a list.
        if ($request->filled('subtasks_text') && ! $request->has('subtasks')) {
            $request->merge(['subtasks' => preg_split('/\r?\n/', (string) $request->input('subtasks_text')) ?: []]);
        }

        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'frequency' => ['required', Rule::in(RecurringTask::FREQUENCIES)],
            'interval' => ['nullable', 'integer', 'min:1', 'max:60'],
            'start_on' => ['required', 'date'],
            'owner_employee_id' => ['nullable', 'integer', Rule::exists('employees', 'id')->where('tenant_id', $tenantId)->whereNull('archived_at')],
            'owner_position_title' => ['nullable', 'string', 'max:120'],
            'tagged_employee_ids' => ['nullable', 'array'],
            'tagged_employee_ids.*' => ['integer', Rule::exists('employees', 'id')->where('tenant_id', $tenantId)],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->where('tenant_id', $tenantId)],
            'priority' => ['nullable', 'in:high,medium,low'],
            'lead_days' => ['nullable', 'integer', 'min:0', 'max:60'],
            'subtasks' => ['nullable', 'array', 'max:20'],
            'subtasks.*' => ['string', 'max:160'],
            'min_attended' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        if (empty($data['owner_employee_id']) && empty($data['owner_position_title'])) {
            throw ValidationException::withMessages(['owner_employee_id' => 'Name an owner: a person or a position.']);
        }

        $schedule = RecurringTask::create([
            'tenant_id' => $tenantId,
            'title' => $data['title'],
            'frequency' => $data['frequency'],
            'interval' => $data['frequency'] === 'monthly' ? 1 : ($data['interval'] ?? 1),
            'start_on' => $data['start_on'],
            'owner_employee_id' => $data['owner_employee_id'] ?? null,
            'owner_position_title' => filled($data['owner_position_title'] ?? null) ? $data['owner_position_title'] : null,
            'tagged_employee_ids' => array_values(array_map('intval', $data['tagged_employee_ids'] ?? [])),
            'project_id' => $data['project_id'] ?? null,
            'priority' => $data['priority'] ?? 'medium',
            'lead_days' => $data['lead_days'] ?? 0,
            'subtasks' => array_values(array_filter(array_map('trim', $data['subtasks'] ?? []), fn (string $t) => $t !== '')),
            'min_attended' => $data['min_attended'] ?? 1,
            'created_by_employee_id' => $request->attributes->get('employee')?->id,
        ]);

        AuditLog::change($schedule, 'created', null, $schedule->id);
        AuditLog::record('Created recurring schedule', $schedule->title.' ('.$schedule->cadenceText().')');

        return $request->expectsJson()
            ? response()->json(['ok' => true, 'id' => $schedule->id])
            : back()->with('ok', 'Recurring schedule created.');
    }

    /** Skip one period with a reason. The period is remembered so the engine never makes it. */
    public function skip(Request $request, RecurringTask $recurringTask): RedirectResponse|JsonResponse
    {
        $this->authorizeTenantRole($request, self::ADMIN_ROLES);
        $this->assertInTenant($recurringTask);

        $data = $request->validate([
            'period' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:255'],
        ]);
        $period = CarbonImmutable::parse($data['period'])->toDateString();

        $existing = $recurringTask->occurrences()->whereDate('period', $period)->first();
        if ($existing?->work_item_id) {
            throw ValidationException::withMessages(['period' => 'That period already has a card. Cancel the card instead.']);
        }
        if ($existing === null) {
            RecurringTaskOccurrence::create([
                'tenant_id' => $recurringTask->tenant_id,
                'recurring_task_id' => $recurringTask->id,
                'period' => $period,
                'skipped_reason' => $data['reason'],
            ]);
        }

        AuditLog::change($recurringTask, 'skipped_period', null, $period, $data['reason']);
        AuditLog::record('Skipped recurring occurrence', $recurringTask->title.' '.$period.': '.$data['reason']);

        return $request->expectsJson()
            ? response()->json(['ok' => true])
            : back()->with('ok', 'Occurrence skipped.');
    }

    public function pause(Request $request, RecurringTask $recurringTask): RedirectResponse|JsonResponse
    {
        $this->authorizeTenantRole($request, self::ADMIN_ROLES);
        $this->assertInTenant($recurringTask);

        if (! $recurringTask->isPaused()) {
            $recurringTask->update(['paused_at' => now()]);
            AuditLog::change($recurringTask, 'paused_at', null, $recurringTask->paused_at);
            AuditLog::record('Paused recurring schedule', $recurringTask->title);
        }

        return $request->expectsJson()
            ? response()->json(['ok' => true])
            : back()->with('ok', 'Schedule paused. Nothing is created until you resume it.');
    }

    public function resume(Request $request, RecurringTask $recurringTask): RedirectResponse|JsonResponse
    {
        $this->authorizeTenantRole($request, self::ADMIN_ROLES);
        $this->assertInTenant($recurringTask);

        if ($recurringTask->isPaused()) {
            $was = $recurringTask->paused_at;
            $recurringTask->update(['paused_at' => null]);
            AuditLog::change($recurringTask, 'paused_at', $was, null);
            AuditLog::record('Resumed recurring schedule', $recurringTask->title);
        }

        return $request->expectsJson()
            ? response()->json(['ok' => true])
            : back()->with('ok', 'Schedule resumed.');
    }

    /** Route binding is not tenant-scoped; a schedule from another company is a 404 here. */
    private function assertInTenant(RecurringTask $schedule): void
    {
        abort_unless($schedule->tenant_id === app(CurrentTenant::class)->id(), 404);
    }
}
