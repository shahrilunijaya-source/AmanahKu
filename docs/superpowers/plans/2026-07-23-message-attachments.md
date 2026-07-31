# Message Attachments Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let employees attach images and files (incl. mobile camera capture) to 1-to-1 direct messages, on both the side panel and the full `/app/messages` page.

**Architecture:** Mirror the existing feedback/leave attachment pattern — a `message_attachments` table, files on the private `local` disk, served only through an auth-gated participant-only stream. `body` becomes optional when a file is attached (image-only messages). A single `messageArr()` change surfaces attachments to all read paths.

**Tech Stack:** Laravel (PHP 8.5), Blade + Alpine.js, MySQL, private `local` filesystem disk. Tests: PHPUnit feature tests with `Storage::fake('local')` + `UploadedFile::fake()`.

## Global Constraints

- Spec: [docs/superpowers/specs/2026-07-23-message-attachments-design.md](../specs/2026-07-23-message-attachments-design.md)
- Accepted mimes (verbatim): `jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,txt,csv`
- Size limit: `8192` KB (8 MB) per file. Max `6` files per message.
- Storage disk: `local` (private). Files under folder `message-attachments`. **Never** a public URL.
- All models tenant-scoped via `BelongsToTenant`; `tenant_id` is the first FK on every table.
- Run tests with: `php artisan test --filter=<TestClass>` (host `php` shim proxies into the lerd PHP 8.5 container).
- Commit after every green task. Branch is `dev` (not `main`) — committing is fine.
- Do NOT run `bun run build` or deploy; this plan is code-only. Asset/deploy steps are out of scope.

---

### Task 1: Data layer — table, model, relation

**Files:**
- Create: `database/migrations/2026_07_23_000001_create_message_attachments_table.php`
- Create: `app/Models/MessageAttachment.php`
- Modify: `app/Models/Message.php` (add `attachments()` hasMany + `HasMany` import)
- Test: `tests/Feature/MessageAttachmentTest.php`

**Interfaces:**
- Produces:
  - Table `message_attachments(id, tenant_id, message_id, path, name, mime nullable, size unsignedInteger default 0, timestamps)`.
  - `App\Models\MessageAttachment` — `BelongsToTenant`, `$guarded = []`, `casts size => integer`, `message(): BelongsTo`, `isImage(): bool`.
  - `App\Models\Message::attachments(): HasMany` → `MessageAttachment`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/MessageAttachmentTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Employee;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessageAttachmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_message_has_many_attachments_and_isimage_detects_images(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $a = Employee::create(['tenant_id' => $tenant->id, 'name' => 'A', 'status' => 'active', 'workload' => 'green']);
        $b = Employee::create(['tenant_id' => $tenant->id, 'name' => 'B', 'status' => 'active', 'workload' => 'green']);
        $c = Conversation::create([
            'tenant_id' => $tenant->id,
            'employee_low_id' => min($a->id, $b->id),
            'employee_high_id' => max($a->id, $b->id),
            'last_message_at' => now(),
        ]);
        $msg = Message::create(['tenant_id' => $tenant->id, 'conversation_id' => $c->id, 'sender_id' => $a->id, 'body' => 'hi']);

        $img = $msg->attachments()->create([
            'tenant_id' => $tenant->id, 'path' => 'message-attachments/x.png', 'name' => 'x.png', 'mime' => 'image/png', 'size' => 10,
        ]);
        $doc = $msg->attachments()->create([
            'tenant_id' => $tenant->id, 'path' => 'message-attachments/y.pdf', 'name' => 'y.pdf', 'mime' => 'application/pdf', 'size' => 20,
        ]);

        $this->assertSame(2, $msg->attachments()->count());
        $this->assertTrue($img->isImage());
        $this->assertFalse($doc->isImage());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=MessageAttachmentTest`
Expected: FAIL — `Class "App\Models\MessageAttachment" not found` (and no `message_attachments` table).

- [ ] **Step 3: Create the migration**

Create `database/migrations/2026_07_23_000001_create_message_attachments_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A file attached to a direct message — image or document. Files live on the
        // private 'local' disk and are only ever reached through
        // MessageController::attachment (participant-gated stream), never a public URL.
        // Mirrors feedback_attachments. tenant_id first FK like every tenant table.
        Schema::create('message_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->constrained('messages')->cascadeOnDelete();
            $table->string('path');                       // location on the private 'local' disk
            $table->string('name');                       // original filename shown to humans
            $table->string('mime')->nullable();           // drives image-vs-chip rendering
            $table->unsignedInteger('size')->default(0);  // bytes
            $table->timestamps();

            $table->index('message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_attachments');
    }
};
```

- [ ] **Step 4: Create the model**

Create `app/Models/MessageAttachment.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file attached to a direct Message — a pasted/snapped image or an uploaded document.
 * Files live on the private 'local' disk and are only ever reached through
 * MessageController::attachment (participant-gated stream), never a public URL.
 */
class MessageAttachment extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected $casts = ['size' => 'integer'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /** Images render as inline thumbnails; everything else as a download chip. */
    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }
}
```

- [ ] **Step 5: Add the relation on Message**

Modify `app/Models/Message.php` — add the `HasMany` import and an `attachments()` method:

Add to the `use` block:
```php
use Illuminate\Database\Eloquent\Relations\HasMany;
```

Add this method inside the class (after `sender()`):
```php
    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class);
    }
```

- [ ] **Step 6: Run migration + test to verify it passes**

Run: `php artisan test --filter=MessageAttachmentTest`
Expected: PASS (RefreshDatabase runs the new migration automatically).

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_07_23_000001_create_message_attachments_table.php app/Models/MessageAttachment.php app/Models/Message.php tests/Feature/MessageAttachmentTest.php
git commit -m "feat(messages): message_attachments table + model + relation"
```

---

### Task 2: Sending — accept, validate, store attachments (image-only allowed)

**Files:**
- Modify: `app/Http/Controllers/MessageController.php` (`send()` + new class constants + imports)
- Test: `tests/Feature/MessageAttachmentTest.php` (add cases)

**Interfaces:**
- Consumes: `Message::attachments()` (Task 1).
- Produces: `POST /app/messages/send` now accepts `attachments[]` (files); `body` optional when ≥1 valid file present; creates a `MessageAttachment` row per stored file. JSON send-response shape unchanged except messages now carry `attachments` (added in Task 4).

- [ ] **Step 1: Write the failing tests**

Append these methods to `tests/Feature/MessageAttachmentTest.php`. They need the messaging harness, so add the same setUp helpers the class will use. Replace the class body's opening (keep the Task 1 test) so the file becomes:

```php
<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Employee;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MessageAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    private Employee $employee;

    private Employee $other;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->user = User::create(['name' => 'Demo', 'email' => 'demo@example.com', 'password' => Hash::make('password')]);
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
            'name' => 'Demo', 'status' => 'active', 'workload' => 'green',
        ]);
        $this->other = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Colleague', 'status' => 'active', 'workload' => 'green',
        ]);
    }

    private function actingInTenant(?User $as = null): self
    {
        $this->actingAs($as ?? $this->user)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    public function test_message_has_many_attachments_and_isimage_detects_images(): void
    {
        $c = Conversation::create([
            'tenant_id' => $this->tenant->id,
            'employee_low_id' => min($this->employee->id, $this->other->id),
            'employee_high_id' => max($this->employee->id, $this->other->id),
            'last_message_at' => now(),
        ]);
        $msg = Message::create(['tenant_id' => $this->tenant->id, 'conversation_id' => $c->id, 'sender_id' => $this->employee->id, 'body' => 'hi']);

        $img = $msg->attachments()->create(['tenant_id' => $this->tenant->id, 'path' => 'message-attachments/x.png', 'name' => 'x.png', 'mime' => 'image/png', 'size' => 10]);
        $doc = $msg->attachments()->create(['tenant_id' => $this->tenant->id, 'path' => 'message-attachments/y.pdf', 'name' => 'y.pdf', 'mime' => 'application/pdf', 'size' => 20]);

        $this->assertSame(2, $msg->attachments()->count());
        $this->assertTrue($img->isImage());
        $this->assertFalse($doc->isImage());
    }

    public function test_sending_with_a_file_stores_it_and_creates_a_row(): void
    {
        $this->actingInTenant()->post('/app/messages/send', [
            'to' => $this->other->id,
            'body' => 'see attached',
            'attachments' => [UploadedFile::fake()->image('photo.png')],
        ])->assertRedirect();

        $msg = Message::first();
        $this->assertNotNull($msg);
        $this->assertSame(1, MessageAttachment::count());
        $att = MessageAttachment::first();
        $this->assertSame($msg->id, $att->message_id);
        $this->assertSame('photo.png', $att->name);
        Storage::disk('local')->assertExists($att->path);
    }

    public function test_image_only_message_is_allowed(): void
    {
        $this->actingInTenant()->post('/app/messages/send', [
            'to' => $this->other->id,
            'attachments' => [UploadedFile::fake()->image('snap.jpg')],
        ])->assertRedirect();

        $this->assertSame(1, Message::count());
        $this->assertSame(1, MessageAttachment::count());
    }

    public function test_empty_body_with_no_file_is_rejected(): void
    {
        $this->actingInTenant()->post('/app/messages/send', ['to' => $this->other->id, 'body' => ''])
            ->assertSessionHasErrors('body');
        $this->assertSame(0, Message::count());
    }

    public function test_oversize_file_is_rejected(): void
    {
        $this->actingInTenant()->post('/app/messages/send', [
            'to' => $this->other->id,
            'body' => 'big',
            'attachments' => [UploadedFile::fake()->create('huge.pdf', 9000, 'application/pdf')], // 9000 KB > 8192
        ])->assertSessionHasErrors('attachments.0');
        $this->assertSame(0, Message::count());
        $this->assertSame(0, MessageAttachment::count());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=MessageAttachmentTest`
Expected: the four send-tests FAIL — image-only redirects but body currently `required` (422/errors), oversize is not yet validated, attachments are ignored so no rows/files. (The Task 1 relation test still PASSES.)

- [ ] **Step 3: Add constants + imports to the controller**

Modify `app/Http/Controllers/MessageController.php`.

Add imports (near the existing `use` lines):
```php
use App\Models\MessageAttachment;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
```

Add these constants inside the class, next to `PANEL_LIMIT` / `THREAD_LIMIT`:
```php
    /** Private disk message attachments live on — reached only via attachment(). */
    private const ATTACHMENT_DISK = 'local';

    /** Ceiling on files per message, and the accepted extensions (images + PDF + Office docs). */
    private const MAX_ATTACHMENTS = 6;

    private const ATTACHMENT_MIMES = 'jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,txt,csv';
```

- [ ] **Step 4: Rewrite `send()` validation + storage**

In `app/Http/Controllers/MessageController.php`, replace the current validation block and message-create block of `send()` with the version below. The recipient/conversation resolution in between is unchanged.

Replace this:
```php
        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'conversation_id' => ['nullable', 'integer'],
            // Recipient must be an active, same-tenant employee (global scope restricts
            // the id set) — never trust the posted id.
            'to' => ['nullable', 'integer', Rule::in(Employee::active()->pluck('id'))],
        ]);
```
with:
```php
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
```

Replace this:
```php
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $employee->id,
            'body' => $data['body'],
        ]);
        $conversation->update(['last_message_at' => now()]);
```
with:
```php
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
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=MessageAttachmentTest`
Expected: PASS (5 tests). Also run the existing suite to confirm no regression:
Run: `php artisan test --filter=MessageTest`
Expected: PASS (10 tests) — `test_message_body_is_required` still passes because the blank-body guard throws a `body` validation error.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/MessageController.php tests/Feature/MessageAttachmentTest.php
git commit -m "feat(messages): accept + store attachments on send, allow image-only"
```

---

### Task 3: Serving — participant-gated attachment stream

**Files:**
- Modify: `app/Http/Controllers/MessageController.php` (add `attachment()`)
- Modify: `routes/web.php` (add the GET route next to `messages.thread`)
- Test: `tests/Feature/MessageAttachmentTest.php` (add participant/non-participant cases)

**Interfaces:**
- Consumes: `MessageAttachment` (Task 1), `Conversation::hasParticipant()` (existing).
- Produces: `GET /app/messages/attachments/{attachment}` route name `messages.attachment`, binding `App\Models\MessageAttachment`. 200 stream for a participant, 403 otherwise.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/MessageAttachmentTest.php`:

```php
    public function test_a_participant_can_stream_an_attachment(): void
    {
        $this->actingInTenant()->post('/app/messages/send', [
            'to' => $this->other->id, 'body' => 'x',
            'attachments' => [UploadedFile::fake()->image('p.png')],
        ])->assertRedirect();
        $att = MessageAttachment::first();

        $this->actingInTenant()->get("/app/messages/attachments/{$att->id}")->assertOk();
    }

    public function test_a_non_participant_cannot_stream_an_attachment(): void
    {
        $this->actingInTenant()->post('/app/messages/send', [
            'to' => $this->other->id, 'body' => 'x',
            'attachments' => [UploadedFile::fake()->image('p.png')],
        ])->assertRedirect();
        $att = MessageAttachment::first();

        $intruderUser = User::create(['name' => 'Nosy', 'email' => 'nosy@example.com', 'password' => Hash::make('password')]);
        $intruderUser->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        Employee::create(['tenant_id' => $this->tenant->id, 'user_id' => $intruderUser->id, 'name' => 'Nosy', 'status' => 'active', 'workload' => 'green']);

        $this->actingInTenant($intruderUser)->get("/app/messages/attachments/{$att->id}")->assertForbidden();
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=MessageAttachmentTest`
Expected: FAIL — route `/app/messages/attachments/{id}` not defined (404, not 200/403).

- [ ] **Step 3: Add the `attachment()` action**

In `app/Http/Controllers/MessageController.php`, add this method (after `thread()`), and add the import `use Symfony\Component\HttpFoundation\StreamedResponse;` near the top:

```php
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
```

- [ ] **Step 4: Register the route**

Modify `routes/web.php`. Find the read-side messages routes (near `messages.thread`):
```php
        Route::get('/app/messages/unread', [MessageController::class, 'unread'])->middleware('throttle:120,1')->name('messages.unread');
        Route::get('/app/messages/thread/{conversation}', [MessageController::class, 'thread'])->name('messages.thread');
```
Add immediately after them:
```php
        Route::get('/app/messages/attachments/{attachment}', [MessageController::class, 'attachment'])->name('messages.attachment');
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=MessageAttachmentTest`
Expected: PASS (7 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/MessageController.php routes/web.php tests/Feature/MessageAttachmentTest.php
git commit -m "feat(messages): participant-gated attachment stream route"
```

---

### Task 4: Read shape — attachments in payloads + list snippet

**Files:**
- Modify: `app/Http/Controllers/MessageController.php` (`messageArr()`, `mapConversation()`, eager-load in `thread()` + `activePayload()`)
- Test: `tests/Feature/MessageAttachmentTest.php` (thread JSON + snippet cases)

**Interfaces:**
- Consumes: `messages.attachment` route (Task 3), `Message::attachments()` (Task 1).
- Produces: every message array now has `'attachments' => [{id, name, isImage, url}]`. Conversation list `snippet` is `'📎 Attachment'` for an attachment-only (empty-body) latest message.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/MessageAttachmentTest.php`:

```php
    public function test_thread_json_includes_attachment_metadata(): void
    {
        $this->actingInTenant()->post('/app/messages/send', [
            'to' => $this->other->id, 'body' => 'x',
            'attachments' => [UploadedFile::fake()->image('p.png')],
        ])->assertRedirect();
        $c = Conversation::first();

        $this->actingInTenant()->get("/app/messages/thread/{$c->id}")
            ->assertOk()
            ->assertJsonFragment(['name' => 'p.png', 'isImage' => true]);
    }

    public function test_screen_snippet_marks_attachment_only_messages(): void
    {
        // Image-only message → empty body → snippet must not be blank.
        $this->actingInTenant()->post('/app/messages/send', [
            'to' => $this->other->id,
            'attachments' => [UploadedFile::fake()->image('p.png')],
        ])->assertRedirect();

        $this->actingInTenant()->get('/app/messages')
            ->assertOk()
            ->assertSee('📎 Attachment');
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=MessageAttachmentTest`
Expected: FAIL — thread JSON has no `name`/`isImage` fields; the screen shows a blank snippet, not `📎 Attachment`.

- [ ] **Step 3: Add attachments to `messageArr()`**

In `app/Http/Controllers/MessageController.php`, replace `messageArr()`:

```php
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
            'attachments' => $message->attachments->map(fn (MessageAttachment $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'isImage' => $a->isImage(),
                'url' => route('messages.attachment', $a),
            ])->values()->all(),
        ];
    }
```

- [ ] **Step 4: Eager-load attachments where threads are fetched**

In `thread()`, change the messages query from:
```php
        $messages = Message::where('conversation_id', $conversation->id)
            ->orderBy('id')
            ->limit(self::THREAD_LIMIT)
            ->get(['id', 'sender_id', 'body', 'created_at'])
```
to (add `->with('attachments')`):
```php
        $messages = Message::where('conversation_id', $conversation->id)
            ->with('attachments')
            ->orderBy('id')
            ->limit(self::THREAD_LIMIT)
            ->get(['id', 'sender_id', 'body', 'created_at'])
```

Make the identical change in `activePayload()` (same query shape).

- [ ] **Step 5: Mark attachment-only snippets in `mapConversation()`**

In `mapConversation()`, replace:
```php
            'snippet' => $last ? Str::limit($last->body, 60) : null,
```
with:
```php
            // An empty-body latest message can only exist if it carried attachments
            // (empty sends are rejected), so label it without loading the files here.
            'snippet' => $last ? ($last->body !== '' ? Str::limit($last->body, 60) : '📎 Attachment') : null,
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=MessageAttachmentTest`
Expected: PASS (9 tests).
Run: `php artisan test --filter=MessageTest`
Expected: PASS (10 tests, no regression).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/MessageController.php tests/Feature/MessageAttachmentTest.php
git commit -m "feat(messages): surface attachments in payloads + label attachment-only snippets"
```

---

### Task 5: Full-page composer + bubble rendering

**Files:**
- Modify: `resources/views/screens/messages.blade.php` (composer form + message bubbles)

**Interfaces:**
- Consumes: `messageArr()` `attachments` array (Task 4); `POST messages.send` accepting `attachments[]` (Task 2).
- Produces: full-page composer with a paperclip input, a mobile-only camera input, a selected-files chip row, and attachment rendering inside message bubbles.

This task is UI; verify in the browser (no PHPUnit).

- [ ] **Step 1: Add attachment rendering to the message bubble**

In `resources/views/screens/messages.blade.php`, inside the `@forelse ($a['messages'] as $m)` loop, the bubble currently renders only `$m['body']`. Make the body div conditional (empty for image-only) and append attachments. Replace the inner bubble block:

```blade
                    <div style="max-width:70%;{{ $m['mine'] ? 'align-self:flex-end;' : 'align-self:flex-start;' }}">
                        <div style="padding:10px 13px;border-radius:14px;font-size:13.5px;line-height:1.55;white-space:pre-wrap;word-break:break-word;{{ $m['mine'] ? 'background:var(--red);color:#fff;border-bottom-right-radius:4px;' : 'background:#fff;color:var(--ink);border:1px solid var(--hairline);border-bottom-left-radius:4px;' }}">{{ $m['body'] }}</div>
                        <div style="font-size:10px;font-family:var(--font-mono);color:var(--muted-soft);margin-top:3px;{{ $m['mine'] ? 'text-align:right;' : '' }}">{{ $m['at'] }}</div>
                    </div>
```
with:
```blade
                    <div style="max-width:70%;{{ $m['mine'] ? 'align-self:flex-end;' : 'align-self:flex-start;' }}">
                        @if ($m['body'] !== '')
                            <div style="padding:10px 13px;border-radius:14px;font-size:13.5px;line-height:1.55;white-space:pre-wrap;word-break:break-word;{{ $m['mine'] ? 'background:var(--red);color:#fff;border-bottom-right-radius:4px;' : 'background:#fff;color:var(--ink);border:1px solid var(--hairline);border-bottom-left-radius:4px;' }}">{{ $m['body'] }}</div>
                        @endif
                        @foreach ($m['attachments'] as $att)
                            <div style="margin-top:{{ $m['body'] !== '' || ! $loop->first ? '6px' : '0' }};">
                                @if ($att['isImage'])
                                    <a href="{{ $att['url'] }}" target="_blank" rel="noopener">
                                        <img src="{{ $att['url'] }}" alt="{{ $att['name'] }}" style="max-width:220px;max-height:220px;border-radius:12px;display:block;border:1px solid var(--hairline);" />
                                    </a>
                                @else
                                    <a href="{{ $att['url'] }}" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:8px;padding:9px 12px;border-radius:12px;text-decoration:none;background:#fff;border:1px solid var(--hairline);color:var(--ink);max-width:240px;">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                                        <span style="font-size:12.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $att['name'] }}</span>
                                    </a>
                                @endif
                            </div>
                        @endforeach
                        <div style="font-size:10px;font-family:var(--font-mono);color:var(--muted-soft);margin-top:3px;{{ $m['mine'] ? 'text-align:right;' : '' }}">{{ $m['at'] }}</div>
                    </div>
```

- [ ] **Step 2: Add the file/camera inputs + selected-files row to the composer form**

In the same file, replace the composer `<form ...>` opening tag to add `enctype` and an Alpine scope for pending files, and insert the trigger buttons + a selected-files preview row. Replace:

```blade
                <form method="post" action="{{ route('messages.send') }}" style="padding:12px 16px;border-top:1px solid var(--hairline);display:flex;align-items:flex-end;gap:10px;flex-shrink:0;">
                    @csrf
```
with:
```blade
                <form method="post" action="{{ route('messages.send') }}" enctype="multipart/form-data"
                      x-data="{ files: [], sync(e){ this.files = Array.from(this.$refs.file.files); },
                                add(e){ const dt = new DataTransfer(); this.files.forEach(f => dt.items.add(f));
                                        Array.from(e.target.files).forEach(f => dt.items.add(f));
                                        this.$refs.file.files = dt.files; this.files = Array.from(dt.files); e.target.value=''; } }"
                      style="padding:12px 16px;border-top:1px solid var(--hairline);display:flex;flex-direction:column;gap:8px;flex-shrink:0;">
                    @csrf
                    {{-- Selected-files preview --}}
                    <div x-show="files.length" x-cloak style="display:flex;flex-wrap:wrap;gap:6px;">
                        <template x-for="(f, i) in files" :key="i">
                            <span style="display:inline-flex;align-items:center;gap:6px;padding:5px 9px;background:var(--canvas);border:1px solid var(--hairline);border-radius:8px;font-size:11.5px;color:var(--ink);max-width:180px;">
                                <span x-text="f.name" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"></span>
                            </span>
                        </template>
                    </div>
                    <div style="display:flex;align-items:flex-end;gap:10px;">
```

Then, still inside the form, the existing hidden inputs + `<textarea>` + Send button follow. Immediately BEFORE the `<textarea name="body" ...>`, insert the trigger buttons and the real (hidden) inputs:

```blade
                        {{-- Real inputs. `file` holds the batch; camera appends into it. --}}
                        <input x-ref="file" type="file" name="attachments[]" multiple
                               accept="{{ '.jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.txt,.csv' }}"
                               @change="sync" style="display:none;" />
                        <input x-ref="cam" type="file" name="attachments[]" accept="image/*" capture="environment"
                               @change="add" style="display:none;" />
                        <button type="button" @click="$refs.file.click()" class="uj-btn-ghost" title="Attach"
                                style="height:44px;width:44px;flex-shrink:0;display:flex;align-items:center;justify-content:center;padding:0;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path></svg>
                        </button>
                        {{-- Camera trigger — mobile/touch only. --}}
                        <button type="button" @click="$refs.cam.click()" class="uj-btn-ghost uj-cam-only" title="Camera"
                                style="height:44px;width:44px;flex-shrink:0;display:none;align-items:center;justify-content:center;padding:0;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path><circle cx="12" cy="13" r="4"></circle></svg>
                        </button>
```

Now make the `<textarea>` no longer `required` (body optional when files attached). Change the textarea opening tag from `<textarea name="body" required maxlength="5000" rows="1"` to `<textarea name="body" maxlength="5000" rows="1"`.

Finally, close the inner flex row: after the existing Send `<button type="submit" ...>...</button>`, add one closing `</div>` before `</form>`. The Send button block is:
```blade
                    <button type="submit" class="uj-btn-primary" style="height:44px;padding:0 18px;font-size:13.5px;flex-shrink:0;"><span x-text="$store.ui.lang==='en' ? 'Send' : 'Hantar'">Send</span></button>
                </form>
```
becomes:
```blade
                    <button type="submit" class="uj-btn-primary" style="height:44px;padding:0 18px;font-size:13.5px;flex-shrink:0;"><span x-text="$store.ui.lang==='en' ? 'Send' : 'Hantar'">Send</span></button>
                    </div>
                </form>
```

- [ ] **Step 3: Add the mobile-only camera visibility rule**

The camera button uses class `uj-cam-only` and inline `display:none`. Reveal it only on touch devices. Add this once near the top of the screen template (right after the opening `@section('screen')` guide include, before the main flex `<div>`):

```blade
<style>@media (hover: none) and (pointer: coarse) { .uj-cam-only { display:inline-flex !important; } }</style>
```

- [ ] **Step 4: Browser verification**

Start the dev server and drive the page:
1. `preview_start` with `{name: "laravel-app"}` (serves http://localhost:9100).
2. Navigate to `http://localhost:9100/dev/login?email=hr@amanahku.test&tenant=unijaya` then to `http://localhost:9100/app/messages`.
3. Open a conversation, attach an image via the paperclip, confirm the filename chip appears, send. Confirm the image thumbnail renders in the bubble.
4. Attach a PDF, send with no text, confirm the file chip renders and the conversation snippet shows `📎 Attachment`.
5. `read_console_messages` — expect no errors.
6. `resize_window` to mobile (`preset: "mobile"`) and confirm the camera button becomes visible.
7. `computer {action: "screenshot"}` to capture proof.

- [ ] **Step 5: Commit**

```bash
git add resources/views/screens/messages.blade.php
git commit -m "feat(messages): full-page composer file+camera attach and bubble rendering"
```

---

### Task 6: Side-panel composer (FormData) + camera + bubble rendering

**Files:**
- Modify: `resources/views/layouts/app.blade.php` (`messagesPanel` Alpine component `send()` + `files` state)
- Modify: `resources/views/partials/messages-panel.blade.php` (composer inputs + bubble rendering)

**Interfaces:**
- Consumes: `messageArr()` `attachments` array (Task 4); `POST messages.send` accepting multipart `attachments[]` (Task 2).
- Produces: panel `send()` posts `FormData` (files included), a paperclip + mobile-only camera trigger, and attachment rendering in panel bubbles.

This task is UI/JS; verify in the browser (no PHPUnit).

- [ ] **Step 1: Switch the Alpine `send()` to FormData + add file state**

In `resources/views/layouts/app.blade.php`, in the `Alpine.data('messagesPanel', ...)` object:

Add `files: [],` to the state (next to `body: '',`).

Replace the entire `send()` method:
```js
            send() {
                const text = this.body.trim();
                if (!text || this.sending || !this.active) return;
                this.sending = true;
                const p = new URLSearchParams();
                p.append('body', text);
                if (this.active.conversationId) p.append('conversation_id', this.active.conversationId);
                else if (this.active.to) p.append('to', this.active.to);
                fetch('{{ route('messages.send') }}', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': this.csrf(), 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: p.toString(),
                }).then(r => r.json()).then(d => {
                    if (d.ok) {
                        this.active.conversationId = d.conversationId;
                        this.active.messages.push(d.message);
                        this.body = '';
                        this.scrollDown();
                    }
                }).catch(() => {}).finally(() => { this.sending = false; });
            },
```
with:
```js
            addFiles(e) {
                this.files = this.files.concat(Array.from(e.target.files));
                e.target.value = '';
            },
            send() {
                const text = this.body.trim();
                if ((!text && this.files.length === 0) || this.sending || !this.active) return;
                this.sending = true;
                const fd = new FormData();
                fd.append('body', text);
                if (this.active.conversationId) fd.append('conversation_id', this.active.conversationId);
                else if (this.active.to) fd.append('to', this.active.to);
                this.files.forEach(f => fd.append('attachments[]', f));
                fetch('{{ route('messages.send') }}', {
                    method: 'POST',
                    // No Content-Type header — the browser sets the multipart boundary.
                    headers: { 'X-CSRF-TOKEN': this.csrf(), 'Accept': 'application/json' },
                    body: fd,
                }).then(r => r.json()).then(d => {
                    if (d.ok) {
                        this.active.conversationId = d.conversationId;
                        this.active.messages.push(d.message);
                        this.body = '';
                        this.files = [];
                        this.scrollDown();
                    }
                }).catch(() => {}).finally(() => { this.sending = false; });
            },
```

Also clear files in `back()`: change `back() { this.view = 'list'; this.active = null; this.body = ''; },` to `back() { this.view = 'list'; this.active = null; this.body = ''; this.files = []; },`.

- [ ] **Step 2: Render attachments in the panel bubble**

In `resources/views/partials/messages-panel.blade.php`, replace the message bubble template:
```blade
                    <template x-for="m in (active ? active.messages : [])" :key="m.id">
                        <div :style="'max-width:78%;'+(m.mine ? 'align-self:flex-end;' : 'align-self:flex-start;')">
                            <div :style="'padding:9px 12px;border-radius:14px;font-size:13px;line-height:1.5;white-space:pre-wrap;word-break:break-word;'+(m.mine ? 'background:var(--red);color:#fff;border-bottom-right-radius:4px;' : 'background:#fff;color:var(--ink);border:1px solid var(--hairline);border-bottom-left-radius:4px;')" x-text="m.body"></div>
                            <div :style="'font-size:10px;font-family:var(--font-mono);color:var(--muted-soft);margin-top:3px;'+(m.mine ? 'text-align:right;' : '')" x-text="m.at"></div>
                        </div>
                    </template>
```
with:
```blade
                    <template x-for="m in (active ? active.messages : [])" :key="m.id">
                        <div :style="'max-width:78%;'+(m.mine ? 'align-self:flex-end;' : 'align-self:flex-start;')">
                            <div x-show="m.body" :style="'padding:9px 12px;border-radius:14px;font-size:13px;line-height:1.5;white-space:pre-wrap;word-break:break-word;'+(m.mine ? 'background:var(--red);color:#fff;border-bottom-right-radius:4px;' : 'background:#fff;color:var(--ink);border:1px solid var(--hairline);border-bottom-left-radius:4px;')" x-text="m.body"></div>
                            <template x-for="att in (m.attachments || [])" :key="att.id">
                                <div style="margin-top:5px;">
                                    <template x-if="att.isImage">
                                        <a :href="att.url" target="_blank" rel="noopener">
                                            <img :src="att.url" :alt="att.name" style="max-width:200px;max-height:200px;border-radius:11px;display:block;border:1px solid var(--hairline);" />
                                        </a>
                                    </template>
                                    <template x-if="!att.isImage">
                                        <a :href="att.url" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:7px;padding:8px 11px;border-radius:11px;text-decoration:none;background:#fff;border:1px solid var(--hairline);color:var(--ink);max-width:210px;">
                                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                                            <span style="font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" x-text="att.name"></span>
                                        </a>
                                    </template>
                                </div>
                            </template>
                            <div :style="'font-size:10px;font-family:var(--font-mono);color:var(--muted-soft);margin-top:3px;'+(m.mine ? 'text-align:right;' : '')" x-text="m.at"></div>
                        </div>
                    </template>
```

- [ ] **Step 3: Add the composer inputs (paperclip + mobile camera + pending row)**

In `resources/views/partials/messages-panel.blade.php`, replace the composer form:
```blade
                <form @submit.prevent="send()" style="flex-shrink:0;padding:12px 16px;border-top:1px solid var(--hairline);display:flex;align-items:flex-end;gap:9px;">
                    <textarea x-model="body" @keydown.enter.prevent="send()" rows="1" maxlength="5000"
                              :placeholder="$store.ui.lang==='en' ? 'Write a message…' : 'Tulis mesej…'"
                              style="flex:1;min-height:42px;max-height:120px;padding:10px 12px;border:1px solid var(--hairline);border-radius:10px;font-size:13px;resize:none;outline:none;font-family:inherit;line-height:1.5;"></textarea>
                    <button type="submit" :disabled="!body.trim() || sending" class="uj-btn-primary" style="height:42px;width:42px;flex-shrink:0;display:flex;align-items:center;justify-content:center;padding:0;" :style="(!body.trim() || sending) ? 'opacity:.5;cursor:not-allowed;' : ''">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4z"></path></svg>
                    </button>
                </form>
```
with:
```blade
                <form @submit.prevent="send()" style="flex-shrink:0;padding:12px 16px;border-top:1px solid var(--hairline);display:flex;flex-direction:column;gap:7px;">
                    <div x-show="files.length" x-cloak style="display:flex;flex-wrap:wrap;gap:6px;">
                        <template x-for="(f, i) in files" :key="i">
                            <span style="display:inline-flex;align-items:center;gap:6px;padding:5px 9px;background:var(--canvas);border:1px solid var(--hairline);border-radius:8px;font-size:11.5px;color:var(--ink);max-width:170px;">
                                <span x-text="f.name" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"></span>
                                <button type="button" @click="files.splice(i,1)" style="background:none;color:var(--muted);font-size:14px;line-height:1;">×</button>
                            </span>
                        </template>
                    </div>
                    <div style="display:flex;align-items:flex-end;gap:9px;">
                        <input x-ref="pfile" type="file" name="attachments[]" multiple
                               accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.txt,.csv"
                               @change="addFiles" style="display:none;" />
                        <input x-ref="pcam" type="file" accept="image/*" capture="environment" @change="addFiles" style="display:none;" />
                        <button type="button" @click="$refs.pfile.click()" title="Attach" style="height:42px;width:38px;flex-shrink:0;display:flex;align-items:center;justify-content:center;background:none;color:var(--muted);">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path></svg>
                        </button>
                        <button type="button" @click="$refs.pcam.click()" title="Camera" class="uj-cam-only" style="height:42px;width:38px;flex-shrink:0;display:none;align-items:center;justify-content:center;background:none;color:var(--muted);">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path><circle cx="12" cy="13" r="4"></circle></svg>
                        </button>
                        <textarea x-model="body" @keydown.enter.prevent="send()" rows="1" maxlength="5000"
                                  :placeholder="$store.ui.lang==='en' ? 'Write a message…' : 'Tulis mesej…'"
                                  style="flex:1;min-height:42px;max-height:120px;padding:10px 12px;border:1px solid var(--hairline);border-radius:10px;font-size:13px;resize:none;outline:none;font-family:inherit;line-height:1.5;"></textarea>
                        <button type="submit" :disabled="(!body.trim() && files.length === 0) || sending" class="uj-btn-primary" style="height:42px;width:42px;flex-shrink:0;display:flex;align-items:center;justify-content:center;padding:0;" :style="((!body.trim() && files.length === 0) || sending) ? 'opacity:.5;cursor:not-allowed;' : ''">
                            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4z"></path></svg>
                        </button>
                    </div>
                </form>
```

The `uj-cam-only` visibility rule from Task 5 lives in the messages screen only. The panel is global, so add the same media-query rule once in `resources/views/layouts/app.blade.php` (inside its existing `<style>` block, or a new one in `<head>`):
```blade
<style>@media (hover: none) and (pointer: coarse) { .uj-cam-only { display:inline-flex !important; } }</style>
```
If Task 5 already added an identical global rule reachable by the panel, do not duplicate it — one definition suffices. (The screen-scoped `<style>` in Task 5 only covers the full page; the panel needs the rule in the layout. Keep the rule in the layout and drop the screen-scoped copy if both would load on the same page.)

- [ ] **Step 4: Browser verification**

1. Ensure the dev server is running (`preview_start {name: "laravel-app"}`).
2. Log in via `http://localhost:9100/dev/login?email=hr@amanahku.test&tenant=unijaya`.
3. On any screen, open the header envelope panel, open a conversation.
4. Attach an image via the paperclip, confirm the pending chip (with ×), send. Confirm the thumbnail renders and the message posts without a full page reload.
5. Send an image with no text — confirm it is allowed and renders.
6. `read_console_messages` — expect no errors (esp. no multipart/CSRF failure).
7. `resize_window {preset: "mobile"}` — confirm the camera button appears in the panel composer.
8. `computer {action: "screenshot"}` for proof.

- [ ] **Step 5: Commit**

```bash
git add resources/views/layouts/app.blade.php resources/views/partials/messages-panel.blade.php
git commit -m "feat(messages): side-panel FormData send with file+camera attach and bubbles"
```

---

## Final verification (after all tasks)

- [ ] Run the whole messaging suite: `php artisan test --filter=Message` — expect all MessageTest (10) + MessageAttachmentTest (9) green.
- [ ] Confirm no orphaned `uj-cam-only` rule duplication across layout + screen.
- [ ] The feature is code + Blade + Alpine only. Do NOT build assets or deploy — there is no compiled JS/CSS bundle change here (all inline Blade/Alpine), so `public/build` is untouched.

## Self-review notes (author)

- **Spec coverage:** table/model/relation (Task 1) ✓; send accept+store+image-only (Task 2) ✓; participant-gated stream (Task 3) ✓; payload attachments + snippet (Task 4) ✓; full-page UI + camera (Task 5) ✓; panel UI + FormData + camera (Task 6) ✓. Camera-on-mobile spec item covered in Tasks 5–6.
- **Type consistency:** attachment array keys `{id, name, isImage, url}` are identical in `messageArr()` (Task 4) and both bubble renderers (Tasks 5–6). Route name `messages.attachment` consistent across Task 3 route + Task 4 `route()` call. Alpine state `files` used consistently in Task 6 `send()`/`addFiles()`/`back()` and the composer template.
- **Empty-body handling:** body stored as `''`; bubbles hide the body div when empty (`@if ($m['body'] !== '')` / `x-show="m.body"`); snippet uses `body !== ''` to detect attachment-only. Consistent across the stack.
