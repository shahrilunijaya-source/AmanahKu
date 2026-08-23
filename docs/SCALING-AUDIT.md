# Scaling audit: code level

Date: 2026-08-10. Target: 34 employees, low concurrency, peak at month end
(timesheet submit, timesheet approval, payroll run).

Scope: application code only. Server and database settings are in
[DEVOPS-SCALING-REQUEST.md](DEVOPS-SCALING-REQUEST.md).

Headline: the code is in better shape than expected. Eager loading, streaming
exports and index coverage are mostly correct already. The one real problem is a
browser poll that runs far too often, and it is a small fix.

---

## Part 1: code changes you can make

Ordered by how much they help. Test each one on staging before you push to main.

### 1. HIGH: the message badge polls every 5 seconds with no jitter and no hidden-tab skip

**File:** [resources/views/layouts/app.blade.php:241](../resources/views/layouts/app.blade.php)

```js
init() { setInterval(() => this.poll(), 5000); },
```

This is the single largest load source in the application.

The notification bell right below it (line 263) was already fixed. It uses a
self-rescheduling `setTimeout` with random jitter, and it returns early when
`document.hidden` is true. The message badge never got the same fix, and it runs
three times more often.

What it costs per poll of `/app/messages/summary`:

| Work | Queries |
|---|---|
| Session read plus session write (`SESSION_DRIVER=database`) | 2 |
| `ResolveTenant` middleware: tenant, membership, role, scope, employee | about 5 |
| `conversationsFor()`: conversations, unread sub-count, two person loads, latest message | about 5 |
| `unreadCount()` | 1 |

That is roughly 12 database round trips, every 5 seconds, for every open tab,
**including tabs the user is not looking at**. With 34 people and one tab each
that is about 7 requests per second and around 80 queries per second, all day,
from one badge.

Also note the route comment disagrees with the code. [routes/web.php:447](../routes/web.php)
says "the ~30s unread poll". The code says 5 seconds.

**Fix:** copy the notification bell pattern that sits 20 lines below it.

```js
Alpine.store('msgbadge', {
    unread: @js($msgUnread ?? 0),
    threads: @js($msgThreads ?? []),
    init() { this.schedule(); },
    schedule() { setTimeout(() => { this.poll(); this.schedule(); }, 30000 + Math.random() * 10000); },
    poll() {
        if (document.hidden) return;
        fetch('{{ route('messages.summary') }}', { headers: { 'Accept': 'application/json' } })
            .then(r => r.json()).then(d => { this.unread = d.unread; this.threads = d.threads; }).catch(() => {});
    },
});
```

This cuts the load about 6 times, and more than that in practice because hidden
tabs stop polling completely. It also matches what the route comment already
claims.

Two things this protects against beyond raw load. The jitter stops many tabs
landing on the same route in the same instant, which is what caused the deadlock
recorded in the bell comment above (`SQLSTATE[40001] 1213`, 2026-08-10). And a
30 second badge refresh is still fast enough for a staff chat feature.

**Risk:** low. Chat messages take up to 30 seconds to show in the badge instead
of 5. Open the messages screen itself and that screen loads live.

---

### 2. MEDIUM: the header bell view composer runs twice on every page

**File:** [app/Providers/AppServiceProvider.php](../app/Providers/AppServiceProvider.php), in `boot()`

```php
View::composer(['partials.header', 'layouts.app'], function ($view) { ... });
```

A view composer fires once for each view it is attached to. Both
`partials.header` and `layouts.app` render on the same page, so this closure runs
twice per page load. It issues 2 queries each time, so every authenticated page
pays 4 queries for the bell instead of 2.

The comment above it explains why both views are listed, and that reason is
valid. The problem is only that the work is not shared between the two calls.

**Fix:** memoize the body. Laravel's `once()` helper caches per request.

```php
View::composer(['partials.header', 'layouts.app'], function ($view) {
    [$notifications, $unreadCount] = once(function () {
        // ... the existing body, returning both values
    });

    $view->with('notifications', $notifications)->with('unreadCount', $unreadCount);
});
```

**Risk:** very low. Same output, half the queries.

---

### 3. MEDIUM: payroll run does one locked query per employee inside the transaction

**File:** [app/Http/Controllers/PayrollController.php:180](../app/Http/Controllers/PayrollController.php)

```php
foreach ($employees as $employee) {
    $claims = $employee->claims()
        ->where('status', 'approved')->whereNull('paid_at')
        ->whereNotIn('id', $usedClaimIds)
        ->lockForUpdate()->get();
    ...
}
```

Each pass runs its own `SELECT ... FOR UPDATE`. With 34 employees that is 34
locked reads plus 34 inserts inside one transaction, and the row locks are held
while the EPF, SOCSO, EIS and PCB maths runs for every person. This is the
longest write transaction in the application, and it fires at month end, which is
exactly the moment you named as the peak.

It will not fail at 34 people. It will be slow, it holds locks on the `claims`
table for that whole time, and it gets worse in a straight line as headcount
grows.

**Fix:** load the claims for the whole employee set once, before the loop, then
group them in PHP.

```php
$claimsByEmployee = Claim::whereIn('employee_id', $employees->pluck('id'))
    ->where('status', 'approved')->whereNull('paid_at')
    ->whereNotIn('id', $usedClaimIds)
    ->lockForUpdate()->get()
    ->groupBy('employee_id');

foreach ($employees as $employee) {
    $claims = $claimsByEmployee->get($employee->id, collect());
    ...
}
```

34 round trips become 1, and the lock window gets much shorter. The locking
behaviour is unchanged: the same rows are still locked, for the same transaction.

**Also on line 177, lower priority:**

```php
$usedClaimIds = Payslip::whereNotNull('claim_ids')->get(['claim_ids'])
```

This reads every payslip row ever created, to build a list of already-used claim
IDs. At 34 payslips a month that is about 400 rows a year. It is harmless now and
it grows forever. Leave it, but write it down.

**Risk:** medium. This touches money. Write a test that runs payroll for several
employees who each have approved unpaid claims, and check the payslip
`claim_ids` and `claims_reimbursement` values match what the current code
produces. Get that test passing before and after the change.

---

### 4. MEDIUM: turn on the lazy-loading guard

**File:** [app/Providers/AppServiceProvider.php](../app/Providers/AppServiceProvider.php), in `boot()`

```php
Model::preventLazyLoading(! $this->app->isProduction());
```

Nothing in the application currently detects an N+1 query. This one line makes
every lazy relation load throw an error in local, staging and test, while
production stays untouched and keeps working.

I checked the hot paths by hand and they are eager loaded correctly. The
timesheet cost report at [TimesheetController.php:397](../app/Http/Controllers/TimesheetController.php)
loads all four relations up front. The roster export chunks with eager loads. But
hand checking does not cover 72 controllers, and it does not cover code you write
next month.

**Risk:** medium, and it is worth planning for. The first run will very likely
throw on some pages and fail some tests. That is the point, but it means this
should be its own change, on its own branch, not bundled with anything else.
Expect to spend a session fixing what it finds.

---

### 5. MEDIUM: only 4 of 72 controllers paginate

Most list screens call `->get()` and render everything.

At 34 employees this is fine for anything sized by headcount, and that is most of
the application. It is not fine for the tables that grow with **time** rather
than headcount:

- `audit_logs`
- `app_notifications`
- `messages`
- `timesheet_entries`
- `attendance_records`
- `knowledge_entries`

These get bigger every day whether or not you hire anyone. A screen that lists
them without a limit is fast today and slow in a year, and it will not be
obvious why.

**Fix:** add `->paginate()` to the index screens over those six tables. Do them
one at a time; each one needs its Blade view updated to render the pagination
links.

**Risk:** low per screen, but it is view work as well as controller work.

---

### 6. MEDIUM: three missing composite indexes

I dumped the live index list. Coverage is good overall. Three gaps matter.

**a. `timesheets` needs `(tenant_id, status)`**

The month-end approval queue filters by tenant and status. The table only has
`tenant_id` alone. Note that `leave_requests`, `claims` and `overtime_requests`
all already have exactly this pair, so this looks like an oversight rather than a
decision.

**b. `audit_logs` needs `(tenant_id, created_at)`**

Today it has `tenant_id` and `user_id` separately and nothing on `created_at`.
The audit screen shows newest first inside one tenant, so MySQL sorts the whole
tenant slice in memory every time. This table only ever grows, so this gets worse
forever.

**c. `work_items` could use `(tenant_id, status)`**

The board filters tenant plus status plus `archived_at`. Only `tenant_id` exists.
Lower priority than the other two, because work items stay roughly bounded by
team size.

**Fix:** one migration.

```php
Schema::table('timesheets', fn (Blueprint $t) => $t->index(['tenant_id', 'status']));
Schema::table('audit_logs', fn (Blueprint $t) => $t->index(['tenant_id', 'created_at']));
Schema::table('work_items', fn (Blueprint $t) => $t->index(['tenant_id', 'status']));
```

**Risk:** low. Adding an index does not change behaviour. On tables this small the
migration is instant. Remember the deploy script takes a database dump before any
migrating deploy, so the rollback path exists.

---

### 7. LOW: three places that will break if DevOps moves to object storage

Good news first. Almost all file handling already goes through the `Storage`
facade with a disk name. If DevOps moves to Spaces or S3, most of the
application follows with an env change and no code edit. Three places do not.

**a. A local absolute path is passed to the image compressor**

[KnowledgeController.php:269](../app/Http/Controllers/KnowledgeController.php)

```php
$path = $file->store('knowledge-attachments', self::ATTACHMENT_DISK);
ImageCompressor::compress(Storage::disk(self::ATTACHMENT_DISK)->path($path), ...);
```

`->path()` only exists on a local disk. On S3 this throws. This is the only place
in the application that assumes files live on the same machine as the code.

**Fix:** compress the upload at its temporary path **before** you store it, so no
disk path is needed afterwards:

```php
ImageCompressor::compress($file->getRealPath(), (string) $file->getMimeType());
$path = $file->store('knowledge-attachments', self::ATTACHMENT_DISK);
```

This is also slightly better as it is, because it stores the already-compressed
file instead of writing the large one and then rewriting it.

**b. Nine controllers hardcode the disk name**

```
DocumentController.php:25          private const DISK = 'local';
ClaimController.php:27             private const RECEIPT_DISK = 'local';
HelpdeskController.php:39          private const ATTACHMENT_DISK = 'local';
LeaveController.php:27             private const ATTACHMENT_DISK = 'local';
AttendanceController.php:24        private const PHOTO_DISK = 'local';
KnowledgeController.php:58         private const ATTACHMENT_DISK = 'local';
AttendanceAdminController.php:33   private const PHOTO_DISK = 'local';
OnboardingContentController.php:30 private const FILE_DISK = 'local';
MessageController.php:50           private const ATTACHMENT_DISK = 'local';
```

Using a constant is fine. Using the literal `'local'` in nine files means an S3
move is nine edits plus a hunt for the ones you missed.

**Fix:** add one config key, for example `config('amanahku.upload_disk')` reading
`env('UPLOAD_DISK', 'local')`, and have all nine read it. Then the move is one
env line.

**c. One file skipped the constant entirely**

[WelcomeWizardController.php:112](../app/Http/Controllers/WelcomeWizardController.php) writes to the
literal `'local'`, for the same `employee-documents` folder that
[DocumentController.php:83](../app/Http/Controllers/DocumentController.php) writes to using its
constant. Same fix as (b).

**Also worth knowing:** [AdminController.php:72](../app/Http/Controllers/AdminController.php) writes
the company logo to the `public` disk, which is served through the
`public/storage` symlink that `deploy.sh` creates. That symlink is the other
seam that changes on a move to object storage. Nothing to fix now, just do not
forget it.

**Risk:** low for (b) and (c), they are mechanical. (a) needs a manual check that
uploaded pictures still come out compressed.

---

### 8. LOW: two unbounded `::all()` calls, both fine for now

- [BuildsPeopleData.php:277](../app/Http/Controllers/Concerns/BuildsPeopleData.php): `UserPermission::all()`
- [SkillController.php:67](../app/Http/Controllers/SkillController.php): `EmployeeSkill::all()`

Both read a whole table and group it in PHP. Both are bounded in practice by
headcount: 34 users times their permission overrides, 34 employees times their
rated skills. Leave them. Revisit if the skill catalogue grows to hundreds of
entries.

---

## Part 2: checked, and there is nothing to fix

Recording these so nobody spends a day re-checking them.

**Sessions are not file based.** `config/session.php` defaults to `database`, and
both `.env.staging.example` and `.env.production.example` set
`SESSION_DRIVER=database`. This is already correct for more than one instance.
One caveat: I can only read the templates in this repo, not the real production
`.env`. Confirming that is request 3 in the DevOps document.

**Exports do not need queueing.** Every export streams. There is no PDF or Excel
library in `composer.json`, so nothing renders a large document in memory. The
roster export at [ReportController.php:29](../app/Http/Controllers/ReportController.php) is the
best example: it chunks 200 rows at a time with eager loads and writes straight
to the output stream. Payroll bank files and statutory reports work the same way.
Do not add a queue here. It would be more moving parts for no gain.

**Mail never blocks a request.** All four notification classes implement
`ShouldQueue`. `AppNotification::send()` only mails when a fresh row was
created, so a repeated scheduled tick does not resend. This is correct, and it
depends on a queue worker actually running in production, which is request 2 in
the DevOps document.

**The timesheet cost report has no N+1.** [TimesheetController.php:397](../app/Http/Controllers/TimesheetController.php)
eager loads `category`, `projectRef`, `subPillar` and
`timesheet.employee.positionBand`, which covers every relation the 200 lines
below it touch. This is the heaviest month-end read path and it is written
correctly. It does hold the whole period in memory, so a full-year report at
scale would be heavy, but not at this size.

**There is no connection pool setting to tune.** This keeps coming up, so to be
clear: PHP-FPM under Laravel does not pool connections. Each worker opens one
connection per request and closes it at the end. `config/database.php` sets no
`PDO::ATTR_PERSISTENT`, which is correct and should stay that way. The real
limits are the PHP-FPM worker count and the MySQL `max_connections` setting, and
neither lives in this repo. They are requests 5 and 6 in the DevOps document.

**The rate limiter deadlock is already fixed.** Commit `07a47ff` moved
`RateLimiter` off the database cache store onto the `file` store. Nothing more to
do in code. It does carry a single-server assumption, which is request 9 in the
DevOps document.

**Index coverage is good.** `attendance_records` has `(employee_id, date)` and
`(tenant_id, date)`. `app_notifications` has `(user_id, read_at)`, which is what
the bell poll needs. `conversations` has `(tenant_id, last_message_at)`.
`messages` has `(conversation_id, id)` and `(tenant_id, read_at, sender_id)`.
`error_events` and `attendance_attempts` both have `created_at`, which the 03:00
prune jobs need. The three gaps are in item 6 above.

---

## Suggested order

1. Item 1, the message badge poll. Biggest effect, smallest diff, lowest risk.
2. Item 2, the double view composer. One-line class of fix.
3. Item 6, the three indexes. One migration.
4. Item 3, the payroll claims loop. Write the test first, this one touches money.
5. Item 7, the storage seams. Do this before DevOps moves storage, not after.
6. Item 4, the lazy-loading guard. Own branch, own session.
7. Item 5, pagination. Ongoing, one screen at a time.

Items 1, 2 and 6 together should be a single afternoon and cover most of the
real risk.

---

## One thing spotted in passing, not a scaling issue

[MessageController.php](../app/Http/Controllers/MessageController.php), `personArr()` reads
`$e?->position`, but `conversationsFor()` eager loads those employees with an
explicit column list (`id,name,initials,avatar_color,position_id`) that does not
include `position`. So the position shown in the chat panel is probably always
empty. Worth a look separately.
