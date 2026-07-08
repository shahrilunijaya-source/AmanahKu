<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Claim;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Services\Ai\AiProvider;
use App\Services\FeatureManager;
use App\Support\WorkforceInsights;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssistantController extends Controller
{
    public function reply(Request $request, AiProvider $ai): JsonResponse
    {
        // The assistant is an opt-out tenant feature — when disabled the endpoint is
        // closed off entirely (the panel is also hidden in the shell).
        if (! app(FeatureManager::class)->enabled(app(CurrentTenant::class)->get(), 'ai.assistant')) {
            return response()->json(['error' => 'The AI assistant is disabled for this workspace.'], 403);
        }

        $data = $request->validate([
            'message' => ['required', 'string', 'max:1000'],
        ]);

        $context = $this->workforceContext($request->attributes->get('employee'));

        return response()->json([
            'reply' => $ai->reply($data['message'], $context),
            'source' => $ai->label(),
        ]);
    }

    /**
     * Tenant-scoped facts the assistant may use. Every query runs under the active
     * tenant's global scope, so nothing here can cross workspaces.
     *
     * @return array<string,mixed>
     */
    private function workforceContext(?Employee $employee): array
    {
        $tenant = app(CurrentTenant::class)->get();

        return [
            'tenant' => $tenant->name,
            'headcount' => Employee::active()->count(),
            // Live overloaded set (open work-item count), name-sorted — same source as the recs.
            'overloaded' => app(WorkforceInsights::class)->overloaded()->pluck('name')->sort()->values()->all(),
            'onProbation' => Employee::active()->where('status', 'probation')->count(),
            'pendingLeave' => LeaveRequest::where('status', 'submitted')->whereHas('employee', fn ($q) => $q->active())->count(),
            'pendingClaims' => Claim::where('status', 'submitted')->whereHas('employee', fn ($q) => $q->active())->count(),
            'you' => $employee ? [
                'name' => $employee->name,
                'openTasks' => $employee->workItems()->whereIn('status', ['todo', 'prog', 'review'])->count(),
            ] : null,
        ];
    }
}
