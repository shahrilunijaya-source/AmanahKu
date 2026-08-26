<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\TotParticipation;
use App\Models\TotSession;
use App\Support\ApiCaller;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

/**
 * TOT (Transfer of Training) sessions. Company-wide by nature — no per-role
 * narrowing beyond the tenant scope every tool already gets from ApiTenant.
 * Comments and reactions are intentionally never exposed here.
 */
#[Description('List TOT (Transfer of Training) sessions for a tenant: date, topic, presenter, status, links, and who attended. Company-wide data — every caller with the tot:read scope sees the whole tenant, no per-employee narrowing. Comments and reactions are not exposed.')]
class TotSessionsTool extends Tool
{
    public function handle(Request $request): Response
    {
        $httpRequest = request();

        if (! ApiCaller::can($httpRequest, 'tot:read')) {
            return Response::error('This token lacks the tot:read scope.');
        }

        $args = $request->validate([
            'year' => ['sometimes', 'integer', 'min:2000', 'max:2100'],
            'month' => ['sometimes', 'integer', 'min:1', 'max:12'],
        ]);

        $year = $args['year'] ?? (isset($args['month']) ? null : now()->year);
        $month = $args['month'] ?? null;

        $query = TotSession::query()->with(['presenters:id,name', 'presenter:id,name', 'participations.employee:id,name']);

        if ($year !== null) {
            $query->where('year', $year);
        }
        if ($month !== null) {
            $query->where('month', $month);
        }

        $sessions = $query->orderBy('year')->orderBy('month')->get()->map($this->sessionRow(...));

        return Response::json(['sessions' => $sessions]);
    }

    /**
     * @return array{date: string, topic: ?string, presenter: ?string, status: string, links: array<int, mixed>, participants: list<string>, participant_count: int}
     */
    private function sessionRow(TotSession $session): array
    {
        return [
            'date' => $session->session_date->toDateString(),
            'topic' => $session->title,
            'presenter' => $session->presenterLabel(),
            'status' => $session->status,
            'links' => $session->links ?? [],
            'participants' => $session->participations->map($this->participantName(...))->filter()->values()->all(),
            'participant_count' => $session->participations->count(),
        ];
    }

    private function participantName(TotParticipation $participation): ?string
    {
        return $participation->employee?->name;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'year' => $schema->integer()
                ->description('Filter to this year. Defaults to the current year when year and month are both omitted.'),
            'month' => $schema->integer()
                ->description('Filter to this month (1-12), across all years unless year is also given.'),
        ];
    }
}
