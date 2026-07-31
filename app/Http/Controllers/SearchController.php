<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    /** Max results returned for the header global search dropdown. */
    private const RESULT_LIMIT = 8;

    /**
     * Tenant-scoped employee search for the header global search box.
     * Matches name / position columns and the department relation name.
     * The BelongsToTenant global scope keeps results within the active tenant.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $q = trim((string) ($data['q'] ?? ''));

        if ($q === '') {
            return response()->json([]);
        }

        // Escape LIKE wildcards so '%' / '_' in the query don't match everything.
        $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';

        $employees = Employee::active()
            ->with('department:id,name')
            ->where(fn ($w) => $w
                ->where('name', 'like', $term)
                ->orWhereHas('positionBand', fn ($p) => $p->where('title', 'like', $term))
                ->orWhereHas('department', fn ($d) => $d->where('name', 'like', $term))
                ->orWhereHas('branch', fn ($r) => $r->where('name', 'like', $term)))
            ->orderBy('name')
            ->limit(self::RESULT_LIMIT)
            ->get(['id', 'name', 'position', 'position_id', 'department_id', 'initials', 'avatar_color']);

        $results = $employees->map(fn (Employee $e) => [
            'id' => $e->id,
            'name' => $e->name,
            'department' => $e->department?->name,
            'position' => $e->position,
            'initials' => $e->initials,
            'avatar_color' => $e->avatar_color,
        ]);

        return response()->json($results);
    }
}
