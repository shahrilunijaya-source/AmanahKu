<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ApiClient;

/**
 * The API's six endpoints, as one table.
 *
 * The public reference page renders its cards from this, and the block its "copy for
 * your AI" button produces is generated from it too — the two are the same array read
 * twice, so they cannot drift apart. ApiDocsTest compares this list against the routes
 * actually registered in routes/api.php, in both directions.
 */
class ApiReference
{
    /** Every endpoint hangs off this. Kept here so nothing spells it out twice. */
    public const BASE_PATH = '/api/v1';

    /**
     * One row per endpoint, in the order the page presents them.
     *
     * `app_key` is false where the scope exists but cannot be granted to an
     * application — the endpoint is still real and still documented, because a staff
     * token reaches it.
     *
     * @var list<array{path: string, scope: string, app_key: bool, title: string,
     *                 blurb: string, fields: string, query: ?string, note: ?string}>
     */
    public const ENDPOINTS = [
        [
            'path' => '/projects',
            'scope' => 'projects:read',
            'app_key' => true,
            'title' => 'Projects',
            'blurb' => 'Every active project, with its code and its category tags.',
            'fields' => 'id, code (nullable), name, categories (string[], sorted A-Z, [] when untagged)',
            'query' => null,
            'note' => 'Key your records on id. code is frequently null.',
        ],
        [
            'path' => '/employees',
            'scope' => 'employees:read',
            'app_key' => true,
            'title' => 'Employee directory',
            'blurb' => 'Active staff, with where they sit in the company.',
            'fields' => 'id, name, email, position, status, department, branch',
            'query' => null,
            'note' => 'Active staff only. Archived people are still named on historical leave records.',
        ],
        [
            'path' => '/positions',
            'scope' => 'positions:read',
            'app_key' => true,
            'title' => 'Position bands',
            'blurb' => 'Job bands, including retired ones. No salary figures.',
            'fields' => 'id, title, code, department, level, status',
            'query' => null,
            'note' => 'Retired bands are included so effort booked under a closed band still resolves to a title.',
        ],
        [
            'path' => '/timesheet-effort',
            'scope' => 'effort:read',
            'app_key' => true,
            'title' => 'Weekly effort',
            'blurb' => 'One week of effort per project, aggregated per position band.',
            'fields' => 'week_start, projects[].project_id, projects[].positions[] { position_id, position_title, headcount, person_days, days_present, alloc_pct }',
            'query' => 'week_start=YYYY-MM-DD (required, must be a Monday)',
            'note' => 'Aggregated server-side: no employee name, id or salary ever crosses the wire.',
        ],
        [
            'path' => '/leave-requests',
            'scope' => 'leave:read',
            'app_key' => true,
            'title' => 'Leave requests',
            'blurb' => 'Who is away and when, with the state of each request.',
            'fields' => 'id, employee, leave_type, date_from, date_to, days, status',
            'query' => null,
            'note' => null,
        ],
        [
            'path' => '/payslips',
            'scope' => 'payslips:read',
            'app_key' => false,
            'title' => 'Finalized payslips',
            'blurb' => 'Finalized payroll runs only. Not available to application keys.',
            'fields' => 'id, employee, period, run_status, gross, net_pay, total_deductions',
            'query' => null,
            'note' => 'Staff tokens only. No application can be issued this scope; see the key screen.',
        ],
    ];

    /**
     * The instruction block the page's primary button copies.
     *
     * Written as a brief for a coding agent working in another repository, not as
     * prose for a person: constraints first, then the endpoint table, then the two
     * error shapes and the traps that have already cost somebody an afternoon.
     */
    public static function agentBrief(): string
    {
        $root = rtrim((string) config('app.url'), '/');
        $base = $root.self::BASE_PATH;
        $specUrl = $root.'/openapi.json';

        $endpoints = collect(self::ENDPOINTS)
            ->map(function (array $e): string {
                $line = sprintf('  GET %-18s [%s]%s', $e['path'], $e['scope'], $e['app_key'] ? '' : ' (staff tokens only — cannot be granted to an app)');
                $line .= "\n      returns: ".$e['fields'];
                if ($e['query'] !== null) {
                    $line .= "\n      query:   ".$e['query'];
                }
                if ($e['note'] !== null) {
                    $line .= "\n      note:    ".$e['note'];
                }

                return $line;
            })
            ->implode("\n");

        $scopes = collect(ApiClient::SCOPES)
            ->map(fn (string $label, string $key): string => "  {$key} — {$label}")
            ->implode("\n");

        return <<<BRIEF
        You are wiring up a client for the AmanahKu API.

        BASE URL   {$base}
        AUTH       Authorization: Bearer <key>   (one key per application, issued by an AmanahKu super-admin)
        ENVELOPE   success -> {"data": ..., "error": null}
                   failure -> {"data": null, "error": "message"}

        WHAT IT IS
        AmanahKu is the source of truth for which projects exist across Unijaya and what
        kind of work each one is. It does NOT hold budget, phases or delivery cadence —
        those live in Track. Do not try to read project status from here.

        HARD CONSTRAINTS — violating any of these will break the integration:
        - Read-only. Every route is GET. There is no write endpoint and none is planned.
        - No webhooks. AmanahKu never calls you. Poll on your own schedule.
        - No pagination. Every list returns the whole company in one response.
        - No rate limit today. Do not build retry logic around 429 or X-RateLimit-*.

        ENDPOINTS
        {$endpoints}

        SCOPES A KEY CAN BE GRANTED
        {$scopes}

        ERROR SHAPES — there are two different 401 bodies, handle both:
          401  {"message": "Unauthenticated."}               key missing or unrecognised (framework default, NOT the envelope)
          401  {"data": null, "error": "Unauthenticated."}   key real but its company binding no longer holds
          403  {"data": null, "error": "This token lacks the <scope> scope."}
          5xx  {"message": "...", "reference": "..."}        reference is present for reported faults only

        TRAPS
        1. week_start MUST be an exact Monday. Any other day returns 200 with an empty
           projects array and no error at all. Validate before sending.
        2. Read both "error" and "message" when handling failures, per the two 401 shapes.
        3. A project's "code" is frequently null. Key your records on "id", never "code".
        4. A key is displayed once at creation and cannot be recovered. Store it as a secret.

        Machine-readable spec: {$specUrl} (OpenAPI 3.1)

        Now: ask me which endpoints I need, then write the client in my project's existing
        HTTP style, with the two 401 shapes and the Monday validation handled.
        BRIEF;
    }
}
