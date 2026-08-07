# Live Notification Bell Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the header notification bell (badge count + dropdown list) refresh live, without a page reload, mirroring the existing `msgbadge` poll-and-replace pattern.

**Architecture:** A new `GET /app/notifications/summary` endpoint returns `{unread, notifications}` as a plain array (same query the header composer already runs, reshaped). The `AppServiceProvider` composer is changed to build that same array instead of raw Eloquent models, so one mapping feeds both the first paint and the JS seed. A new `notifbell` Alpine store (registered inline in `layouts/app.blade.php`, next to `kbadge`/`msgbadge`) seeds from that array and polls the new endpoint every 15s, full-replacing its state each tick. `header.blade.php`'s badge and list switch from Blade `@if`/`@forelse` to `x-show`/`x-for` off the store.

**Tech Stack:** Laravel 13 / PHP 8.5, Alpine.js 3, PHPUnit 12, Blade.

## Global Constraints

- Every change must be programmatically tested (PHPUnit feature test for the new endpoint).
- Run `vendor/bin/pint --dirty --format agent` after PHP changes.
- `resources/js/notifier.js` is untouched — do not modify it.
- `x-data="{ notif: false }"` in `header.blade.php` must remain byte-for-byte identical — `NotifierMarkupTest::test_bell_binds_to_the_shared_alerts_store` asserts this exact string for unrelated OS-push wiring.
- New route throttled `120,1`, matching `notifications.unseen`.
- Poll interval: 15 seconds.
- No bilingual time strings (unlike `msgbadge`'s `atShort`/`atShortMs`) — a single `diffForHumans()` string, matching the app's current (non-bilingual) notification timestamp behavior.
- Spec: `docs/superpowers/specs/2026-08-07-live-notification-bell-design.md`.

---

### Task 1: Notification summary endpoint

**Files:**
- Modify: `app/Http/Controllers/NotificationController.php`
- Modify: `routes/web.php:267` (add new route directly below the existing `notifications.unseen` line)
- Test: `tests/Feature/NotificationSummaryTest.php` (new)

**Interfaces:**
- Produces: `NotificationController::summary(Request $request): JsonResponse`, returning JSON `{"unread": int, "notifications": [{"id": int, "title": string, "body": string|null, "url": string|null, "read_at": bool, "at": string}]}` — newest-first, latest 8, all read states, for the current user + current tenant (`app(CurrentTenant::class)->id()`).
- Produces: route name `notifications.summary`, path `GET /app/notifications/summary`, `throttle:120,1`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/NotificationSummaryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The poll endpoint that backs the live header bell. Unlike notifications.unseen
 * (cursor-based, unread-only, feeds the OS-push notifier) this returns the same
 * mixed-read-state snapshot the header composer renders on first paint, so a poll
 * tick can fully replace the bell's state.
 */
class NotificationSummaryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        app(CurrentTenant::class)->set($this->tenant);
        $this->user = $this->member('Aina', 'aina@acme.test');
    }

    protected function tearDown(): void
    {
        app(CurrentTenant::class)->set(null);
        parent::tearDown();
    }

    private function member(string $name, string $email): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'employee']);

        return $user;
    }

    public function test_returns_unread_count_and_recent_notifications(): void
    {
        AppNotification::create(['user_id' => $this->user->id, 'title' => 'Read one', 'read_at' => now()]);
        $unread = AppNotification::create(['user_id' => $this->user->id, 'title' => 'Unread one', 'body' => 'Body', 'url' => '/app']);

        $response = $this->actingAs($this->user)->withSession(['current_tenant' => $this->tenant->id])
            ->getJson(route('notifications.summary'));

        $response->assertOk()
            ->assertJsonPath('unread', 1)
            ->assertJsonCount(2, 'notifications')
            ->assertJsonPath('notifications.0.id', $unread->id)
            ->assertJsonPath('notifications.0.title', 'Unread one')
            ->assertJsonPath('notifications.0.body', 'Body')
            ->assertJsonPath('notifications.0.url', '/app')
            ->assertJsonPath('notifications.0.read_at', false)
            ->assertJsonPath('notifications.1.read_at', true);
    }

    public function test_caps_results_at_eight(): void
    {
        collect(range(1, 9))->each(
            fn (int $i) => AppNotification::create(['user_id' => $this->user->id, 'title' => "Bell {$i}"])
        );

        $this->actingAs($this->user)->withSession(['current_tenant' => $this->tenant->id])
            ->getJson(route('notifications.summary'))
            ->assertOk()
            ->assertJsonCount(8, 'notifications');
    }

    public function test_never_returns_another_users_notifications(): void
    {
        $other = $this->member('Bala', 'bala@acme.test');
        AppNotification::create(['user_id' => $other->id, 'title' => 'Not yours']);

        $this->actingAs($this->user)->withSession(['current_tenant' => $this->tenant->id])
            ->getJson(route('notifications.summary'))
            ->assertOk()
            ->assertJsonPath('unread', 0)
            ->assertJsonCount(0, 'notifications');
    }

    public function test_requires_authentication(): void
    {
        $this->getJson(route('notifications.summary'))->assertUnauthorized();
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/NotificationSummaryTest.php`
Expected: FAIL (route `notifications.summary` not defined / 404s).

- [ ] **Step 3: Add the route**

In `routes/web.php`, directly below line 267 (`Route::get('/app/notifications/unseen', ...)`):

```php
        Route::get('/app/notifications/summary', [NotificationController::class, 'summary'])->middleware('throttle:120,1')->name('notifications.summary');
```

- [ ] **Step 4: Implement the controller action**

In `app/Http/Controllers/NotificationController.php`, add this method (after `unseen()`):

```php
    /**
     * Poll target for the live header bell: the same latest-8, mixed-read-state
     * snapshot the header composer renders on first paint (AppServiceProvider),
     * flattened to JSON so a poll tick can fully replace the bell's client state.
     * Unlike unseen(), this is not cursor-based and includes already-read rows.
     */
    public function summary(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $tenantId = app(CurrentTenant::class)->id();

        $notifications = AppNotification::where('user_id', $userId)
            ->where('tenant_id', $tenantId)   // explicit, not just the global scope
            ->latest()
            ->take(8)
            ->get();

        // A second query, not a count over $notifications: the true unread total is
        // never bounded by the 8-row page, matching the composer's own approach.
        $unread = AppNotification::where('user_id', $userId)->where('tenant_id', $tenantId)->whereNull('read_at')->count();

        return response()->json([
            'unread' => $unread,
            'notifications' => $notifications->map(fn (AppNotification $n) => [
                'id' => $n->id,
                'title' => $n->title,
                'body' => $n->body,
                'url' => $n->url,
                'read_at' => $n->read_at !== null,
                'at' => $n->created_at->diffForHumans(),
            ])->values(),
        ]);
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/NotificationSummaryTest.php`
Expected: PASS (4 tests).

- [ ] **Step 6: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/NotificationController.php routes/web.php tests/Feature/NotificationSummaryTest.php
git commit -m "feat(notifications): add live-bell summary poll endpoint"
```

---

### Task 2: Composer builds the same array shape

**Files:**
- Modify: `app/Providers/AppServiceProvider.php:77-89`
- Test: `tests/Feature/NotificationsTest.php` (extend)

**Interfaces:**
- Consumes: nothing new.
- Produces: the `partials.header` view now receives `$notifications` as a plain array (same shape Task 1 returns per row: `id`, `title`, `body`, `url`, `read_at` (bool), `at`), and `$unreadCount` as before (int). Task 3's store seed (`@js($notifications)`) and Task 4's Blade template both depend on this exact shape.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/NotificationsTest.php` (inside the class, needs `use App\Http\Middleware\...` — actually just hits any `/app` screen since the composer runs on every `partials.header` render):

```php
    public function test_header_bell_data_is_shaped_for_the_live_store(): void
    {
        AppNotification::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
            'title' => 'Shaped', 'body' => 'x', 'url' => '/app/somewhere',
        ]);

        $response = $this->actingInTenant()->get(route('app.screen'));

        $response->assertOk();
        $response->assertViewHas('notifications', function ($notifications) {
            $first = $notifications[0];

            return is_array($first)
                && array_key_exists('id', $first)
                && array_key_exists('title', $first)
                && array_key_exists('body', $first)
                && array_key_exists('url', $first)
                && array_key_exists('read_at', $first)
                && is_bool($first['read_at'])
                && array_key_exists('at', $first);
        });
    }
```

`route('app.screen')` is `GET /app/{screen?}` (`routes/web.php:464-466`), which renders through `AppController::screen` and includes `partials.header` — the same route the dev-login helper redirects staff into.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/NotificationsTest.php`
Expected: FAIL — `$notifications[0]` is an `AppNotification` model, not an array, or the shape assertion fails.

- [ ] **Step 3: Update the composer**

In `app/Providers/AppServiceProvider.php`, replace lines 77-89:

```php
        // Share the current user's notifications with the app header bell. Shaped as a
        // plain array (not raw models) because the same shape also seeds the notifbell
        // Alpine store's first-paint state — see layouts/app.blade.php.
        View::composer('partials.header', function ($view) {
            $notifications = collect();
            $unreadCount = 0;

            if (Auth::check() && app(CurrentTenant::class)->check()) {
                $uid = Auth::id();
                $tid = app(CurrentTenant::class)->id();
                $notifications = AppNotification::where('user_id', $uid)->where('tenant_id', $tid)->latest()->take(8)->get()
                    ->map(fn (AppNotification $n) => [
                        'id' => $n->id,
                        'title' => $n->title,
                        'body' => $n->body,
                        'url' => $n->url,
                        'read_at' => $n->read_at !== null,
                        'at' => $n->created_at->diffForHumans(),
                    ])->values();
                $unreadCount = AppNotification::where('user_id', $uid)->where('tenant_id', $tid)->whereNull('read_at')->count();
            }

            $view->with('notifications', $notifications)->with('unreadCount', $unreadCount);
        });
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --compact tests/Feature/NotificationsTest.php`
Expected: PASS.

- [ ] **Step 5: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 6: Commit**

```bash
git add app/Providers/AppServiceProvider.php tests/Feature/NotificationsTest.php
git commit -m "refactor(notifications): shape header composer as a plain array"
```

---

### Task 3: `notifbell` Alpine store

**Files:**
- Modify: `resources/views/layouts/app.blade.php:233-246` (add new store block after the `msgbadge` store's closing `});`, i.e. after line 246, before the `messagesPanel` block)

**Interfaces:**
- Consumes: `$notifications` and `$unreadCount` (from Task 2, now plain arrays/int), route `notifications.summary` (from Task 1).
- Produces: `Alpine.store('notifbell', { unread: int, notifications: array, init(), poll() })` — `unread` and `notifications` are read reactively by Task 4's Blade template via `$store.notifbell.unread` / `$store.notifbell.notifications`.

- [ ] **Step 1: Add the store**

In `resources/views/layouts/app.blade.php`, insert immediately after the `msgbadge` store's closing `});` (after line 246, before the blank line + comment that starts the `messagesPanel` block):

```php
        // Header bell unread badge + dropdown list. Seeded server-side (from the same
        // AppServiceProvider composer that renders the first paint), then refreshed by
        // a 15s poll — slower than msgbadge's 5s since HR events aren't chat-speed
        // urgent. Full-replace, no cursor: same trade-off as msgbadge, simplest thing
        // that stays correct even if another tab or "Mark all read" changed state.
        Alpine.store('notifbell', {
            unread: @js($unreadCount ?? 0),
            notifications: @js($notifications ?? []),
            init() { setInterval(() => this.poll(), 15000); },
            poll() {
                fetch('{{ route('notifications.summary') }}', { headers: { 'Accept': 'application/json' } })
                    .then(r => r.json()).then(d => { this.unread = d.unread; this.notifications = d.notifications; }).catch(() => {});
            },
        });
```

This block is unconditional (not gated behind an `@if ($xEnabled ?? false)` like `kbadge`/`msgbadge`) because notifications have no per-tenant feature flag — every tenant has the bell.

- [ ] **Step 2: Verify no PHP/Blade syntax errors**

Run: `php -l resources/views/layouts/app.blade.php` (Blade files are plain PHP once compiled, but a raw `php -l` on the `.blade.php` source still catches unbalanced braces/quotes in the inline `<script>` block's PHP-interpolated parts). If this doesn't apply cleanly (Blade directives aren't valid raw PHP), skip it and instead run:

Run: `php artisan view:clear && php artisan view:cache`
Expected: no compilation error.

- [ ] **Step 3: Commit**

```bash
git add resources/views/layouts/app.blade.php
git commit -m "feat(notifications): add notifbell Alpine store"
```

---

### Task 4: Wire the bell markup to the store

**Files:**
- Modify: `resources/views/partials/header.blade.php:160-192`
- Test: `tests/Feature/NotifierMarkupTest.php` (must still pass unmodified — do not edit this file)

**Interfaces:**
- Consumes: `$store.notifbell.unread` (int), `$store.notifbell.notifications` (array of `{id, title, body, url, read_at, at}`) from Task 3.

- [ ] **Step 1: Replace the badge and list**

In `resources/views/partials/header.blade.php`, replace lines 160-192 with:

```blade
    <div x-data="{ notif: false }" style="position:relative;">
        <button @click="notif = ! notif" class="uj-hd-ib" :aria-expanded="notif"
                :aria-label="$store.ui.lang==='en' ? ($store.notifbell.unread ? `Notifications (${$store.notifbell.unread} unread)` : 'Notifications') : ($store.notifbell.unread ? `Pemberitahuan (${$store.notifbell.unread} belum dibaca)` : 'Pemberitahuan')">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--body)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0"></path></svg>
            <template x-if="$store.notifbell.unread > 0">
                <span style="position:absolute;top:3px;right:3px;min-width:15px;height:15px;padding:0 3px;background:var(--red);color:#fff;border-radius:9999px;border:1.5px solid #fff;font-size:var(--t-micro);font-weight:700;display:flex;align-items:center;justify-content:center;" x-text="$store.notifbell.unread > 9 ? '9+' : $store.notifbell.unread"></span>
            </template>
        </button>
        <div x-show="notif" x-cloak class="uj-hd-panel" @click.outside="notif = false" @keydown.escape.window="notif = false" style="position:absolute;right:0;top:46px;width:340px;max-width:88vw;background:#fff;border:1px solid var(--hairline);border-radius:12px;box-shadow:var(--shadow-menu);z-index:60;overflow:hidden;">
            <div style="display:flex;align-items:center;justify-content:space-between;padding:13px 16px;border-bottom:1px solid var(--hairline);">
                <span style="font-size:var(--t-base);font-weight:600;color:var(--ink);" x-text="$store.ui.lang==='en' ? 'Notifications' : 'Pemberitahuan'">Notifications</span>
                <template x-if="$store.notifbell.unread > 0">
                    <form method="post" action="{{ route('notifications.read') }}">@csrf<button type="submit" style="font-size:var(--t-sm);color:var(--red);background:none;" x-text="$store.ui.lang==='en' ? 'Mark all read' : 'Tanda semua dibaca'">Mark all read</button></form>
                </template>
                {{-- Opt-in must be click-driven: browsers reject a permission request that
                     is not tied to a user gesture. Hidden once granted, denied, or unsupported. --}}
                <button type="button" x-show="$store.alerts.canAsk" x-cloak @click="$store.alerts.enable()"
                        style="font-size:var(--t-sm);color:var(--red);background:none;"
                        x-text="$store.ui.lang==='en' ? 'Turn on alerts' : 'Hidupkan makluman'">Turn on alerts</button>
            </div>
            <div style="max-height:360px;overflow-y:auto;">
                <template x-for="n in $store.notifbell.notifications" :key="n.id">
                    <a :href="n.url || '#'" style="display:block;padding:12px 16px;border-bottom:1px solid var(--hairline-soft);text-decoration:none;" :style="{ background: n.read_at ? '#fff' : 'var(--red-tint)' }">
                        <div style="font-size:var(--t-base);font-weight:600;color:var(--ink);" x-text="n.title"></div>
                        <div x-show="n.body" style="font-size:var(--t-sm);color:var(--body);margin-top:2px;line-height:1.45;" x-text="n.body"></div>
                        <div style="font-size:var(--t-micro);color:var(--muted);margin-top:4px;font-family:var(--font-mono);" x-text="n.at"></div>
                    </a>
                </template>
                <template x-if="$store.notifbell.notifications.length === 0">
                    <div style="padding:36px 20px;text-align:center;font-size:var(--t-base);color:var(--muted);" x-text="$store.ui.lang==='en' ? 'You\'re all caught up.' : 'Semua sudah dibaca.'">You're all caught up.</div>
                </template>
            </div>
        </div>
    </div>
```

Notes on this diff versus the original:
- `x-data="{ notif: false }"` is unchanged — required verbatim by `NotifierMarkupTest`.
- The `@if ($unreadCount > 0)` / blade `{{ $unreadCount > 9 ? '9+' : $unreadCount }}` badge becomes `x-if`/`x-text` reading `$store.notifbell.unread`.
- The `@if ($unreadCount > 0)` around "Mark all read" becomes `x-if` on the same store value.
- The `@forelse ($notifications as $n) ... @empty ... @endforelse` becomes `x-for` + a sibling `x-if` for the empty state (Alpine has no single `@forelse` equivalent — this is the standard two-template pattern already used for `threads` in `messages-panel.blade.php:39-65`).
- `aria-label` moves from a server-computed `@js($unreadCount ? ... )` string to a client-computed template literal, since the count is now live.

- [ ] **Step 2: Run the pinned markup test**

Run: `php artisan test --compact tests/Feature/NotifierMarkupTest.php`
Expected: PASS (unmodified — confirms `x-data="{ notif: false }"` and the `alerts` store hooks are still intact).

- [ ] **Step 3: Run the full notification test suite**

Run: `php artisan test --compact tests/Feature/NotificationsTest.php tests/Feature/NotificationSummaryTest.php tests/Feature/UnseenNotificationsTest.php tests/Feature/NotifierMarkupTest.php`
Expected: all PASS.

- [ ] **Step 4: Commit**

```bash
git add resources/views/partials/header.blade.php
git commit -m "feat(notifications): make header bell badge and list live"
```

---

### Task 5: Build assets and manually verify in browser

**Files:**
- None (build output only): `public/build/**`

- [ ] **Step 1: Rebuild frontend assets**

Run: `bun run build`

- [ ] **Step 2: Commit the build output**

```bash
git add public/build
git commit -m "chore: rebuild assets for live notification bell"
```

- [ ] **Step 3: Run the full test suite**

Run: `php artisan test --compact`
Expected: all PASS (no regressions elsewhere).

- [ ] **Step 4: Manual browser verification**

This step is not scriptable — perform it directly (e.g. via the Claude Browser pane) rather than delegating to a subagent, since it requires observing live DOM state across a poll tick:

1. Run `lerd artisan migrate` if the dev DB is behind (check `lerd status` first).
2. Open two contexts: dev-login as `hr@amanahku.test` (tenant `unijaya`) in one, and as the notification's *recipient* in another — or simpler, dev-login as `employee@amanahku.test` and note their bell's current unread count.
3. As HR or manager, trigger an action that calls `AppNotification::send()` for that employee (e.g. approve/verify a leave request, update a helpdesk ticket assigned to them — check `app/Models/AppNotification.php` callers for the fastest path with the seeded accounts).
4. On the employee's already-open tab, without reloading, wait up to 15s.
5. Confirm: the bell badge count increments, and opening the dropdown shows the new notification — no manual refresh.
6. Confirm "Mark all read" still works (full reload is expected/unchanged) and the badge clears.
7. Confirm `notifier.js`'s OS-push path is untouched: check `read_console_messages` / `preview_logs` for no new errors, and that the existing "Turn on alerts" button still renders when permission is `default`.

- [ ] **Step 5: Report results**

Summarize pass/fail for each check in Step 4 to the user before considering the task done.
