<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Models\Department;
use App\Models\Employee;
use App\Models\OfficeRequest;
use App\Models\OfficeRequestComment;
use App\Models\OfficeRequestVote;
use App\Models\WorkItem;
use App\Support\ImageCompressor;
use App\Support\Permissions;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CR-21: Office Requests / Permintaan Pejabat — a public wishlist/to-do for the Admin team.
 * Every raise creates one T.A.A. card owned by the resolved Finance Manager, tagged Admin
 * department staff as helpers. See docs/build/sessions/S14/contract.md.
 */
class OfficeRequestController extends Controller
{
    /** "PM and above" per docs/build/contracts/roles.md, collapsed through effectiveRole(). */
    private const PM_AND_ABOVE = ['manager', 'hr', 'management'];

    /** The department whose active staff are tagged as helpers on every raised card. */
    private const ADMIN_DEPARTMENT = 'Admin';

    /** The Position title resolved to the card's owner, CR-18-style. */
    private const OWNER_POSITION = 'Finance Manager';

    private const ATTACHMENT_DISK = 'local';

    private const IMAGE_MIMES = 'jpeg,jpg,png,gif,webp';

    private const IMAGE_MAX_KB = 8192;

    // ── Board screen ──────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function screenData(Request $request, ?Employee $employee): array
    {
        $requests = OfficeRequest::with(['employee:id,name,nickname', 'workItem.participants'])
            ->orderByDesc('votes')->orderByDesc('created_at')->get();

        $myVotes = $employee
            ? OfficeRequestVote::where('employee_id', $employee->id)->pluck('office_request_id')
            : collect();

        // Precomputed per row (not per request() call) so the board can show admin-team
        // controls (note/done) only on the cards this viewer may actually act on.
        $hrOrManagement = $this->hasTenantRole($request, ['hr', 'management']);
        $adminRequestIds = $requests->filter(
            fn (OfficeRequest $r) => $hrOrManagement || $this->employeeIsCardAdmin($employee, $r->workItem)
        )->pluck('id');

        return [
            'orRequests' => $requests,
            'orGrouped' => [
                'open' => $requests->where('status', 'open')->values(),
                'in_progress' => $requests->where('status', 'in_progress')->values(),
                'done' => $requests->where('status', 'done')->values(),
            ],
            'orPantryWishlist' => $requests->where('category', 'pantry')
                ->where('status', '!=', 'done')->sortByDesc('votes')->values(),
            'orMyVotes' => $myVotes,
            'orAdminRequestIds' => $adminRequestIds,
            'orCategories' => OfficeRequest::CATEGORIES,
            'orUrgencies' => OfficeRequest::URGENCIES,
            'orCanSeeInsights' => $this->hasTenantRole($request, self::PM_AND_ABOVE),
        ];
    }

    // ── Raise ─────────────────────────────────────────────────────────

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');
        $tenant = app(CurrentTenant::class)->get();
        abort_unless($tenant, 403);

        $data = $request->validate([
            'category' => ['required', Rule::in(OfficeRequest::CATEGORIES)],
            'title' => ['required', 'string', 'max:160'],
            'description' => ['required', 'string', 'max:2000'],
            'location' => ['required', 'string', 'max:160'],
            'urgency' => ['required', Rule::in(OfficeRequest::URGENCIES)],
            'urgency_reason' => ['required_if:urgency,urgent', 'nullable', 'string', 'max:500'],
            'photo' => ['nullable', 'image', 'mimes:'.self::IMAGE_MIMES, 'max:'.self::IMAGE_MAX_KB],
            'vehicle_plate' => ['nullable', 'string', 'max:20'],
            'vehicle_mileage' => ['nullable', 'integer', 'min:0'],
            'vehicle_last_service_at' => ['nullable', 'date'],
        ]);

        $urgent = $data['urgency'] === 'urgent';
        $owner = $this->resolveOwner($tenant->id);
        $helpers = $this->adminDepartmentEmployees($tenant->id)->where('id', '!=', $owner->id);

        $due = Carbon::now()->addDays($urgent ? 1 : 5)->toDateString();
        $card = WorkItem::create([
            'employee_id' => $owner->id,
            'title' => $data['title'],
            'type' => 'task',
            'priority' => $urgent ? 'high' : 'medium',
            'due_at' => $due,
            'labels' => ['office'],
            'status' => 'todo',
            'progress' => 0,
            'sort_order' => (int) WorkItem::where('employee_id', $owner->id)->where('status', 'todo')->max('sort_order') + 1,
        ]);

        if ($helpers->isNotEmpty()) {
            $card->participants()->sync($helpers->mapWithKeys(fn (Employee $e) => [$e->id => ['role' => 'helper']])->all());
        }

        $photoPath = null;
        if ($request->hasFile('photo') && $request->file('photo')->isValid()) {
            $photoPath = $request->file('photo')->store('office-request-photos', self::ATTACHMENT_DISK);
            ImageCompressor::compress(Storage::disk(self::ATTACHMENT_DISK)->path($photoPath), (string) $request->file('photo')->getMimeType());
        }

        $officeRequest = OfficeRequest::create([
            'employee_id' => $employee->id,
            'category' => $data['category'],
            'title' => $data['title'],
            'description' => $data['description'],
            'photo_path' => $photoPath,
            'location' => $data['location'],
            'urgency' => $data['urgency'],
            'urgency_reason' => $data['urgency_reason'] ?? null,
            'status' => 'open',
            'work_item_id' => $card->id,
            'votes' => 1,
            'vehicle_plate' => $data['vehicle_plate'] ?? null,
            'vehicle_mileage' => $data['vehicle_mileage'] ?? null,
            'vehicle_last_service_at' => $data['vehicle_last_service_at'] ?? null,
        ]);

        OfficeRequestVote::create(['office_request_id' => $officeRequest->id, 'employee_id' => $employee->id]);

        if ($urgent) {
            $recipients = collect([$owner->user_id])
                ->merge(DB::table('tenant_user')->where('tenant_id', $tenant->id)
                    ->whereIn('role', Permissions::MANAGEMENT_TIER)->pluck('user_id'))
                ->filter()->unique();

            foreach ($recipients as $userId) {
                AppNotification::send($userId, "Urgent office request: {$officeRequest->title}", $data['description'], url('/app/office-requests'));
            }
        }

        if ($request->wantsJson()) {
            return response()->json(['id' => $officeRequest->id, 'work_item_id' => $card->id, 'votes' => $officeRequest->votes], 201);
        }

        return redirect()->route('app.screen', 'office-requests')->with('ok', 'Request raised.');
    }

    // ── Duplicate check ───────────────────────────────────────────────

    public function similar(Request $request): JsonResponse
    {
        $title = trim((string) $request->query('title', ''));
        if ($title === '') {
            return response()->json([]);
        }

        $matches = OfficeRequest::where('status', 'open')
            ->whereRaw('LOWER(title) LIKE ?', ['%'.strtolower($title).'%'])
            ->orderByDesc('votes')
            ->get(['id', 'title', 'votes']);

        return response()->json($matches->map(fn (OfficeRequest $r) => ['id' => $r->id, 'title' => $r->title, 'votes' => $r->votes])->values());
    }

    // ── Upvote (idempotent — same person never counts twice) ──────────

    public function upvote(Request $request, OfficeRequest $officeRequest): JsonResponse
    {
        $this->assertTenant($officeRequest);
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403);

        $vote = OfficeRequestVote::firstOrCreate([
            'office_request_id' => $officeRequest->id,
            'employee_id' => $employee->id,
        ]);
        if ($vote->wasRecentlyCreated) {
            $officeRequest->increment('votes');
        }

        return response()->json(['votes' => $officeRequest->fresh()->votes]);
    }

    // ── Comments (scope 2) ───────────────────────────────────────────

    public function comment(Request $request, OfficeRequest $officeRequest): JsonResponse|RedirectResponse
    {
        $this->assertTenant($officeRequest);
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403);

        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);

        OfficeRequestComment::create([
            'office_request_id' => $officeRequest->id,
            'employee_id' => $employee->id,
            'body' => $data['body'],
        ]);

        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('ok', 'Comment added.');
    }

    // ── Admin note ────────────────────────────────────────────────────

    public function adminNote(Request $request, OfficeRequest $officeRequest): JsonResponse
    {
        $this->assertTenant($officeRequest);
        abort_unless($this->isAdminTeam($request, $request->attributes->get('employee'), $officeRequest), 403);

        $data = $request->validate(['note' => ['required', 'string', 'max:1000']]);

        $officeRequest->update([
            'admin_note' => $data['note'],
            'status' => $officeRequest->status === 'open' ? 'in_progress' : $officeRequest->status,
        ]);

        return response()->json(['ok' => true]);
    }

    // ── Done ──────────────────────────────────────────────────────────

    public function done(Request $request, OfficeRequest $officeRequest): JsonResponse
    {
        $this->assertTenant($officeRequest);
        abort_unless($this->isAdminTeam($request, $request->attributes->get('employee'), $officeRequest), 403);

        $data = $request->validate(['note' => ['required', 'string', 'max:1000']]);

        $officeRequest->update([
            'status' => 'done',
            'closing_note' => $data['note'],
            'done_at' => Carbon::now(),
        ]);

        $officeRequest->workItem?->update(['status' => 'done', 'done_at' => Carbon::now()]);

        $voterEmployeeIds = OfficeRequestVote::where('office_request_id', $officeRequest->id)->pluck('employee_id');
        $userIds = Employee::whereIn('id', $voterEmployeeIds)->pluck('user_id')->filter()->unique();
        foreach ($userIds as $userId) {
            AppNotification::send($userId, "Office request done: {$officeRequest->title}", $data['note'], url('/app/office-requests'));
        }

        return response()->json(['ok' => true]);
    }

    // ── Reopen (requester only, within 3 days of done_at) ──────────────

    public function reopen(Request $request, OfficeRequest $officeRequest): JsonResponse
    {
        $this->assertTenant($officeRequest);
        $employee = $request->attributes->get('employee');
        abort_unless($employee && $employee->id === $officeRequest->employee_id, 403);

        if ($officeRequest->status !== 'done' || ! $officeRequest->withinReopenWindow()) {
            return response()->json(['message' => 'This request can no longer be reopened.'], 422);
        }

        $officeRequest->update(['status' => 'open', 'done_at' => null, 'closing_note' => null]);
        $officeRequest->workItem?->update(['status' => 'todo', 'done_at' => null]);

        return response()->json(['ok' => true]);
    }

    // ── Photo ─────────────────────────────────────────────────────────

    public function photoShow(Request $request, OfficeRequest $officeRequest): StreamedResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403);
        $this->assertTenant($officeRequest);
        abort_unless($officeRequest->photo_path && Storage::disk(self::ATTACHMENT_DISK)->exists($officeRequest->photo_path), 404);

        return Storage::disk(self::ATTACHMENT_DISK)->response($officeRequest->photo_path, basename($officeRequest->photo_path));
    }

    // ── Insights (PM and above; JSON or HTML, gate + view live in AppController) ──

    /** @return array<string, mixed> */
    public function insightsData(Request $request): array
    {
        $month = (string) ($request->query('month') ?: Carbon::now()->format('Y-m'));
        $start = Carbon::parse($month.'-01')->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $raised = OfficeRequest::whereBetween('created_at', [$start, $end]);
        $requests = (clone $raised)->count();
        $byCategory = (clone $raised)->selectRaw('category, count(*) as c')->groupBy('category')->pluck('c', 'category');
        $topVoted = (clone $raised)->orderByDesc('votes')->orderBy('id')->take(5)->get(['title', 'votes'])
            ->map(fn (OfficeRequest $r) => ['title' => $r->title, 'votes' => (int) $r->votes])->values();

        $doneInMonth = OfficeRequest::whereBetween('done_at', [$start, $end])->get(['created_at', 'done_at']);
        $avgDays = $doneInMonth->isEmpty() ? 0.0 : round(
            $doneInMonth->avg(fn (OfficeRequest $r) => $r->created_at->diffInMinutes($r->done_at) / 1440),
            2 // QA F5: two decimals in JSON, one on the page
        );

        return [
            'month' => $month,
            'requests' => $requests,
            'avg_days_to_close' => $avgDays,
            'by_category' => $byCategory,
            'top_voted' => $topVoted,
        ];
    }

    // ── Shared helpers ────────────────────────────────────────────────

    private function assertTenant(OfficeRequest $officeRequest): void
    {
        abort_unless($officeRequest->tenant_id === app(CurrentTenant::class)->id(), 404);
    }

    /** Admin team = the resolved card owner, the tagged (helper) participants, and HR / management. */
    private function isAdminTeam(Request $request, ?Employee $employee, OfficeRequest $officeRequest): bool
    {
        return $this->hasTenantRole($request, ['hr', 'management'])
            || $this->employeeIsCardAdmin($employee, $officeRequest->workItem);
    }

    /** The owner-or-helper half of isAdminTeam(), usable without a request (board precompute). */
    private function employeeIsCardAdmin(?Employee $employee, ?WorkItem $card): bool
    {
        if (! $employee || ! $card) {
            return false;
        }

        if ($card->employee_id === $employee->id) {
            return true;
        }

        $participants = $card->relationLoaded('participants') ? $card->participants : $card->participants()->get();

        return $participants->contains(fn (Employee $p) => $p->id === $employee->id && ($p->pivot->role ?? null) === 'helper');
    }

    /**
     * The active employee whose Position title is "Finance Manager" (CR-18's
     * RecurringTask::resolveOwner() resolution), falling back to the first active
     * HR/management employee when the position has no holder — logged to OPEN.md.
     */
    private function resolveOwner(int $tenantId): Employee
    {
        $holder = Employee::active()->where('tenant_id', $tenantId)
            ->whereHas('positionBand', fn ($q) => $q->where('title', self::OWNER_POSITION))
            ->orderBy('id')->first();
        if ($holder) {
            return $holder;
        }

        $fallbackUserIds = DB::table('tenant_user')->where('tenant_id', $tenantId)
            ->whereIn('role', ['hr', 'management', 'director'])->pluck('user_id');

        $fallback = Employee::active()->where('tenant_id', $tenantId)
            ->whereIn('user_id', $fallbackUserIds)->orderBy('id')->first();

        abort_unless($fallback, 500, 'No Finance Manager or HR/management employee to own Office Requests.');

        return $fallback;
    }

    /**
     * Active employees of the department named "Admin" — the helper roster on every card.
     *
     * @return Collection<int, Employee>
     */
    private function adminDepartmentEmployees(int $tenantId): Collection
    {
        $dept = Department::where('tenant_id', $tenantId)->where('name', 'like', self::ADMIN_DEPARTMENT.'%')->orderBy('id')->first(); // QA F1: the real tenant calls it "Administration"

        return $dept
            ? Employee::active()->where('tenant_id', $tenantId)->where('department_id', $dept->id)->get()
            : collect();
    }
}
