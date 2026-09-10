<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Reaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * CR-30: the tenant's reaction set. Anyone signed in reads it; HR and management add
 * or retire. There is deliberately no rename and no delete: an old tally must keep
 * reading the way it did when it was left.
 */
class ReactionController extends Controller
{
    private const ADMIN_ROLES = ['management', 'hr'];

    /** Active reactions, in picker order. */
    public function index(): JsonResponse
    {
        return response()->json([
            'reactions' => Reaction::active()->map(fn (Reaction $r) => $r->toPayload())->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $this->authorizeTenantRole($request, self::ADMIN_ROLES);

        $data = $request->validate([
            'key' => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/'],
            'label' => ['required', 'string', 'max:60'],
            'icon' => ['required', 'string', 'max:16'],
        ]);

        $set = Reaction::set();
        if ($set->contains('key', $data['key'])) {
            throw ValidationException::withMessages(['key' => 'That key is already in the set (retired ones keep their key).']);
        }
        if ($set->whereNull('retired_at')->count() >= Reaction::MAX_ACTIVE) {
            throw ValidationException::withMessages(['key' => 'Ten active reactions is the limit. Retire one first.']);
        }

        $reaction = Reaction::create($data + ['sort' => ((int) $set->max('sort')) + 1]);
        AuditLog::record('Added reaction', $reaction->key.' ('.$reaction->label.')');

        return $request->expectsJson()
            ? response()->json(['ok' => true, 'reaction' => $reaction->toPayload()], 201)
            : back()->with('ok', 'Reaction added.');
    }

    public function retire(Request $request, string $key): RedirectResponse|JsonResponse
    {
        $this->authorizeTenantRole($request, self::ADMIN_ROLES);

        $reaction = Reaction::set()->firstWhere('key', $key);
        abort_unless($reaction !== null, 404);

        if ($reaction->retired_at === null) {
            $reaction->update(['retired_at' => now()]);
            AuditLog::record('Retired reaction', $reaction->key.' ('.$reaction->label.')');
        }

        return $request->expectsJson()
            ? response()->json(['ok' => true, 'reaction' => $reaction->fresh()->toPayload()])
            : back()->with('ok', 'Reaction retired. Old items keep showing it.');
    }
}
