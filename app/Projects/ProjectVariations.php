<?php

declare(strict_types=1);

namespace App\Projects;

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\ProjectVariation;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * CR-06b §E4, E5: raising and deciding a contract Variation — the only path
 * contract_value, contract_start, contract_end and client move once a project
 * exists. Raising never touches the project row; approval applies the change and
 * writes the next ProjectVersion (effective on `variation_date`, so a report re-run
 * for an earlier period keeps reading the old figure); rejection leaves the project
 * untouched. Field list, order and every message match OPEN "QA / CR-06b / shapes
 * fixed by CR06bTest".
 */
final class ProjectVariations
{
    /** Fields a Variation may move — same set ProjectMaster::VARIATION_FIELDS locks in place. */
    private const FIELDS = ['contract_value', 'contract_start', 'contract_end', 'client'];

    private const DATE_FIELDS = ['contract_start', 'contract_end'];

    public function raise(Project $project, array $data, ?UploadedFile $attachment, ?int $userId): ProjectVariation
    {
        $changes = [];
        $delta = null;

        foreach (self::FIELDS as $field) {
            if (! array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
                continue;
            }

            $current = $project->getAttribute($field);
            if (in_array($field, self::DATE_FIELDS, true) && $current instanceof CarbonInterface) {
                $current = $current->toDateString();
            }

            $new = $data[$field];
            $currentCompare = $field === 'contract_value' && $current !== null
                ? number_format((float) $current, 2, '.', '')
                : (string) $current;
            $newCompare = $field === 'contract_value'
                ? number_format((float) $new, 2, '.', '')
                : (string) $new;

            if ($currentCompare === $newCompare) {
                continue; // sent, but not actually a change
            }

            $changes[$field] = ['old' => $current, 'new' => $new];

            if ($field === 'contract_value') {
                // Written as a formatted string, not a float: `delta` is a string(20)
                // column (see the migration) precisely so this exact "-150000.00"
                // shape survives on disk instead of losing its trailing zeros.
                $delta = number_format((float) $new - (float) ($current ?? 0), 2, '.', '');
            }
        }

        if ($changes === []) {
            throw ValidationException::withMessages([
                'vo_no' => 'This variation does not change anything on the project.',
            ]);
        }

        $path = $attachment?->store('project-variations', 'local') ?: null;

        $variation = $project->variations()->create([
            'tenant_id' => $project->tenant_id,
            'vo_no' => $data['vo_no'],
            'variation_date' => $data['variation_date'],
            'reason' => $data['reason'],
            'changes' => $changes,
            'delta' => $delta,
            'attachment_path' => $path,
            'status' => 'pending',
            'raised_by_id' => $userId,
        ]);

        AuditLog::change($project, 'variation', null, 'VO '.$variation->vo_no.' pending', $data['reason']);

        return $variation;
    }

    /** Applies the change, writes the next version, decides the variation. Returns the new version number. */
    public function approve(Project $project, ProjectVariation $variation, ?int $userId): int
    {
        $this->assertPending($variation);

        $project->fill($this->newValues($variation));
        $project->save();

        $versionNo = ($project->versions()->max('version_no') ?? 0) + 1;
        $reason = 'VO '.$variation->vo_no.': '.$variation->reason;

        $version = $project->versions()->create([
            'tenant_id' => $project->tenant_id,
            'version_no' => $versionNo,
            'effective_date' => $variation->variation_date->toDateString(),
            'snapshot' => $project->masterSnapshot(),
            'changes' => $variation->changes,
            'reason' => $reason,
            'created_by_id' => $userId,
        ]);

        foreach ($variation->changes as $field => $delta) {
            AuditLog::change($project, $field, $delta['old'] ?? null, $delta['new'] ?? null, $reason);
        }

        $variation->update([
            'status' => 'approved',
            'decided_by_id' => $userId,
            'decided_at' => now(),
            'version_id' => $version->id,
        ]);

        return $versionNo;
    }

    public function reject(ProjectVariation $variation, ?string $note, ?int $userId): void
    {
        $this->assertPending($variation);

        $variation->update([
            'status' => 'rejected',
            'decided_by_id' => $userId,
            'decided_at' => now(),
            'decision_note' => $note,
        ]);

        AuditLog::change($variation->project, 'variation', 'pending', 'VO '.$variation->vo_no.' rejected');
    }

    private function assertPending(ProjectVariation $variation): void
    {
        if (! $variation->isPending()) {
            throw ValidationException::withMessages([
                'variation' => 'This variation has already been decided.',
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function newValues(ProjectVariation $variation): array
    {
        $values = [];
        foreach ($variation->changes as $field => $delta) {
            $values[$field] = $delta['new'] ?? null;
        }

        return $values;
    }
}
