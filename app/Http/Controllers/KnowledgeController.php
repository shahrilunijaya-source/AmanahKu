<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\KnowledgeAttachment;
use App\Models\KnowledgeComment;
use App\Models\KnowledgeContribution;
use App\Models\KnowledgeEntry;
use App\Models\KnowledgeReaction;
use App\Models\KnowledgeRead;
use App\Models\KnowledgeSegment;
use App\Models\KnowledgeStar;
use App\Services\FeatureManager;
use App\Support\Amanahku;
use App\Support\ImageCompressor;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Knowledge Bank — company-wide lesson sharing with a mandatory monthly habit.
 *
 *  - screenData(): the full /app/knowledge-bank page (segment tree, entry list,
 *    culture stats, and an HR/management compliance grid).
 *  - context(): the always-on data for the global chrome (header button pulse +
 *    unread badge, the slide-over panel feed, and the dashboard reminder). Merged
 *    into every screen render by AppController, so it stays deliberately lean.
 */
class KnowledgeController extends Controller
{
    /** Roles allowed to moderate (delete) any comment. */
    private const PRIVILEGED_ROLES = ['manager', 'management', 'hr'];

    /** Roles allowed to see the monthly compliance grid (who owes a lesson). */
    private const COMPLIANCE_ROLES = ['management', 'hr'];

    /** Unread / "New" window: entries newer than this count toward the badge. */
    private const NEW_DAYS = 30;

    /** Entries per page on the full Knowledge Bank screen. */
    private const PAGE_SIZE = 20;

    /** Pictures per lesson. */
    private const MAX_IMAGES = 10;

    /** Private disk pictures live on — reached only via attachment(). */
    private const ATTACHMENT_DISK = 'local';

    private const IMAGE_MIMES = 'jpeg,jpg,png,gif,webp';

    private const IMAGE_MAX_KB = 8192;

    // ── Full page ───────────────────────────────────────────────────────────

    /**
     * Data for the dedicated Knowledge Bank screen.
     *
     * @return array<string, mixed>
     */
    public function screenData(Request $request, ?Employee $employee): array
    {
        $segments = KnowledgeSegment::whereNull('parent_id')
            ->with('children')
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        $seg = $request->query('seg');
        $subseg = $request->query('subseg');
        $q = trim((string) $request->query('q', ''));

        $entries = KnowledgeEntry::with([
            'employee:id,name,nickname,initials,avatar_color,position',
            'segment:id,label',
            'subSegment:id,label',
            'attachments',
            'comments' => fn ($c) => $c->with('employee:id,name,nickname,initials,avatar_color')->orderBy('id'),
        ])
            ->withCount(['stars', 'comments'])
            ->when($seg, fn ($b) => $b->where('seg_id', $seg))
            ->when($subseg, fn ($b) => $b->where('subseg_id', $subseg))
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w
                ->where('title', 'like', "%$q%")
                ->orWhere('body', 'like', "%$q%")))
            ->orderByDesc('id')
            ->paginate(self::PAGE_SIZE)
            ->withQueryString();

        // Read / starred state is only needed for the entries actually on this page, so
        // scope both lookups to the page's ids (AK-DB-05) — the old pluck-all loaded a
        // per-employee id list that grows for the life of the account on every render.
        $entryIds = $entries->pluck('id');

        $readIds = $employee
            ? KnowledgeRead::where('employee_id', $employee->id)->whereIn('entry_id', $entryIds)->pluck('entry_id')->all()
            : [];

        // Entry ids the current employee has starred — drives the toggled star state.
        $starredIds = $employee
            ? KnowledgeStar::where('employee_id', $employee->id)->whereIn('entry_id', $entryIds)->pluck('entry_id')->all()
            : [];

        $role = $request->attributes->get('tenantRole', 'employee');
        $privileged = in_array($role, self::PRIVILEGED_ROLES, true);
        $canSeeCompliance = in_array($role, self::COMPLIANCE_ROLES, true);

        return array_merge([
            'segments' => $segments,
            'entries' => $entries,
            'readIds' => $readIds,
            'starredIds' => $starredIds,
            'reactionCounts' => $this->reactionCounts($entryIds->all()),
            'myReactions' => $this->myReactions($entryIds->all(), $employee),
            'newCutoff' => now()->subDays(self::NEW_DAYS),
            'filters' => ['seg' => $seg, 'subseg' => $subseg, 'q' => $q],
            'canSubmit' => (bool) $employee,
            'privileged' => $privileged,
            'canSeeCompliance' => $canSeeCompliance,
            'compliance' => $canSeeCompliance ? $this->compliance() : null,
        ], $this->stats(), $this->monthLabels());
    }

    // ── Global chrome context (merged into every screen) ─────────────────────

    /**
     * Always-on data for the header button, slide-over panel and dashboard
     * reminder. Returns kbEnabled=false (and nothing else heavy) when the module
     * is off for the tenant or the user has no employee record here.
     *
     * @return array<string, mixed>
     */
    public function context(?Employee $employee): array
    {
        $tenant = app(CurrentTenant::class)->get();

        if (! $tenant || ! app(FeatureManager::class)->screenAllowed($tenant, 'knowledge-bank')) {
            return ['kbEnabled' => false];
        }

        // Unread count drives the header badge only — the panel no longer lists entries.
        $cutoff = now()->subDays(self::NEW_DAYS);
        $unreadCount = 0;
        if ($employee) {
            // Subquery instead of pluck()->all(): the per-employee read-receipt list
            // grows monotonically and would otherwise be loaded into PHP on every render.
            $unreadCount = KnowledgeEntry::where('created_at', '>=', $cutoff)
                ->whereNotIn('id', KnowledgeRead::where('employee_id', $employee->id)->select('entry_id'))
                ->count();
        }

        // Add-lesson form tree (top level + sub-segments). Cached per tenant —
        // segments change only via storeSegment(), which busts it. Cache plain
        // arrays, never live models: the file store serialize()s the payload, and
        // unserializing model objects breaks (__PHP_Incomplete_Class) when the
        // entry outlives a code/autoload change.
        $segmentsForm = Cache::remember(
            'kb:tree:'.$tenant->id,
            now()->addMinutes(10),
            fn () => KnowledgeSegment::whereNull('parent_id')
                ->with('children:id,parent_id,label')
                ->orderBy('sort_order')->orderBy('id')
                ->get(['id', 'label'])
                ->map(fn (KnowledgeSegment $s) => [
                    'id' => $s->id,
                    'label' => $s->label,
                    'children' => $s->children
                        ->map(fn (KnowledgeSegment $c) => ['id' => $c->id, 'label' => $c->label])
                        ->values()->all(),
                ])->values()->all(),
        );

        $owes = $employee ? $this->owesLesson($employee) : false;

        return array_merge([
            'kbEnabled' => true,
            'kbCanSubmit' => (bool) $employee,
            'kbOwes' => $owes,
            'kbUnread' => $unreadCount,
            'kbDaysLeft' => max(0, now()->daysInMonth - now()->day),
            'kbSegmentsForm' => $segmentsForm,
            'kbPopular' => $this->popularEntries(),
        ], $this->stats('kb'), $this->monthLabels('kb'));
    }

    // ── Writes ───────────────────────────────────────────────────────────────

    /** Any employee in the workspace may share a lesson. */
    public function store(Request $request): RedirectResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');

        $segIds = $this->segmentIds();
        $data = $request->validate([
            'seg_id' => ['required', Rule::in($segIds)],
            'subseg_id' => ['nullable', Rule::in($segIds)],
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
            'tags' => ['nullable', 'string', 'max:200'],
            'images' => ['nullable', 'array', 'max:'.self::MAX_IMAGES],
            'images.*' => ['image', 'mimes:'.self::IMAGE_MIMES, 'max:'.self::IMAGE_MAX_KB],
            'captions' => ['nullable', 'array'],
            'captions.*' => ['nullable', 'string', 'max:200'],
        ], [
            'images.max' => 'You can attach up to '.self::MAX_IMAGES.' pictures.',
            'images.*.image' => 'Attachments must be pictures.',
            'images.*.mimes' => 'Pictures must be JPG, PNG, GIF, or WebP.',
            'images.*.max' => 'Each picture must be 8 MB or smaller.',
        ]);

        $tags = collect(explode(',', (string) ($data['tags'] ?? '')))
            ->map(fn ($t) => trim($t))->filter()->values()->all();

        $entry = KnowledgeEntry::create([
            'seg_id' => $data['seg_id'],
            'subseg_id' => $data['subseg_id'] ?? null,
            'employee_id' => $employee->id,
            'title' => $data['title'],
            'body' => $data['body'],
            'tags' => $tags ?: null,
        ]);

        $this->storeImages($entry, (array) $request->file('images', []), (array) ($data['captions'] ?? []));

        // Mark this calendar month's contribution as fulfilled (clears the reminder
        // and turns the panel banner green). Author never "owes" on entries they
        // just wrote — mark the entry as read for them too.
        $this->markContributed($employee);
        $this->forgetStatsCache();
        KnowledgeRead::firstOrCreate(
            ['employee_id' => $employee->id, 'entry_id' => $entry->id],
            ['read_at' => now()],
        );

        AuditLog::record('Shared a lesson', $entry->title);

        return back()->with('ok', 'Lesson shared with the company — "'.$entry->title.'".');
    }

    /**
     * Persist validated image uploads as ordered, captioned KnowledgeAttachment rows,
     * compressing each before it lands on disk. Called after the entry already exists so a
     * rejected batch can never orphan files.
     *
     * @param  array<int, UploadedFile|null>  $files
     * @param  array<int, string|null>  $captions
     */
    private function storeImages(KnowledgeEntry $entry, array $files, array $captions, int $startOrder = 0): void
    {
        $order = $startOrder;
        foreach (array_values($files) as $i => $file) {
            if (! $file || ! $file->isValid()) {
                continue;
            }

            $path = $file->store('knowledge-attachments', self::ATTACHMENT_DISK);
            abort_unless($path !== false, 500, 'Picture could not be stored.');

            ImageCompressor::compress(Storage::disk(self::ATTACHMENT_DISK)->path($path), (string) $file->getMimeType());

            $entry->attachments()->create([
                'tenant_id' => $entry->tenant_id,
                'path' => $path,
                'name' => $file->getClientOriginalName() ?: 'picture',
                'mime' => $file->getMimeType(),
                'size' => Storage::disk(self::ATTACHMENT_DISK)->size($path),
                'caption' => trim((string) ($captions[$i] ?? '')) ?: null,
                'sort_order' => $order,
            ]);
            $order++;
        }
    }

    /** The author may edit their own entry's title/body/tags at any time. */
    public function update(Request $request, KnowledgeEntry $entry): RedirectResponse|JsonResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');
        abort_unless($entry->tenant_id === app(CurrentTenant::class)->id(), 403);
        abort_unless($entry->employee_id === $employee->id, 403, 'You can only edit your own lesson.');

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
            'tags' => ['nullable', 'string', 'max:200'],
            'images' => ['nullable', 'array'],
            'images.*' => ['image', 'mimes:'.self::IMAGE_MIMES, 'max:'.self::IMAGE_MAX_KB],
            'captions' => ['nullable', 'array'],
            'captions.*' => ['nullable', 'string', 'max:200'],
            'remove_images' => ['nullable', 'array'],
            'remove_images.*' => ['integer'],
            'reorder' => ['nullable', 'array'],
            'reorder.*' => ['integer'],
            'caption_updates' => ['nullable', 'array'],
            'caption_updates.*' => ['nullable', 'string', 'max:200'],
        ], [
            'images.*.image' => 'Attachments must be pictures.',
            'images.*.mimes' => 'Pictures must be JPG, PNG, GIF, or WebP.',
            'images.*.max' => 'Each picture must be 8 MB or smaller.',
        ]);

        $tags = collect(explode(',', (string) ($data['tags'] ?? '')))
            ->map(fn ($t) => trim($t))->filter()->values()->all();

        $entry->update([
            'title' => $data['title'],
            'body' => $data['body'],
            'tags' => $tags ?: null,
        ]);

        $removeIds = array_map('intval', $data['remove_images'] ?? []);
        if ($removeIds !== []) {
            $toRemove = $entry->attachments()->whereIn('id', $removeIds)->get();
            foreach ($toRemove as $att) {
                Storage::disk(self::ATTACHMENT_DISK)->delete($att->path);
                $att->delete();
            }
        }

        // Reorder governs only the surviving pre-existing pictures — validated and applied
        // BEFORE new uploads are appended, so its id set matches what the client actually
        // sent (a freshly uploaded picture has no id yet on the client and is never part of
        // the reorder payload; it always lands after the reordered set, in upload order).
        if (! empty($data['reorder'])) {
            $reorder = array_map('intval', $data['reorder']);
            $survivingIds = $entry->attachments()->pluck('id')->sort()->values()->all();
            $requestedIds = collect($reorder)->sort()->values()->all();
            abort_unless($survivingIds === $requestedIds, 422, 'The picture order no longer matches this lesson\'s pictures.');

            foreach ($reorder as $position => $id) {
                $entry->attachments()->where('id', $id)->update(['sort_order' => $position]);
            }
        }

        $newFiles = array_values(array_filter((array) $request->file('images', []), fn ($f) => $f && $f->isValid()));
        $remainingCount = $entry->attachments()->count();
        abort_if($remainingCount + count($newFiles) > self::MAX_IMAGES, 422, 'A lesson can carry up to '.self::MAX_IMAGES.' pictures.');

        if ($newFiles !== []) {
            $startOrder = (int) ($entry->attachments()->max('sort_order') ?? -1) + 1;
            $this->storeImages($entry, $newFiles, (array) ($data['captions'] ?? []), $startOrder);
        }

        if (! empty($data['caption_updates'])) {
            foreach ($data['caption_updates'] as $id => $caption) {
                $entry->attachments()->where('id', (int) $id)->update(['caption' => trim((string) $caption) ?: null]);
            }
        }

        $this->forgetStatsCache();
        AuditLog::record('Edited a lesson', $entry->title);

        if ($request->expectsJson()) {
            return response()->json([
                'title' => $entry->title,
                'body' => $entry->body,
                'bodyHtml' => Amanahku::linkify($entry->body),
                'tags' => $entry->tags,
                'attachments' => $entry->attachments()->get()->map(fn (KnowledgeAttachment $a) => [
                    'id' => $a->id,
                    'url' => route('knowledge.attachments.show', $a),
                    'caption' => $a->caption,
                ]),
            ]);
        }

        return back()->with('ok', 'Lesson updated.');
    }

    /**
     * Stream a lesson's picture inline through a tenant-gated action — never a public URL.
     * A lesson is company-wide, so any employee in the same tenant may view it (unlike
     * message attachments, which are participant-gated).
     */
    public function attachment(Request $request, KnowledgeAttachment $attachment): StreamedResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403);
        abort_unless($attachment->tenant_id === app(CurrentTenant::class)->id(), 403);
        abort_unless(Storage::disk(self::ATTACHMENT_DISK)->exists($attachment->path), 404);

        return Storage::disk(self::ATTACHMENT_DISK)->response($attachment->path, $attachment->name);
    }

    /** Create a segment (top-level by default; sub-segment when a parent is given). */
    public function storeSegment(Request $request): RedirectResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');

        // A "Top-level segment" <select> option submits as an empty string, not an
        // absent field — normalize it so `nullable` actually applies.
        if ($request->input('parent_id') === '') {
            $request->merge(['parent_id' => null]);
        }

        $data = $request->validate([
            'label' => ['required', 'string', 'max:80'],
            'parent_id' => ['nullable', Rule::in($this->segmentIds(topLevelOnly: true))],
        ]);

        $segment = KnowledgeSegment::create([
            'label' => $data['label'],
            'parent_id' => $data['parent_id'] ?? null,
            'created_by' => $employee->id,
            'sort_order' => (int) (KnowledgeSegment::max('sort_order') ?? 0) + 1,
        ]);

        AuditLog::record('Created knowledge segment', $segment->label);
        $this->forgetStatsCache();

        return back()->with('ok', 'Segment "'.$segment->label.'" created.');
    }

    /** Toggle the current employee's star on an entry (one per person). */
    public function toggleStar(Request $request, KnowledgeEntry $entry): RedirectResponse|JsonResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');
        abort_unless($entry->tenant_id === app(CurrentTenant::class)->id(), 403);

        $existing = KnowledgeStar::where('entry_id', $entry->id)
            ->where('employee_id', $employee->id)
            ->first();

        $message = 'Starred.';

        if ($existing) {
            $existing->delete();
            $message = 'Star removed.';
        } else {
            try {
                KnowledgeStar::create(['entry_id' => $entry->id, 'employee_id' => $employee->id]);
            } catch (QueryException $e) {
                // 23xxx = the unique (entry_id, employee_id) duplicate-star guard. Anything else
                // is a real DB failure — don't mask it behind a friendly message.
                if (! str_starts_with((string) $e->getCode(), '23')) {
                    throw $e;
                }
                $message = 'You already starred this.';
            }
        }

        if ($request->expectsJson()) {
            return response()->json($this->entryState($entry, $employee));
        }

        return back()->with('ok', $message);
    }

    /**
     * Toggle one emoji reaction for the acting employee. One emoji per person per
     * entry — pressing the one you left is the undo. Mirrors TotController::react.
     */
    public function react(Request $request, KnowledgeEntry $entry): RedirectResponse|JsonResponse
    {
        abort_unless($entry->tenant_id === app(CurrentTenant::class)->id(), 403);

        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');

        $data = $request->validate([
            'emoji' => ['required', 'string', 'in:'.implode(',', KnowledgeEntry::EMOJI)],
        ]);

        $had = KnowledgeReaction::where('entry_id', $entry->id)
            ->where('employee_id', $employee->id)
            ->pluck('emoji');

        KnowledgeReaction::where('entry_id', $entry->id)
            ->where('employee_id', $employee->id)
            ->delete();

        if (! $had->contains($data['emoji'])) {
            try {
                KnowledgeReaction::create([
                    'entry_id' => $entry->id,
                    'employee_id' => $employee->id,
                    'emoji' => $data['emoji'],
                ]);
            } catch (QueryException $e) {
                if (! str_starts_with((string) $e->getCode(), '23')) {
                    throw $e;
                }
            }
        }

        return $request->expectsJson()
            ? response()->json($this->entryState($entry, $employee))
            : back();
    }

    /**
     * The reactive header of one entry — reaction tallies, this viewer's own
     * reaction and star, and the live comment count. Returned by react() and
     * toggleStar() so the drawer re-syncs after every action.
     *
     * @return array<string, mixed>
     */
    private function entryState(KnowledgeEntry $entry, ?Employee $employee): array
    {
        $reactions = $entry->reactions()->get();

        return [
            'reactions' => $reactions->groupBy('emoji')->map->count()->all(),
            'mine' => $employee ? $reactions->where('employee_id', $employee->id)->pluck('emoji')->values()->all() : [],
            'stars' => $entry->stars()->count(),
            'starred' => $employee ? $entry->stars()->where('employee_id', $employee->id)->exists() : false,
            'commentsCount' => $entry->comments()->count(),
        ];
    }

    /**
     * Emoji tallies per entry, keyed entry id => [emoji => count].
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, int>>
     */
    private function reactionCounts(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return KnowledgeReaction::whereIn('entry_id', $ids)
            ->get()
            ->groupBy('entry_id')
            ->map(fn (Collection $rows) => $rows->groupBy('emoji')->map->count()->all())
            ->all();
    }

    /**
     * The acting employee's own reactions, keyed entry id => list of emoji.
     *
     * @param  list<int>  $ids
     * @return array<int, list<string>>
     */
    private function myReactions(array $ids, ?Employee $employee): array
    {
        if ($ids === [] || ! $employee) {
            return [];
        }

        return KnowledgeReaction::whereIn('entry_id', $ids)
            ->where('employee_id', $employee->id)
            ->get()
            ->groupBy('entry_id')
            ->map(fn (Collection $rows) => $rows->pluck('emoji')->values()->all())
            ->all();
    }

    /**
     * The discussion for one entry, as JSON — feeds the slide-over comment
     * drawer (the same shape the T.O.T. drawer consumes).
     */
    public function commentsList(Request $request, KnowledgeEntry $entry): JsonResponse
    {
        abort_unless($entry->tenant_id === app(CurrentTenant::class)->id(), 403);

        $employee = $request->attributes->get('employee');
        $privileged = $this->hasTenantRole($request, self::PRIVILEGED_ROLES);

        $comments = $entry->comments()
            ->with('employee:id,name,nickname,initials,avatar_color')
            ->orderBy('id')
            ->get()
            ->map(fn (KnowledgeComment $c): array => [
                'id' => $c->id,
                'name' => $c->employee?->name ?? 'Unknown',
                'initials' => $c->employee?->initials ?? '–',
                'color' => $c->employee?->avatar_color ?? '#3a6ea5',
                'at' => $c->created_at?->diffForHumans(),
                'body' => $c->body,
                'canDelete' => $privileged || ($employee && $c->employee_id === $employee->id),
            ]);

        return response()->json(['comments' => $comments, 'commentsCount' => $comments->count()]);
    }

    /** Add a comment to an entry. Any employee in the workspace may comment. */
    public function comment(Request $request, KnowledgeEntry $entry): RedirectResponse|JsonResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');
        abort_unless($entry->tenant_id === app(CurrentTenant::class)->id(), 403);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        KnowledgeComment::create([
            'entry_id' => $entry->id,
            'employee_id' => $employee->id,
            'body' => $data['body'],
        ]);

        if ($request->expectsJson()) {
            return response()->json(['commentsCount' => $entry->comments()->count()]);
        }

        return back()->with('ok', 'Comment posted.');
    }

    /** Delete a comment — the author, or a privileged role, only. */
    public function deleteComment(Request $request, KnowledgeComment $comment): RedirectResponse|JsonResponse
    {
        abort_unless($comment->tenant_id === app(CurrentTenant::class)->id(), 403);

        $employee = $request->attributes->get('employee');
        $privileged = $this->hasTenantRole($request, self::PRIVILEGED_ROLES);
        abort_unless($privileged || ($employee && $comment->employee_id === $employee->id), 403);

        $entryId = $comment->entry_id;
        $comment->delete();

        if ($request->expectsJson()) {
            return response()->json(['commentsCount' => KnowledgeComment::where('entry_id', $entryId)->count()]);
        }

        return back()->with('ok', 'Comment deleted.');
    }

    /**
     * Clear the unread badge: record a read receipt for every unread recent entry
     * for the current employee. Called by the panel on open (fetch); returns JSON.
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $employee = $request->attributes->get('employee');
        if (! $employee) {
            return response()->json(['ok' => false, 'unread' => 0]);
        }

        $tenantId = app(CurrentTenant::class)->id();
        $readIds = KnowledgeRead::where('employee_id', $employee->id)->pluck('entry_id')->all();
        $unread = KnowledgeEntry::where('created_at', '>=', now()->subDays(self::NEW_DAYS))
            ->whereNotIn('id', $readIds ?: [0])
            ->pluck('id');

        $now = now();
        $rows = $unread->map(fn ($id) => [
            'tenant_id' => $tenantId,
            'employee_id' => $employee->id,
            'entry_id' => $id,
            'read_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        if ($rows !== []) {
            KnowledgeRead::insertOrIgnore($rows);
        }

        return response()->json(['ok' => true, 'unread' => 0]);
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    /** True when the employee has not logged a contribution this calendar month. */
    private function owesLesson(Employee $employee): bool
    {
        return ! KnowledgeContribution::where('employee_id', $employee->id)
            ->where('year', (int) now()->year)
            ->where('month', (int) now()->month)
            ->where('submitted', true)
            ->exists();
    }

    private function markContributed(Employee $employee): void
    {
        KnowledgeContribution::mark($employee, (int) now()->year, (int) now()->month);
    }

    /**
     * Company-wide culture stats. $prefix '' keys the full-page view
     * (total/segCount/contribPct/teamStreak); $prefix 'kb' keys the global chrome.
     *
     * @return array<string, mixed>
     */
    private function stats(string $prefix = ''): array
    {
        // Tenant-wide aggregates rendered into every screen's chrome — cached per
        // tenant and busted by the writes that change them (store/storeSegment).
        $raw = Cache::remember('kb:stats:'.app(CurrentTenant::class)->id(), now()->addMinutes(10), function (): array {
            $headcount = max(Employee::active()->count(), 1);
            $contributed = KnowledgeContribution::where('year', (int) now()->year)
                ->where('month', (int) now()->month)
                ->where('submitted', true)
                ->distinct('employee_id')->count('employee_id');

            return [
                'total' => KnowledgeEntry::count(),
                'segCount' => KnowledgeSegment::count(),
                'contribPct' => (int) round($contributed / $headcount * 100),
                'teamStreak' => $this->teamStreak(),
            ];
        });

        $key = fn (string $base) => $prefix === '' ? $base : $prefix.ucfirst($base);

        return collect($raw)->mapWithKeys(fn ($v, $k) => [$key($k) => $v])->all();
    }

    /**
     * The 3 lessons the tenant found most useful — ranked by helpful stars +
     * reactions, not recency. Uncached: it's a 3-row, index-backed query, and
     * caching it would show stale ranks right after a star/react toggle.
     *
     * @return array<int, array<string, mixed>>
     */
    private function popularEntries(): array
    {
        return KnowledgeEntry::with(['employee:id,name,nickname,initials,avatar_color', 'segment:id,label'])
            ->withCount(['stars', 'comments', 'reactions'])
            ->orderByRaw('stars_count + reactions_count desc')
            ->orderByDesc('id')
            ->limit(3)
            ->get()
            ->map(fn (KnowledgeEntry $e) => [
                'id' => $e->id,
                'seg' => $e->segment?->label,
                'title' => $e->title,
                'body' => $e->body,
                'author' => $e->employee?->name ?? 'Unknown',
                'initials' => $e->employee?->initials ?? '–',
                'color' => $e->employee?->avatar_color ?? config('amanahku.avatar_color'),
                'stars' => (int) $e->stars_count,
                'reactions' => (int) $e->reactions_count,
                'comments' => (int) $e->comments_count,
            ])->values()->all();
    }

    /** Bust the cached chrome aggregates after a write that changes them. */
    private function forgetStatsCache(): void
    {
        $tenantId = app(CurrentTenant::class)->id();
        Cache::forget('kb:stats:'.$tenantId);
        Cache::forget('kb:tree:'.$tenantId);
    }

    /**
     * Consecutive months (counting back from the current month) in which the
     * company logged at least one contribution. A light "habit alive" signal.
     */
    private function teamStreak(): int
    {
        // Bounded + DISTINCT in SQL: the streak loop only looks back 36 months, so
        // never load every contribution row ever written (headcount × months).
        $months = KnowledgeContribution::where('submitted', true)
            ->where('year', '>=', (int) now()->year - 4)
            ->distinct()
            ->get(['year', 'month'])
            ->map(fn ($c) => sprintf('%04d-%02d', $c->year, $c->month))
            ->unique()->flip();

        $streak = 0;
        $cursor = now()->startOfMonth();
        for ($i = 0; $i < 36; $i++) {
            if (! $months->has($cursor->format('Y-m'))) {
                break;
            }
            $streak++;
            $cursor = $cursor->subMonth();
        }

        return $streak;
    }

    /**
     * Per-employee submitted/not-yet status for the current month — the HR/management
     * compliance grid.
     *
     * @return array{progressPct:int, members:Collection}
     */
    private function compliance(): array
    {
        $submittedIds = KnowledgeContribution::where('year', (int) now()->year)
            ->where('month', (int) now()->month)
            ->where('submitted', true)
            ->pluck('employee_id')->all();

        $members = Employee::active()->orderBy('name')->get(['id', 'name', 'nickname', 'initials', 'avatar_color'])
            ->map(fn ($e) => [
                'name' => $e->display_name,
                'initials' => $e->initials,
                'color' => $e->avatar_color,
                'submitted' => in_array($e->id, $submittedIds, true),
            ]);

        $headcount = max($members->count(), 1);

        return [
            'progressPct' => (int) round(count($submittedIds) / $headcount * 100),
            'members' => $members,
        ];
    }

    /** Current month name in EN + BM (Carbon has no bundled BM locale here). */
    private function monthLabels(string $prefix = ''): array
    {
        $ms = [1 => 'Januari', 'Februari', 'Mac', 'April', 'Mei', 'Jun', 'Julai', 'Ogos', 'September', 'Oktober', 'November', 'Disember'];
        $en = now()->format('F');
        $key = fn (string $base) => $prefix === '' ? $base : $prefix.ucfirst($base);

        return [
            $key('monthEn') => $en,
            $key('monthMs') => $ms[(int) now()->month] ?? $en,
        ];
    }

    /**
     * Segment ids in the active tenant (the global scope handles tenant isolation;
     * we list them so validation rejects ids from other tenants).
     *
     * @return array<int>
     */
    private function segmentIds(bool $topLevelOnly = false): array
    {
        return KnowledgeSegment::when($topLevelOnly, fn ($b) => $b->whereNull('parent_id'))
            ->pluck('id')->all();
    }
}
