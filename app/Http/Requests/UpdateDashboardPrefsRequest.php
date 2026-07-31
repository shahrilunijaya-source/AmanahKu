<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a dashboard card-preference save: which scope it applies to, which
 * card ids are hidden, and their display order. `queue`/`secondary` being present
 * in `hidden` is not rejected here — DashboardPrefs::merge() silently strips those
 * pinned ids so a stale client payload can never bury a user's own action queue.
 */
class UpdateDashboardPrefsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'scope' => ['required', 'string', 'in:me,company'],
            'hidden' => ['array'],
            'hidden.*' => ['string'],
            'order' => ['array'],
            'order.*' => ['string'],
        ];
    }
}
