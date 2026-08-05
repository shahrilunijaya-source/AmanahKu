# Feedback-into-Helpdesk Merge Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Retire the standalone Feedback module (bug/idea reports with status-only triage) and fold it into the Helpdesk/Ticket system, so reporters get real answers (a resolution note) and a way to check whether their report was answered — the thing Feedback never had.

**Architecture:** Two new columns + one new table on the existing `tickets` schema (category gains `Bug`/`Idea`, `page_url` for repro context, `ticket_attachments` for screenshots). `HelpdeskController` grows category-conditional branches instead of a parallel controller. A one-off migration copies every `feedback_items`/`feedback_attachments` row into `tickets`/`ticket_attachments`, verifies counts, then drops the old tables. `FeedbackController`, `FeedbackItem`, `FeedbackAttachment`, feedback routes/views are deleted in the final task once everything they did lives in Helpdesk.

**Tech Stack:** Laravel 13 (PHP 8.5), Blade + Alpine.js, MySQL (prod/staging) / SQLite in-memory (tests, `phpunit.xml:31-32`), PHPUnit 12, Pint.

## Global Constraints

- Full spec: `docs/superpowers/specs/2026-08-05-feedback-into-helpdesk-design.md` — every task below implements a section of it; read it if a task's rationale is unclear.
- Bug/Idea ticket **view** access: `management`, `hr` (via `hasTenantRole`, which already covers `director` through `Permissions::effectiveRole` and covers superadmin-as-observer through `User::roleIn()` collapsing to `'management'` — see `app/Models/User.php:113-117`). `manager` and everyone else get **no** visibility into Bug/Idea tickets in the privileged inbox.
- Bug/Idea ticket **act** (assign/resolve/status) access: `$request->user()->isSuperAdmin()` only, checked directly — NOT `hasTenantRole`, because that would also admit real director/HR tenant members.
- The ticket raiser always sees their own Bug/Idea ticket (status + resolution) in `myTickets` regardless of role — this is never filtered. It also has to be **rendered** for every role: the pre-purge Helpdesk blade only ever drew a "My tickets" card in the non-privileged branch, so Task 10 extracts it into `partials/my-tickets.blade.php` and includes it in both. A manager's own Bug ticket is filtered off the board by design, so that card is its only surface.
- `partials/ticket-raise.blade.php` is included on **every** screen (`layouts/app.blade.php:177`). It must never read screen-local view data — it takes its category/priority lists from `HelpdeskController::CATEGORIES`/`::PRIORITIES` (public as of Task 3). It also carries over the What's New (changelog) tab from `partials/feedback.blade.php`, which is otherwise deleted with that file in Task 13.
- Tests that need the Helpdesk **screen** to render can only run from Task 10 onward: `module.helpdesk` is in `Features::OFF` until Task 7 (screen 404s) and `screens/helpdesk.blade.php` does not exist until Task 10 (falls back to `screens.empty`). Earlier tasks test controller methods directly — see Task 5.
- Non-Bug/Idea categories (IT/Facilities/HR/Other) keep every existing rule unchanged: `manager`/`management`/`hr` can view and act, priority is required from the submitter, no attachments, no `page_url`.
- Attachment caps carried over from Feedback unchanged: max 6 files, mimes `jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,txt,csv`, 8 MB per file, private `local` disk, auth-gated stream only.
- Ticket submission still requires an employee profile (`abort_unless($employee, 403, ...)`) — unchanged from today's Helpdesk rule. Confirmed via `FeedbackItem::whereNull('employee_id')->count()` = 0 on the current dataset, so this is not a regression for anyone with data on record.
- Deleting `tests/Feature/FeedbackTest.php` in the final task is pre-approved by the merge spec (the whole module retires) — this satisfies the project's "do not remove tests without approval" rule via that spec approval, not a fresh exception.
- Run `vendor/bin/pint --dirty --format agent` after any PHP file change, before considering a task done.
- Every task's test(s) must pass via `php artisan test --compact <path>` before moving to the next task.

---

### Task 1: Extend `tickets` schema — Bug/Idea category + `page_url`

**Files:**
- Create: `database/migrations/2026_08_05_000001_add_bug_idea_categories_and_page_url_to_tickets_table.php`
- Test: `tests/Feature/HelpdeskTest.php` (append)

**Interfaces:**
- Produces: `tickets.category` enum now accepts `Bug`, `Idea` alongside `IT`, `Facilities`, `HR`, `Other`. New nullable `tickets.page_url` (string, max 500) column. `tickets.employee_id` becomes **nullable**.

**Why `employee_id` becomes nullable:** `feedback_items.employee_id` is `nullable()->nullOnDelete()` but `tickets.employee_id` is `foreignId()->constrained()` — NOT NULL. Task 12 copies one into the other. The current local dataset has zero such rows (spec lines 54-58), but staging and production were never checked, and a single null there would crash the migration mid-deploy. Relaxing the column is the option that loses no reports: a feedback item whose author's employee record was since deleted is still a real report, and the board blade already renders `$t->employee?->name` with an "Unknown" fallback (`pre-blade-purge:…/helpdesk.blade.php:149`). `store()` always sets it, so nothing new can be created without a raiser.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/HelpdeskTest.php` (inside the class, after `test_raising_a_ticket_requires_a_subject`):

```php
    public function test_ticket_category_accepts_bug_and_idea_and_stores_page_url(): void
    {
        $ticket = Ticket::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'category' => 'Bug', 'priority' => 'medium', 'subject' => 'Clock-in broken',
            'description' => 'Nothing happens on tap.', 'status' => 'open',
            'page_url' => 'http://localhost/app/dash',
        ]);

        $this->assertSame('Bug', $ticket->fresh()->category);
        $this->assertSame('http://localhost/app/dash', $ticket->fresh()->page_url);
    }

    /** Task 12 copies feedback rows whose employee_id may be null; the column has to accept them. */
    public function test_ticket_accepts_a_null_employee_id(): void
    {
        $ticket = Ticket::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => null,
            'category' => 'Idea', 'priority' => 'medium', 'subject' => 'Orphaned report',
            'description' => 'Author has no employee record.', 'status' => 'open',
        ]);

        $this->assertNull($ticket->fresh()->employee_id);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/HelpdeskTest.php --filter=test_ticket_`
Expected: both FAIL — a `page_url` column-not-found error or a CHECK-constraint/enum-truncation failure on `category`, and a NOT NULL violation on `employee_id`.

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->enum('category', ['IT', 'Facilities', 'HR', 'Other', 'Bug', 'Idea'])
                ->default('IT')->change();
            // Feedback allowed a report from someone with no employee record; tickets did not.
            // Relaxed so the Task 12 fold cannot hit a NOT NULL violation mid-deploy.
            $table->foreignId('employee_id')->nullable()->change();
            $table->string('page_url', 500)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('page_url');
            $table->foreignId('employee_id')->nullable(false)->change();
            $table->enum('category', ['IT', 'Facilities', 'HR', 'Other'])->default('IT')->change();
        });
    }
};
```

Both `change()` calls rewrite a column that carries a foreign key. On MySQL the constraint is a separate object and survives; on SQLite (the test connection) Laravel rebuilds the whole table and re-creates the three FKs from the schema. Step 4 is where you find out if that assumption is wrong — if the rebuild errors, drop and re-add the FK explicitly around the change rather than working around it in the model.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/HelpdeskTest.php --filter=test_ticket_`
Expected: PASS (both)

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_08_05_000001_add_bug_idea_categories_and_page_url_to_tickets_table.php tests/Feature/HelpdeskTest.php
git commit -m "feat(helpdesk): add Bug/Idea categories and page_url to tickets"
```

---

### Task 2: `ticket_attachments` table + `TicketAttachment` model + `Ticket::attachments()`

**Files:**
- Create: `database/migrations/2026_08_05_000002_create_ticket_attachments_table.php`
- Create: `app/Models/TicketAttachment.php`
- Modify: `app/Models/Ticket.php`
- Test: `tests/Feature/HelpdeskTest.php` (append)

**Interfaces:**
- Consumes: `Ticket` model (`app/Models/Ticket.php:11-28`), `BelongsToTenant` trait (`app/Models/Concerns/BelongsToTenant.php`).
- Produces: `TicketAttachment::class` with `tenant_id`, `ticket_id`, `path`, `name`, `mime`, `size` — consumed by Task 3 (store) and Task 4 (stream).
  - `TicketAttachment::isImage(): bool`
  - `TicketAttachment::ticket(): BelongsTo`
  - `Ticket::attachments(): HasMany` (oldest-first)

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/HelpdeskTest.php`:

```php
    public function test_ticket_has_many_attachments_oldest_first(): void
    {
        $ticket = Ticket::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'category' => 'Bug', 'priority' => 'medium', 'subject' => 'Broken export',
            'description' => 'CSV export 500s.', 'status' => 'open',
        ]);

        $first = $ticket->attachments()->create([
            'tenant_id' => $this->tenant->id, 'path' => 'ticket-attachments/a.png',
            'name' => 'a.png', 'mime' => 'image/png', 'size' => 100,
        ]);
        $second = $ticket->attachments()->create([
            'tenant_id' => $this->tenant->id, 'path' => 'ticket-attachments/b.png',
            'name' => 'b.png', 'mime' => 'image/png', 'size' => 200,
        ]);

        $ordered = $ticket->attachments()->pluck('id')->all();
        $this->assertSame([$first->id, $second->id], $ordered);
        $this->assertTrue($first->isImage());
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/HelpdeskTest.php --filter=test_ticket_has_many_attachments_oldest_first`
Expected: FAIL — `TicketAttachment` class not found / `attachments` relation undefined.

- [ ] **Step 3: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->string('path');                       // location on the private 'local' disk
            $table->string('name');                       // original / generated filename shown to humans
            $table->string('mime')->nullable();           // drives image-vs-document rendering
            $table->unsignedInteger('size')->default(0);  // bytes
            $table->timestamps();

            $table->index('ticket_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_attachments');
    }
};
```

- [ ] **Step 4: Create the model**

```php
<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file a reporter attaches to a ticket — a pasted screenshot or an uploaded document,
 * relevant to Bug/Idea tickets in particular. Files live on the private 'local' disk and
 * are only ever reached through HelpdeskController::attachment (auth-gated stream), never
 * a public URL.
 */
class TicketAttachment extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected $casts = ['size' => 'integer'];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** Images render as inline thumbnails; everything else as a download chip. */
    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }
}
```

- [ ] **Step 5: Add the relation to `Ticket`**

In `app/Models/Ticket.php`, add after the `assignee()` method (currently the last method, line 27):

```php

    /** Screenshots + documents the reporter attached, in upload order. */
    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class)->oldest('id');
    }
```

And add the import at the top alongside the existing `BelongsTo` import:

```php
use Illuminate\Database\Eloquent\Relations\HasMany;
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/HelpdeskTest.php --filter=test_ticket_has_many_attachments_oldest_first`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_08_05_000002_create_ticket_attachments_table.php app/Models/TicketAttachment.php app/Models/Ticket.php tests/Feature/HelpdeskTest.php
git commit -m "feat(helpdesk): add ticket_attachments table, model, and Ticket relation"
```

---

### Task 3: `HelpdeskController::store` — accept Bug/Idea with conditional fields + attachments

**Files:**
- Modify: `app/Http/Controllers/HelpdeskController.php:1-107`
- Modify: `routes/web.php:278` (port Feedback's rate limit)
- Test: `tests/Feature/HelpdeskTest.php` (append)

**Interfaces:**
- Consumes: `TicketAttachment` (Task 2), `Ticket` (existing), `Employee` (existing).
- Produces: `HelpdeskController::store()` now accepts `category` in `['IT','Facilities','HR','Other','Bug','Idea']`; for `Bug`/`Idea`, `priority` is forced to `'medium'` server-side regardless of input, `description` becomes optional (was required for other categories), `page_url` and `attachments[]` are accepted and persisted.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/HelpdeskTest.php`:

```php
    public function test_bug_ticket_ignores_posted_priority_and_defaults_to_medium(): void
    {
        $this->actingInTenant()->post('/app/helpdesk', [
            'category' => 'Bug',
            'priority' => 'urgent',
            'subject' => 'Clock-in button does nothing',
            'description' => 'Tapping clock-in on the dashboard has no effect.',
            'page_url' => 'http://localhost/app/dash',
        ])->assertRedirect();

        $this->assertDatabaseHas('tickets', [
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'category' => 'Bug',
            'priority' => 'medium',
            'page_url' => 'http://localhost/app/dash',
            'status' => 'open',
        ]);
    }

    public function test_idea_ticket_allows_missing_description(): void
    {
        $response = $this->actingInTenant()->post('/app/helpdesk', [
            'category' => 'Idea',
            'subject' => 'Dark mode please',
        ]);

        $response->assertRedirect();
        $ticket = Ticket::withoutGlobalScopes()->latest('id')->first();
        $this->assertNotNull($ticket);
        $this->assertSame('Idea', $ticket->category);
        $this->assertSame('medium', $ticket->priority);
    }

    public function test_it_ticket_still_requires_description_and_priority(): void
    {
        $response = $this->actingInTenant()->post('/app/helpdesk', [
            'category' => 'IT',
            'subject' => 'No description supplied',
        ]);

        $response->assertSessionHasErrors(['priority', 'description']);
    }

    public function test_submit_stores_pasted_screenshot_and_uploaded_document_on_a_bug_ticket(): void
    {
        Storage::fake('local');

        $response = $this->actingInTenant()->post('/app/helpdesk', [
            'category' => 'Bug',
            'subject' => 'Layout broken with proof',
            'description' => 'See attached.',
            'attachments' => [
                UploadedFile::fake()->image('screenshot-1.png'),
                UploadedFile::fake()->create('trace.pdf', 40, 'application/pdf'),
            ],
        ]);

        $response->assertRedirect();
        $ticket = Ticket::withoutGlobalScopes()->latest('id')->first();
        $this->assertSame(2, $ticket->attachments()->count());
        foreach ($ticket->attachments as $att) {
            $this->assertSame($this->tenant->id, $att->tenant_id);
            Storage::disk('local')->assertExists($att->path);
        }
    }

    public function test_submit_rejects_a_disallowed_file_type(): void
    {
        Storage::fake('local');

        $response = $this->actingInTenant()->post('/app/helpdesk', [
            'category' => 'Bug',
            'subject' => 'Sneaky upload',
            'description' => 'x',
            'attachments' => [UploadedFile::fake()->create('malware.exe', 10)],
        ]);

        $response->assertSessionHasErrors('attachments.0');
        $this->assertSame(0, Ticket::withoutGlobalScopes()->count());
    }

    public function test_submit_rejects_more_than_the_attachment_cap(): void
    {
        Storage::fake('local');

        $files = array_map(fn ($i) => UploadedFile::fake()->image("s{$i}.png"), range(1, 7));
        $response = $this->actingInTenant()->post('/app/helpdesk', [
            'category' => 'Idea',
            'subject' => 'Too many pics',
            'attachments' => $files,
        ]);

        $response->assertSessionHasErrors('attachments');
        $this->assertSame(0, Ticket::withoutGlobalScopes()->count());
    }
```

Add the required `use` statements at the top of `tests/Feature/HelpdeskTest.php`:

```php
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact tests/Feature/HelpdeskTest.php`
Expected: the six new tests FAIL (category rejected by validation, no attachment handling exists, `description`/`priority` both hard-required today).

- [ ] **Step 3: Rewrite `HelpdeskController::store` (and constants)**

Replace lines 16-24 (the class constants) in `app/Http/Controllers/HelpdeskController.php`:

```php
    private const PRIVILEGED_ROLES = ['manager', 'management', 'hr'];

    /** Roles that may view (not necessarily act on) Bug/Idea tickets — narrower than the general privileged tier. */
    private const FEEDBACK_VIEW_ROLES = ['management', 'hr'];

    /** Public so the globally-included ticket-raise modal can build its selects without a screen-local $categories. */
    public const CATEGORIES = ['IT', 'Facilities', 'HR', 'Other', 'Bug', 'Idea'];

    /** Categories that carry Feedback's old submission shape: optional description, forced medium priority, page_url + attachments. */
    private const FEEDBACK_CATEGORIES = ['Bug', 'Idea'];

    /** Public for the same reason as CATEGORIES — read directly by partials/ticket-raise.blade.php. */
    public const PRIORITIES = ['low', 'medium', 'high', 'urgent'];

    private const STATUSES = ['open', 'in_progress', 'resolved', 'closed'];

    /** Private disk ticket screenshots/documents live on — reached only via attachment(). */
    private const ATTACHMENT_DISK = 'local';

    /** Ceiling on files per ticket, and the accepted extensions (images + PDF + Office docs). */
    private const MAX_ATTACHMENTS = 6;

    private const ATTACHMENT_MIMES = 'jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,txt,csv';
```

Replace the `store()` method (lines 80-107):

```php
    /** Any employee in the workspace may raise a support ticket, bug report, or idea. */
    public function store(Request $request): RedirectResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');

        $isFeedback = in_array($request->input('category'), self::FEEDBACK_CATEGORIES, true);

        $data = $request->validate([
            'category' => ['required', Rule::in(self::CATEGORIES)],
            'priority' => [$isFeedback ? 'nullable' : 'required', Rule::in(self::PRIORITIES)],
            'subject' => ['required', 'string', 'max:150'],
            'description' => [$isFeedback ? 'nullable' : 'required', 'string', 'max:2000'],
            'page_url' => ['nullable', 'string', 'max:500'],
            // Pasted screenshots + uploaded documents on Bug/Idea tickets. Each capped at
            // 8 MB; whole set capped at MAX_ATTACHMENTS. Mirrors the old Feedback module.
            'attachments' => ['nullable', 'array', 'max:'.self::MAX_ATTACHMENTS],
            'attachments.*' => ['file', 'mimes:'.self::ATTACHMENT_MIMES, 'max:8192'],
        ], [
            'attachments.max' => 'You can attach up to '.self::MAX_ATTACHMENTS.' files.',
            'attachments.*.mimes' => 'Attachments must be an image, PDF, or Office document.',
            'attachments.*.max' => 'Each attachment must be 8 MB or smaller.',
        ]);

        // No tickets() relation is defined on Employee (and that model is off-limits),
        // so bind the raiser explicitly. tenant_id is auto-filled by BelongsToTenant.
        $ticket = Ticket::create([
            'employee_id' => $employee->id,
            'category' => $data['category'],
            'priority' => $isFeedback ? 'medium' : $data['priority'],
            'subject' => $data['subject'],
            'description' => $data['description'] ?? '',
            'page_url' => $isFeedback ? ($data['page_url'] ?? null) : null,
            'status' => 'open',
        ]);

        // Persist each file to the private disk and hang a row off the ticket. Storing
        // after the ticket exists keeps orphan files impossible if validation rejects the batch.
        foreach ((array) $request->file('attachments', []) as $file) {
            if (! $file || ! $file->isValid()) {
                continue;
            }
            $path = $file->store('ticket-attachments', self::ATTACHMENT_DISK);
            abort_unless($path !== false, 500, 'Attachment could not be stored.');
            $ticket->attachments()->create([
                'tenant_id' => $ticket->tenant_id,
                'path' => $path,
                'name' => $file->getClientOriginalName() ?: 'attachment',
                'mime' => $file->getClientMimeType(),
                'size' => $file->getSize() ?? 0,
            ]);
        }

        AuditLog::record('Raised ticket', $data['subject'].' · '.$data['category']);

        return back()->with('ok', 'Ticket raised — '.$data['subject'].'.');
    }
```

- [ ] **Step 4: Port Feedback's rate limit onto the ticket route**

`feedback.store` carried `->middleware('throttle:20,1')` (`routes/web.php:317`) because it was reachable from a modal on every page. After Task 9 the ticket-raise modal is reachable from every page too, and it now accepts file uploads, so the limit matters more, not less. `helpdesk.store` has none today. In `routes/web.php`, change line 278 from:

```php
        Route::post('/app/helpdesk', [HelpdeskController::class, 'store'])->name('helpdesk.store');
```

to:

```php
        // 20/min per user — carried over from the retiring feedback.store route. This modal is
        // on every screen and takes uploads, so it stays rate-limited.
        Route::post('/app/helpdesk', [HelpdeskController::class, 'store'])->middleware('throttle:20,1')->name('helpdesk.store');
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/HelpdeskTest.php`
Expected: all PASS, including the pre-existing `test_employee_raises_a_ticket` and `test_raising_a_ticket_requires_a_subject`. No test posts more than 20 times, so the throttle is inert here.

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/HelpdeskController.php routes/web.php tests/Feature/HelpdeskTest.php
git commit -m "feat(helpdesk): accept Bug/Idea tickets with attachments and page_url"
```

---

### Task 4: Ticket attachment streaming action + route

**Files:**
- Modify: `app/Http/Controllers/HelpdeskController.php`
- Modify: `routes/web.php:279` (add one line after)
- Test: `tests/Feature/HelpdeskTest.php` (append)

**Interfaces:**
- Consumes: `TicketAttachment` (Task 2).
- Produces: `HelpdeskController::attachment(Request, TicketAttachment): StreamedResponse`, route `helpdesk.attachment` at `GET /app/helpdesk/attachments/{attachment}`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/HelpdeskTest.php`:

```php
    private function seedAttachment(Ticket $ticket): TicketAttachment
    {
        $path = UploadedFile::fake()->image('shot.png')->store('ticket-attachments', 'local');

        return TicketAttachment::create([
            'tenant_id' => $ticket->tenant_id,
            'ticket_id' => $ticket->id,
            'path' => $path,
            'name' => 'shot.png',
            'mime' => 'image/png',
            'size' => 1024,
        ]);
    }

    public function test_raiser_can_download_own_attachment(): void
    {
        Storage::fake('local');
        $ticket = Ticket::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'category' => 'Bug', 'priority' => 'medium', 'subject' => 'x',
            'description' => 'x', 'status' => 'open',
        ]);
        $att = $this->seedAttachment($ticket);

        $response = $this->actingInTenant()->get('/app/helpdesk/attachments/'.$att->id);

        $response->assertOk();
    }

    public function test_hr_can_download_bug_ticket_attachment(): void
    {
        Storage::fake('local');
        $ticket = Ticket::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'category' => 'Bug', 'priority' => 'medium', 'subject' => 'x',
            'description' => 'x', 'status' => 'open',
        ]);
        $att = $this->seedAttachment($ticket);
        $hr = $this->hrActor();

        $response = $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/helpdesk/attachments/'.$att->id);

        $response->assertOk();
    }

    public function test_unrelated_employee_cannot_download_ticket_attachment(): void
    {
        Storage::fake('local');
        $ticket = Ticket::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'category' => 'Bug', 'priority' => 'medium', 'subject' => 'x',
            'description' => 'x', 'status' => 'open',
        ]);
        $att = $this->seedAttachment($ticket);

        $stranger = User::create(['name' => 'Nosy', 'email' => 'nosy@example.com', 'password' => Hash::make('password')]);
        $stranger->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $stranger->id,
            'name' => 'Nosy', 'status' => 'active', 'workload' => 'green',
        ]);

        $response = $this->actingAs($stranger)->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/helpdesk/attachments/'.$att->id);

        $response->assertForbidden();
    }
```

Add `use App\Models\TicketAttachment;` to the top of `tests/Feature/HelpdeskTest.php`.

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact tests/Feature/HelpdeskTest.php --filter=download`
Expected: FAIL — route `helpdesk.attachment` does not exist (404).

- [ ] **Step 3: Add the route**

In `routes/web.php`, change line 279 from:

```php
        Route::post('/app/helpdesk/{ticket}', [HelpdeskController::class, 'update'])->name('helpdesk.update');
```

to:

```php
        Route::post('/app/helpdesk/{ticket}', [HelpdeskController::class, 'update'])->name('helpdesk.update');
        // Stream a ticket's screenshot/document — auth-gated (raiser or an appropriate viewer), never public.
        Route::get('/app/helpdesk/attachments/{attachment}', [HelpdeskController::class, 'attachment'])->name('helpdesk.attachment');
```

- [ ] **Step 4: Add the controller action**

In `app/Http/Controllers/HelpdeskController.php`, add after `store()` (before `update()`):

```php

    /**
     * Stream a ticket attachment through an auth-gated action (never a public URL) — inline,
     * so image thumbnails and PDFs render straight in the helpdesk views. The raiser always
     * gets their own attachments; everyone else needs an appropriate view tier: Bug/Idea
     * tickets are gated to FEEDBACK_VIEW_ROLES (management/hr), everything else to the
     * general PRIVILEGED_ROLES. Tenant-scoped model binding already blocks cross-tenant ids;
     * the explicit check is defence in depth.
     */
    public function attachment(Request $request, TicketAttachment $attachment): StreamedResponse
    {
        abort_unless($attachment->tenant_id === app(CurrentTenant::class)->id(), 403);

        $ticket = $attachment->ticket;
        $employee = $request->attributes->get('employee');
        $isOwner = $ticket && $employee && $ticket->employee_id === $employee->id;
        $isFeedbackCategory = $ticket && in_array($ticket->category, self::FEEDBACK_CATEGORIES, true);
        $canView = $isFeedbackCategory
            ? $this->hasTenantRole($request, self::FEEDBACK_VIEW_ROLES)
            : $this->hasTenantRole($request, self::PRIVILEGED_ROLES);

        abort_unless($isOwner || $canView, 403);
        abort_unless(Storage::disk(self::ATTACHMENT_DISK)->exists($attachment->path), 404);

        return Storage::disk(self::ATTACHMENT_DISK)->response($attachment->path, $attachment->name);
    }
```

Add these imports at the top of `HelpdeskController.php` (alongside the existing `use` block):

```php
use App\Models\TicketAttachment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/HelpdeskTest.php --filter=download`
Expected: PASS (all three)

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/HelpdeskController.php routes/web.php tests/Feature/HelpdeskTest.php
git commit -m "feat(helpdesk): stream ticket attachments through an auth-gated action"
```

---

### Task 5: `screenData` — restrict Bug/Idea board visibility, expose `isSuperAdmin`

**Files:**
- Modify: `app/Http/Controllers/HelpdeskController.php:35-78`
- Test: `tests/Feature/HelpdeskTest.php` (append)

**Interfaces:**
- Consumes: `hasTenantRole` (`app/Http/Controllers/Controller.php:24-34`), `User::isSuperAdmin()` (`app/Models/User.php:52-54`).
- Produces: `screenData()` return array gains `'isSuperAdmin' => bool`. The `grouped`/`counts`/non-privileged-`myTickets` computation is unchanged in shape; only the underlying ticket query for the privileged board excludes Bug/Idea rows for viewers who are not `management`/`hr`. `myTickets` (both branches) is never filtered by category.

- [ ] **Step 1: Write the failing tests**

These drive `screenData()` **directly** rather than through `GET /app/helpdesk`. Two reasons, both blocking otherwise: `module.helpdesk` is still in `Features::OFF` until Task 7, so the screen 404s (`AppController.php:184`); and `resources/views/screens/helpdesk.blade.php` does not exist until Task 10, so `AppController.php:214` falls back to `screens.empty` — an `assertDontSee` against that would pass for the wrong reason. Direct calls test the thing this task actually changes and have precedent in this suite (`PettyCashTest.php:204-209`, `ComplianceTest.php:174-178`). The HTTP-level render is covered by Task 10's smoke test.

Add these `use` statements at the top of `tests/Feature/HelpdeskTest.php`:

```php
use App\Http\Controllers\HelpdeskController;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\Request;
```

Append to `tests/Feature/HelpdeskTest.php`:

```php
    /**
     * Build the request/tenant context ResolveTenant would have left behind, then call
     * screenData() the way AppController does.
     *
     * @return array<string, mixed>
     */
    private function screenDataAs(User $user, string $role, ?Employee $employee): array
    {
        $request = Request::create('/app/helpdesk', 'GET');
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('tenantRole', $role);
        app(CurrentTenant::class)->set($this->tenant);

        return app(HelpdeskController::class)->screenData($request, $employee);
    }

    /** Flatten the status-keyed board into a subject list, so assertions read plainly. */
    private function boardSubjects(array $data): array
    {
        return $data['grouped']->flatten()->pluck('subject')->all();
    }

    public function test_manager_does_not_see_bug_tickets_in_the_board(): void
    {
        Ticket::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'category' => 'Bug', 'priority' => 'medium', 'subject' => 'Manager-hidden bug',
            'description' => 'x', 'status' => 'open',
        ]);
        Ticket::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'category' => 'IT', 'priority' => 'medium', 'subject' => 'Manager-visible IT',
            'description' => 'x', 'status' => 'open',
        ]);
        $manager = User::create(['name' => 'Mgr', 'email' => 'mgr@example.com', 'password' => Hash::make('password')]);
        $manager->tenants()->attach($this->tenant->id, ['role' => 'manager']);
        $managerEmployee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $manager->id,
            'name' => 'Mgr', 'status' => 'active', 'workload' => 'green',
        ]);

        $subjects = $this->boardSubjects($this->screenDataAs($manager, 'manager', $managerEmployee));

        // The IT ticket proves the board is populated at all — without it, a screenData()
        // that returned an empty board for every role would still pass the Bug assertion.
        $this->assertContains('Manager-visible IT', $subjects);
        $this->assertNotContains('Manager-hidden bug', $subjects);
    }

    public function test_hr_and_director_see_bug_tickets_in_the_board(): void
    {
        Ticket::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'category' => 'Bug', 'priority' => 'medium', 'subject' => 'HR-visible bug',
            'description' => 'x', 'status' => 'open',
        ]);
        $hr = $this->hrActor();
        $hrEmployee = Employee::where('user_id', $hr->id)->firstOrFail();

        $this->assertContains('HR-visible bug', $this->boardSubjects($this->screenDataAs($hr, 'hr', $hrEmployee)));
        // director collapses to management through Permissions::effectiveRole (spec line 160).
        $this->assertContains('HR-visible bug', $this->boardSubjects($this->screenDataAs($hr, 'director', $hrEmployee)));
    }

    public function test_superadmin_observer_sees_bug_tickets_in_the_board(): void
    {
        Ticket::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'category' => 'Bug', 'priority' => 'medium', 'subject' => 'Observer-visible bug',
            'description' => 'x', 'status' => 'open',
        ]);
        $superadmin = User::create(['name' => 'Root', 'email' => 'root@example.com', 'password' => Hash::make('password')]);
        $superadmin->forceFill(['is_super_admin' => true])->save();

        // No membership row, so ResolveTenant hands screenData the roleIn() collapse: 'management'.
        $data = $this->screenDataAs($superadmin, $superadmin->roleIn($this->tenant), null);

        $this->assertContains('Observer-visible bug', $this->boardSubjects($data));
        $this->assertTrue($data['isSuperAdmin']);
    }

    public function test_raiser_sees_own_bug_ticket_in_my_tickets_as_a_plain_employee(): void
    {
        Ticket::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'category' => 'Bug', 'priority' => 'medium', 'subject' => 'My own bug',
            'description' => 'x', 'status' => 'resolved', 'resolution' => 'Fixed in 1.2.3.',
        ]);

        $data = $this->screenDataAs($this->user, 'employee', $this->employee);

        $this->assertFalse($data['privileged']);
        $this->assertSame(['My own bug'], $data['myTickets']->pluck('subject')->all());
        $this->assertSame('Fixed in 1.2.3.', $data['myTickets']->first()->resolution);
    }

    public function test_manager_still_sees_a_bug_ticket_they_raised_in_my_tickets(): void
    {
        $manager = User::create(['name' => 'Mgr', 'email' => 'mgr@example.com', 'password' => Hash::make('password')]);
        $manager->tenants()->attach($this->tenant->id, ['role' => 'manager']);
        $managerEmployee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $manager->id,
            'name' => 'Mgr', 'status' => 'active', 'workload' => 'green',
        ]);
        Ticket::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $managerEmployee->id,
            'category' => 'Bug', 'priority' => 'medium', 'subject' => 'Bug I raised myself',
            'description' => 'x', 'status' => 'resolved', 'resolution' => 'Done.',
        ]);

        $data = $this->screenDataAs($manager, 'manager', $managerEmployee);

        // Filtered OUT of the board (manager is not in FEEDBACK_VIEW_ROLES) but still present
        // in myTickets — the raiser always tracks their own report. This is the whole feature.
        $this->assertNotContains('Bug I raised myself', $this->boardSubjects($data));
        $this->assertSame(['Bug I raised myself'], $data['myTickets']->pluck('subject')->all());
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact tests/Feature/HelpdeskTest.php --filter=bug_ticket`
Expected: FAIL — manager currently sees every category in the board (no filter exists yet), `isSuperAdmin` is not in the returned array, and today's privileged `myTickets` is derived from the board collection so a manager's own Bug ticket disappears with it.

- [ ] **Step 3: Rewrite `screenData`**

Replace `app/Http/Controllers/HelpdeskController.php` lines 35-78 (the whole `screenData` method):

```php
    /**
     * Build the helpdesk screen data. Tenant scope is automatic via BelongsToTenant.
     *
     * Privileged roles get every non-Bug/Idea ticket grouped by status, an assignee picker,
     * and per-status counts. Bug/Idea tickets are folded into that same board, but only for
     * viewers whose tenant role is management/hr (FEEDBACK_VIEW_ROLES) — manager and everyone
     * else never see them there, mirroring the old Feedback inbox's narrower triage tier. A
     * plain employee only ever sees the tickets they raised (myTickets is never filtered by
     * category — the raiser always sees their own Bug/Idea ticket and its resolution).
     *
     * @return array<string, mixed>
     */
    public function screenData(Request $request, ?Employee $employee): array
    {
        $privileged = $this->hasTenantRole($request, self::PRIVILEGED_ROLES);
        $canSeeFeedbackCategories = $this->hasTenantRole($request, self::FEEDBACK_VIEW_ROLES);
        $isSuperAdmin = (bool) $request->user()?->isSuperAdmin();

        if (! $privileged) {
            $myTickets = $employee
                ? Ticket::with('assignee')->where('employee_id', $employee->id)
                    ->orderByDesc('created_at')->get()
                : new Collection;

            return [
                'privileged' => false,
                'isSuperAdmin' => $isSuperAdmin,
                'myTickets' => $myTickets,
                'grouped' => new Collection,
                'employees' => new Collection,
                'counts' => $this->emptyCounts(),
                'categories' => self::CATEGORIES,
                'priorities' => self::PRIORITIES,
                'statuses' => self::STATUSES,
            ];
        }

        $boardQuery = Ticket::with(['employee', 'assignee']);
        if (! $canSeeFeedbackCategories) {
            $boardQuery->whereNotIn('category', self::FEEDBACK_CATEGORIES);
        }
        $tickets = $boardQuery->orderByDesc('created_at')->get();

        // grouped[status] = collection of tickets in that status (every status present).
        $grouped = (new Collection(self::STATUSES))
            ->mapWithKeys(fn (string $s) => [$s => $tickets->where('status', $s)->values()]);

        return [
            'privileged' => true,
            'isSuperAdmin' => $isSuperAdmin,
            // myTickets is built from the SAME set that raised it — not the category-filtered
            // board — so a manager who personally raised a Bug ticket still sees it here.
            'myTickets' => $employee
                ? Ticket::with('assignee')->where('employee_id', $employee->id)
                    ->orderByDesc('created_at')->get()
                : new Collection,
            'grouped' => $grouped,
            'employees' => Employee::active()->orderBy('name')->get(['id', 'name', 'initials', 'avatar_color']),
            'counts' => (new Collection(self::STATUSES))
                ->mapWithKeys(fn (string $s) => [$s => $tickets->where('status', $s)->count()])
                ->all(),
            'categories' => self::CATEGORIES,
            'priorities' => self::PRIORITIES,
            'statuses' => self::STATUSES,
        ];
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/HelpdeskTest.php`
Expected: all PASS, including every pre-existing test in the file.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/HelpdeskController.php tests/Feature/HelpdeskTest.php
git commit -m "feat(helpdesk): restrict Bug/Idea board visibility to management/hr"
```

---

### Task 6: `HelpdeskController::update` — superadmin-only triage for Bug/Idea

**Files:**
- Modify: `app/Http/Controllers/HelpdeskController.php` (the `update` method)
- Test: `tests/Feature/HelpdeskTest.php` (append)

**Interfaces:**
- Consumes: `User::isSuperAdmin()`.
- Produces: `update()` now 403s a `management`/`hr` (non-superadmin) user acting on a Bug/Idea ticket, while still 200ing them on every other category, and still 200ing a superadmin on Bug/Idea.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/HelpdeskTest.php`:

```php
    public function test_hr_cannot_triage_a_bug_ticket(): void
    {
        $hr = $this->hrActor();
        $ticket = Ticket::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'category' => 'Bug', 'priority' => 'medium', 'subject' => 'Needs superadmin',
            'description' => 'x', 'status' => 'open',
        ]);

        $response = $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/helpdesk/{$ticket->id}", [
                'status' => 'resolved', 'resolution' => 'Nice try.',
            ]);

        $response->assertForbidden();
        $this->assertSame('open', $ticket->fresh()->status);
    }

    public function test_superadmin_can_triage_a_bug_ticket(): void
    {
        $superadmin = User::create(['name' => 'Root', 'email' => 'root@example.com', 'password' => Hash::make('password')]);
        $superadmin->forceFill(['is_super_admin' => true])->save();
        $ticket = Ticket::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'category' => 'Idea', 'priority' => 'medium', 'subject' => 'Dark mode',
            'description' => 'x', 'status' => 'open',
        ]);

        $response = $this->actingAs($superadmin)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/helpdesk/{$ticket->id}", [
                'status' => 'resolved', 'resolution' => 'Shipped in 2.0.',
            ]);

        $response->assertRedirect();
        $fresh = $ticket->fresh();
        $this->assertSame('resolved', $fresh->status);
        $this->assertSame('Shipped in 2.0.', $fresh->resolution);
    }

    public function test_hr_still_triages_non_feedback_tickets(): void
    {
        $hr = $this->hrActor();
        $ticket = Ticket::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'category' => 'IT', 'priority' => 'high', 'subject' => 'Broken laptop',
            'description' => 'x', 'status' => 'open',
        ]);

        $response = $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/helpdesk/{$ticket->id}", [
                'status' => 'resolved', 'resolution' => 'Replaced.',
            ]);

        $response->assertRedirect();
        $this->assertSame('resolved', $ticket->fresh()->status);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact tests/Feature/HelpdeskTest.php --filter=triage`
Expected: `test_hr_cannot_triage_a_bug_ticket` FAILs (currently 200s, since hr is in `PRIVILEGED_ROLES`); `test_superadmin_can_triage_a_bug_ticket` FAILs (superadmin has no `Employee`/tenant membership seeded in this test path so `hasTenantRole` currently resolves it via `roleIn`'s `management` fallback and it would already pass today — run it anyway to confirm the baseline, the real regression guard is the HR test).

- [ ] **Step 3: Update `update()`**

Replace the entire `update()` method in `app/Http/Controllers/HelpdeskController.php` (currently lines 109-135, from the `/** Privileged only... */` doc comment through the method's closing `}`):

```php
    /** Privileged only: assign, move status, and record a resolution. Bug/Idea tickets are superadmin-only to act on. */
    public function update(Request $request, Ticket $ticket): RedirectResponse
    {
        abort_unless($ticket->tenant_id === app(CurrentTenant::class)->id(), 403);

        if (in_array($ticket->category, self::FEEDBACK_CATEGORIES, true)) {
            abort_unless($request->user()->isSuperAdmin(), 403);
        } else {
            $this->authorizeTenantRole($request, self::PRIVILEGED_ROLES);
        }

        $tenantId = app(CurrentTenant::class)->id();

        $data = $request->validate([
            'status' => ['required', Rule::in(self::STATUSES)],
            'assignee_employee_id' => [
                'nullable', 'integer',
                Rule::exists('employees', 'id')->where('tenant_id', $tenantId),
            ],
            'resolution' => ['nullable', 'string', 'max:2000'],
        ]);

        $ticket->update([
            'status' => $data['status'],
            'assignee_employee_id' => $data['assignee_employee_id'] ?? null,
            'resolution' => $data['resolution'] ?? null,
        ]);

        AuditLog::record('Updated ticket', $ticket->subject.' · '.$data['status']);

        return back()->with('ok', 'Ticket updated — '.$ticket->subject.'.');
    }
```

This moves the tenant-ownership check to the top (was previously second), replaces the single `authorizeTenantRole` call with the category-conditional branch, and leaves validation/update/audit/redirect untouched.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/HelpdeskTest.php`
Expected: all PASS, including the pre-existing `test_privileged_user_updates_status_assignee_and_resolution` (uses HR on an IT ticket — still allowed) and `test_plain_employee_cannot_update_a_ticket`.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/HelpdeskController.php tests/Feature/HelpdeskTest.php
git commit -m "feat(helpdesk): restrict Bug/Idea ticket triage to superadmin"
```

---

### Task 7: Feature flag — Helpdesk defaults ON

**Files:**
- Modify: `app/Support/Features.php:135-161`
- Test: `tests/Feature/HelpdeskTest.php` (append)

**Interfaces:**
- Produces: `Features::default('module.helpdesk')` returns `true` for a tenant with no stored override.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/HelpdeskTest.php`:

```php
    public function test_helpdesk_module_defaults_on(): void
    {
        $this->assertTrue(\App\Support\Features::default('module.helpdesk'));
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/HelpdeskTest.php --filter=test_helpdesk_module_defaults_on`
Expected: FAIL — `module.helpdesk` is currently in `Features::OFF`, so `default()` returns `false`.

- [ ] **Step 3: Remove `module.helpdesk` from the OFF list**

In `app/Support/Features.php`, delete this line from the `OFF` array (currently line 155):

```php
        'module.helpdesk',
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/HelpdeskTest.php --filter=test_helpdesk_module_defaults_on`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Support/Features.php tests/Feature/HelpdeskTest.php
git commit -m "feat(helpdesk): default the Helpdesk module ON for every tenant"
```

---

### Task 8: Rename `feedback-attach.js` → `ticket-attach.js` (generic Alpine component)

**Files:**
- Create: `resources/js/ticket-attach.js` (content identical to `feedback-attach.js`, renamed export)
- Delete: `resources/js/feedback-attach.js`
- Modify: `resources/js/app.js` (import + registration lines)

**Interfaces:**
- Produces: `registerTicketAttach(Alpine)` registers `Alpine.data('ticketAttach', ...)` — same shape/behavior as the old `feedbackAttach` (files/error state, `addFiles`, `onPaste`, `tryAdd`, `remove`, `sync`, `ext`). Consumed by Task 9's `partials/ticket-raise.blade.php` via `x-data="ticketAttach()"`.

This task is a pure rename with no behavior change, so it has no new test — the existing attachment feature tests from Task 3/4 exercise the server side, and this is client-only glue with no test harness in this repo (mirrors how `feedback-attach.js` itself was never unit tested). Verify by inspection + the manual browser check in Task 11.

- [ ] **Step 1: Create `resources/js/ticket-attach.js`**

```js
// Ticket-raise modal attachments (Bug/Idea categories). Lets a reporter paste a screenshot
// straight into the Description area, or attach up to six images/PDFs/documents, with live
// thumbnail previews and per-file removal. The real <input type="file" name="attachments[]">
// stays hidden; we keep its FileList in sync from our own array via a DataTransfer so the
// plain form POST carries exactly what the previews show. Client checks mirror the server
// rules (mimes + 8 MB each + max 6) — the server remains the source of truth.

const ACCEPT_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'csv'];
const MAX_FILES = 6;
const MAX_BYTES = 8 * 1024 * 1024;

export function registerTicketAttach(Alpine) {
    Alpine.data('ticketAttach', () => ({
        files: [],      // { file, isImage, url }
        error: '',      // '' | 'type' | 'size' | 'max' — blade renders the bilingual message

        // File picker (change) and drop both land here.
        addFiles(list) {
            for (const f of Array.from(list || [])) {
                if (!this.tryAdd(f)) break;
            }
            this.sync();
        },

        // Clipboard paste inside the Description textarea: pull image blobs out and attach them.
        // Non-image pastes (plain text) fall through untouched so typing still works.
        onPaste(e) {
            const items = (e.clipboardData && e.clipboardData.items) || [];
            let added = false;
            for (const it of items) {
                if (it.kind !== 'file' || !it.type.startsWith('image/')) continue;
                const blob = it.getAsFile();
                if (!blob) continue;
                const ext = (blob.type.split('/')[1] || 'png').replace('jpeg', 'jpg');
                const named = new File([blob], `screenshot-${Date.now()}.${ext}`, { type: blob.type });
                if (this.tryAdd(named)) added = true;
            }
            if (added) {
                e.preventDefault();  // don't dump the image's binary into the textarea
                this.sync();
            }
        },

        tryAdd(file) {
            this.error = '';
            const ext = (file.name.split('.').pop() || '').toLowerCase();
            if (!ACCEPT_EXT.includes(ext)) { this.error = 'type'; return false; }
            if (file.size > MAX_BYTES) { this.error = 'size'; return false; }
            if (this.files.length >= MAX_FILES) { this.error = 'max'; return false; }
            const isImage = file.type.startsWith('image/');
            this.files.push({ file, isImage, url: isImage ? URL.createObjectURL(file) : '' });
            return true;
        },

        remove(i) {
            const f = this.files[i];
            if (f && f.url) URL.revokeObjectURL(f.url);
            this.files.splice(i, 1);
            this.error = '';
            this.sync();
        },

        // Rebuild the hidden input's FileList from our array so the form submits it verbatim.
        sync() {
            const dt = new DataTransfer();
            this.files.forEach((f) => dt.items.add(f.file));
            this.$refs.input.files = dt.files;
        },

        ext(name) {
            return (name.split('.').pop() || '').toUpperCase();
        },
    }));
}
```

- [ ] **Step 2: Delete `resources/js/feedback-attach.js`**

```bash
rm resources/js/feedback-attach.js
```

- [ ] **Step 3: Update `resources/js/app.js`**

Change line 4 from:

```js
import { registerFeedbackAttach } from './feedback-attach';
```

to:

```js
import { registerTicketAttach } from './ticket-attach';
```

Change line 27 from:

```js
registerFeedbackAttach(Alpine);
```

to:

```js
registerTicketAttach(Alpine);
```

- [ ] **Step 4: Build assets**

Run: `bun run build`
Expected: build succeeds with no errors referencing `feedback-attach`.

- [ ] **Step 5: Commit**

```bash
git add resources/js/ticket-attach.js resources/js/app.js public/build
git rm resources/js/feedback-attach.js
git commit -m "refactor(helpdesk): rename feedback-attach.js to generic ticket-attach.js"
```

---

### Task 9: `partials/ticket-raise.blade.php` — shared raise-ticket modal

**Files:**
- Create: `resources/views/partials/ticket-raise.blade.php` (**copied from** `resources/views/partials/feedback.blade.php`, then adapted)
- Modify: `resources/views/layouts/app.blade.php:177`

**Interfaces:**
- Consumes: route `helpdesk.store` (Task 3), `ticketAttach()` Alpine component (Task 8), `HelpdeskController::CATEGORIES` / `::PRIORITIES` (made public in Task 3), `$releases`/`$latestVersion` from the changelog view composer (`AppServiceProvider.php:93`, repointed in Task 10).
- Produces: a global modal opened via `$dispatch('ticket-raise-open')` (defaults `category=IT`) or `$dispatch('ticket-raise-open', { category: 'Bug' })` (used by the sidebar shortcut in Task 10). Consumed by Task 10's screen ("+ New ticket" button) and Task 10's sidebar wiring.

**Two constraints that dictate the approach — do not write this partial from scratch:**

1. `layouts/app.blade.php:177` includes this partial on **every** screen, not just Helpdesk. It therefore must not read `$categories`/`$priorities`, which only `HelpdeskController::screenData` supplies — on `/app/dash` those are undefined and the whole page throws. Read the public controller constants instead.
2. `partials/feedback.blade.php` is **not** just a report form. It is a two-tab hub: *Report* and *What's New* (the changelog, fed by `$releases`/`$latestVersion`, whose "seen" click clears the `$store.changelog.unseen` badge that `partials/sidebar.blade.php:148` still renders). Task 13 deletes that file. If the What's New tab is not carried over, the changelog surface disappears and the sidebar badge is left with nothing to clear it.

So: **copy the file, keep everything, replace only the Report tab's form fields.**

This task has no PHPUnit test — it is pure Blade/Alpine markup. It is verified via the Task 11 manual browser check (submitting through the modal end-to-end, plus opening What's New) and the server-side tests already covering `helpdesk.store` in Task 3.

- [ ] **Step 1: Copy the existing partial as the starting point**

```bash
cp resources/views/partials/feedback.blade.php resources/views/partials/ticket-raise.blade.php
```

- [ ] **Step 2: Adapt the header `@php` block and the Alpine root**

Replace the leading comment + `@php` block + opening `<div x-data=...>` (lines 1-24 of the copy) with:

```blade
{{--
    Ticket-raise hub — two tabs: Report (raise a ticket) and What's New (changelog).
    Included on every screen from layouts/app.blade.php, so it reads the category and
    priority lists from HelpdeskController's public constants rather than from screen data.
    Opened by the sidebar's pinned "Send feedback" button ($dispatch('ticket-raise-open',
    { category: 'Bug' })) and by the Helpdesk screen's "+ New ticket" button (no detail,
    so it defaults to IT). What's New is unchanged from the retiring feedback partial:
    fed by config/changelog.php through the view composer, and opening that tab marks the
    latest version seen, which clears the "New" badge everywhere.
    Reopens itself after a failed submit so validation errors stay visible.
--}}
@php
    $ticketHasError = $errors->hasAny(['category', 'priority', 'subject', 'description', 'page_url', 'attachments'])
        || collect($errors->keys())->contains(fn ($k) => str_starts_with($k, 'attachments'));
    $releases = $releases ?? [];
    $categories = \App\Http\Controllers\HelpdeskController::CATEGORIES;
    $priorities = \App\Http\Controllers\HelpdeskController::PRIORITIES;
    $noteMeta = [
        'new' => ['en' => 'New', 'ms' => 'Baharu', 'dot' => 'var(--success)'],
        'improved' => ['en' => 'Improved', 'ms' => 'Diperbaik', 'dot' => 'var(--info)'],
        'fixed' => ['en' => 'Fixed', 'ms' => 'Dibaiki', 'dot' => 'var(--amber)'],
    ];
@endphp
<div x-data="{ show: {{ $ticketHasError ? 'true' : 'false' }}, tab: 'report', category: '{{ old('category', 'IT') }}' }"
     x-show="show" x-cloak
     @ticket-raise-open.window="show = true; tab = 'report'; category = $event.detail?.category || 'IT'; $nextTick(() => { document.getElementById('tr-page-url').value = window.location.href; $refs.subject?.focus(); })"
     @keydown.escape.window="show = false"
     style="position:fixed;inset:0;z-index:200;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(31,30,26,.55);backdrop-filter:blur(2px);">
```

Then in the header block just below it, change the modal title text from `'Feedback &amp; updates' : 'Maklum balas &amp; kemas kini'` to `'Raise a ticket' : 'Buka ticket'`. Leave the two tab buttons (`tab = 'report'` / `tab = 'whatsnew'; $store.changelog.markSeen()`) **exactly as they are**.

- [ ] **Step 3: Replace the Report tab's form**

Swap the `<form x-show="tab === 'report'" ...>` element — from its opening tag through its closing `</form>` — for the version below. Everything after it (the whole `{{-- ── What's New tab ── --}}` block, the footer with "View all updates →", and the two closing `</div>`s) stays **untouched**.

```blade
        {{-- ── Report tab ── --}}
        <form x-show="tab === 'report'" action="{{ route('helpdesk.store') }}" method="post" enctype="multipart/form-data" x-data="ticketAttach()" style="display:flex;flex-direction:column;min-height:0;">
            @csrf
            <input type="hidden" id="tr-page-url" name="page_url" value="{{ old('page_url') }}">

            <div style="padding:20px 26px;display:flex;flex-direction:column;gap:16px;overflow-y:auto;max-height:calc(88vh - 180px);">
                <p style="font-size:13px;color:var(--muted);margin:0;line-height:1.5;"
                   x-show="category === 'Bug' || category === 'Idea'"
                   x-text="$store.ui.lang==='en' ? 'Spotted a bug or have an idea? Tell us — it goes straight to the team.' : 'Jumpa pepijat atau ada idea? Beritahu kami — terus sampai kepada pasukan.'"></p>

                <div>
                    <label style="display:block;font-size:12.5px;font-weight:600;color:var(--ink);margin-bottom:7px;"
                           x-text="$store.ui.lang==='en' ? 'Category' : 'Kategori'">Category</label>
                    <select name="category" x-model="category" required
                            style="height:42px;padding:0 12px;border:1px solid var(--hairline);border-radius:9px;font-size:13.5px;background:#fff;color:var(--ink);outline:none;width:100%;">
                        @foreach ($categories as $c)
                            <option value="{{ $c }}">{{ $c }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Priority is a support-desk triage field. Bug/Idea always land as medium
                     server-side (HelpdeskController::store), so the submitter never sees it. --}}
                <div x-show="category !== 'Bug' && category !== 'Idea'">
                    <label style="display:block;font-size:12.5px;font-weight:600;color:var(--ink);margin-bottom:7px;"
                           x-text="$store.ui.lang==='en' ? 'Priority' : 'Keutamaan'">Priority</label>
                    <select name="priority" :required="category !== 'Bug' && category !== 'Idea'"
                            style="height:42px;padding:0 12px;border:1px solid var(--hairline);border-radius:9px;font-size:13.5px;background:#fff;color:var(--ink);outline:none;width:100%;">
                        @foreach ($priorities as $p)
                            <option value="{{ $p }}" @selected(old('priority', 'medium') === $p)>{{ ucfirst($p) }}</option>
                        @endforeach
                    </select>
                    @include('partials.hint', ['en' => 'Urgent = work is fully blocked right now. High = serious but you can still work. Use Low/Medium for everyday requests.', 'ms' => 'Urgent = kerja terhenti sepenuhnya sekarang. High = serius tetapi anda masih boleh bekerja. Guna Low/Medium untuk permintaan harian.'])
                </div>

                <div>
                    <label style="display:block;font-size:12.5px;font-weight:600;color:var(--ink);margin-bottom:7px;"
                           x-text="$store.ui.lang==='en' ? 'Subject' : 'Subjek'">Subject</label>
                    <input x-ref="subject" name="subject" value="{{ old('subject') }}" required maxlength="150"
                           style="height:42px;padding:0 12px;border:1px solid var(--hairline);border-radius:9px;font-size:13.5px;background:#fff;color:var(--ink);outline:none;width:100%;">
                    @error('subject')<p style="font-size:12px;color:var(--error);margin:7px 0 0;">{{ $message }}</p>@enderror
                </div>

                {{-- Description + attachments share one Alpine scope (the form's ticketAttach())
                     so a paste inside the textarea can hand image blobs to the attachment manager. --}}
                <div>
                    <label style="display:block;font-size:12.5px;font-weight:600;color:var(--ink);margin-bottom:7px;">
                        <span x-show="category !== 'Bug' && category !== 'Idea'" x-text="$store.ui.lang==='en' ? 'Description' : 'Penerangan'">Description</span>
                        <span x-show="category === 'Bug' || category === 'Idea'" x-cloak x-text="$store.ui.lang==='en' ? 'Description (optional)' : 'Penerangan (pilihan)'">Description</span>
                    </label>
                    <textarea name="description" :required="category !== 'Bug' && category !== 'Idea'" @paste="onPaste($event)" maxlength="2000" rows="4"
                              style="width:100%;padding:11px 12px;border:1px solid var(--hairline);border-radius:9px;font-size:13.5px;background:#fff;color:var(--ink);outline:none;resize:vertical;">{{ old('description') }}</textarea>
                    @error('description')<p style="font-size:12px;color:var(--error);margin:7px 0 0;">{{ $message }}</p>@enderror
                </div>

                <div x-show="category === 'Bug' || category === 'Idea'" x-cloak>
                    <input type="file" name="attachments[]" x-ref="input" multiple
                           accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.txt,.csv"
                           @change="addFiles($event.target.files)" style="display:none;">
                    <button type="button" @click="$refs.input.click()"
                            style="height:38px;padding:0 14px;border-radius:9px;font-size:12.5px;font-weight:500;color:var(--body);background:#fff;border:1px solid var(--hairline);"
                            x-text="$store.ui.lang==='en' ? 'Attach screenshot / file' : 'Lampirkan tangkapan skrin / fail'">Attach screenshot / file</button>
                    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:10px;">
                        <template x-for="(f, i) in files" :key="i">
                            <div style="position:relative;width:56px;height:56px;border-radius:8px;overflow:hidden;border:1px solid var(--hairline-soft);">
                                <img x-show="f.isImage" :src="f.url" style="width:100%;height:100%;object-fit:cover;">
                                <div x-show="!f.isImage" style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:var(--muted);" x-text="ext(f.file.name)"></div>
                                <button type="button" @click="remove(i)" style="position:absolute;top:2px;right:2px;background:rgba(0,0,0,.55);color:#fff;border:none;border-radius:50%;width:16px;height:16px;font-size:11px;line-height:1;cursor:pointer;">&times;</button>
                            </div>
                        </template>
                    </div>
                    <p x-show="error === 'type'" x-cloak style="font-size:12px;color:var(--error);margin:7px 0 0;" x-text="$store.ui.lang==='en' ? 'That file type is not accepted.' : 'Jenis fail itu tidak diterima.'"></p>
                    <p x-show="error === 'size'" x-cloak style="font-size:12px;color:var(--error);margin:7px 0 0;" x-text="$store.ui.lang==='en' ? 'Each file must be 8 MB or smaller.' : 'Setiap fail mesti 8 MB atau lebih kecil.'"></p>
                    <p x-show="error === 'max'" x-cloak style="font-size:12px;color:var(--error);margin:7px 0 0;" x-text="$store.ui.lang==='en' ? 'You can attach up to 6 files.' : 'Anda boleh lampirkan sehingga 6 fail.'"></p>
                    @error('attachments')<p style="font-size:12px;color:var(--error);margin:7px 0 0;">{{ $message }}</p>@enderror
                    @foreach ($errors->get('attachments.*') as $messages)@foreach ($messages as $message)<p style="font-size:12px;color:var(--error);margin:7px 0 0;">{{ $message }}</p>@endforeach @endforeach
                </div>
            </div>

            <div style="padding:15px 26px 20px;border-top:1px solid var(--hairline);display:flex;align-items:center;justify-content:flex-end;gap:12px;flex-shrink:0;">
                <button type="button" @click="show = false"
                        style="height:42px;padding:0 16px;border-radius:9px;font-size:13.5px;font-weight:500;color:var(--body);background:#fff;border:1px solid var(--hairline);"
                        x-text="$store.ui.lang==='en' ? 'Cancel' : 'Batal'"></button>
                <button type="submit" class="uj-btn-primary" style="height:42px;padding:0 22px;font-size:13.5px;"
                        x-text="$store.ui.lang==='en' ? 'Submit ticket' : 'Hantar ticket'"></button>
            </div>
        </form>
```

- [ ] **Step 4: Include it in the layout**

In `resources/views/layouts/app.blade.php`, change line 177 from:

```blade
        @include('partials.feedback')
```

to:

```blade
        @include('partials.ticket-raise')
```

- [ ] **Step 5: Sanity-check that the modal no longer depends on screen data**

```bash
grep -n '\$categories\|\$priorities' resources/views/partials/ticket-raise.blade.php
```

Expected: hits only inside the `@php` block (where both are assigned from the controller constants) and the two `@foreach` loops that consume them — never an undefined read. Then confirm a non-Helpdesk screen still renders in Task 11 Step 2.

- [ ] **Step 6: Commit**

```bash
git add resources/views/partials/ticket-raise.blade.php resources/views/layouts/app.blade.php
git commit -m "feat(helpdesk): add shared ticket-raise modal"
```

---

### Task 10: Restore + adapt `screens/helpdesk.blade.php`, repoint sidebar shortcut

**Files:**
- Create: `resources/views/screens/helpdesk.blade.php` (restored from `pre-blade-purge` tag, then adapted)
- Create: `resources/views/partials/my-tickets.blade.php` (extracted from the restored blade, rendered by **both** branches)
- Modify: `resources/views/partials/sidebar.blade.php:142-149`
- Modify: `app/Providers/AppServiceProvider.php:93`
- Test: `tests/Feature/HelpdeskTest.php` (append — the HTTP-level smoke test)

**Interfaces:**
- Consumes: `screenData()` output from Task 5 (`privileged`, `isSuperAdmin`, `myTickets`, `grouped`, `counts`, `employees`, `categories`, `priorities`, `statuses`), `ticket-raise-open` event (Task 9), route `helpdesk.attachment` (Task 4).
- Produces: a working Helpdesk screen at `/app/helpdesk`; the sidebar's pinned button dispatches `ticket-raise-open` with `category: 'Bug'` instead of the retired `feedback-open`.

**Three gaps in the pre-purge blade this task must close — the restore alone is not enough:**

1. **The privileged branch never renders `$myTickets`.** Check it yourself: `git show pre-blade-purge:resources/views/screens/helpdesk.blade.php` — the "My tickets" card lives only in the `@if (! $privileged)` branch (lines 94-122); the `@else` branch (lines 125-207) renders `$grouped` and nothing else. Task 5 computes `myTickets` for privileged viewers too, but with no card to render it, a **manager** who raises a Bug ticket sees it nowhere: the board filters it out (manager is not in `FEEDBACK_VIEW_ROLES`) and no My-tickets card exists in their branch. That breaks the one guarantee the whole merge exists to deliver (spec lines 81-86). Step 2 below extracts the card into a partial and includes it in both branches.
2. **Nothing renders attachments.** Task 4 builds the auth-gated `helpdesk.attachment` stream, Task 3 stores the files, and no view links to either — uploads would be write-only. The retiring `resources/views/screens/feedback.blade.php:105-118` is the model to copy.
3. **Nothing renders `page_url`.** Same file, line 85, including its `^https?://` guard before emitting an `href`.

- [ ] **Step 1: Restore the pre-purge blade as a starting point**

```bash
git show pre-blade-purge:resources/views/screens/helpdesk.blade.php > resources/views/screens/helpdesk.blade.php
```

- [ ] **Step 2: Extract the "My tickets" card into a shared partial**

The restored file's lines **94-122** are the `<div class="uj-card">…@forelse ($myTickets as $t)…@endforelse</div>` block. Move that block verbatim into a new `resources/views/partials/my-tickets.blade.php`, prefixed with this comment, and add the ticket-context block from Step 5 inside the `@forelse` body:

```blade
{{-- The raiser's own tickets, with status and resolution note. Rendered by BOTH branches of
     the Helpdesk screen: a plain employee has nothing else, and a privileged viewer needs it
     because the board filters Bug/Idea away from anyone outside FEEDBACK_VIEW_ROLES — without
     this a manager could not see the bug report they filed themselves. --}}
```

In `screens/helpdesk.blade.php`, replace the block you just moved with:

```blade
    @include('partials.my-tickets')
```

The `$pill`, `$priorityColor` and `$statusMeta` closures the card uses are defined in the screen's own `@php` block at the top, so an `@include` inherits them — no data needs passing.

- [ ] **Step 3: Replace the employee-view inline raise-form block with a button that opens the shared modal**

In the restored file, replace this block — the `@if (! $privileged)` branch's raise-card and its inline form, **originally lines 54-92** (`<div x-data="{ raise: …">` down to the `</div>` that closes the card, immediately before the blank line and the "My tickets" `@include` you created in Step 2). Do **not** delete past line 92: lines 93-123 are the My-tickets include and the branch's closing `</div>`.

```blade
<div x-data="{ raise: {{ $errors->any() ? 'true' : 'false' }} }">
    <div class="uj-card" style="padding:20px;margin-bottom:16px;">
        <div class="uj-card-head" style="padding:0;margin-bottom:14px;">
            <h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Raise a support ticket' : 'Buka ticket sokongan'">Raise a support ticket</h3>
            <button @click="raise = ! raise" class="uj-btn-primary" style="height:34px;padding:0 13px;font-size:12.5px;"><span x-text="raise ? ($store.ui.lang==='en' ? 'Cancel' : 'Batal') : ($store.ui.lang==='en' ? '+ New ticket' : '+ Ticket baharu')"></span></button>
        </div>
        <form x-show="raise" x-cloak method="post" action="{{ route('helpdesk.store') }}">
            @csrf
            @if ($errors->any())<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:9px 12px;margin-bottom:14px;">{{ $errors->first() }}</div>@endif
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;">
                <div>
                    <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Category *' : 'Kategori *'">Category *</label>
                    <select name="category" required style="{{ $fs }}width:100%;">
                        @foreach ($categories as $c)
                            <option value="{{ $c }}" @selected(old('category') === $c)>{{ $c }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Priority *' : 'Keutamaan *'">Priority *</label>
                    <select name="priority" required style="{{ $fs }}width:100%;margin-bottom:6px;">
                        @foreach ($priorities as $p)
                            <option value="{{ $p }}" @selected(old('priority', 'medium') === $p)>{{ ucfirst($p) }}</option>
                        @endforeach
                    </select>
                    @include('partials.hint', ['en' => 'Urgent = work is fully blocked right now. High = serious but you can still work. Use Low/Medium for everyday requests.', 'ms' => 'Urgent = kerja terhenti sepenuhnya sekarang. High = serius tetapi anda masih boleh bekerja. Guna Low/Medium untuk permintaan harian.'])
                </div>
            </div>
            <div style="margin-top:12px;">
                <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Subject *' : 'Subjek *'">Subject *</label>
                <input name="subject" value="{{ old('subject') }}" required maxlength="150" placeholder="e.g. Laptop won't connect to VPN" :placeholder="$store.ui.lang==='en' ? 'e.g. Laptop will not connect to VPN' : 'cth. Laptop tidak boleh sambung ke VPN'" style="{{ $fs }}width:100%;" />
            </div>
            <div style="margin-top:12px;">
                <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Description *' : 'Penerangan *'">Description *</label>
                <textarea name="description" required maxlength="2000" rows="4" placeholder="Describe the issue, steps taken, and any error messages." :placeholder="$store.ui.lang==='en' ? 'Describe the issue, steps taken, and any error messages.' : 'Terangkan masalah, langkah yang dibuat, dan sebarang mesej ralat.'" style="width:100%;padding:10px 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;background:#fff;color:var(--ink);outline:none;resize:vertical;">{{ old('description') }}</textarea>
            </div>
            <button type="submit" class="uj-btn-primary" style="height:42px;padding:0 20px;font-size:13.5px;margin-top:16px;" x-text="$store.ui.lang==='en' ? 'Submit ticket' : 'Hantar ticket'">Submit ticket</button>
        </form>
    </div>
```

with:

```blade
<div>
    <div class="uj-card" style="padding:20px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;">
        <h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Raise a support ticket' : 'Buka ticket sokongan'">Raise a support ticket</h3>
        <button type="button" @click="$dispatch('ticket-raise-open')" class="uj-btn-primary" style="height:34px;padding:0 13px;font-size:12.5px;" x-text="$store.ui.lang==='en' ? '+ New ticket' : '+ Ticket baharu'">+ New ticket</button>
    </div>
```

(Only the raise-card and its inline `<form>` go. The `@include('partials.my-tickets')` from Step 2 and the branch's closing `</div>` at line 123 stay.)

- [ ] **Step 4: Do the same in the privileged view's "+ New ticket" entry point**

The privileged branch (`@else`) doesn't have a raise-form today (management/HR triage rather than raise), but management/HR/superadmin staff are also employees who might raise tickets themselves — the pre-purge blade didn't give them a raise button at all. Add one at the top of the privileged branch, right after the opening `<div x-data="{ open: ... }">` (originally line 127):

```blade
    <div style="display:flex;justify-content:flex-end;margin-bottom:12px;">
        <button type="button" @click="$dispatch('ticket-raise-open')" class="uj-btn-primary" style="height:34px;padding:0 13px;font-size:12.5px;" x-text="$store.ui.lang==='en' ? '+ New ticket' : '+ Ticket baharu'">+ New ticket</button>
    </div>
```

Then, at the **bottom** of the same privileged branch — after the `@foreach ($statuses as $s)` board loop closes and before the branch's closing `</div>` (originally line 207) — include the card from Step 2:

```blade
    @include('partials.my-tickets')
```

Without this, a manager who raised a Bug ticket has no surface that shows it: the board filtered it out and this branch had no My-tickets card. `HelpdeskTest::test_manager_still_sees_a_bug_ticket_they_raised_in_my_tickets` (Task 5) pins the data side; this is the view side.

- [ ] **Step 5: Gate the per-ticket "Manage" control to superadmin for Bug/Idea**

In the privileged branch's ticket loop (originally around line 155), the `Manage` button is unconditional:

```blade
                        <button @click="open = (open === {{ $t->id }} ? null : {{ $t->id }})" class="uj-btn-ghost" style="height:30px;padding:0 11px;font-size:12px;flex-shrink:0;" x-text="$store.ui.lang==='en' ? 'Manage' : 'Urus'">Manage</button>
```

Wrap it (and the entire `x-show="open === {{ $t->id }}"` inline edit form block below it) so it only renders when the viewer may act on this ticket. Replace both with:

```blade
                        @if (! in_array($t->category, ['Bug', 'Idea'], true) || $isSuperAdmin)
                            <button @click="open = (open === {{ $t->id }} ? null : {{ $t->id }})" class="uj-btn-ghost" style="height:30px;padding:0 11px;font-size:12px;flex-shrink:0;" x-text="$store.ui.lang==='en' ? 'Manage' : 'Urus'">Manage</button>
                        @endif
```

and further down, wrap the `<div x-show="open === {{ $t->id }}" ...>` inline form block in the same `@if (! in_array($t->category, ['Bug', 'Idea'], true) || $isSuperAdmin) ... @endif` — a director/HR viewer sees the ticket, its description, and its resolution note (if any), but no way to open the edit form, matching the old Feedback module's "view but no triage control" behavior (mirrored by `HelpdeskTest::test_hr_cannot_triage_a_bug_ticket` at the controller layer, and this is the view-layer counterpart so the control doesn't even render for someone the controller would 403).

- [ ] **Step 6: Render attachments and `page_url` on every ticket that has them**

Task 3 stores the files and Task 4 streams them; without this step nothing in the UI ever links to `helpdesk.attachment`, so uploads are write-only and the reported page URL is invisible. Model it on the retiring `resources/views/screens/feedback.blade.php:85` (`page_url`) and `:105-118` (attachments), including the `^https?://` guard that stops a stored non-URL from becoming an `href`.

Add this block in **two** places — inside `partials/my-tickets.blade.php`'s `@forelse` body, and inside the privileged board loop — in both cases immediately after the `{{ $t->description }}` line and before the resolution card:

```blade
                    @php $safeUrl = $t->page_url && preg_match('~^https?://~i', $t->page_url) ? $t->page_url : null; @endphp
                    @if ($safeUrl)
                        <div style="font-size:12px;color:var(--muted);margin-top:8px;">
                            <span x-text="$store.ui.lang==='en' ? 'Reported from' : 'Dilapor dari'">Reported from</span>
                            <a href="{{ $safeUrl }}" style="color:var(--red);text-decoration:none;">{{ $safeUrl }}</a>
                        </div>
                    @endif
                    {{-- Thumbnails and download chips both point at the auth-gated stream route,
                         never a public URL — the file lives on the private 'local' disk. --}}
                    @if ($t->attachments->isNotEmpty())
                        <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:10px;">
                            @foreach ($t->attachments as $att)
                                @if ($att->isImage())
                                    <a href="{{ route('helpdesk.attachment', $att) }}" target="_blank" rel="noopener noreferrer"
                                       style="display:block;width:64px;height:64px;border-radius:8px;overflow:hidden;border:1px solid var(--hairline-soft);">
                                        <img src="{{ route('helpdesk.attachment', $att) }}" alt="{{ $att->name }}" loading="lazy"
                                             style="width:100%;height:100%;object-fit:cover;">
                                    </a>
                                @else
                                    <a href="{{ route('helpdesk.attachment', $att) }}" target="_blank" rel="noopener noreferrer"
                                       style="display:inline-flex;align-items:center;gap:7px;height:34px;padding:0 12px;border-radius:8px;border:1px solid var(--hairline-soft);font-size:12.5px;color:var(--body);text-decoration:none;">
                                        <span style="font-weight:700;font-size:10.5px;color:var(--muted);">{{ strtoupper(pathinfo($att->name, PATHINFO_EXTENSION)) }}</span>
                                        <span>{{ $att->name }}</span>
                                    </a>
                                @endif
                            @endforeach
                        </div>
                    @endif
```

Both ticket queries in `screenData()` must eager-load the relation or this is an N+1 across the board. In `app/Http/Controllers/HelpdeskController.php`, add `'attachments'` to every `Ticket::with(...)` call in `screenData()`: the two `myTickets` queries become `Ticket::with(['assignee', 'attachments'])` and `$boardQuery` becomes `Ticket::with(['employee', 'assignee', 'attachments'])`.

- [ ] **Step 7: Add the HTTP-level smoke test**

Task 5's tests drive `screenData()` directly, so nothing yet proves the screen actually renders. Now that the flag is ON (Task 7) and the blade exists, add one end-to-end check. Append to `tests/Feature/HelpdeskTest.php`:

```php
    public function test_helpdesk_screen_renders_the_board_and_my_tickets(): void
    {
        Ticket::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'category' => 'Bug', 'priority' => 'medium', 'subject' => 'Rendered bug',
            'description' => 'x', 'status' => 'resolved', 'resolution' => 'Fixed in 1.2.3.',
        ]);

        // The raiser (plain employee) sees their own ticket and its resolution note.
        $response = $this->actingInTenant()->get('/app/helpdesk');
        $response->assertOk();
        $response->assertSee('Rendered bug');
        $response->assertSee('Fixed in 1.2.3.');

        // HR sees it on the board and gets no Manage control on a Bug ticket.
        $hr = $this->hrActor();
        $hrResponse = $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])->get('/app/helpdesk');
        $hrResponse->assertOk();
        $hrResponse->assertSee('Rendered bug');
        $hrResponse->assertDontSee(route('helpdesk.update', 1));
    }
```

Run: `php artisan test --compact tests/Feature/HelpdeskTest.php`
Expected: all PASS. A failure here means the restore or one of the includes above is wrong — Task 5's direct-call tests cannot catch that.

- [ ] **Step 8: Repoint the sidebar shortcut**

In `resources/views/partials/sidebar.blade.php`, replace lines 142-149:

```blade
        {{-- Feedback — opens the global feedback modal. --}}
        <button type="button" @click="$dispatch('feedback-open')" class="uj-feedback-btn"
                :title="$store.ui.lang==='en' ? 'Send feedback' : 'Maklum balas'"
                style="width:100%;display:flex;align-items:center;gap:11px;padding:9px 10px;border-radius:9px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.12);color:#fff;font-size:var(--t-sm);font-weight:500;text-align:left;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;color:var(--red);"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
            <span class="uj-nav-lbl uj-sb-hide" x-text="$store.ui.lang==='en' ? 'Send feedback' : 'Maklum balas'">Send feedback</span>
            <span x-show="$store.changelog.unseen" x-cloak class="uj-sb-hide" style="font-size:var(--t-micro);font-weight:700;letter-spacing:.4px;text-transform:uppercase;color:#fff;background:var(--red);border-radius:9999px;padding:1px 7px;">New</span>
        </button>
```

with:

```blade
        {{-- Send feedback — opens the shared ticket-raise modal pre-filled to category Bug. --}}
        <button type="button" @click="$dispatch('ticket-raise-open', { category: 'Bug' })" class="uj-feedback-btn"
                :title="$store.ui.lang==='en' ? 'Send feedback' : 'Maklum balas'"
                style="width:100%;display:flex;align-items:center;gap:11px;padding:9px 10px;border-radius:9px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.12);color:#fff;font-size:var(--t-sm);font-weight:500;text-align:left;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;color:var(--red);"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
            <span class="uj-nav-lbl uj-sb-hide" x-text="$store.ui.lang==='en' ? 'Send feedback' : 'Maklum balas'">Send feedback</span>
            <span x-show="$store.changelog.unseen" x-cloak class="uj-sb-hide" style="font-size:var(--t-micro);font-weight:700;letter-spacing:.4px;text-transform:uppercase;color:#fff;background:var(--red);border-radius:9999px;padding:1px 7px;">New</span>
        </button>
```

- [ ] **Step 9: Update the changelog composer's view list**

In `app/Providers/AppServiceProvider.php`, change line 93 from:

```php
        View::composer(['partials.feedback', 'partials.sidebar'], function ($view) {
```

to:

```php
        View::composer(['partials.ticket-raise', 'partials.sidebar'], function ($view) {
```

This keeps `$releases`/`$latestVersion` flowing to the What's New tab that Task 9 carried over. If the composer is repointed but the tab was dropped, the sidebar's "New" badge (`partials/sidebar.blade.php:148`) has nothing left to clear it — verify the tab is present before moving on.

- [ ] **Step 10: Build assets and commit**

```bash
bun run build
vendor/bin/pint --dirty --format agent
git add resources/views/screens/helpdesk.blade.php resources/views/partials/my-tickets.blade.php resources/views/partials/sidebar.blade.php app/Http/Controllers/HelpdeskController.php app/Providers/AppServiceProvider.php tests/Feature/HelpdeskTest.php public/build
git commit -m "feat(helpdesk): restore helpdesk screen, render attachments, gate Bug/Idea Manage control to superadmin"
```

---

### Task 11: Manual browser verification

**Files:** none (verification only)

- [ ] **Step 1: Start the dev server and open the app**

Use the Browser pane's `preview_start` with the `laravel-app` launch config (per this project's CLAUDE.md, reachable at `http://localhost:9100`).

- [ ] **Step 2: The globally-included modal does not break other screens**

Log in via `http://localhost:9100/dev/login?email=employee@amanahku.test&tenant=unijaya` and open `/app/dash`, `/app/leave` and `/app/claims`. Confirm each renders with no `Undefined variable $categories` / `$priorities` error — the ticket-raise modal is included on every screen, so a screen-local dependency shows up here immediately.

- [ ] **Step 3: What's New still works**

From any screen, click "Send feedback" in the sidebar, then the "What's new" tab. Confirm the changelog entries render and the red "New" dot on the sidebar button clears after opening it.

- [ ] **Step 4: Employee raises a Bug ticket via the sidebar shortcut**

Still as `employee@amanahku.test`. Click "Send feedback" in the sidebar. Confirm the modal opens on the Report tab with category pre-set to `Bug`, priority field hidden, an attachment button visible. Paste a clipboard image or attach a file, fill Subject, submit. Confirm redirect back with a success flash and the ticket appears under the Helpdesk screen's "My tickets" section with status Open **and the attached image visible as a thumbnail** — click it and confirm it streams.

- [ ] **Step 5: Manager cannot see the Bug ticket in the board, but sees their own**

Log in via `http://localhost:9100/dev/login?email=manager@amanahku.test&tenant=unijaya`, open `/app/helpdesk`. Confirm the employee's Bug ticket from Step 4 is absent from the board (manager sees only IT/Facilities/HR/Other tickets there) and that a "+ New ticket" button is present. Then raise a Bug ticket **as the manager** and confirm it appears in the "My tickets" card at the bottom of the same screen — it must not be missing just because the board filters Bug/Idea away from this role.

- [ ] **Step 6: HR sees the Bug ticket but cannot manage it**

Log in via `http://localhost:9100/dev/login?email=hr@amanahku.test&tenant=unijaya`, open `/app/helpdesk`. Confirm the Bug ticket appears in the board, its description, its "Reported from" page URL and its attachment thumbnail are visible, but no "Manage" button renders on it.

- [ ] **Step 7: Superadmin triages the Bug ticket**

Log in via `http://localhost:9100/dev/login?email=superadmin@amanahku.com` (super-admin console entry point per this project's CLAUDE.md — switch into the `unijaya` workspace from there). Open `/app/helpdesk`, find the Bug ticket, click Manage, set status to Resolved with a resolution note, save. Confirm it saves without a 403.

- [ ] **Step 8: Reporter sees the resolution**

Log back in as `employee@amanahku.test`, open `/app/helpdesk`. Confirm "My tickets" now shows the ticket as Resolved with the resolution note visible — this is the actual feature the whole merge exists to deliver.

- [ ] **Step 9: Take a screenshot of the resolved ticket in "My tickets" as evidence, then report the walkthrough result.**

No commit for this task (verification only).

---

### Task 12: Data migration — fold `feedback_items`/`feedback_attachments` into `tickets`/`ticket_attachments`

**Files:**
- Create: `database/migrations/2026_08_05_000003_migrate_feedback_items_to_tickets.php`
- Test: `tests/Feature/FeedbackMigrationTest.php` (new file)

**Interfaces:**
- Consumes: `feedback_items`/`feedback_attachments` schemas (existing, about to be dropped), `tickets`/`ticket_attachments` schemas (Task 1 + Task 2).
- Produces: every pre-existing `feedback_items` row becomes a `tickets` row (`category` = `Bug`/`Idea`, `status` remapped: open→open, reviewing→in_progress, resolved→resolved, declined→closed, `resolution` null, `page_url` carried over, `employee_id` carried over including nulls — see Task 1), every `feedback_attachments` row becomes a `ticket_attachments` row pointing at the same file path. `feedback_items` and `feedback_attachments` tables no longer exist after this migration runs. Either the whole copy lands or none of it does: the inserts and the count verification share one transaction.

Because `RefreshDatabase` runs every migration (including this one) before each test, and this migration drops the very tables it reads from, the standard integration-test harness can't seed "old-shape" data before the migration runs. Test the migration class directly instead: recreate the old tables by hand, seed rows, invoke the migration's `up()` a second time, and assert the result — a standard technique for testing one-off data migrations in Laravel.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/FeedbackMigrationTest.php`:

```php
<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The one-off migration that folds feedback_items/feedback_attachments into
 * tickets/ticket_attachments has already run (and dropped the old tables) by the time
 * RefreshDatabase finishes migrating for this test. To exercise its logic we recreate the
 * old tables by hand, seed pre-merge-shape rows, then invoke the migration file's up()
 * a second time and assert the result — the migration itself is idempotent-safe to call
 * again because it only ever reads feedback_items/feedback_attachments and both are empty
 * the second time regardless (this test recreates them with rows first).
 */
class FeedbackMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function recreateOldFeedbackTables(): void
    {
        Schema::create('feedback_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('type', ['bug', 'idea']);
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('page_url', 500)->nullable();
            $table->enum('status', ['open', 'reviewing', 'resolved', 'declined'])->default('open');
            $table->timestamps();
        });

        Schema::create('feedback_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // Deliberately NOT ->constrained() here, unlike the real retired table. The
            // rollback test below has to insert an attachment whose parent item does not
            // exist, which is the only way to make the migration's count guard disagree
            // with reality. The FK contributes nothing to what these tests check.
            $table->unsignedBigInteger('feedback_item_id')->index();
            $table->string('path');
            $table->string('name');
            $table->string('mime')->nullable();
            $table->unsignedInteger('size')->default(0);
            $table->timestamps();
        });
    }

    public function test_feedback_rows_and_attachments_migrate_into_tickets(): void
    {
        // Arrange — real tenant/user/employee (FK targets), then hand-seed old-shape rows.
        $tenant = \App\Models\Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $user = \App\Models\User::create(['name' => 'Demo', 'email' => 'demo@example.com', 'password' => bcrypt('password')]);
        $employee = \App\Models\Employee::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id,
            'name' => 'Demo', 'status' => 'active', 'workload' => 'green',
        ]);

        $this->recreateOldFeedbackTables();

        $itemId = DB::table('feedback_items')->insertGetId([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'employee_id' => $employee->id,
            'type' => 'bug', 'title' => 'Old bug report', 'description' => 'It broke.',
            'page_url' => 'http://localhost/app/dash', 'status' => 'resolved',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('feedback_attachments')->insert([
            'tenant_id' => $tenant->id, 'feedback_item_id' => $itemId,
            'path' => 'feedback-attachments/shot.png', 'name' => 'shot.png',
            'mime' => 'image/png', 'size' => 1024,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // A report whose author has no employee record. feedback_items.employee_id was
        // nullable and tickets.employee_id was not until the Task 1 migration relaxed it;
        // without that, this row alone would abort the whole fold on a real host.
        DB::table('feedback_items')->insert([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'employee_id' => null,
            'type' => 'idea', 'title' => 'Orphaned idea', 'description' => null,
            'page_url' => null, 'status' => 'reviewing',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // A Bug ticket raised through the new UI *before* this migration runs. The count
        // guard must ignore it — it is not part of the fold.
        \App\Models\Ticket::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'employee_id' => $employee->id,
            'category' => 'Bug', 'priority' => 'medium', 'subject' => 'Raised after deploy',
            'description' => 'x', 'status' => 'open',
        ]);

        // Act — run the migration's up() directly.
        $migration = require base_path('database/migrations/2026_08_05_000003_migrate_feedback_items_to_tickets.php');
        $migration->up();

        // Assert — ticket exists with mapped fields, attachment copied, old tables gone.
        $this->assertDatabaseHas('tickets', [
            'tenant_id' => $tenant->id, 'employee_id' => $employee->id,
            'category' => 'Bug', 'priority' => 'medium', 'subject' => 'Old bug report',
            'description' => 'It broke.', 'page_url' => 'http://localhost/app/dash',
            'status' => 'resolved',
        ]);
        $ticket = \App\Models\Ticket::withoutGlobalScopes()->where('subject', 'Old bug report')->first();
        $this->assertSame(1, $ticket->attachments()->count());
        $this->assertSame('feedback-attachments/shot.png', $ticket->attachments->first()->path);

        // The null-employee report survived, with its status remapped reviewing → in_progress
        // and its null description defaulted to '' (tickets.description is NOT NULL).
        $this->assertDatabaseHas('tickets', [
            'tenant_id' => $tenant->id, 'employee_id' => null,
            'category' => 'Idea', 'subject' => 'Orphaned idea',
            'description' => '', 'status' => 'in_progress',
        ]);

        // The pre-existing Bug ticket is untouched and did not trip the count guard.
        $this->assertSame(1, \App\Models\Ticket::withoutGlobalScopes()->where('subject', 'Raised after deploy')->count());

        $this->assertFalse(Schema::hasTable('feedback_items'));
        $this->assertFalse(Schema::hasTable('feedback_attachments'));
    }

    public function test_a_failed_verification_rolls_back_every_inserted_row(): void
    {
        $tenant = \App\Models\Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $user = \App\Models\User::create(['name' => 'Demo', 'email' => 'demo@example.com', 'password' => bcrypt('password')]);

        $this->recreateOldFeedbackTables();
        DB::table('feedback_items')->insert([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'employee_id' => null,
            'type' => 'bug', 'title' => 'Will not survive', 'description' => 'x',
            'page_url' => null, 'status' => 'open',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Force a mismatch: an extra attachment row pointing at no feedback item inflates
        // $expectedAttachments, so nothing will be copied for it and verification throws.
        DB::table('feedback_attachments')->insert([
            'tenant_id' => $tenant->id, 'feedback_item_id' => 9999,
            'path' => 'x', 'name' => 'x', 'mime' => null, 'size' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration = require base_path('database/migrations/2026_08_05_000003_migrate_feedback_items_to_tickets.php');

        try {
            $migration->up();
            $this->fail('Expected the count verification to throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('attachments', $e->getMessage());
        }

        // The transaction rolled the insert back, and the old tables still stand — so a
        // re-run after the operator fixes the data starts clean instead of double-inserting.
        $this->assertSame(0, \App\Models\Ticket::withoutGlobalScopes()->count());
        $this->assertTrue(Schema::hasTable('feedback_items'));
    }
}
```


- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/FeedbackMigrationTest.php`
Expected: FAIL — the migration file doesn't exist yet (`require` throws).

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('feedback_items')) {
            return;
        }

        $expectedTickets = DB::table('feedback_items')->count();
        $expectedAttachments = DB::table('feedback_attachments')->count();

        // Everything that copies rows runs inside one transaction: the verification below
        // throws on a mismatch, and without this the throw would leave half-migrated tickets
        // behind with feedback_items still present, so a re-run would double-insert. The two
        // DROPs stay outside — MySQL commits implicitly on DDL, so wrapping them buys nothing
        // and would only obscure where a failure actually happened.
        DB::transaction(function () use ($expectedTickets, $expectedAttachments) {
            $migratedTickets = 0;
            $migratedAttachments = 0;

            DB::table('feedback_items')->orderBy('id')->chunkById(100, function ($items) use (&$migratedTickets, &$migratedAttachments) {
                foreach ($items as $item) {
                    $ticketId = DB::table('tickets')->insertGetId([
                        'tenant_id' => $item->tenant_id,
                        // Nullable on both sides as of the Task 1 migration — a report whose
                        // author has no employee record still migrates rather than aborting.
                        'employee_id' => $item->employee_id,
                        'assignee_employee_id' => null,
                        'category' => ucfirst($item->type),
                        'priority' => 'medium',
                        'subject' => $item->title,
                        'description' => $item->description ?? '',
                        'page_url' => $item->page_url,
                        'status' => match ($item->status) {
                            'open' => 'open',
                            'reviewing' => 'in_progress',
                            'resolved' => 'resolved',
                            'declined' => 'closed',
                            default => 'open',
                        },
                        'resolution' => null,
                        'created_at' => $item->created_at,
                        'updated_at' => $item->updated_at,
                    ]);
                    $migratedTickets++;

                    DB::table('feedback_attachments')->where('feedback_item_id', $item->id)->orderBy('id')
                        ->get()->each(function ($att) use ($ticketId, &$migratedAttachments) {
                            DB::table('ticket_attachments')->insert([
                                'tenant_id' => $att->tenant_id,
                                'ticket_id' => $ticketId,
                                'path' => $att->path,
                                'name' => $att->name,
                                'mime' => $att->mime,
                                'size' => $att->size,
                                'created_at' => $att->created_at,
                                'updated_at' => $att->updated_at,
                            ]);
                            $migratedAttachments++;
                        });
                }
            });

            // Count what THIS migration wrote, not what the tables hold. A plain
            // tickets/ticket_attachments count would also sweep up any Bug/Idea ticket
            // raised through the UI between deploying Task 3 and running this migration,
            // and would then fail for a reason that has nothing to do with the fold.
            if ($migratedTickets !== $expectedTickets) {
                throw new \RuntimeException("Feedback migration mismatch: expected {$expectedTickets} tickets, migrated {$migratedTickets}.");
            }

            if ($migratedAttachments !== $expectedAttachments) {
                throw new \RuntimeException("Feedback migration mismatch: expected {$expectedAttachments} attachments, migrated {$migratedAttachments}.");
            }
        });

        Schema::dropIfExists('feedback_attachments');
        Schema::dropIfExists('feedback_items');
    }

    public function down(): void
    {
        // One-way data fold — not reversible. Recreate empty tables only so schema:down
        // doesn't hard-fail structurally; data is not restored.
        Schema::create('feedback_items', function ($table) {
            $table->id();
            $table->timestamps();
        });
        Schema::create('feedback_attachments', function ($table) {
            $table->id();
            $table->timestamps();
        });
    }
};
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/FeedbackMigrationTest.php`
Expected: PASS (both tests — the happy-path fold and the rollback guard)

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_08_05_000003_migrate_feedback_items_to_tickets.php tests/Feature/FeedbackMigrationTest.php
git commit -m "feat(helpdesk): migrate feedback_items/feedback_attachments into tickets"
```

---

### Task 13: Retire the Feedback module

**Files:**
- Delete: `app/Http/Controllers/FeedbackController.php`
- Delete: `app/Models/FeedbackItem.php`
- Delete: `app/Models/FeedbackAttachment.php`
- Delete: `resources/views/screens/feedback.blade.php`
- Delete: `resources/views/partials/feedback.blade.php`
- Delete: `tests/Feature/FeedbackTest.php`
- Modify: `routes/web.php:316-321`
- Modify: `app/Http/Controllers/AppController.php` (lines ~167, ~370)
- Modify: `app/Support/Amanahku.php` (lines ~161, ~380, ~463)

**Interfaces:** none produced — this is pure removal now that Tasks 1-12 have ported every capability.

- [ ] **Step 1: Confirm nothing else in the app still calls the routes/classes being removed**

```bash
grep -rn "FeedbackController\|FeedbackItem\|FeedbackAttachment\|feedback\.store\|feedback\.status\|feedback\.attachment\|feedback-open" app/ resources/ routes/ tests/ --include="*.php" --include="*.blade.php" --include="*.js"
```

Expected: only hits inside the files this task is about to delete/modify, plus the data-migration file from Task 12 (which references the `feedback_items`/`feedback_attachments` *tables*, not the classes — leave that file untouched, it's historical and must keep working against a fresh `migrate:fresh`).

- [ ] **Step 2: Delete the retired files**

```bash
git rm app/Http/Controllers/FeedbackController.php
git rm app/Models/FeedbackItem.php
git rm app/Models/FeedbackAttachment.php
git rm resources/views/screens/feedback.blade.php
git rm resources/views/partials/feedback.blade.php
git rm tests/Feature/FeedbackTest.php
```

- [ ] **Step 3: Remove the feedback routes**

In `routes/web.php`, delete lines 316-321:

```php
        // Feedback hub (report a bug / suggest an idea) — pinned in the sidebar, opens a modal.
        Route::post('/app/feedback', [FeedbackController::class, 'store'])->middleware('throttle:20,1')->name('feedback.store');
        // Feedback inbox triage — management/HR move an item along its lifecycle.
        Route::post('/app/feedback/{feedback}/status', [FeedbackController::class, 'setStatus'])->name('feedback.status');
        // Stream a report's screenshot/document — auth-gated (reporter or inbox viewer), never public.
        Route::get('/app/feedback/attachments/{attachment}', [FeedbackController::class, 'attachment'])->name('feedback.attachment');
```

Also remove the `use App\Http\Controllers\FeedbackController;` import line near the top of `routes/web.php` if present (check with `grep -n "use App\\\\Http\\\\Controllers\\\\FeedbackController" routes/web.php`).

- [ ] **Step 4: Remove `feedback` from `AppController`**

In `app/Http/Controllers/AppController.php` line ~167, remove `'feedback', ` from the `in_array` list:

```php
        if (in_array($screen, ['attendance-report', 'timesheet-reports', 'leave-report', 'audit', 'team-board', 'profile-test-results'], true)) {
```

And remove the `screenData` dispatch line (originally line 370):

```php
            'feedback' => app(FeedbackController::class)->screenData($request),
```

Also remove the `use App\Http\Controllers\FeedbackController;`-style reference if `AppController.php` imports it directly (it calls `app(FeedbackController::class)` so check for a `use` statement or fully-qualify — grep first).

- [ ] **Step 5: Remove `feedback` nav entries from `Amanahku.php`**

In `app/Support/Amanahku.php`:
- Remove line ~161: `['id' => 'feedback', 'label' => 'Feedback Inbox', 'label_ms' => 'Peti Maklum Balas'],` from the `Oversight` children array.
- Remove line ~380: `'feedback' => ['title' => 'Feedback Inbox', ...],` from the screen-title map.
- Remove line ~463: `'Feedback Inbox' => 'Peti Maklum Balas',` from the breadcrumb translation map.

- [ ] **Step 6: Run the full test suite**

Run: `php artisan test --compact`
Expected: all PASS, zero references to the deleted classes anywhere.

- [ ] **Step 7: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "chore(helpdesk): retire the standalone Feedback module"
```

---

## Self-Review Notes

- **Spec coverage:** every spec section (data model, submission, triage/visibility, feature flag, sidebar, data migration, testing) maps to a task above. The spec's "org-chart-superior read grant" line was explicitly dropped during brainstorming (superseded by the superadmin/director/HR-only rule) and is correctly absent from every task here.
- **Type/name consistency checked:** `FEEDBACK_CATEGORIES` (Task 3) is the single constant every later task (`4`, `5`, `6`) reuses for the Bug/Idea check — no task invents a second name for it. `FEEDBACK_VIEW_ROLES` (Task 5) is likewise reused verbatim in Task 4's attachment gate. `ticketAttach()`/`registerTicketAttach` (Task 8) names match exactly what Task 9's Blade references.
- **No placeholders:** every step has literal code, not a description of code.
- **Blocking issues found in review and fixed here (2026-08-05):**
  1. The ticket-raise modal is globally included but originally looped `$categories`/`$priorities`, which only `HelpdeskController::screenData` supplies — every non-Helpdesk page would have thrown. Those two constants are now `public` (Task 3) and read directly by the partial (Task 9 Step 2).
  2. `partials/feedback.blade.php` is a two-tab hub whose second tab is the What's New changelog. Task 9 now **copies** that file and replaces only the Report tab's form, so the changelog and the sidebar's "New" badge survive Task 13's deletion.
  3. The pre-purge Helpdesk blade renders "My tickets" only in the non-privileged branch, so a manager's own Bug ticket — filtered off the board by Task 5 — had nowhere to appear. Task 10 Step 2 extracts the card into `partials/my-tickets.blade.php`, Step 4 includes it in the privileged branch, and Task 5 adds `test_manager_still_sees_a_bug_ticket_they_raised_in_my_tickets`.
  4. Task 5's tests originally hit `GET /app/helpdesk`, which 404s until Task 7 and renders `screens.empty` until Task 10 — the `assertDontSee` would have passed for the wrong reason. They now call `screenData()` directly (precedent: `PettyCashTest.php:204-209`), each paired with a positive control, and the HTTP render is covered by Task 10 Step 7.
  5. Nothing rendered attachments or `page_url`, making Task 3's uploads and Task 4's stream route unreachable. Task 10 Step 6 adds both to the board loop and the My-tickets partial, modelled on `screens/feedback.blade.php:85,105-118`, and eager-loads `attachments` in `screenData()`.
- **Non-blocking issues found in the same review and also fixed (2026-08-05):**
  6. `feedback.store` carried `throttle:20,1`; `helpdesk.store` had none, and the modal now sits on every page and takes uploads. Ported in Task 3 Step 4.
  7. Task 12's migration was unwrapped, so a verification throw would leave half-migrated tickets behind with the old tables intact — a re-run would double-insert. Copy + verify now run inside `DB::transaction()` (DDL drops stay outside), with `test_a_failed_verification_rolls_back_every_inserted_row` proving it.
  8. Task 12's guard compared against `DB::table('tickets')->whereIn('category', …)->count()`, which also counts Bug/Idea tickets raised through the UI after Task 3 shipped. It now counts only rows this migration wrote, with a pre-existing ticket seeded in the test to pin the difference.
  9. `feedback_items.employee_id` is nullable but `tickets.employee_id` was not, so one such row on staging or prod would abort the fold mid-deploy. Task 1 relaxes the column (no report is dropped; the board blade already renders `$t->employee?->name` with an "Unknown" fallback) and Task 12's test migrates a null-employee row.
