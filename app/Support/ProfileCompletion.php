<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Employee;
use App\Services\FeatureManager;
use App\Tenancy\CurrentTenant;

/**
 * Derives an employee's profile-completeness state on read — there is no stored
 * "complete" flag (mirrors how SetupController computes company setup from live
 * data). Powers the first-login wizard gate, the resume-point, and the dashboard
 * nudge.
 *
 * Two tiers:
 *   - ESSENTIAL (identity core + contact/emergency): hard-gated by
 *     EnsureProfileComplete so HR always has a usable record. ~2 minutes.
 *   - FULL (all four groups): the 100% target the nudge chases. Never blocks the
 *     daily-driver flows (clock-in, board).
 */
class ProfileCompletion
{
    /** Employee columns that make up the hard-gated essential set. */
    private const ESSENTIAL_FIELDS = [
        'nric', 'date_of_birth', 'phone', 'address',
        'emergency_contact_name', 'emergency_contact_phone',
    ];

    /**
     * True once every essential field is filled. This is the only tier that blocks
     * app access (via EnsureProfileComplete).
     */
    public function essentialDone(Employee $employee): bool
    {
        foreach (self::ESSENTIAL_FIELDS as $field) {
            if (blank($employee->{$field})) {
                return false;
            }
        }

        return true;
    }

    /**
     * The four completeness groups, each {key, label, label_ms, done, essential}.
     * The bank group is dropped when the payroll module is off for the tenant, so a
     * company that doesn't run payroll can still reach 100%.
     *
     * @return array<int, array{key:string,label:string,label_ms:string,done:bool,essential:bool}>
     */
    public function groups(Employee $employee): array
    {
        $groups = [
            [
                'key' => 'identity',
                'label' => 'Identity',
                'label_ms' => 'Identiti',
                'done' => filled($employee->nric) && filled($employee->date_of_birth)
                    && filled($employee->gender) && filled($employee->marital_status),
                'essential' => true,
            ],
            [
                'key' => 'contact',
                'label' => 'Contact & emergency',
                'label_ms' => 'Hubungan & kecemasan',
                'done' => filled($employee->phone) && filled($employee->address)
                    && filled($employee->emergency_contact_name) && filled($employee->emergency_contact_phone),
                'essential' => true,
            ],
        ];

        if ($this->payrollEnabled()) {
            $groups[] = [
                'key' => 'bank',
                'label' => 'Bank & statutory',
                'label_ms' => 'Bank & berkanun',
                'done' => $this->bankDone($employee),
                'essential' => false,
            ];
        }

        $groups[] = [
            'key' => 'certs',
            'label' => 'Certificates & personality',
            'label_ms' => 'Sijil & personaliti',
            'done' => $this->certsDone($employee) && $this->personalityDone($employee),
            'essential' => false,
        ];

        return $groups;
    }

    /** Whole-profile completion percentage across the applicable groups. */
    public function percent(Employee $employee): int
    {
        $groups = $this->groups($employee);
        $done = count(array_filter($groups, fn ($g) => $g['done']));

        return (int) round($done / max(count($groups), 1) * 100);
    }

    /** True only when every applicable group is done (the 100% target). */
    public function fullyComplete(Employee $employee): bool
    {
        foreach ($this->groups($employee) as $group) {
            if (! $group['done']) {
                return false;
            }
        }

        return true;
    }

    /**
     * The keys of the groups still outstanding — drives the wizard's resume point
     * and the nudge's "what's left" copy.
     *
     * @return array<int, string>
     */
    public function missing(Employee $employee): array
    {
        return array_values(array_map(
            fn ($g) => $g['key'],
            array_filter($this->groups($employee), fn ($g) => ! $g['done']),
        ));
    }

    /**
     * Compact summary for the dashboard nudge card + shared context. Runs on every
     * screen render, so groups() is computed exactly once and everything else is
     * derived from that array — never re-query per derived value.
     */
    public function summary(Employee $employee): array
    {
        $groups = $this->groups($employee);
        $done = count(array_filter($groups, fn ($g) => $g['done']));
        $total = max(count($groups), 1);

        return [
            'pct' => (int) round($done / $total * 100),
            'essentialDone' => $this->essentialDone($employee),
            'complete' => $done === count($groups),
            'missing' => array_values(array_map(
                fn ($g) => $g['key'],
                array_filter($groups, fn ($g) => ! $g['done']),
            )),
            'groups' => $groups,
        ];
    }

    private function bankDone(Employee $employee): bool
    {
        $employee->loadMissing('salaryStructure');
        $s = $employee->salaryStructure;

        return $s !== null
            && filled($s->bank_name) && filled($s->bank_account_no)
            && filled($s->epf_no) && filled($s->socso_no);
    }

    private function certsDone(Employee $employee): bool
    {
        $this->loadExistenceFlags($employee);

        return (bool) $employee->has_certificate;
    }

    private function personalityDone(Employee $employee): bool
    {
        $this->loadExistenceFlags($employee);

        return (bool) $employee->has_personality;
    }

    /**
     * Load the certificate + personality existence flags in a single query (AK-PERF-01).
     * summary() runs on every screen render; the two ->exists() checks were two separate
     * round-trips. loadExists batches them into one and caches the booleans on the model,
     * so certsDone()/personalityDone() are free after the first call.
     */
    private function loadExistenceFlags(Employee $employee): void
    {
        if (isset($employee->has_certificate)) {
            return;
        }

        $employee->loadExists([
            'documents as has_certificate' => fn ($q) => $q->where('category', 'Certificate'),
            'profileTestResult as has_personality' => fn ($q) => $q->whereNotNull('submitted_at'),
        ]);
    }

    private function payrollEnabled(): bool
    {
        $tenant = app(CurrentTenant::class)->get();

        return $tenant !== null && app(FeatureManager::class)->screenAllowed($tenant, 'payroll');
    }
}
