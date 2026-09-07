<?php

declare(strict_types=1);

namespace App\Projects;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Project;
use App\Support\AuditContext;
use App\Support\Permissions;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * CR-06a §E2, E3, E5, E6, E7: who may touch which project-master field, and the
 * versioning + audit that every master change writes. Field-level permission,
 * the contract-terms lock (E4, "not in place" — CR-06b adds the actual
 * Variation path in S10) and the closed-project lock (E7) all live here so
 * ProjectController stays a thin validate-then-delegate layer.
 */
final class ProjectMaster
{
    /** Editable by hr and the management tier (director). */
    public const FINANCE_FIELDS = [
        'contract_value', 'bond_value', 'bond_submitted_at', 'loa_date',
        'loa_ref', 'agreement_date', 'agreement_ref',
    ];

    /** Editable by manager and the management tier. */
    public const PM_FIELDS = ['pm_id', 'pe_id', 'status', 'drive_link', 'procurement_method', 'contractor'];

    /** Editable by anyone who may reach the update route at all (today's EDITOR_ROLES). */
    public const BASE_FIELDS = ['name', 'sort', 'categories', 'is_active', 'code'];

    /**
     * Contract terms: E4 says these "cannot be edited in place" for anyone, a Variation
     * is the only path (CR-06b, S10). Distinct from FINANCE_FIELDS — a role that has no
     * finance authority at all is turned away with the ordinary field-permission message
     * (they may never raise a Variation either); hr/management are told to use the
     * Variation path instead.
     */
    public const VARIATION_FIELDS = ['contract_value', 'contract_start', 'contract_end', 'client'];

    /** Immutable once the project exists — the Track integration key. */
    public const LOCKED_FIELDS = ['project_code'];

    /** Human labels for the 403/422 messages below. */
    private const LABELS = [
        'project_code' => 'Project code',
        'name' => 'Project name',
        'client' => 'Client',
        'contract_value' => 'Contract value',
        'contract_start' => 'Contract start',
        'contract_end' => 'Contract end',
        'procurement_method' => 'Procurement method',
        'contractor' => 'Contractor',
        'bond_value' => 'Bond value',
        'bond_submitted_at' => 'Bond submitted date',
        'loa_date' => 'LOA date',
        'loa_ref' => 'LOA reference',
        'agreement_date' => 'Agreement date',
        'agreement_ref' => 'Agreement reference',
        'drive_link' => 'Drive link',
        'pm_id' => 'PM',
        'pe_id' => 'PE',
        'status' => 'Status',
    ];

    /**
     * The fields a role may set on the master, base fields always included (they gate
     * on the update route itself, not per-field). Every other role (employee) gets none
     * — the update route already 403s them before this is ever consulted.
     *
     * @return list<string>
     */
    public function editableFields(string $role): array
    {
        $role = Permissions::effectiveRole($role);

        return match ($role) {
            'management' => [...self::FINANCE_FIELDS, ...self::PM_FIELDS, ...self::BASE_FIELDS],
            'hr' => [...self::FINANCE_FIELDS, ...self::BASE_FIELDS],
            'manager' => [...self::PM_FIELDS, ...self::BASE_FIELDS],
            default => [],
        };
    }

    public function create(int $tenantId, array $data, ?Employee $by, ?int $userId): Project
    {
        $data['tenant_id'] = $tenantId;
        $project = Project::create($data);

        $project->versions()->create([
            'tenant_id' => $tenantId,
            'version_no' => 1,
            'effective_date' => now()->toDateString(),
            'snapshot' => $project->masterSnapshot(),
            'changes' => null,
            'reason' => null,
            'created_by_id' => $userId,
        ]);

        AuditLog::change($project, 'project_code', null, $project->project_code);
        AuditLog::record('Added project', $project->name);

        return $project;
    }

    /**
     * Applies an update, versions it when a master field actually moved, and returns the
     * version number now current (unchanged when nothing on the master moved — a
     * sort/categories/is_active-only save, say). Throws/aborts per the field the caller
     * is not allowed to touch, in this order: closed lock, code lock, contract-terms
     * lock, then per-field role permission.
     */
    public function update(Project $project, array $data, string $role, ?int $userId, ?string $effectiveDate, ?string $reason): int
    {
        if ($project->isClosed()) {
            throw ValidationException::withMessages([
                'project' => 'This project is closed. A director must reopen it first.',
            ]);
        }

        $project->fill($data);

        foreach (self::LOCKED_FIELDS as $field) {
            if ($project->isDirty($field)) {
                throw ValidationException::withMessages([
                    $field => self::label($field).' is locked once the project is created.',
                ]);
            }
        }

        $financeAuthorized = in_array(Permissions::effectiveRole($role), ['hr', 'management'], true);
        foreach (self::VARIATION_FIELDS as $field) {
            if (! $project->isDirty($field)) {
                continue;
            }

            if (! $financeAuthorized) {
                abort(403, self::label($field).' can only be changed by finance or a director.');
            }

            throw ValidationException::withMessages([
                $field => self::label($field).' changes through a Variation, not in place.',
            ]);
        }

        $editable = $this->editableFields($role);
        foreach ($project->getDirty() as $field => $value) {
            if (in_array($field, self::LOCKED_FIELDS, true) || in_array($field, self::VARIATION_FIELDS, true)) {
                continue; // already cleared above
            }
            if (in_array($field, $editable, true)) {
                continue;
            }

            $who = in_array($field, self::PM_FIELDS, true) ? 'a project manager or a director' : 'finance or a director';
            abort(403, self::label($field).' can only be changed by '.$who.'.');
        }

        $changes = [];
        foreach (Project::MASTER_FIELDS as $field) {
            if ($project->isDirty($field)) {
                $changes[$field] = ['old' => $project->getOriginal($field), 'new' => $project->getAttribute($field)];
            }
        }

        if ($project->isDirty('status') && $project->status === 'closed') {
            $project->fill([
                'closed_at' => now(),
                'closed_by_id' => $this->employeeIdForUser($project->tenant_id, $userId),
            ]);
        }

        AuditContext::reason($reason);
        try {
            $project->save();
        } finally {
            AuditContext::reason(null);
        }

        if ($changes === []) {
            $current = $project->currentVersion();

            return $current !== null ? $current->version_no : 1;
        }

        $versionNo = ($project->versions()->max('version_no') ?? 0) + 1;
        $project->versions()->create([
            'tenant_id' => $project->tenant_id,
            'version_no' => $versionNo,
            'effective_date' => $effectiveDate ?? now()->toDateString(),
            'snapshot' => $project->masterSnapshot(),
            'changes' => $changes,
            'reason' => $reason,
            'created_by_id' => $userId,
        ]);

        return $versionNo;
    }

    public function reopen(Project $project, string $reason, ?Employee $by, ?int $userId): int
    {
        if (! $project->isClosed()) {
            throw ValidationException::withMessages([
                'project' => 'This project is not closed.',
            ]);
        }

        $project->fill(['status' => 'active', 'closed_at' => null, 'closed_by_id' => null]);

        AuditContext::reason($reason);
        try {
            $project->save();
        } finally {
            AuditContext::reason(null);
        }

        $versionNo = ($project->versions()->max('version_no') ?? 0) + 1;
        $project->versions()->create([
            'tenant_id' => $project->tenant_id,
            'version_no' => $versionNo,
            'effective_date' => now()->toDateString(),
            'snapshot' => $project->masterSnapshot(),
            'changes' => ['status' => ['old' => 'closed', 'new' => 'active']],
            'reason' => $reason,
            'created_by_id' => $userId,
        ]);

        return $versionNo;
    }

    private function employeeIdForUser(int $tenantId, ?int $userId): ?int
    {
        if ($userId === null) {
            return null;
        }

        return Employee::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('user_id', $userId)->value('id');
    }

    private static function label(string $field): string
    {
        return self::LABELS[$field] ?? Str::headline($field);
    }
}
