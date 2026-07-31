<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Claim;
use App\Models\ComplianceItem;
use App\Models\Employee;
use App\Models\ExpenseReport;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\ProbationReview;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\WeeklyHrDigest as WeeklyHrDigestNotification;
use App\Tenancy\CurrentTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Weekly HR digest.
 *
 * For each tenant, computes a tenant-scoped summary (pending approvals,
 * new joiners, upcoming probation decisions, compliance expiries) and emails
 * it to that tenant's management/HR users only. Plain employees and managers
 * are NOT notified — the digest is HR/management oversight data.
 *
 * Tenant-aware like the leave commands: the active tenant is set per loop so
 * the BelongsToTenant global scope keeps every count tenant-isolated; counts
 * never bleed across tenants. Context is cleared at the end.
 */
class WeeklyHrDigest extends Command
{
    protected $signature = 'digest:weekly';

    protected $description = 'Email a weekly HR digest (pending approvals, new joiners, probation + compliance) to each tenant\'s management/HR users.';

    /** Roles that receive the digest. */
    private const RECIPIENT_ROLES = ['management', 'hr'];

    private const NEW_JOINER_DAYS = 7;

    private const HORIZON_DAYS = 30;

    public function handle(CurrentTenant $context): int
    {
        $now = Carbon::now();
        $sentTenants = 0;

        foreach (Tenant::query()->orderBy('id')->get() as $tenant) {
            $context->set($tenant);

            try {
                $recipients = $this->recipientsFor($tenant);

                if ($recipients->isEmpty()) {
                    continue;
                }

                $summary = $this->summaryFor($now);

                Notification::send($recipients, new WeeklyHrDigestNotification($tenant, $summary));
                $sentTenants++;
            } catch (\Throwable $e) {
                // One-shot weekly digest — isolate per-tenant failures so one bad tenant
                // doesn't silently skip the digest for everyone after it.
                report($e);
                $this->error("Weekly HR digest failed for tenant {$tenant->id}: {$e->getMessage()}");
            }
        }

        $context->set(null);

        $this->info("Weekly HR digest sent for {$sentTenants} tenant(s).");

        return self::SUCCESS;
    }

    /**
     * The management/HR users attached to this tenant via the tenant_user pivot.
     *
     * @return Collection<int, User>
     */
    private function recipientsFor(Tenant $tenant): Collection
    {
        return $tenant->users()
            ->wherePivotIn('role', self::RECIPIENT_ROLES)
            ->get();
    }

    /**
     * Build the tenant-scoped summary. Every query below runs under the active
     * tenant's global scope, so the counts are automatically tenant-isolated.
     *
     * @return array{pending: array{leave:int, claims:int, expenses:int, overtime:int}, newJoiners:int, probationDecisions:int, complianceExpiries:int}
     */
    private function summaryFor(Carbon $now): array
    {
        $joinerSince = $now->copy()->subDays(self::NEW_JOINER_DAYS)->startOfDay();
        $horizon = $now->copy()->addDays(self::HORIZON_DAYS)->endOfDay();

        return [
            'pending' => [
                'leave' => LeaveRequest::where('status', 'submitted')->count(),
                'claims' => Claim::where('status', 'submitted')->count(),
                'expenses' => ExpenseReport::where('status', 'submitted')->count(),
                'overtime' => OvertimeRequest::where('status', 'submitted')->count(),
            ],
            'newJoiners' => Employee::active()->whereNotNull('joined_at')
                ->whereBetween('joined_at', [$joinerSince, $now])
                ->count(),
            'probationDecisions' => ProbationReview::where('status', 'active')
                ->whereNotNull('end_date')
                ->whereBetween('end_date', [$now->copy()->startOfDay(), $horizon])
                ->count(),
            'complianceExpiries' => ComplianceItem::whereNotNull('expires_at')
                ->whereBetween('expires_at', [$now->copy()->startOfDay(), $horizon])
                ->count(),
        ];
    }
}
