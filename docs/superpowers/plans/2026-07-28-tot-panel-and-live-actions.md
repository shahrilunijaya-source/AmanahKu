# TOT panel and live actions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Shrink the TOT session panel to a card with an icon row, move comments into a modal that loads on demand, and stop every action from reloading the page.

**Architecture:** Each action endpoint returns JSON when the request asks for it and keeps its existing redirect when it does not, so the screen works with JavaScript off and every existing test keeps passing. One Alpine component per session holds the card state and updates it from those JSON responses. The always-open comment thread, the composer, the 1 to 5 row and the note box leave the card; reactions and ratings move into hover flyouts, comments into a modal.

**Tech Stack:** Laravel 13, PHP 8.5, PHPUnit 12, Larastan 3, Pint, Blade, Alpine 3, Tailwind 4, Vite via bun.

Spec: [2026-07-28-tot-panel-and-assign-permission-design.md](../specs/2026-07-28-tot-panel-and-assign-permission-design.md), Parts B and C.

## Global Constraints

- **No em dashes** in code, comments, Blade copy or commit messages. Use connector words.
- Commit messages say what changed and why, not just "updated file".
- Run `vendor/bin/pint --dirty --format agent` before every commit. It must report `"result":"passed"`.
- Run `composer analyse` before the final commit of each task. It must report `"errors":0`.
- Every screen string is bilingual: `x-text="$store.ui.lang==='en' ? 'English' : 'Bahasa Melayu'"`.
- **Never remove the redirect branch.** Each action keeps `back()->with('ok', ...)` for non-JSON requests. This is what keeps the screen usable without JavaScript and what keeps the existing `TotTest` assertions valid.
- **Scores stay private.** `visibleScores()` decides who may see a score. The JSON must apply the same rule, and no response may ever carry a rater's name or a per-person score.
- **Reactions and ratings never show names**, only counts.
- **The rater-facing reassurance sentence is not optional and is not shortened.** It reads "Only <presenter> and management see scores, and never with your name." and it belongs beside the rating control, not only in the collapsible guide panel at the top of the screen. It is the only thing telling somebody their score is safe to give honestly.
- **A test that starts failing is a finding, not an obstacle.** Never change a test's fixture or delete a test to make a suite green. Report the conflict and stop. A previous attempt on this plan changed a privacy test so that its rater was a different person, which turned the suite green and removed the exact case the test existed to cover.
- Fetch calls follow the pattern already in `resources/views/layouts/app.blade.php` (search for `X-CSRF-TOKEN`): the token comes from `document.querySelector('meta[name=csrf-token]').content` and the request sends `Accept: application/json`.
- Use `bun` for any JS tooling, never npm, node, yarn or pnpm. Run `bun run build` and commit `public/build` whenever Blade classes change.
- The app runs under Lerd at `http://localhost:9100`, not `amanahku.test`. Sign in with no password at `http://localhost:9100/dev/login?email=<account>&tenant=unijaya`.
- The SQLSTATE 23 catches in `react()` and `rate()` stay exactly as they are. Removing the page reload makes those races more likely, not less.

### Motion values, exact

| Thing | Value |
|---|---|
| Flyout fade in | 150ms ease |
| Flyout scale in | 200ms `cubic-bezier(.2, .9, .3, 1.35)` from `translateY(8px) scale(.92)` |
| Emoji stagger | 30ms per emoji, six total |
| Emoji hover | `scale(1.4) translateY(-5px)`, 140ms, same easing |
| Modal backdrop | 150ms fade |
| Modal card | 180ms scale from `.96` |
| Reduced motion | `@media (prefers-reduced-motion: reduce)` drops every transform and keeps only the fades |

### Not in this plan

- The duplicate flash banner and the toast conversion. That is Part D and is running as a separate task. The fetch handlers here call `$store.toast.success(...)`, which already exists in `resources/js/toast.js` and works today whether or not Part D has landed.
- The `tot.assign` permission. That is a separate plan.
- Threaded replies. Comments stay flat.
- Press and hold. Dropped on purpose: it fights the iOS selection callout and the Android context menu, and the heart has no default action a tap would otherwise take.

---

## File Structure

| File | Responsibility |
|---|---|
| `app/Http/Controllers/TotController.php` | Adds `sessionState()`, the one place that decides what a card knows. Every action returns it as JSON or falls back to a redirect. Swaps eager comments for a count. |
| `app/Http/Controllers/TotController.php` (`comments` action) | New read endpoint returning one session's thread as JSON. |
| `routes/web.php` | One new GET route for that endpoint. |
| `resources/views/screens/tot.blade.php` | The card: reaction pills, icon row, flyouts, modal, and the Alpine component that drives them. |
| `resources/css/app.css` | The flyout and modal styles, including the reduced-motion block. |
| `tests/Feature/TotLiveActionsTest.php` | New coverage for the JSON contract. |

---

## Task 1: One place that says what a card knows

**Files:**
- Modify: `app/Http/Controllers/TotController.php`
- Test: `tests/Feature/TotLiveActionsTest.php` (create)

**Interfaces:**
- Consumes: the existing private helpers `reactionCounts(array $ids)`, `watchedCounts(array $ids)` and `visibleScores(Collection $saved, ?Employee $employee, bool $privileged)`.
- Produces: `private function sessionState(Request $request, TotSession $session): array` returning exactly these keys:

```php
[
    'id' => int,
    'reactions' => array<string, int>,   // emoji => count, only emoji with at least one
    'mine' => list<string>,              // the caller's own emoji
    'watched' => int,                    // how many people watched
    'iWatched' => bool,
    'comments' => int,                   // count only, never the bodies
    'myScore' => int|null,
    'myNote' => string|null,
    'score' => array{average: float, count: int}|null,  // null unless the caller may see it
]
```

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/TotLiveActionsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\TotSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TotLiveActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create(['name' => 'Demo', 'email' => 'demo@example.com', 'password' => Hash::make('password')]);
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
            'name' => 'Demo', 'status' => 'active', 'workload' => 'green',
        ]);
    }

    private function actingInTenant(): self
    {
        $this->actingAs($this->user)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    private function slot(): TotSession
    {
        return TotSession::create([
            'tenant_id' => $this->tenant->id, 'year' => 2026, 'month' => 9,
            'title' => 'Barcode rollout', 'status' => 'done',
        ]);
    }

    public function test_reacting_returns_the_new_state_as_json(): void
    {
        $session = $this->slot();

        $response = $this->actingInTenant()
            ->postJson("/app/tot/{$session->id}/react", ['emoji' => '👍']);

        $response->assertOk()
            ->assertJsonPath('reactions.👍', 1)
            ->assertJsonPath('mine', ['👍'])
            ->assertJsonPath('comments', 0);
    }

    public function test_reacting_twice_removes_it_and_says_so(): void
    {
        $session = $this->slot();

        $this->actingInTenant()->postJson("/app/tot/{$session->id}/react", ['emoji' => '👍']);
        $response = $this->actingInTenant()->postJson("/app/tot/{$session->id}/react", ['emoji' => '👍']);

        $response->assertOk()
            ->assertJsonPath('mine', [])
            ->assertJsonMissingPath('reactions.👍');
    }

    public function test_a_plain_form_post_still_redirects(): void
    {
        $session = $this->slot();

        $this->actingInTenant()
            ->post("/app/tot/{$session->id}/react", ['emoji' => '👍'])
            ->assertRedirect();
    }

    public function test_watching_returns_the_new_state(): void
    {
        $session = $this->slot();

        $this->actingInTenant()->postJson("/app/tot/{$session->id}/watched")
            ->assertOk()
            ->assertJsonPath('watched', 1)
            ->assertJsonPath('iWatched', true);
    }

    public function test_rating_returns_my_score_but_hides_the_summary_from_a_plain_viewer(): void
    {
        $session = $this->slot();

        $this->actingInTenant()
            ->postJson("/app/tot/{$session->id}/rate", ['score' => 4, 'note' => 'Useful'])
            ->assertOk()
            ->assertJsonPath('myScore', 4)
            ->assertJsonPath('myNote', 'Useful')
            ->assertJsonPath('score', null);
    }

    public function test_the_presenter_sees_the_score_summary(): void
    {
        $session = $this->slot();
        $session->update(['presenter_employee_id' => $this->employee->id]);

        $this->actingInTenant()
            ->postJson("/app/tot/{$session->id}/rate", ['score' => 5])
            ->assertOk()
            ->assertJsonPath('score.average', 5)
            ->assertJsonPath('score.count', 1);
    }

    public function test_the_state_never_carries_a_rater_name_or_note_list(): void
    {
        $session = $this->slot();
        $session->update(['presenter_employee_id' => $this->employee->id]);

        $response = $this->actingInTenant()
            ->postJson("/app/tot/{$session->id}/rate", ['score' => 5, 'note' => 'Secret note']);

        $this->assertArrayNotHasKey('notes', $response->json('score'));
        $response->assertJsonMissing(['name' => 'Demo']);
    }

    public function test_commenting_returns_the_new_count(): void
    {
        $session = $this->slot();

        $this->actingInTenant()
            ->postJson("/app/tot/{$session->id}/comment", ['body' => 'Good session'])
            ->assertOk()
            ->assertJsonPath('comments', 1);
    }
}
```

`test_the_state_never_carries_a_rater_name_or_note_list` is the one that matters most. The card's score summary shows an average and a count only. The anonymous note list stays on the full screen render, where `visibleScores()` already gates it, and must not leak into a response that any rater triggers.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=TotLiveActionsTest`

Expected: FAIL. Every action returns a 302 redirect, so `assertOk()` fails on the JSON calls.

- [ ] **Step 3: Add the state builder**

In `app/Http/Controllers/TotController.php`, add this private method directly above `assertSameTenant()`:

```php
    /**
     * Everything a session card draws, for the acting viewer.
     *
     * One method so the JSON a live action returns and the data the screen renders can never
     * drift apart, and so the privacy rule has exactly one home: the score summary is present
     * only for a viewer visibleScores() would show it to, and it carries an average and a
     * count, never a name and never the anonymous notes.
     *
     * @return array<string, mixed>
     */
    private function sessionState(Request $request, TotSession $session): array
    {
        $employee = $request->attributes->get('employee');
        $privileged = $this->hasTenantRole($request, self::PRIVILEGED_ROLES);

        $mine = $employee
            ? TotReaction::where('session_id', $session->id)
                ->where('employee_id', $employee->id)
                ->pluck('emoji')->all()
            : [];

        $participation = $employee
            ? TotParticipation::where('session_id', $session->id)
                ->where('employee_id', $employee->id)
                ->first()
            : null;

        $summary = $this->visibleScores(
            collect([$session->id => $session]),
            $employee,
            $privileged
        )[$session->id] ?? null;

        return [
            'id' => $session->id,
            'reactions' => $this->reactionCounts([$session->id])[$session->id] ?? [],
            'mine' => $mine,
            'watched' => $this->watchedCounts([$session->id])[$session->id] ?? 0,
            'iWatched' => $participation?->watched_at !== null,
            'comments' => TotComment::where('session_id', $session->id)->count(),
            'myScore' => $participation?->score,
            'myNote' => $participation?->note,
            'score' => $summary === null ? null : [
                'average' => $summary['average'],
                'count' => $summary['count'],
            ],
        ];
    }
```

The `score` key deliberately rebuilds a two-key array rather than passing `$summary` through. `visibleScores()` also returns a `notes` list, and that list must not reach a response any rater can trigger.

- [ ] **Step 4: Return it from every action**

Change the return of each action. In every case, keep the existing redirect for a non-JSON request.

`react()`, both returns (the delete branch and the create branch), become:

```php
        return $request->expectsJson()
            ? response()->json($this->sessionState($request, $session))
            : back();
```

`watched()`:

```php
        return $request->expectsJson()
            ? response()->json($this->sessionState($request, $session))
            : back()->with('ok', 'Marked as watched.');
```

`rate()`:

```php
        return $request->expectsJson()
            ? response()->json($this->sessionState($request, $session))
            : back()->with('ok', 'Thanks, your rating was saved.');
```

`comment()`:

```php
        return $request->expectsJson()
            ? response()->json($this->sessionState($request, $session))
            : back()->with('ok', 'Comment posted.');
```

`deleteComment()` needs the parent session, which it does not currently load:

```php
        $comment->delete();

        return $request->expectsJson()
            ? response()->json($this->sessionState($request, $comment->session))
            : back()->with('ok', 'Comment removed.');
```

Read the `session()` relationship on `App\Models\TotComment` before writing that line. If the model has no `session()` belongsTo, add one following the style of the model's existing relations, and load it into a local variable before `delete()` so it is still resolvable afterwards.

Also change the return type of these five methods from `RedirectResponse` to `RedirectResponse|JsonResponse`, and add `use Illuminate\Http\JsonResponse;` to the imports.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=TotLiveActionsTest`

Expected: PASS, 8 tests.

- [ ] **Step 6: Run the whole TOT suite**

Run: `php artisan test --compact --filter=Tot`

Expected: PASS. Existing tests post plain forms, so they still take the redirect branch untouched. If any existing test now fails, the redirect branch was changed by mistake; restore it.

- [ ] **Step 7: Format, analyse and commit**

```bash
vendor/bin/pint --dirty --format agent
composer analyse
git add app/Http/Controllers/TotController.php tests/Feature/TotLiveActionsTest.php
git commit -m "feat(tot): return card state as JSON so actions can stop reloading

Every action keeps its redirect for a plain form post, so the screen still
works without JavaScript and the existing tests stay valid. sessionState() is
the single place the privacy rule lives: an average and a count for a viewer
allowed to see scores, and never a name or the anonymous notes."
```

---

## Task 2: Comments load on demand

**Files:**
- Modify: `app/Http/Controllers/TotController.php` (`screenData`, plus a new `comments` action), `routes/web.php:320-329`
- Test: `tests/Feature/TotLiveActionsTest.php`

**Interfaces:**
- Consumes: `assertSameTenant()`.
- Produces: `public function comments(Request $request, TotSession $session): JsonResponse` returning `['comments' => list<array{id: int, name: string, initials: string, color: string, presenter: bool, body: string, at: string, canDelete: bool}>, 'notes' => list<string>]`, on the route named `tot.comments` at `GET /app/tot/{session}/comments`. Also replaces the `comments` key in `screenData()` with `commentCounts` of shape `array<int, int>`.

`notes` is the anonymous rating notes, and it is `[]` for anybody `visibleScores()` would not show scores to. It rides on this endpoint rather than on `sessionState()` because `sessionState()` answers every action, including ones an ordinary rater triggers, and the notes must only ever travel to a presenter or to management.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/TotLiveActionsTest.php`:

```php
    public function test_the_thread_loads_on_demand(): void
    {
        $session = $this->slot();
        $this->actingInTenant()->post("/app/tot/{$session->id}/comment", ['body' => 'First']);

        $this->actingInTenant()->getJson("/app/tot/{$session->id}/comments")
            ->assertOk()
            ->assertJsonPath('comments.0.body', 'First')
            ->assertJsonPath('comments.0.name', 'Demo')
            ->assertJsonPath('comments.0.canDelete', true);
    }

    public function test_the_thread_carries_only_this_session(): void
    {
        $mine = $this->slot();
        $other = TotSession::create([
            'tenant_id' => $this->tenant->id, 'year' => 2026, 'month' => 10, 'status' => 'planned',
        ]);
        $this->actingInTenant()->post("/app/tot/{$mine->id}/comment", ['body' => 'Mine']);
        $this->actingInTenant()->post("/app/tot/{$other->id}/comment", ['body' => 'Other']);

        $this->actingInTenant()->getJson("/app/tot/{$mine->id}/comments")
            ->assertOk()
            ->assertJsonCount(1, 'comments')
            ->assertJsonPath('comments.0.body', 'Mine');
    }

    public function test_the_presenter_gets_the_anonymous_notes_with_the_thread(): void
    {
        $session = $this->slot();
        $session->update(['presenter_employee_id' => $this->employee->id]);
        $this->actingInTenant()->post("/app/tot/{$session->id}/rate", ['score' => 5, 'note' => 'Clear slides']);

        $this->actingInTenant()->getJson("/app/tot/{$session->id}/comments")
            ->assertOk()
            ->assertJsonPath('notes', ['Clear slides']);
    }

    public function test_a_plain_viewer_gets_no_notes(): void
    {
        $session = $this->slot();
        $this->actingInTenant()->post("/app/tot/{$session->id}/rate", ['score' => 5, 'note' => 'Clear slides']);

        $this->actingInTenant()->getJson("/app/tot/{$session->id}/comments")
            ->assertOk()
            ->assertJsonPath('notes', []);
    }

    public function test_a_foreign_tenant_thread_is_not_readable(): void
    {
        $other = Tenant::create(['slug' => 'beta', 'name' => 'Beta', 'initials' => 'BT']);
        $foreign = TotSession::create([
            'tenant_id' => $other->id, 'year' => 2026, 'month' => 11, 'status' => 'planned',
        ]);

        $this->actingInTenant()->getJson("/app/tot/{$foreign->id}/comments")->assertNotFound();
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=TotLiveActionsTest`

Expected: FAIL with 404 on `/app/tot/{id}/comments`. The route does not exist.

- [ ] **Step 3: Add the route**

In `routes/web.php`, inside the same group as the other TOT routes, add this line directly above the `tot.comment` POST route:

```php
        Route::get('/app/tot/{session}/comments', [TotController::class, 'comments'])->name('tot.comments');
```

Order matters in this group: put it above `Route::post('/app/tot/{session}', ...)` so nothing shadows it.

- [ ] **Step 4: Add the action**

In `app/Http/Controllers/TotController.php`, add this public method directly after `comment()`:

```php
    /**
     * One session's thread, oldest first. Loaded when the comment modal opens rather than
     * with the screen, so twelve months of discussion no longer ride along with every page
     * view of the year lineup.
     */
    public function comments(Request $request, TotSession $session): JsonResponse
    {
        $this->assertSameTenant($session);

        $employee = $request->attributes->get('employee');
        $privileged = $this->hasTenantRole($request, self::PRIVILEGED_ROLES);

        $rows = TotComment::with('employee')
            ->where('session_id', $session->id)
            ->orderBy('created_at')
            ->get()
            ->map(fn (TotComment $c) => [
                'id' => $c->id,
                'name' => $c->employee->name,
                'initials' => $c->employee->initials ?? '',
                'color' => $c->employee->avatar_color ?? '#3a6ea5',
                'presenter' => $session->presenter_employee_id !== null
                    && $c->employee_id === $session->presenter_employee_id,
                'body' => $c->body,
                'at' => $c->created_at?->format('j M') ?? '',
                'canDelete' => $privileged || ($employee && $c->employee_id === $employee->id),
            ])
            ->all();

        $summary = $this->visibleScores(collect([$session->id => $session]), $employee, $privileged);

        return response()->json([
            'comments' => $rows,
            'notes' => $summary[$session->id]['notes'] ?? [],
        ]);
    }
```

- [ ] **Step 5: Swap eager comments for a count in screenData()**

In `screenData()`, replace the `'comments' => $this->commentsBySession($ids),` line with:

```php
            'commentCounts' => $this->commentCounts($ids),
```

Then replace the private `commentsBySession()` helper with:

```php
    /**
     * How many comments each session has. The card shows a number; the thread itself loads
     * only when somebody opens the modal.
     *
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private function commentCounts(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return TotComment::whereIn('session_id', $ids)
            ->selectRaw('session_id, count(*) as aggregate')
            ->groupBy('session_id')
            ->pluck('aggregate', 'session_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }
```

The view still references `$comments` at this point, so it will break until Task 3. That is expected: the next step tells you to keep the view compiling with a temporary read of the new key.

- [ ] **Step 6: Keep the view compiling**

In `resources/views/screens/tot.blade.php`, find the line that reads the per-session comments (search for `$comments[$session->id]`) and change the local assignment to use the count for now:

```php
$sessionCommentCount = $commentCounts[$session->id] ?? 0;
```

Then replace the `@forelse ($sessionComments as $comment)` block and the count display with a single placeholder line that renders the number, so the screen still loads:

```blade
<div class="tot-note">{{ $sessionCommentCount }} <span x-text="$store.ui.lang==='en' ? 'comments' : 'komen'">comments</span></div>
```

Delete the old comment loop, the empty state and the inline composer. Task 6 builds their replacement inside the modal. Leaving them would render a thread from a variable that no longer exists.

- [ ] **Step 7: Run the tests**

Run: `php artisan test --compact --filter=Tot`

Expected: PASS. Any existing test that asserts on a comment body appearing in the screen HTML will now fail, because the thread no longer renders on the page. That is the intended change: move the assertion to `/app/tot/{id}/comments` and say so in your report. Do not delete the test.

- [ ] **Step 8: Format, analyse and commit**

```bash
vendor/bin/pint --dirty --format agent
composer analyse
bun run build
git add app/Http/Controllers/TotController.php routes/web.php resources/views/screens/tot.blade.php tests/Feature/TotLiveActionsTest.php public/build
git commit -m "feat(tot): load a session thread on demand instead of with the page

Twelve months of discussion used to ride along with every view of the year
lineup so that most cards could render an empty state. The card now needs a
count, and the modal fetches the thread when somebody opens it."
```

---

## Task 3: The card becomes pills plus an icon row

**Files:**
- Modify: `resources/views/screens/tot.blade.php`, `resources/css/app.css`
- Test: none new. This is markup; the server contract is already covered by Tasks 1 and 2.

**Interfaces:**
- Consumes: `$reactionCounts`, `$myReactions`, `$myParticipation`, `$watchedCounts`, `$scores`, `$commentCounts` from `screenData()`, and the `sessionState()` JSON shape from Task 1.
- Produces: an Alpine component named `totCard` registered on the panel element, holding the reactive state each icon reads.

- [ ] **Step 1: Read the current panel**

Open `resources/views/screens/tot.blade.php` and read from the `{{-- Links --}}` comment (near line 298) through the end of the discussion block (near line 434). You are replacing the emoji bar, the watched pill, the whole rating block and the comment placeholder from Task 2. Leave the links block, the Knowledge Bank cross-link block and the edit form alone.

- [ ] **Step 2: Add the Alpine component**

On the element that currently carries `x-data` for the panel, add the card state. If the panel already has an `x-data` object, merge these keys into it rather than adding a second `x-data`:

```blade
x-data="totCard({
    id: {{ $session->id }},
    reactions: @js($reactionCounts[$session->id] ?? (object) []),
    mine: @js($myReact),
    watched: {{ $watched }},
    iWatched: @js((bool) $myPart?->watched_at),
    comments: {{ $sessionCommentCount }},
    myScore: @js($myPart?->score),
    myNote: @js($myPart?->note),
    score: @js($sessionScore ? ['average' => $sessionScore['average'], 'count' => $sessionScore['count']] : null),
    canParticipate: @js($canParticipate),
})"
```

**Where the component lives.** This app does not register Alpine components inside Blade. Every reusable one is its own ES module under `resources/js/` exporting a `register<Name>(Alpine)` function, imported and called in `resources/js/app.js`. See `resources/js/work-board.js` and its `registerWorkBoard(Alpine)` call for the closest example, since it also drives a modal and a comment thread over fetch.

So: create `resources/js/tot-card.js` exporting `registerTotCard(Alpine)`, add `import { registerTotCard } from './tot-card';` to the import block in `resources/js/app.js` keeping that block's alphabetical order, and call `registerTotCard(Alpine);` alongside the other register calls.

**Do not disturb the existing row-level `x-data`.** The month row already carries `x-data="{ open: ..., editing: ... }"` and the links repeater carries its own. Put `x-data="totCard({...})"` on the panel element nested inside the row. Alpine scopes nest, so the inner component still reads `open` and `editing` from the row above it.

```js
Alpine.data('totCard', (seed) => ({
    ...seed,
    flyout: null,
    modalOpen: false,
    thread: null,
    notes: [],
    busy: false,

    // Total across every emoji, which is what the heart shows.
    get reactionTotal() {
        return Object.values(this.reactions).reduce((a, b) => a + b, 0);
    },

    // One place that talks to the server. Every action returns the same card state, so
    // there is one merge and one failure path rather than five of each.
    async act(url, body = null) {
        if (this.busy) return;
        this.busy = true;
        try {
            const res = await fetch(url, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                },
                body: body ? JSON.stringify(body) : null,
            });
            if (!res.ok) throw new Error(res.status);
            Object.assign(this, await res.json());
        } catch (e) {
            Alpine.store('toast').error(
                Alpine.store('ui').lang === 'en'
                    ? 'That did not save. Try again.'
                    : 'Tidak berjaya disimpan. Cuba lagi.'
            );
        } finally {
            this.busy = false;
        }
    },

    react(emoji) { return this.act(`/app/tot/${this.id}/react`, { emoji }); },
    toggleWatched() { return this.act(`/app/tot/${this.id}/watched`); },
    rate(score) { return this.act(`/app/tot/${this.id}/rate`, { score }); },
    saveNote(note) { return this.act(`/app/tot/${this.id}/rate`, { score: this.myScore, note }); },
}))
```

Note there is no optimistic update here. The server response is the only source of truth for the counts, and every action is a single round trip on a local network. Adding an optimistic path plus a rollback would be more code and more ways to disagree with the server, for a saving nobody will perceive. If the app is later used over a slow link, revisit this and not before.

- [ ] **Step 3: Replace the emoji bar with reaction pills**

Delete the whole `{{-- Emoji bar + watched --}}` block (the `<div>` from line 311 to line 340) and put this in its place:

```blade
{{-- Reaction pills: one per emoji that somebody actually pressed, count only, never names. --}}
<div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:14px;" x-show="reactionTotal > 0">
    <template x-for="(count, emoji) in reactions" :key="emoji">
        <span class="tot-pill" :data-mine="mine.includes(emoji) ? '1' : null">
            <span x-text="emoji"></span><b x-text="count"></b>
        </span>
    </template>
</div>
```

- [ ] **Step 4: Add the icon row**

Directly after the pills, add:

```blade
<div class="tot-actions">
    <span class="tot-fw">
        <button type="button" class="tot-act" :data-on="mine.length ? '1' : null"
                @click="flyout = flyout === 'react' ? null : 'react'"
                @mouseenter="flyout = 'react'"
                :aria-label="$store.ui.lang==='en' ? 'React to this session' : 'Beri reaksi'">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1.1 1L12 21l7.7-7.6 1.1-1a5.5 5.5 0 0 0 0-7.8z"/></svg>
            <span x-text="reactionTotal || ''"></span>
        </button>
    </span>

    <button type="button" class="tot-act" @click="openThread()"
            :aria-label="$store.ui.lang==='en' ? 'Open comments' : 'Buka komen'">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 8.9 8.9 0 0 1-4-.9L3 21l1.9-4.9A8.4 8.4 0 0 1 12 3.1a8.4 8.4 0 0 1 9 8.4z"/></svg>
        <span x-text="comments || ''"></span>
    </button>

    <button type="button" class="tot-act" :data-on="iWatched ? '1' : null"
            @click="toggleWatched()" x-show="canParticipate"
            :aria-label="$store.ui.lang==='en' ? 'Mark as watched' : 'Tanda sudah tonton'">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        <span x-text="watched || ''"></span>
    </button>

    <span class="tot-fw" x-show="canParticipate">
        <button type="button" class="tot-act" :data-on="myScore ? '1' : null"
                @click="flyout = flyout === 'rate' ? null : 'rate'"
                @mouseenter="flyout = 'rate'"
                :aria-label="$store.ui.lang==='en' ? 'Rate this session' : 'Nilai sesi ini'">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l3.1 6.3 6.9 1-5 4.9 1.2 6.9L12 17.8 5.8 21l1.2-6.9-5-4.9 6.9-1z"/></svg>
            <span x-text="score ? `${score.average} (${score.count})` : ''"></span>
        </button>
    </span>
</div>
```

`openThread()` is defined in Task 6. Until then, add a stub to the `totCard` component so the page does not throw:

```js
    openThread() { this.modalOpen = true; },
```

The star's number renders only when `score` is present, and `sessionState()` decides that. A viewer who may not see scores gets a plain star with no digits.

- [ ] **Step 5: Delete the rating block**

Delete the whole `@if ($canParticipate || $sessionScore)` block (lines 347 to 379), including the score-summary paragraph and its note list.

The average and the count move to the star. **The anonymous note list moves into the comment modal**, built in Task 6, where a presenter reads their feedback in one place. It is not dropped. Do not delete anything here until you have read Task 6, so you know where each piece lands.

- [ ] **Step 6: Add the styles**

In `resources/css/app.css`, next to the existing `.tot-` rules, add:

```css
.tot-pill { display:inline-flex; align-items:center; gap:5px; height:26px; padding:0 9px;
  border-radius:999px; background:var(--hairline-soft); font-size:13px; color:var(--body); }
.tot-pill[data-mine] { background:var(--red-tint); color:var(--brand); }
.tot-pill b { font-weight:600; font-size:12px; color:var(--muted); }

.tot-actions { display:flex; align-items:center; gap:22px; margin-top:12px; padding-top:10px;
  border-top:1px solid var(--hairline-soft); }
.tot-act { display:inline-flex; align-items:center; gap:7px; background:none; border:0; padding:0;
  cursor:pointer; color:var(--muted); font-size:13.5px; }
.tot-act:hover { color:var(--brand); }
.tot-act[data-on] { color:var(--brand); }
```

Use the CSS variables that file already defines. If `--red-tint`, `--hairline-soft`, `--brand`, `--body` or `--muted` are named differently in this codebase, use the real names; grep the file for the ones the existing `.tot-` rules use.

- [ ] **Step 7: Build and look at it**

```bash
bun run build
```

Then open `http://localhost:9100/dev/login?email=employee@amanahku.test&tenant=unijaya` and go to `/app/tot`. Expand a month. Confirm: pills show only for emoji somebody pressed, the icon row renders, clicking the eye toggles it without the page reloading, and the numbers change in place.

- [ ] **Step 8: Run the suite and commit**

```bash
php artisan test --compact --filter=Tot
vendor/bin/pint --dirty --format agent
composer analyse
git add resources/views/screens/tot.blade.php resources/css/app.css public/build
git commit -m "feat(tot): replace the open panel with reaction pills and an icon row

An untouched slot used to spend about 400px rendering an empty thread, an
empty composer and a rating form. It now costs about 100px, and every action
updates the card in place instead of reloading the year lineup."
```

---

## Task 4: The emoji flyout

**Files:**
- Modify: `resources/views/screens/tot.blade.php`, `resources/css/app.css`

**Interfaces:**
- Consumes: `flyout` and `react(emoji)` from the `totCard` component (Task 3), and `TotSession::EMOJI`.
- Produces: nothing.

- [ ] **Step 1: Add the flyout markup**

Inside the `<span class="tot-fw">` that wraps the heart button, directly before the button, add:

```blade
<span class="tot-fly" x-show="flyout === 'react'" x-cloak
      @mouseleave="flyout = null" @keydown.escape.window="flyout = null">
    @foreach (\App\Models\TotSession::EMOJI as $i => $emoji)
        <button type="button" class="tot-fly-e" style="--d:{{ $i * 30 }}ms"
                @click="react(@js($emoji)); flyout = null"
                :data-mine="mine.includes(@js($emoji)) ? '1' : null"
                aria-label="React {{ $emoji }}">{{ $emoji }}</button>
    @endforeach
</span>
```

The `--d` custom property carries the 30ms stagger per emoji, computed at render time from the loop index so the CSS needs no `nth-child` list.

- [ ] **Step 2: Add the styles**

In `resources/css/app.css`:

```css
.tot-fw { position:relative; display:inline-flex; }
.tot-fly { position:absolute; bottom:100%; left:-10px; margin-bottom:10px; display:flex;
  align-items:center; gap:6px; padding:7px 11px; border-radius:999px; background:var(--card);
  border:1px solid var(--hairline); white-space:nowrap; z-index:20;
  animation:tot-fly-in 200ms cubic-bezier(.2,.9,.3,1.35) both; }
@keyframes tot-fly-in { from { opacity:0; transform:translateY(8px) scale(.92); }
  to { opacity:1; transform:none; } }
.tot-fly-e { font-size:23px; line-height:1; background:none; border:0; padding:0; cursor:pointer;
  transform-origin:bottom center; animation:tot-e-in 260ms var(--d) both; }
@keyframes tot-e-in { from { opacity:0; transform:translateY(10px) scale(.5); }
  to { opacity:1; transform:none; } }
.tot-fly-e:hover { transform:scale(1.4) translateY(-5px); transition:transform 140ms cubic-bezier(.2,.9,.3,1.35); }
.tot-fly-e[data-mine] { filter:none; }
.tot-fly-e:not([data-mine]) { filter:grayscale(.35); }

@media (prefers-reduced-motion: reduce) {
  .tot-fly, .tot-fly-e { animation:tot-fade-in 150ms both; }
  .tot-fly-e:hover { transform:none; }
  @keyframes tot-fade-in { from { opacity:0; } to { opacity:1; } }
}
```

Add `[x-cloak] { display:none !important; }` if the file does not already define it. Grep first.

- [ ] **Step 3: Build and check the interaction**

```bash
bun run build
```

At `/app/tot`, hover the heart. The flyout should rise with the six emoji arriving in sequence. Click one; the pill row updates and the flyout closes, with no page reload. Press Escape with the flyout open; it closes. Tab to the heart with the keyboard and press Enter; it opens.

Then turn on the operating system's reduced-motion setting and reload. The flyout should fade only, with no rise and no stagger.

- [ ] **Step 4: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/screens/tot.blade.php resources/css/app.css public/build
git commit -m "feat(tot): react through a hover flyout instead of six always-on buttons

Opens on hover, on focus and on tap. Press and hold was considered and
dropped: it fights the iOS selection callout and the Android context menu,
and unlike a social feed the heart has no default action a tap would take."
```

---

## Task 5: The rating flyout

**Files:**
- Modify: `resources/views/screens/tot.blade.php`, `resources/css/app.css`

**Interfaces:**
- Consumes: `flyout`, `myScore`, `myNote`, `rate(score)` and `saveNote(note)` from `totCard`.
- Produces: nothing.

- [ ] **Step 1: Add the flyout markup**

Inside the `<span class="tot-fw">` that wraps the star, directly before the button, add:

```blade
<span class="tot-fly" x-show="flyout === 'rate'" x-cloak
      @mouseleave="flyout = null" @keydown.escape.window="flyout = null"
      x-data="{ noting: false }">
    <template x-if="!noting">
        <span style="display:flex;align-items:center;gap:6px;">
            @foreach ([1, 2, 3, 4, 5] as $n)
                <button type="button" class="tot-sc" :data-mine="myScore === {{ $n }} ? '1' : null"
                        @click="rate({{ $n }}); noting = true">{{ $n }}</button>
            @endforeach
            <span class="tot-note" style="font-size:11.5px;padding-left:4px;max-width:210px;"
                  x-text="$store.ui.lang==='en' ? @js('Only '.($session->presenter?->name ?? $session->presenter_name ?? 'the presenter').' and management see scores, and never with your name.') : @js('Hanya '.($session->presenter?->name ?? $session->presenter_name ?? 'pembentang').' dan pengurusan nampak skor, dan tidak sekali dengan nama anda.')">Only {{ $session->presenter?->name ?? $session->presenter_name ?? 'the presenter' }} and management see scores, and never with your name.</span>
        </span>
    </template>
    <template x-if="noting">
        <span style="display:flex;align-items:center;gap:6px;">
            <input type="text" maxlength="1000" class="tot-field" style="height:30px;width:210px;"
                   :value="myNote"
                   :placeholder="$store.ui.lang==='en' ? 'Add a note, optional' : 'Tambah nota, pilihan'"
                   @keydown.enter.prevent="saveNote($event.target.value); flyout = null; noting = false"
                   @blur="saveNote($event.target.value); noting = false">
        </span>
    </template>
</span>
```

The score saves on the click, before the note box appears. Somebody who picks a number and walks away has still rated. The note saves on Enter or on blur, and it is prefilled from `myNote` so an existing note can be edited rather than retyped.

### The prefill reverses an existing decision, deliberately

The code this replaces carried the opposite rule, and it said so:

> Deliberately never prefilled with the rater's own note text: ratings are pseudonymous even to their own author, so the screen never echoes a note back.

That is now overruled by product decision: a rater sees and edits their own note. `sessionState()` returns `myNote` only to its own author, and no view ever renders another person's note beside a name, so the promise that matters, that nobody can tie a note to a person, is unchanged.

**Three things must move together with the prefill.** Doing only some of them leaves the feature incoherent.

**1. Restore the page seed.** `resources/views/screens/tot.blade.php` currently has `myNote: null` in the `totCard(...)` seed with a comment explaining why. Replace both the value and the comment:

```blade
                        myScore: @js($myPart?->score),
                        myNote: @js($myPart?->note),
```

**2. Change what a blank note means in `TotController::rate()`.** Today it uses `$request->filled('note')`, so an empty submit preserves the saved note. That was correct only because no field existed to resubmit from. With a prefilled box, an empty box is a deliberate clear, and this is also what fixes the recorded limitation that a rater can never clear a note.

The rule is: if the request carries the `note` key, take it verbatim, including empty, which becomes null. If the key is absent, leave the note untouched. The absent case is load-bearing, because the flyout's score buttons post a score with no note and must not wipe anything.

Replace both occurrences in `rate()`, the one in the main path and the one in the race-recovery catch, with:

```php
        if ($request->has('note')) {
            $row->note = $request->input('note') === '' ? null : $data['note'];
        }
```

Update the long comment above the first one. It currently explains why a blank submit preserves the note. Replace that reasoning with: the box is prefilled from the rater's own note, so a blank box now means clear it, while a score-only submit from the flyout carries no note key at all and leaves the note alone.

**3. Fix the test that this changes.** `test_a_plain_employee_never_receives_scores` in `tests/Feature/TotTest.php` puts the viewer's own note on the session and asserts `assertDontSee('Very good')`. The viewer's own note now appears in the page on purpose, so that assertion becomes wrong.

Do NOT weaken it by moving the note onto a different employee, which is what a previous attempt did. Cover both halves instead:

```php
    public function test_a_plain_employee_never_receives_scores(): void
    {
        $other = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Presenter',
            'status' => 'active', 'workload' => 'green',
        ]);
        $session = $this->makeSession(['presenter_employee_id' => $other->id]);
        TotParticipation::create([
            'tenant_id' => $this->tenant->id, 'session_id' => $session->id,
            'employee_id' => $this->employee->id, 'score' => 5, 'note' => 'My own words',
        ]);
        TotParticipation::create([
            'tenant_id' => $this->tenant->id, 'session_id' => $session->id,
            'employee_id' => $other->id, 'score' => 2, 'note' => 'Somebody elses words',
        ]);

        $response = $this->actingInTenant()->get('/app/tot?year=2026');

        // No aggregate, and never another person's words.
        $response->assertViewHas('scores', fn ($scores) => $scores === []);
        $response->assertDontSee('Somebody elses words');

        // Their own note comes back so the flyout can prefill it for editing.
        $response->assertSee('My own words', false);
    }
```

Add a test for the clear path too:

```php
    public function test_a_rater_can_clear_their_own_note(): void
    {
        $session = $this->makeSession();
        $this->actingInTenant()->post("/app/tot/{$session->id}/rate", ['score' => 4, 'note' => 'First thoughts']);

        $this->actingInTenant()->post("/app/tot/{$session->id}/rate", ['score' => 4, 'note' => '']);

        $row = TotParticipation::where('session_id', $session->id)
            ->where('employee_id', $this->employee->id)->firstOrFail();
        $this->assertNull($row->note);
    }

    public function test_a_score_only_submit_leaves_an_existing_note_alone(): void
    {
        $session = $this->makeSession();
        $this->actingInTenant()->post("/app/tot/{$session->id}/rate", ['score' => 4, 'note' => 'Keep me']);

        $this->actingInTenant()->post("/app/tot/{$session->id}/rate", ['score' => 2]);

        $row = TotParticipation::where('session_id', $session->id)
            ->where('employee_id', $this->employee->id)->firstOrFail();
        $this->assertSame('Keep me', $row->note);
        $this->assertSame(2, (int) $row->score);
    }
```

- [ ] **Step 2: Add the styles**

```css
.tot-sc { width:30px; height:30px; border-radius:50%; border:1px solid var(--hairline);
  background:none; font-size:13.5px; color:var(--body); cursor:pointer;
  transition:transform 140ms cubic-bezier(.2,.9,.3,1.35), border-color 140ms ease; }
.tot-sc:hover { transform:scale(1.15); border-color:var(--brand); color:var(--brand); }
.tot-sc[data-mine] { border-color:var(--brand); color:var(--brand); font-weight:600; }

@media (prefers-reduced-motion: reduce) { .tot-sc:hover { transform:none; } }
```

- [ ] **Step 3: Build and check**

```bash
bun run build
```

At `/app/tot`, hover the star as an ordinary employee. Pick a 4. The flyout should swap to the note box in place. Type a note and press Enter; the flyout closes. Hover the star again; the 4 is marked as yours and the note is prefilled.

Then sign in as the presenter of that slot at `http://localhost:9100/dev/login?email=<their account>&tenant=unijaya` and confirm the star now shows an average and a count. Sign in as an ordinary employee who is not the presenter and confirm the star shows no digits.

- [ ] **Step 4: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/screens/tot.blade.php resources/css/app.css public/build
git commit -m "feat(tot): rate from a flyout that swaps to a note box in place

The score saves on the click, so picking a number and walking away still
counts. The note box replaces the five circles rather than sending the rater
to another surface, because most people never write one."
```

---

## Task 6: The comment modal

**Files:**
- Modify: `resources/views/screens/tot.blade.php`, `resources/css/app.css`

**Interfaces:**
- Consumes: the `tot.comments` GET route and its JSON shape from Task 2, and `modalOpen`, `thread`, `comments` and `act()` from `totCard`.
- Produces: `openThread()`, `postComment(body)` and `removeComment(id)` on `totCard`.

- [ ] **Step 1: Replace the openThread stub**

In the `totCard` component, replace the stub from Task 3 with:

```js
    async openThread() {
        this.modalOpen = true;
        if (this.thread !== null) return;
        try {
            const res = await fetch(`/app/tot/${this.id}/comments`, {
                headers: { 'Accept': 'application/json' },
            });
            if (!res.ok) throw new Error(res.status);
            const payload = await res.json();
            this.thread = payload.comments;
            this.notes = payload.notes;
        } catch (e) {
            this.thread = [];
            this.notes = [];
            Alpine.store('toast').error(
                Alpine.store('ui').lang === 'en'
                    ? 'Could not load the discussion.'
                    : 'Tidak dapat memuatkan perbincangan.'
            );
        }
    },

    async postComment(body) {
        if (!body.trim()) return;
        await this.act(`/app/tot/${this.id}/comment`, { body });
        this.thread = null;
        await this.openThread();
    },

    async removeComment(id) {
        if (this.busy) return;
        this.busy = true;
        try {
            const res = await fetch(`/app/tot/comments/${id}`, {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    'Accept': 'application/json',
                },
            });
            if (!res.ok) throw new Error(res.status);
            Object.assign(this, await res.json());
            this.thread = this.thread.filter((c) => c.id !== id);
        } catch (e) {
            Alpine.store('toast').error(
                Alpine.store('ui').lang === 'en' ? 'Could not remove that.' : 'Tidak dapat membuang.'
            );
        } finally {
            this.busy = false;
        }
    },
```

`postComment` refetches rather than appending locally, because the server owns the ordering, the formatted date and the `canDelete` flag. One extra request per comment posted is a fair price for never rendering a row the server did not build.

- [ ] **Step 2: Replace the comment placeholder with the modal**

Delete the placeholder line added in Task 2 (the `<div class="tot-note">{{ $sessionCommentCount }} ...` one). Add the modal at the end of the panel, after the edit form:

```blade
<div class="tot-modal" x-show="modalOpen" x-cloak @keydown.escape.window="modalOpen = false">
    <div class="tot-modal-back" @click="modalOpen = false"></div>
    <div class="tot-modal-card" role="dialog" aria-modal="true"
         :aria-label="$store.ui.lang==='en' ? 'Discussion' : 'Perbincangan'">
        <div class="tot-modal-head">
            <span style="flex:1;font-size:14.5px;color:var(--ink);">{{ $session->title ?: ($session->session_date->format('F Y')) }}</span>
            <button type="button" class="tot-act" @click="modalOpen = false"
                    :aria-label="$store.ui.lang==='en' ? 'Close' : 'Tutup'">&times;</button>
        </div>

        <div class="tot-modal-body">
            {{-- Anonymous rating notes. Present only for a viewer the server decided may see
                 scores, which is the presenter and management. Never a name, never a score
                 beside a note. This is where the note list from the old card lives now. --}}
            <template x-if="notes.length">
                <div style="margin-bottom:16px;padding-bottom:14px;border-bottom:1px solid var(--hairline-soft);">
                    <div class="tot-note" style="margin-bottom:7px;"
                         x-text="$store.ui.lang==='en' ? 'Anonymous notes from raters' : 'Nota tanpa nama daripada penilai'">Anonymous notes from raters</div>
                    <template x-for="(n, i) in notes" :key="i">
                        <div style="font-size:13.5px;color:var(--body);margin-bottom:5px;" x-text="n"></div>
                    </template>
                </div>
            </template>

            <template x-if="thread === null">
                <div class="tot-note" x-text="$store.ui.lang==='en' ? 'Loading' : 'Memuatkan'">Loading</div>
            </template>
            <template x-if="thread !== null && thread.length === 0">
                <div class="tot-note" x-text="$store.ui.lang==='en' ? 'No comments yet. Start the discussion.' : 'Belum ada komen. Mulakan perbincangan.'">No comments yet. Start the discussion.</div>
            </template>
            <template x-for="c in (thread || [])" :key="c.id">
                <div style="display:flex;gap:11px;margin-bottom:16px;">
                    <div class="tot-av" :style="`background:${c.color};color:#fff;`" x-text="c.initials"></div>
                    <div style="min-width:0;flex:1;">
                        <div style="display:flex;align-items:baseline;gap:8px;flex-wrap:wrap;">
                            <span style="font-size:13.5px;font-weight:600;color:var(--ink);" x-text="c.name"></span>
                            <span class="tot-presenter-tag" x-show="c.presenter"
                                  x-text="$store.ui.lang==='en' ? 'Presenter' : 'Pembentang'">Presenter</span>
                            <span class="tot-note" style="font-size:12px;" x-text="c.at"></span>
                            <button type="button" x-show="c.canDelete" style="margin-left:auto;font-size:11px;color:var(--muted);background:none;border:0;cursor:pointer;"
                                    @click="removeComment(c.id)"
                                    :aria-label="$store.ui.lang==='en' ? 'Remove comment' : 'Buang komen'">&times;</button>
                        </div>
                        <div style="font-size:13.5px;color:var(--body);margin-top:2px;" x-text="c.body"></div>
                    </div>
                </div>
            </template>
        </div>

        @if ($canParticipate)
            <div class="tot-modal-foot">
                <input type="text" maxlength="2000" class="tot-field" x-ref="composer"
                       :placeholder="$store.ui.lang==='en' ? 'Ask a question or add what you learned' : 'Tanya soalan atau kongsi apa yang anda pelajari'"
                       @keydown.enter.prevent="postComment($event.target.value); $event.target.value = ''">
                <button type="button" class="tot-btn-p" style="height:34px;font-size:12.5px;"
                        @click="postComment($refs.composer.value); $refs.composer.value = ''"
                        x-text="$store.ui.lang==='en' ? 'Post' : 'Hantar'">Post</button>
            </div>
        @endif
    </div>
</div>
```

- [ ] **Step 3: Add the styles**

```css
.tot-modal { position:fixed; inset:0; z-index:60; display:flex; align-items:center;
  justify-content:center; padding:18px; }
.tot-modal-back { position:absolute; inset:0; background:rgba(0,0,0,.45);
  animation:tot-fade-in 150ms both; }
.tot-modal-card { position:relative; width:100%; max-width:460px; max-height:80vh;
  display:flex; flex-direction:column; background:var(--card); border-radius:14px;
  overflow:hidden; animation:tot-modal-in 180ms cubic-bezier(.2,.9,.3,1.35) both; }
@keyframes tot-modal-in { from { opacity:0; transform:scale(.96); } to { opacity:1; transform:none; } }
.tot-modal-head { display:flex; align-items:center; gap:10px; padding:12px 16px;
  border-bottom:1px solid var(--hairline-soft); }
.tot-modal-body { padding:14px 16px; overflow-y:auto; flex:1; }
.tot-modal-foot { display:flex; gap:8px; padding:12px 16px;
  border-top:1px solid var(--hairline-soft); }

@media (prefers-reduced-motion: reduce) {
  .tot-modal-card { animation:tot-fade-in 150ms both; }
}
```

`position: fixed` is correct here. The rule against it applies to the design-mockup tool, not to the app.

- [ ] **Step 4: Build and check**

```bash
bun run build
```

At `/app/tot`: press the speech bubble. The modal opens, shows a loading line, then the thread. Post a comment; it appears and the count on the card goes up without a page reload. Delete your own comment; it disappears and the count goes down. Press Escape; the modal closes. Click the backdrop; it closes. Open a month nobody has commented on; the empty state reads correctly in both languages.

Check the `ui` store's language toggle while the modal is open, and confirm every string switches.

Then sign in as the presenter of a rated slot and open the modal. The anonymous notes must appear above the thread, with no name and no score beside any of them. Sign in as an ordinary employee who is not the presenter, open the same modal, and confirm the notes section is not there at all.

- [ ] **Step 5: Run the suite and commit**

```bash
php artisan test --compact --filter=Tot
vendor/bin/pint --dirty --format agent
composer analyse
git add resources/views/screens/tot.blade.php resources/css/app.css public/build
git commit -m "feat(tot): move the discussion into a modal that loads on open

The thread and its composer used to render inside every expanded card, which
is why a slot with no comments still cost 200px to say so. Posting refetches
rather than appending locally, because the server owns the ordering, the date
format and who may delete a row."
```

---

## Task 7: Whole-screen check

**Files:**
- Modify: whatever the checks below turn up.
- Test: `tests/Feature/TotLiveActionsTest.php`

**Interfaces:**
- Consumes: everything above.
- Produces: nothing.

- [ ] **Step 1: Add the no-JavaScript regression**

Append to `tests/Feature/TotLiveActionsTest.php`:

```php
    public function test_every_action_still_works_as_a_plain_form_post(): void
    {
        $session = $this->slot();

        $this->actingInTenant()->post("/app/tot/{$session->id}/react", ['emoji' => '👍'])->assertRedirect();
        $this->actingInTenant()->post("/app/tot/{$session->id}/watched")->assertRedirect();
        $this->actingInTenant()->post("/app/tot/{$session->id}/rate", ['score' => 3])->assertRedirect();
        $this->actingInTenant()->post("/app/tot/{$session->id}/comment", ['body' => 'Plain post'])->assertRedirect();

        $this->assertSame(1, \App\Models\TotComment::where('session_id', $session->id)->count());
        $this->assertSame(3, \App\Models\TotParticipation::where('session_id', $session->id)->value('score'));
    }
```

This is the test that stops somebody deleting the redirect branch in six months because "everything is fetch now".

- [ ] **Step 2: Run the whole suite**

Run: `php artisan test --compact`

Expected: PASS, with the same skip count the branch started with.

- [ ] **Step 3: Check the screen at both breakpoints**

Open `/app/tot` at a desktop width and at 375px wide. Confirm at the narrow width: the icon row does not wrap into something unreadable, the flyouts stay inside the viewport rather than being clipped at the screen edge, and the modal fills sensibly rather than sitting as a small box.

If a flyout is clipped at the left edge on a narrow screen, change `.tot-fly { left:-10px; }` to `left:0; right:auto;` inside a `@media (max-width: 480px)` block rather than repositioning it with JavaScript.

- [ ] **Step 4: Check keyboard and screen reader basics**

Tab through one expanded card. Every action must be reachable and must show a visible focus ring. The modal must take focus when it opens and must return focus to the speech bubble when it closes. If it does not, add `x-trap` only if Alpine's focus plugin is already installed in this project; check `resources/js/app.js` first. If it is not installed, set focus manually with `$refs` rather than adding a dependency.

- [ ] **Step 5: Confirm the counts are honest**

As an employee, react, then rate, then comment on the same slot, one after another with no page reload between them. Then reload the page and confirm every number on the card matches what the live updates showed. A mismatch means `sessionState()` and `screenData()` disagree, which is exactly the drift `sessionState()` exists to prevent.

- [ ] **Step 6: Final format, analyse and commit**

```bash
vendor/bin/pint --format agent
composer analyse
php artisan test --compact
git add -A
git commit -m "test(tot): pin the no-JavaScript path so the redirect branch survives

Every action has a fetch path now, which makes the redirect branch look dead.
It is not: it is what keeps the screen usable without JavaScript."
```

---

## Verification before handing back

Run all of these and paste the real output:

```bash
php artisan test --compact
composer analyse
vendor/bin/pint --format agent
git status --short
```

Expected: the suite passes with the same skip count the branch started with, PHPStan reports `"errors":0`, Pint reports `"result":"passed"`, and the working tree is clean.

Confirm `public/build` is committed. The staging host has no Node, so an uncommitted build means CSS and JS 404 after deploy.

Do not deploy. Leave the work committed on `dev`.
