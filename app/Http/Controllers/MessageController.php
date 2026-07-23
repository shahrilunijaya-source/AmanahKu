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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
            ->get(['id', 'name', 'initials', 'avatar_color', 'position_id'])
            ->map(fn (Employee $e) => $this->personArr($e))
            ->values()->all();

        return [
            'msgConversations' => $conversations,
            'msgActive' => $active,
            'msgRecipients' => $recipients,
            'msgCanSend' => true,
        ];
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
            ->orderBy('id')
            ->limit(self::THREAD_LIMIT)
            ->get(['id', 'sender_id', 'body', 'created_at'])
            ->map(fn (Message $m) => $this->messageArr($m, $employee))
            ->values()->all();

        return response()->json([
            'ok' => true,
            'conversationId' => $conversation->id,
            'other' => $this->personArr($conversation->other($employee->id)),
            'messages' => $messages,
        ]);
    }

    /** The ~30s unread-count poll target. */
    public function unread(Request $request): JsonResponse
    {
        $employee = $request->attributes->get('employee');

        return response()->json(['unread' => $employee ? $this->unreadCount($employee) : 0]);
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
            ->orderBy('id')
            ->limit(self::THREAD_LIMIT)
            ->get(['id', 'sender_id', 'body', 'created_at'])
            ->map(fn (Message $m) => $this->messageArr($m, $viewer))
            ->values()->all();

        return [
            'conversationId' => $conversation->id,
            'to' => $other?->id,
            'other' => $this->personArr($other),
            'messages' => $messages,
        ];
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
            'snippet' => $last ? Str::limit($last->body, 60) : null,
            'lastMine' => $last ? ($last->sender_id === $viewer->id) : false,
            'at' => $conversation->last_message_at?->diffForHumans(),
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
            'body' => $message->body,
            'at' => $message->created_at?->format('d M, H:i'),
        ];
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
