<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesSingleStepApproval;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\ExpenseReport;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class ExpenseController extends Controller
{
    use AuthorizesSingleStepApproval;

    private const CATEGORIES = ['Travel', 'Meals', 'Accommodation', 'Office Supplies', 'Mileage', 'Medical', 'Other'];

    /**
     * Build the expense-reports screen data. Tenant scope is automatic via BelongsToTenant.
     *
     * Every employee sees their own reports (with lines eager-loaded). Privileged
     * roles additionally receive the cross-employee pending-approvals queue so they
     * can approve or reject submitted reports.
     *
     * @return array<string, mixed>
     */
    public function screenData(Request $request, ?Employee $employee): array
    {
        $privileged = $this->hasTenantRole($request, $this->singleStepApproverRoles());

        $myReports = $employee
            ? ExpenseReport::with('lines')->where('employee_id', $employee->id)->latest()->get()
            : new Collection;

        $pendingReports = $privileged
            ? ExpenseReport::with(['lines', 'employee'])->where('status', 'submitted')->latest()->get()
            : new Collection;

        return [
            'myReports' => $myReports,
            'pendingReports' => $pendingReports,
            'categories' => self::CATEGORIES,
            'privileged' => $privileged,
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');

        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'period_label' => ['nullable', 'string', 'max:60'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.expense_date' => ['required', 'date'],
            'lines.*.category' => ['required', 'in:'.implode(',', self::CATEGORIES)],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.amount' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
            'lines.*.receipt_path' => ['nullable', 'string', 'max:255'],
        ]);

        $report = ExpenseReport::create([
            'employee_id' => $employee->id,
            'title' => $data['title'],
            'period_label' => $data['period_label'] ?? null,
            'status' => 'draft',
        ]);

        foreach ($data['lines'] as $line) {
            $report->lines()->create([
                'expense_date' => $line['expense_date'],
                'category' => $line['category'],
                'description' => $line['description'],
                'amount' => $line['amount'],
                'receipt_path' => $line['receipt_path'] ?? null,
            ]);
        }
        $report->recomputeTotal();

        return back()->with('ok', 'Draft report "'.$report->title.'" created with '.count($data['lines']).' line(s).');
    }

    public function addLine(Request $request, ExpenseReport $report): RedirectResponse
    {
        $this->authorizeOwner($request, $report);
        abort_unless($report->status === 'draft', 422, 'Only draft reports can be edited.');

        $data = $request->validate([
            'expense_date' => ['required', 'date'],
            'category' => ['required', 'in:'.implode(',', self::CATEGORIES)],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
            'receipt_path' => ['nullable', 'string', 'max:255'],
        ]);

        $report->lines()->create($data);
        $report->recomputeTotal();

        return back()->with('ok', 'Line added — total RM '.number_format((float) $report->fresh()->total, 2).'.');
    }

    public function submit(Request $request, ExpenseReport $report): RedirectResponse
    {
        $this->authorizeOwner($request, $report);
        abort_unless($report->status === 'draft', 422);
        abort_if($report->lines()->count() === 0, 422, 'Cannot submit an empty report.');

        $report->recomputeTotal();
        $report->update(['status' => 'submitted', 'submitted_at' => now()]);
        AuditLog::record('Submitted expense report', $report->title.' · RM '.number_format((float) $report->total, 2));

        // Notify the requester's immediate superior that a report awaits review (AK-PROC-02).
        $actor = $request->attributes->get('employee');
        if ($actor?->reportsTo?->user_id) {
            AppNotification::send(
                $actor->reportsTo->user_id,
                'Expense report awaiting approval',
                $actor->name.' · RM '.number_format((float) $report->total, 2),
                route('app.screen', 'expenses'),
            );
        }

        return back()->with('ok', 'Report "'.$report->title.'" submitted for approval.');
    }

    public function approve(Request $request, ExpenseReport $report): RedirectResponse
    {
        $this->authorizeSingleStepApprover($request, $report, $report->employee_id);
        abort_unless($report->status === 'submitted', 422);

        $report->update([
            'status' => 'approved',
            'decided_by_id' => $request->attributes->get('employee')?->id,
            'decided_at' => now(),
        ]);
        AuditLog::record('Approved expense report', $report->employee->name.' · RM '.number_format((float) $report->total, 2));
        AppNotification::send(
            $report->employee->user_id,
            'Expense report approved',
            $report->title.' · RM '.number_format((float) $report->total, 2),
            route('app.screen', 'expenses'),
        );

        return back()->with('ok', 'Report approved for '.$report->employee->name.'.');
    }

    public function reject(Request $request, ExpenseReport $report): RedirectResponse
    {
        $this->authorizeSingleStepApprover($request, $report, $report->employee_id);
        abort_unless($report->status === 'submitted', 422);

        $report->update([
            'status' => 'rejected',
            'decided_by_id' => $request->attributes->get('employee')?->id,
            'decided_at' => now(),
        ]);
        AuditLog::record('Rejected expense report', $report->employee->name);
        AppNotification::send(
            $report->employee->user_id,
            'Expense report declined',
            $report->title.' was declined',
            route('app.screen', 'expenses'),
        );

        return back()->with('ok', 'Report rejected for '.$report->employee->name.'.');
    }

    private function authorizeOwner(Request $request, ExpenseReport $report): void
    {
        abort_unless($report->tenant_id === app(CurrentTenant::class)->id(), 403);
        $actor = $request->attributes->get('employee');
        abort_unless($actor && $actor->id === $report->employee_id, 403, 'You can only edit your own reports.');
    }
}
