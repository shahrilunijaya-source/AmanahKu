<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\BigDeal;
use App\Models\Employee;
use App\Models\Reaction;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CR-24 Big Deal Alert: raised by PM-and-above from a project or T.A.A. card,
 * shown as a Moment in the dashboard's moments band for 3 days, then archived
 * to the Wins page (The Playground). Route-model binding is not tenant-scoped
 * (AK note), so every action re-checks tenant_id itself before touching a
 * bound BigDeal.
 */
class BigDealController extends Controller
{
    /** roles.md "PM and above" — the CR text's "PM and above ... Director can also raise". */
    private const RAISE_ROLES = ['manager', 'hr', 'management', 'director'];

    private const TYPES = ['go_live', 'tender_won', 'claim_received', 'uat_completed', 'milestone', 'client_compliment', 'other'];

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeTenantRole($request, self::RAISE_ROLES);

        $data = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', self::TYPES)],
            'title' => ['required', 'string', 'max:255'],
            'story' => ['nullable', 'string', 'max:4000'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'work_item_id' => ['nullable', 'integer', 'exists:work_items,id'],
            'track_ref' => ['nullable', 'string', 'max:100'],
            'team' => ['nullable', 'array'],
            'team.*' => ['integer', 'exists:employees,id'],
            'photos' => ['nullable', 'array', 'max:3'],
            'photos.*' => ['file', 'image', 'max:5120'],
            'client_contact' => ['nullable', 'string', 'max:255'],
            'names_approved' => ['nullable', 'boolean'],
            'source' => ['required_if:type,client_compliment', 'nullable', 'file', 'max:10240'],
        ]);

        $raiser = $request->attributes->get('employee');
        abort_unless($raiser instanceof Employee, 403);

        $deal = BigDeal::create([
            'type' => $data['type'],
            'title' => $data['title'],
            'story' => $data['story'] ?? null,
            'raised_by' => $raiser->id,
            'project_id' => $data['project_id'] ?? null,
            'work_item_id' => $data['work_item_id'] ?? null,
            'track_ref' => $data['track_ref'] ?? null,
            'client_contact' => $data['client_contact'] ?? null,
            'names_approved' => (bool) ($data['names_approved'] ?? false),
            'source_path' => $request->file('source')?->store('big-deals/sources', 'local'),
            'published_at' => now(),
        ]);

        foreach ($data['team'] ?? [] as $employeeId) {
            DB::table('big_deal_members')->insert([
                'big_deal_id' => $deal->id, 'employee_id' => $employeeId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        foreach ($request->file('photos') ?? [] as $photo) {
            DB::table('big_deal_photos')->insert([
                'big_deal_id' => $deal->id, 'path' => $photo->store('big-deals/photos', 'local'),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        AuditLog::record('big_deal.raised', "big_deal:{$deal->id}");

        return back()->with('ok', 'Big Deal raised.');
    }

    /** Any signed-in tenant member reads a photo — the banner and Wins page both embed it. */
    public function photo(BigDeal $deal, int $photo): StreamedResponse
    {
        $this->assertSameTenant($deal);

        $row = DB::table('big_deal_photos')->where('id', $photo)->where('big_deal_id', $deal->id)->first();
        abort_unless($row !== null, 404);
        abort_unless(Storage::disk('local')->exists($row->path), 404);

        return Storage::disk('local')->response($row->path);
    }

    /** CR-30 reaction, one per person per deal, toggle semantics (same as AwardController::react). */
    public function react(Request $request, BigDeal $deal): JsonResponse
    {
        $this->assertSameTenant($deal);

        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403);

        $data = $request->validate([
            'reaction' => ['required', 'string', 'in:'.implode(',', Reaction::activeKeys())],
        ]);

        $had = DB::table('big_deal_reactions')->where('big_deal_id', $deal->id)
            ->where('employee_id', $employee->id)->pluck('reaction');

        DB::table('big_deal_reactions')->where('big_deal_id', $deal->id)->where('employee_id', $employee->id)->delete();

        if (! $had->contains($data['reaction'])) {
            try {
                DB::table('big_deal_reactions')->insert([
                    'tenant_id' => app(CurrentTenant::class)->id(),
                    'big_deal_id' => $deal->id,
                    'employee_id' => $employee->id,
                    'reaction' => $data['reaction'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (QueryException $e) {
                if (! str_starts_with((string) $e->getCode(), '23')) {
                    throw $e;
                }
            }
        }

        return response()->json(['ok' => true, 'html' => $this->reactPartial($deal, $employee)]);
    }

    /**
     * The picker + tally region for one deal — used both by react() and by the
     * dashboard/Wins moment builders on first paint (same pattern as
     * BirthdayWishController::wishesPartial()).
     */
    public function reactPartial(BigDeal $deal, ?Employee $viewer): string
    {
        $rows = DB::table('big_deal_reactions')->where('big_deal_id', $deal->id)->get();

        return view('partials.dash.big-deal-react', [
            'dealId' => $deal->id,
            'counts' => $rows->groupBy('reaction')->map->count()->all(),
            'mine' => $viewer ? $rows->where('employee_id', $viewer->id)->pluck('reaction')->values()->all() : [],
        ])->render();
    }

    /** Wins page (The Playground): every Big Deal ever raised, newest first — an archive, not a window. */
    public function screenData(Request $request, ?Employee $employee): array
    {
        $deals = BigDeal::with(['members', 'photos', 'raisedBy', 'project'])
            ->orderByDesc('published_at')->get();

        return [
            'deals' => $deals->map(fn (BigDeal $deal) => [
                'deal' => $deal,
                'reactHtml' => $this->reactPartial($deal, $employee),
            ]),
        ];
    }

    private function assertSameTenant(BigDeal $deal): void
    {
        abort_unless($deal->tenant_id === app(CurrentTenant::class)->id(), 404);
    }
}
