<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\PayrollRun;
use App\Models\PayrollSubmission;
use App\Models\Tenant;
use App\Services\FeatureManager;
use Carbon\CarbonImmutable;

/**
 * Spec F12: when each statutory filing is due, and opening the rows for a run.
 *
 * Every monthly filing (EPF, SOCSO/EIS, PCB, HRD Corp levy) is due on the 15th of the
 * month after the wage month. The agencies do not move that date for a weekend or a
 * public holiday, so neither do we — an earlier payment is always accepted, a later one
 * is late whatever day the 15th falls on.
 */
final class StatutoryCalendar
{
    public function __construct(private readonly FeatureManager $features) {}

    /** The 15th of the month after $period ("2026-08" gives 2026-09-15). Never shifted. */
    public static function monthlyDueDate(string $period): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $period.'-01')->startOfDay()->addMonth()->day(15);
    }

    /**
     * Form EA must reach employees by the last day of February; Form E must reach LHDN
     * by 31 March — both for the year after the one being reported.
     */
    public static function annualDueDate(string $agency, int $year): CarbonImmutable
    {
        $next = CarbonImmutable::create($year + 1, 1, 1)->startOfDay();

        return $agency === 'form_e' ? $next->month(3)->day(31) : $next->month(2)->endOfMonth()->startOfDay();
    }

    /**
     * Opens the filings a finalized run owes: EPF, SOCSO/EIS and PCB always, the HRD Corp
     * levy only when the company pays it. A December run also opens that year's Form EA
     * and Form E, which are due the following February and March.
     */
    public function openFor(PayrollRun $run, Tenant $tenant): void
    {
        $due = self::monthlyDueDate($run->period)->toDateString();
        $agencies = ['epf', 'socso_eis', 'pcb'];
        if (HrdCorpLevy::rate((string) $this->features->value($tenant, 'payroll.hrdf')) > 0) {
            $agencies[] = 'hrdcorp';
        }

        foreach ($agencies as $agency) {
            $this->open($tenant->id, $agency, $due, $run->id, null);
        }

        [$year, $month] = array_map('intval', explode('-', $run->period));
        if ($month === 12) {
            foreach (['ea', 'form_e'] as $agency) {
                $this->open($tenant->id, $agency, self::annualDueDate($agency, $year)->toDateString(), null, $year);
            }
        }
    }

    /**
     * Written out rather than firstOrCreate because tenant_id is not fillable and this
     * also runs from a console command with no tenant context set.
     */
    private function open(int $tenantId, string $agency, string $due, ?int $runId, ?int $year): void
    {
        $exists = PayrollSubmission::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('agency', $agency)
            ->where('payroll_run_id', $runId)->where('year', $year)->exists();
        if ($exists) {
            return;
        }

        (new PayrollSubmission)->forceFill([
            'tenant_id' => $tenantId,
            'payroll_run_id' => $runId,
            'year' => $year,
            'agency' => $agency,
            'due_on' => $due,
        ])->save();
    }
}
