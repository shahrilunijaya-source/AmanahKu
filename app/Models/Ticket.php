<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ticket extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    /** Ordered field keys + bilingual labels for a structured Bug description, JSON-encoded into `description`. */
    public const BUG_DESCRIPTION_FIELDS = [
        'steps_to_reproduce' => ['en' => 'Steps to reproduce', 'ms' => 'Langkah untuk hasilkan semula'],
        'expected_behavior' => ['en' => 'Expected behavior', 'ms' => 'Tingkah laku dijangka'],
        'actual_behavior' => ['en' => 'Actual behavior', 'ms' => 'Tingkah laku sebenar'],
        'additional_context' => ['en' => 'Additional context', 'ms' => 'Konteks tambahan'],
    ];

    /** Ordered field keys + bilingual labels for a structured Idea description, JSON-encoded into `description`. */
    public const IDEA_DESCRIPTION_FIELDS = [
        'problem' => ['en' => 'Problem', 'ms' => 'Masalah'],
        'proposed_solution' => ['en' => 'Proposed solution', 'ms' => 'Cadangan penyelesaian'],
        'additional_context' => ['en' => 'Additional context', 'ms' => 'Konteks tambahan'],
    ];

    /**
     * Decode a structured Bug/Idea description into ordered [key => ['label' => [...], 'value' => string]].
     * Returns null for a plain free-text description — an older ticket raised before this field existed,
     * or a non-Bug/Idea category — so the caller falls back to rendering the raw string.
     *
     * @return array<string, array{label: array{en: string, ms: string}, value: string}>|null
     */
    public function structuredDescription(): ?array
    {
        $fields = match ($this->category) {
            'Bug' => self::BUG_DESCRIPTION_FIELDS,
            'Idea' => self::IDEA_DESCRIPTION_FIELDS,
            default => null,
        };
        if (! $fields) {
            return null;
        }

        $decoded = json_decode((string) $this->description, true);
        if (! is_array($decoded) || array_diff(array_keys($fields), array_keys($decoded)) !== []) {
            return null;
        }

        $result = [];
        foreach ($fields as $key => $label) {
            $value = trim((string) ($decoded[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            $result[$key] = ['label' => $label, 'value' => $value];
        }

        return $result === [] ? null : $result;
    }

    /** The employee who raised the ticket. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** The privileged staff member assigned to handle the ticket. */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assignee_employee_id');
    }

    /** Screenshots + documents the reporter attached, in upload order. */
    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class)->oldest('id');
    }
}
