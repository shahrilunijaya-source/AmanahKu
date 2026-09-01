<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\DashboardWidgets;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a dashboard widget-preference save: which widgets are hidden, and
 * their order within each of the two columns.
 *
 * Ids are checked against the registry here so an unknown id is a 422 rather
 * than something silently stored. Pinned widgets appearing in `hidden` are NOT
 * rejected — DashboardPrefs::merge() strips them regardless of what was sent,
 * so a stale client can never bury a user's own action list.
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
        $ids = Rule::in(DashboardWidgets::ids());
        $rules = [
            'hidden' => ['array'],
            'hidden.*' => ['string', $ids],
            'order' => ['array'],
        ];

        foreach (DashboardWidgets::COLUMNS as $column) {
            $rules["order.{$column}"] = ['array'];
            $rules["order.{$column}.*"] = ['string', $ids];
        }

        return $rules;
    }
}
