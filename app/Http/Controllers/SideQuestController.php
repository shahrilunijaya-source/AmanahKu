<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Reaction;
use App\Models\SideQuest;
use App\Models\SideQuestPost;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CR-26 Side Quests: optional, non-KPI challenges HR/director keep 2-3 live at a
 * time in The Playground. Completion is self-declared (a note, a photo, or both),
 * posted to a feed anyone can react to, and earns a 30-day badge that never
 * counts toward anything. Route-model binding is not tenant-scoped
 * (SubstituteBindings runs before ResolveTenant — same trap as BigDeal/PlotTwist),
 * so every action re-checks tenant_id before touching a bound quest or post.
 */
class SideQuestController extends Controller
{
    private const CURATE_ROLES = ['hr', 'director'];

    /** HR/director publish a new live quest. */
    public function store(Request $request): RedirectResponse
    {
        $this->authorizeTenantRole($request, self::CURATE_ROLES);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'blurb' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->assertUnderLiveCap();

        $employee = $request->attributes->get('employee');

        $quest = SideQuest::create([
            'title' => $data['title'],
            'blurb' => $data['blurb'] ?? null,
            'status' => 'live',
            'created_by' => $employee?->id,
        ]);

        AuditLog::record('side_quest.published', "side_quest:{$quest->id}");

        return back()->with('ok', 'Quest published.');
    }

    /** HR/director retire a live quest; its old posts stay in the feed. */
    public function retire(Request $request, SideQuest $quest): RedirectResponse
    {
        $this->assertSameTenant($quest);
        $this->authorizeTenantRole($request, self::CURATE_ROLES);

        $quest->update(['status' => 'retired']);
        AuditLog::record('side_quest.retired', "side_quest:{$quest->id}");

        return back()->with('ok', 'Quest retired.');
    }

    /** HR/director turn a staff-suggested quest live (same live cap as publishing). */
    public function approve(Request $request, SideQuest $quest): RedirectResponse
    {
        $this->assertSameTenant($quest);
        $this->authorizeTenantRole($request, self::CURATE_ROLES);
        abort_unless($quest->status === 'suggested', 422);

        $this->assertUnderLiveCap();

        $quest->update(['status' => 'live']);
        AuditLog::record('side_quest.approved', "side_quest:{$quest->id}");

        return back()->with('ok', 'Quest is live.');
    }

    /** Anyone signed in suggests a quest for HR to pick up. */
    public function suggest(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
        ]);

        $employee = $request->attributes->get('employee');

        $quest = SideQuest::create([
            'title' => $data['title'],
            'status' => 'suggested',
            'suggested_by' => $employee?->id,
        ]);

        AuditLog::record('side_quest.suggested', "side_quest:{$quest->id}");

        return back()->with('ok', 'Sent to HR. Thanks.');
    }

    /**
     * Self-declared completion: a one-liner, a photo, or both. Live quests only,
     * once per person per quest. Writes the post, a 30-day badge and the audit
     * entry in one go.
     */
    public function complete(Request $request, SideQuest $quest): RedirectResponse
    {
        $this->assertSameTenant($quest);

        $employee = $request->attributes->get('employee');
        abort_unless($employee instanceof Employee, 403);

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:280'],
            'photo' => ['nullable', 'image', 'max:5120'],
        ]);

        if (blank($data['note'] ?? null) && ! $request->hasFile('photo')) {
            throw ValidationException::withMessages(['note' => 'Add a one-liner or a photo.']);
        }

        if ($quest->status !== 'live') {
            throw ValidationException::withMessages(['quest' => 'This quest is not live any more.']);
        }

        if (SideQuestPost::where('quest_id', $quest->id)->where('employee_id', $employee->id)->exists()) {
            throw ValidationException::withMessages(['quest' => 'Already completed.']);
        }

        try {
            $post = SideQuestPost::create([
                'quest_id' => $quest->id,
                'employee_id' => $employee->id,
                'note' => $data['note'] ?? null,
                'photo_path' => $request->file('photo')?->store('side-quests/photos', 'local'),
            ]);
        } catch (QueryException $e) {
            if (str_starts_with((string) $e->getCode(), '23')) {
                throw ValidationException::withMessages(['quest' => 'Already completed.']);
            }
            throw $e;
        }

        DB::table('side_quest_badges')->insert([
            'tenant_id' => app(CurrentTenant::class)->id(),
            'employee_id' => $employee->id,
            'quest_id' => $quest->id,
            'post_id' => $post->id,
            'earned_at' => now(),
            'expires_at' => now()->addDays(30),
        ]);

        AuditLog::record('side_quest.completed', "side_quest_post:{$post->id}");

        return back()->with('ok', 'Posted.');
    }

    /** Any signed-in tenant member reads a completion's photo. */
    public function photo(SideQuestPost $post): StreamedResponse
    {
        $this->assertSameTenantPost($post);
        abort_unless($post->photo_path !== null, 404);
        abort_unless(Storage::disk('local')->exists($post->photo_path), 404);

        return Storage::disk('local')->response($post->photo_path);
    }

    /** CR-30 reaction, one per person per post, toggle semantics. */
    public function react(Request $request, SideQuestPost $post): JsonResponse
    {
        $this->assertSameTenantPost($post);

        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403);

        $data = $request->validate([
            'reaction' => ['required', 'string', 'in:'.implode(',', Reaction::activeKeys())],
        ]);

        $had = DB::table('side_quest_reactions')->where('post_id', $post->id)
            ->where('employee_id', $employee->id)->pluck('reaction');

        DB::table('side_quest_reactions')->where('post_id', $post->id)->where('employee_id', $employee->id)->delete();

        if (! $had->contains($data['reaction'])) {
            try {
                DB::table('side_quest_reactions')->insert([
                    'post_id' => $post->id,
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

        return response()->json(['ok' => true, 'html' => $this->reactPartial($post, $employee)]);
    }

    /** The picker + tally region for one post — used by react() and screenData()'s first paint. */
    public function reactPartial(SideQuestPost $post, ?Employee $viewer): string
    {
        $rows = DB::table('side_quest_reactions')->where('post_id', $post->id)->get();

        return view('partials.dash.side-quest-react', [
            'postId' => $post->id,
            'counts' => $rows->groupBy('reaction')->map->count()->all(),
            'mine' => $viewer ? $rows->where('employee_id', $viewer->id)->pluck('reaction')->values()->all() : [],
        ])->render();
    }

    /** @return array<string, mixed> */
    public function screenData(Request $request, ?Employee $employee): array
    {
        $canCurate = $this->hasTenantRole($request, self::CURATE_ROLES);

        $quests = SideQuest::where('status', 'live')->orderBy('id')->get();

        $myPosts = $employee
            ? SideQuestPost::where('employee_id', $employee->id)->get()->keyBy('quest_id')
            : collect();

        $myBadgeExpiry = $employee
            ? DB::table('side_quest_badges')->where('employee_id', $employee->id)->pluck('expires_at', 'quest_id')
            : collect();

        $posts = SideQuestPost::with(['quest', 'employee'])->orderByDesc('id')->get();

        return [
            'quests' => $quests,
            'myPosts' => $myPosts,
            'myBadgeExpiry' => $myBadgeExpiry,
            'posts' => $posts->map(fn (SideQuestPost $post) => [
                'post' => $post,
                'reactHtml' => $this->reactPartial($post, $employee),
            ]),
            'suggestions' => $canCurate ? SideQuest::where('status', 'suggested')->orderBy('id')->get() : collect(),
            'canCurate' => $canCurate,
        ];
    }

    /** Publishing/retiring/approving refuse a 4th live quest — 2 to 3 at a time. */
    private function assertUnderLiveCap(): void
    {
        if (SideQuest::where('status', 'live')->count() >= SideQuest::MAX_LIVE) {
            throw ValidationException::withMessages(['title' => 'At most 3 quests can be live at a time — retire one first.']);
        }
    }

    private function assertSameTenant(SideQuest $quest): void
    {
        abort_unless($quest->tenant_id === app(CurrentTenant::class)->id(), 404);
    }

    private function assertSameTenantPost(SideQuestPost $post): void
    {
        abort_unless($post->tenant_id === app(CurrentTenant::class)->id(), 404);
    }
}
