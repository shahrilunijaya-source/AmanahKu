<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Employee;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Services\FeatureManager;
use App\Tenancy\CurrentTenant;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * In-app 1-to-1 direct messaging.
 *
 *  - screenData(): the full /app/messages page (conversation list + active thread +
 *    composer + new-message recipient picker).
 *  - context(): the always-on chrome (header envelope badge + slide-over panel feed),
 *    merged into every screen render by AppController — kept deliberately lean and
 *    bounded because it runs on ~40 screens per navigation.
 *  - send/markRead/thread/unread(): the write + JSON endpoints backing the panel's
 *    inline chat and the ~30s unread-count poll.
 *
 * Everything is tenant-scoped (BelongsToTenant on Conversation/Message). A pair maps
 * to one canonical conversation row; read state is the recipient's read_at per message.
 */
class MessageController extends Controller
{
    /** Conversations surfaced in the slide-over panel feed. */
    private const PANEL_LIMIT = 15;

    /** Messages loaded into a single open thread (recent-most, ascending). */
    private const THREAD_LIMIT = 100;

    /** Private disk message attachments live on — reached only via attachment(). */
    private const ATTACHMENT_DISK = 'local';

    /** Ceiling on files per message, and the accepted extensions (images + PDF + Office docs). */
    private const MAX_ATTACHMENTS = 6;

    private const ATTACHMENT_MIMES = 'jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,txt,csv';

    /**
     * BM day and month names. Carbon ships no bundled BM locale here, so this mirrors
     * the hand-map already used by Amanahku::todayLabel, KnowledgeController::monthLabels
     * and timesheet-capture.js. Kept local rather than extracted: three other call sites
     * would have to move with it, which is a bigger change than this screen.
     *
     * @var array<int, string>
     */
    private const MS_DAYS = ['Ahad', 'Isnin', 'Selasa', 'Rabu', 'Khamis', 'Jumaat', 'Sabtu'];

    /** @var array<int, string> */
    private const MS_DAYS_SHORT = ['Ahd', 'Isn', 'Sel', 'Rab', 'Kha', 'Jum', 'Sab'];

    /** @var array<int, string> */
    private const MS_MONTHS = [1 => 'Januari', 'Februari', 'Mac', 'April', 'Mei', 'Jun', 'Julai', 'Ogos', 'September', 'Oktober', 'November', 'Disember'];

    /** @var array<int, string> */
    private const MS_MONTHS_SHORT = [1 => 'Jan', 'Feb', 'Mac', 'Apr', 'Mei', 'Jun', 'Jul', 'Ogo', 'Sep', 'Okt', 'Nov', 'Dis'];

    // ── Full page ───────────────────────────────────────────────────────────

    /**
     * Data for the dedicated /app/messages screen.
     *
     * @return array<string, mixed>
     */
    public function screenData(Request $request, ?Employee $employee): array
    {
        if (! $employee) {
            return ['msgConversations' => [], 'msgActive' => null, 'msgRecipients' => [], 'msgCanSend' => false];
        }

        $conversations = $this->conversationsFor($employee)
            ->map(fn (Conversation $c) => $this->mapConversation($c, $employee))
            ->values()->all();

        // Active thread, resolved from ?c=<conversationId> (existing) or ?to=<employeeId>
        // (deep-link from a profile — a blank composer until the first message is sent).
        $active = $this->resolveActive($request, $employee);

        // Recipient picker for a brand-new message: active staff in this tenant, minus self.
        $recipients = Employee::active()
            ->where('id', '!=', $employee->id)
            ->orderBy('name')
            ->get(['id', 'name', 'nickname', 'initials', 'avatar_color', 'position_id'])
            ->map(fn (Employee $e) => $this->personArr($e))
            ->values()->all();

        return [
            'msgConversations' => $conversations,
            'msgActive' => $active,
            'msgRecipients' => $recipients,
            'msgCanSend' => true,
        ];
    }

    /**
     * Just the thread column of the messages screen, rendered as an HTML fragment.
     *
     * The screen swaps this one region in when you open a conversation or send a message,
     * instead of navigating and re-rendering the whole shell (sidebar, notification feed
     * and context() run on every full render). It shares its Blade partial with the first
     * page render, so the two paths cannot drift.
     */
    public function pane(Request $request): View
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403);

        return view('partials.messages-thread', [
            'a' => $this->resolveActive($request, $employee),
            'msgCanSend' => true,
            // ?draft= seeds the first render only; a swap never re-seeds it.
            'draft' => '',
        ]);
    }

    // ── Global chrome context (merged into every screen) ─────────────────────

    /**
     * Always-on data for the header envelope button + slide-over panel. Returns
     * msgEnabled=false (and nothing heavy) when the module is off for the tenant.
     *
     * @return array<string, mixed>
     */
    public function context(?Employee $employee): array
    {
        $tenant = app(CurrentTenant::class)->get();

        if (! $tenant || ! app(FeatureManager::class)->screenAllowed($tenant, 'messages')) {
            return ['msgEnabled' => false];
        }

        if (! $employee) {
            return ['msgEnabled' => true, 'msgUnread' => 0, 'msgThreads' => []];
        }

        // Bounded, indexed, PLAIN-ARRAY payload (never cache Eloquent models into the
        // file store — that re-triggers the serialize 500 that broke every screen).
        $threads = $this->conversationsFor($employee, self::PANEL_LIMIT)
            ->map(fn (Conversation $c) => $this->mapConversation($c, $employee))
            ->values()->all();

        return [
            'msgEnabled' => true,
            'msgUnread' => $this->unreadCount($employee),
            'msgThreads' => $threads,
        ];
    }

    // ── Writes / JSON ─────────────────────────────────────────────────────────

    /** Send a message — into an existing thread (conversation_id) or a new pair (to). */
    public function send(Request $request): RedirectResponse|JsonResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');

        $data = $request->validate([
            // Optional when a file is attached (image-only messages). The "blank body AND
            // no file" case is rejected explicitly below so empty sends stay impossible.
            'body' => ['nullable', 'string', 'max:5000'],
            'conversation_id' => ['nullable', 'integer'],
            // Recipient must be an active, same-tenant employee (global scope restricts
            // the id set) — never trust the posted id.
            'to' => ['nullable', 'integer', Rule::in(Employee::active()->pluck('id'))],
            // Images + PDF + Office docs, each ≤ 8 MB, whole set capped — same discipline
            // as feedback attachments + leave docs.
            'attachments' => ['nullable', 'array', 'max:'.self::MAX_ATTACHMENTS],
            'attachments.*' => ['file', 'mimes:'.self::ATTACHMENT_MIMES, 'max:8192'],
        ], [
            'attachments.max' => 'You can attach up to '.self::MAX_ATTACHMENTS.' files.',
            'attachments.*.mimes' => 'Attachments must be an image, PDF, or Office document.',
            'attachments.*.max' => 'Each attachment must be 8 MB or smaller.',
        ]);

        $files = array_values(array_filter(
            (array) $request->file('attachments', []),
            fn ($f) => $f && $f->isValid(),
        ));

        // No empty sends: require a body OR at least one valid file.
        if (trim((string) ($data['body'] ?? '')) === '' && $files === []) {
            throw ValidationException::withMessages(['body' => 'Write a message or attach a file.']);
        }

        if (! empty($data['conversation_id'])) {
            $conversation = Conversation::findOrFail($data['conversation_id']);
            abort_unless($conversation->tenant_id === app(CurrentTenant::class)->id(), 403);
            abort_unless($conversation->hasParticipant($employee->id), 403);
        } else {
            $to = (int) ($data['to'] ?? 0);
            abort_if($to === 0 || $to === $employee->id, 422, 'Pick someone to message.');
            $conversation = Conversation::firstOrCreatePair($employee->id, $to);
        }

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $employee->id,
            'body' => $data['body'] ?? '',
        ]);

        // Persist each file to the private disk AFTER the message exists, so a rejected
        // batch can never orphan files.
        foreach ($files as $file) {
            $path = $file->store('message-attachments', self::ATTACHMENT_DISK);
            abort_unless($path !== false, 500, 'Attachment could not be stored.');
            $message->attachments()->create([
                'tenant_id' => $message->tenant_id,
                'path' => $path,
                'name' => $file->getClientOriginalName() ?: 'attachment',
                'mime' => $file->getClientMimeType(),
                'size' => $file->getSize() ?? 0,
            ]);
        }

        $message->load('attachments');
        $conversation->update(['last_message_at' => now()]);

        $conversation->loadMissing(['employeeLow', 'employeeHigh']);
        AuditLog::record('Sent a message', 'to '.($conversation->other($employee->id)?->name ?? 'colleague'));

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'conversationId' => $conversation->id,
                'message' => $this->messageArr($message, $employee),
            ]);
        }

        return redirect()->route('app.screen', ['screen' => 'messages', 'c' => $conversation->id]);
    }

    /** Mark every message the OTHER party sent in this thread as read. */
    public function markRead(Request $request, Conversation $conversation): JsonResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403);
        abort_unless($conversation->tenant_id === app(CurrentTenant::class)->id(), 403);
        abort_unless($conversation->hasParticipant($employee->id), 403);

        Message::where('conversation_id', $conversation->id)
            ->whereNull('read_at')
            ->where('sender_id', '!=', $employee->id)
            ->update(['read_at' => now()]);

        return response()->json(['ok' => true, 'unread' => $this->unreadCount($employee)]);
    }

    /** JSON thread for the slide-over panel's inline chat (no side effects). */
    public function thread(Request $request, Conversation $conversation): JsonResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403);
        abort_unless($conversation->tenant_id === app(CurrentTenant::class)->id(), 403);
        abort_unless($conversation->hasParticipant($employee->id), 403);

        $conversation->loadMissing(['employeeLow', 'employeeHigh']);

        $messages = Message::where('conversation_id', $conversation->id)
            ->with('attachments')
            ->orderBy('id')
            ->limit(self::THREAD_LIMIT)
            ->get(['id', 'sender_id', 'body', 'created_at', 'read_at'])
            ->map(fn (Message $m) => $this->messageArr($m, $employee))
            ->values()->all();

        return response()->json([
            'ok' => true,
            'conversationId' => $conversation->id,
            'other' => $this->personArr($conversation->other($employee->id)),
            'messages' => $messages,
        ]);
    }

    /**
     * Stream a message attachment inline through an auth-gated action — never a public URL.
     * A direct message is private between two people, so only the two conversation
     * participants may fetch its files. Tenant-scoped model binding already blocks
     * cross-tenant ids; the explicit checks are defence in depth.
     */
    public function attachment(Request $request, MessageAttachment $attachment): StreamedResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403);
        abort_unless($attachment->tenant_id === app(CurrentTenant::class)->id(), 403);

        $conversation = $attachment->message?->conversation;
        abort_unless($conversation && $conversation->hasParticipant($employee->id), 403);
        abort_unless(Storage::disk(self::ATTACHMENT_DISK)->exists($attachment->path), 404);

        return Storage::disk(self::ATTACHMENT_DISK)->response($attachment->path, $attachment->name);
    }

    /** The ~30s unread-count poll target. */
    public function unread(Request $request): JsonResponse
    {
        $employee = $request->attributes->get('employee');

        return response()->json(['unread' => $employee ? $this->unreadCount($employee) : 0]);
    }

    /**
     * Unread count + the panel's thread list, same shape as context() — polled while the
     * envelope badge or the slide-over panel is on screen, so a message that lands in a
     * conversation you don't have open still moves it to the top / shows its snippet
     * without a full page reload.
     */
    public function summary(Request $request): JsonResponse
    {
        $employee = $request->attributes->get('employee');
        if (! $employee) {
            return response()->json(['unread' => 0, 'threads' => []]);
        }

        $threads = $this->conversationsFor($employee, self::PANEL_LIMIT)
            ->map(fn (Conversation $c) => $this->mapConversation($c, $employee))
            ->values()->all();

        return response()->json(['unread' => $this->unreadCount($employee), 'threads' => $threads]);
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    /**
     * The viewer's conversations, newest first, with the "other" participant, the
     * latest message (snippet) and a per-thread unread count — all eager/aggregate so
     * there is no N+1 even when this runs on every screen via context().
     *
     * @return Collection<int, Conversation>
     */
    private function conversationsFor(Employee $employee, ?int $limit = null): Collection
    {
        return Conversation::query()
            ->where(fn (Builder $q) => $q
                ->where('employee_low_id', $employee->id)
                ->orWhere('employee_high_id', $employee->id))
            ->with([
                'employeeLow:id,name,initials,avatar_color,position_id',
                'employeeHigh:id,name,initials,avatar_color,position_id',
                'latestMessage',
            ])
            ->withCount(['messages as unread_count' => fn (Builder $q) => $q
                ->whereNull('read_at')
                ->where('sender_id', '!=', $employee->id)])
            ->orderByDesc('last_message_at')
            ->when($limit, fn (Builder $b) => $b->limit($limit))
            ->get();
    }

    /** Unread total for the badge — subquery id set, never hydrated into PHP. */
    private function unreadCount(Employee $employee): int
    {
        return Message::whereNull('read_at')
            ->where('sender_id', '!=', $employee->id)
            ->whereIn('conversation_id', $this->viewerConversationIds($employee))
            ->count();
    }

    /** Subquery of the viewer's conversation ids (for whereIn without pluck()->all()). */
    private function viewerConversationIds(Employee $employee): Builder
    {
        return Conversation::query()
            ->where(fn (Builder $q) => $q
                ->where('employee_low_id', $employee->id)
                ->orWhere('employee_high_id', $employee->id))
            ->select('id');
    }

    /**
     * Resolve the open thread from the request: ?c=<conversationId> (existing) or
     * ?to=<employeeId> (deep-link — a blank composer if no thread exists yet).
     *
     * @return array<string, mixed>|null
     */
    private function resolveActive(Request $request, Employee $employee): ?array
    {
        if ($cid = $request->query('c')) {
            $conversation = Conversation::with(['employeeLow', 'employeeHigh'])->find($cid);
            if (! $conversation || ! $conversation->hasParticipant($employee->id)) {
                return null;
            }

            return $this->activePayload($conversation, $conversation->other($employee->id), $employee);
        }

        if (($to = $request->query('to')) && (int) $to !== $employee->id) {
            // No active() scope: an archived colleague still resolves for an existing chat.
            $other = Employee::find($to);
            if (! $other) {
                return null;
            }

            $conversation = Conversation::findPair($employee->id, (int) $other->id);
            if ($conversation) {
                return $this->activePayload($conversation, $other, $employee);
            }

            // Nothing exchanged yet — blank composer; the row is created on first send.
            return [
                'conversationId' => null,
                'to' => $other->id,
                'other' => $this->personArr($other),
                'messages' => [],
                'runs' => [],
            ];
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function activePayload(Conversation $conversation, ?Employee $other, Employee $viewer): array
    {
        $messages = Message::where('conversation_id', $conversation->id)
            ->with('attachments')
            ->orderBy('id')
            ->limit(self::THREAD_LIMIT)
            ->get(['id', 'sender_id', 'body', 'created_at', 'read_at'])
            ->map(fn (Message $m) => $this->messageArr($m, $viewer))
            ->values()->all();

        return [
            'conversationId' => $conversation->id,
            'to' => $other?->id,
            'other' => $this->personArr($other),
            'messages' => $messages,
            'runs' => $this->runsFor($messages),
        ];
    }

    /**
     * Fold a flat message list into display runs.
     *
     * A run is a burst from one sender on one date. The screen prints one timestamp per
     * run instead of one per message, which is what made a five-message burst print the
     * same 10px string five times. A `day` entry is emitted whenever the date changes,
     * so a thread spanning weeks stops reading as one continuous block.
     *
     * @param  array<int, array<string, mixed>>  $messages  ascending by id
     * @return array<int, array{day?: string, dayMs?: string, mine?: bool, time?: string, read?: bool, bubbles?: array<int, string>, attachments?: array<int, array<string, mixed>>}>
     */
    private function runsFor(array $messages): array
    {
        $runs = [];
        $date = null;
        $sender = null;

        foreach ($messages as $m) {
            if ($m['date'] !== $date) {
                $date = $m['date'];
                $sender = null; // a new day always starts a new run
                $runs[] = ['day' => $this->dayLabel($date, 'en'), 'dayMs' => $this->dayLabel($date, 'ms')];
            }

            if ($m['senderId'] !== $sender) {
                $sender = $m['senderId'];
                $runs[] = ['mine' => $m['mine'], 'time' => $m['time'], 'read' => $m['read'], 'bubbles' => [], 'attachments' => []];
            }

            $i = array_key_last($runs);
            if ($m['body'] !== '') {
                $runs[$i]['bubbles'][] = $m['body'];
            }
            $runs[$i]['attachments'] = array_merge($runs[$i]['attachments'], $m['attachments']);
            // The run's stamp is its LAST message, and it is read only once every
            // message in it has been read.
            $runs[$i]['time'] = $m['time'];
            $runs[$i]['read'] = $runs[$i]['read'] && $m['read'];
        }

        return $runs;
    }

    /** "Today" / "Yesterday" / "Monday, 21 July", in EN or BM. */
    private function dayLabel(?string $date, string $lang): string
    {
        if ($date === null) {
            return '';
        }

        $d = Carbon::parse($date)->startOfDay();

        if ($d->isToday()) {
            return $lang === 'en' ? 'Today' : 'Hari ini';
        }
        if ($d->isYesterday()) {
            return $lang === 'en' ? 'Yesterday' : 'Semalam';
        }

        return $lang === 'en'
            ? $d->format('l, j F')
            : self::MS_DAYS[$d->dayOfWeek].', '.$d->day.' '.self::MS_MONTHS[(int) $d->month];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapConversation(Conversation $conversation, Employee $viewer): array
    {
        $other = $conversation->other($viewer->id);
        $last = $conversation->latestMessage;

        return [
            'id' => $conversation->id,
            'other' => $this->personArr($other),
            // An empty-body latest message can only exist if it carried attachments
            // (empty sends are rejected), so label it without loading the files here.
            // 120, not 60: the screen's row gives the snippet two lines.
            'snippet' => $last ? ($last->body !== '' ? Str::limit($last->body, 120) : '📎 Attachment') : null,
            'lastMine' => $last ? ($last->sender_id === $viewer->id) : false,
            // `at` stays diffForHumans for the slide-over panel, which reads it.
            'at' => $conversation->last_message_at?->diffForHumans(),
            'bucket' => $this->bucketFor($conversation->last_message_at),
            'atShort' => $this->shortStamp($conversation->last_message_at, 'en'),
            'atShortMs' => $this->shortStamp($conversation->last_message_at, 'ms'),
            'unread' => (int) ($conversation->unread_count ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function messageArr(Message $message, Employee $viewer): array
    {
        return [
            'id' => $message->id,
            'mine' => $message->sender_id === $viewer->id,
            'senderId' => $message->sender_id,
            'body' => $message->body,
            'at' => $message->created_at?->format('d M, H:i'),
            // Grouping keys for runsFor(): `date` starts a new day divider, `time` is the
            // one timestamp the whole run carries. `read` is what the sender never used
            // to see, even though markRead() has been writing it all along.
            'date' => $message->created_at?->format('Y-m-d'),
            'time' => $message->created_at?->format('H:i'),
            'read' => $message->read_at !== null,
            'attachments' => $message->attachments->map(fn (MessageAttachment $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'isImage' => $a->isImage(),
                'url' => route('messages.attachment', $a),
            ])->values()->all(),
        ];
    }

    /**
     * Which heading a conversation files under in the index: `today`, `week` (the last
     * seven days), or `earlier`. A thread that has never been written to files as
     * `earlier` rather than inventing a fourth group for it.
     */
    private function bucketFor(?Carbon $at): string
    {
        if ($at === null) {
            return 'earlier';
        }
        if ($at->isToday()) {
            return 'today';
        }

        return $at->greaterThan(now()->subDays(7)) ? 'week' : 'earlier';
    }

    /** Index stamp: "09:41" today, "Mon" within the week, "24 Jul" beyond it. */
    private function shortStamp(?Carbon $at, string $lang): string
    {
        if ($at === null) {
            return '';
        }
        if ($at->isToday()) {
            return $at->format('H:i');
        }
        if ($at->greaterThan(now()->subDays(7))) {
            return $lang === 'en' ? $at->format('D') : self::MS_DAYS_SHORT[$at->dayOfWeek];
        }

        return $lang === 'en'
            ? $at->format('j M')
            : $at->day.' '.self::MS_MONTHS_SHORT[(int) $at->month];
    }

    /**
     * Display shape for a participant. Loaded WITHOUT active() so archived people still
     * render their name/avatar in old threads.
     *
     * @return array<string, mixed>
     */
    private function personArr(?Employee $e): array
    {
        return [
            'id' => $e?->id,
            'name' => $e?->name ?? 'Unknown',
            'initials' => $e?->initials ?? '–',
            'color' => $e?->avatar_color ?? config('amanahku.avatar_color'),
            'position' => $e?->position,
        ];
    }
}
