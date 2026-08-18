# Team Board Assign Task Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let manager/management/HR assign a task to any active staff member directly from the Team board screen, without navigating to that person's profile page.

**Architecture:** Reuse the existing `POST /app/board/assign/{employee}` route/controller (`WorkItemController::assign()`) unchanged. Team board's own data builder gains a permission flag and an assignable-employee roster; the Blade template gets two trigger buttons and one shared modal (a classic HTML form, not a fetch — team board's rows/window are rendered once per page load, so a normal redirect-on-submit is the simplest way to show the new card, same cost the existing profile-page assign flow already pays). The modal is styled with the card drawer's existing `.wd-*` classes for visual consistency, but does not reuse the drawer's autosave-per-field JS (that assumes an already-saved card; this form creates one in a single POST, same shape `assign()` already accepts).

**Tech Stack:** Laravel 13 (Blade, no new routes/controllers), Alpine.js (existing `teamBoard()` component in `resources/js/team-board.js`), plain CSS (`resources/css/app.css`), PHPUnit feature tests.

## Global Constraints

- No new routes, no `WorkItemController` changes — `work.assign` (`POST /app/board/assign/{employee}`) is reused exactly as-is.
- Assign-permitted roles: `manager`, `management`, `hr` (director folds into `management` via `Permissions::effectiveRole()`) — matches `WorkItemController::ASSIGNER_ROLES` and the profile page's existing `canAssign` check exactly. Use `$this->hasTenantRole($request, ['manager', 'management', 'hr'])`.
- Team board's person table (`$teamPeople`/`$teamRows`) stays scoped to people who already carry a task — do not expand it to every active employee. The "assign to anyone" roster is a separate, unscoped list (see Task 1).
- No live DOM patching of the new card into the table/window after a successful assign — a normal page reload (classic form POST) is the intended behavior.
- New CSS gets its own classes (`.tb-assign-scrim`, `.tb-assign-modal`) — never reuse or bump the shared `.wd-scrim`/`.wd`/`.tb-win-modal` z-index values (past incident: those three overlays across two screens already collide if a bare z-index changes).
- Run `vendor/bin/pint --dirty --format agent` after any PHP edit (project convention).
- Spec: `docs/superpowers/specs/2026-08-17-team-board-assign-task-design.md`.

---

## Task 1: Team board payload — `canAssign` flag and assignable-employee roster

**Files:**
- Modify: `app/Http/Controllers/Concerns/BuildsWorkData.php:185-247` (`teamBoardData()`)
- Test: `tests/Feature/TeamBoardDataTest.php`

**Interfaces:**
- Consumes: `Employee::active()` scope (excludes archived), `Employee::$display_name`/`$initials`/`$avatar_color` accessors, `Controller::hasTenantRole()` (already available via `AppController extends Controller`, and `BuildsWorkData` is mixed into it).
- Produces: two new keys in `teamBoardData()`'s return array — `canAssign` (bool) and `assignableEmployees` (`Illuminate\Support\Collection` of `['id' => int, 'name' => string, 'initials' => ?string, 'color' => ?string]`), consumed by Task 2's Blade template.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/TeamBoardDataTest.php`, inside the `TeamBoardDataTest` class (after `test_data_scope_still_applies`, before the closing `}`):

```php
    public function test_can_assign_flag_true_for_manager(): void
    {
        $this->makeCard($this->managerEmployee);

        $response = $this->actingAsManager()->get('/app/team-board');
        $response->assertOk();

        $this->assertTrue($response->viewData('canAssign'));
    }

    /**
     * An 'employee'-role user with a direct report can still see team board
     * (Permissions::canSeeAll()'s reports_to_id clause), but is not one of
     * the three assign-permitted roles — the button must stay hidden for them.
     */
    public function test_can_assign_flag_false_for_lead_without_assign_role(): void
    {
        $leadUser = User::create(['name' => 'Lead', 'email' => 'lead@example.com', 'password' => Hash::make('password')]);
        $leadUser->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $lead = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $leadUser->id,
            'name' => 'Lead', 'status' => 'active', 'workload' => 'green',
        ]);
        $this->makeEmployee('Report', ['reports_to_id' => $lead->id]);
        $this->makeCard($lead);

        $response = $this->actingAs($leadUser)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/team-board');
        $response->assertOk();

        $this->assertFalse($response->viewData('canAssign'));
    }

    public function test_assignable_employees_are_active_and_exclude_self(): void
    {
        $alice = $this->makeEmployee('Alice');
        $archived = $this->makeEmployee('Gone', ['archived_at' => now()]);
        $this->makeCard($this->managerEmployee);

        $response = $this->actingAsManager()->get('/app/team-board');
        $response->assertOk();

        $roster = $response->viewData('assignableEmployees');
        $ids = $roster->pluck('id')->all();

        $this->assertContains($alice->id, $ids);
        $this->assertNotContains($archived->id, $ids, 'Archived staff must not be assignable');
        $this->assertNotContains($this->managerEmployee->id, $ids, 'Viewer must not appear in their own assign-to roster');
    }

    /**
     * The roster is deliberately NOT restricted to people already carrying a
     * task — that's the whole point (assign to someone with zero open items).
     */
    public function test_assignable_employees_include_people_with_no_current_tasks(): void
    {
        $noTasks = $this->makeEmployee('Fresh Hire');
        $this->makeCard($this->managerEmployee);

        $response = $this->actingAsManager()->get('/app/team-board');
        $response->assertOk();

        $roster = $response->viewData('assignableEmployees');
        $this->assertContains($noTasks->id, $roster->pluck('id')->all());

        // Confirm the person table itself stays as-is (this employee has no row there).
        $teamPeople = $response->viewData('teamPeople');
        $this->assertNotContains($noTasks->id, $teamPeople->pluck('id')->all());
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=TeamBoardDataTest`
Expected: FAIL — `canAssign`/`assignableEmployees` view data keys don't exist yet (null, so `assertTrue`/`->pluck()` on null fails).

- [ ] **Step 3: Implement**

In `app/Http/Controllers/Concerns/BuildsWorkData.php`, find `teamBoardData()`'s `return` statement (currently lines 241-246):

```php
        return [
            'teamRows' => $teamRows,
            'teamPeople' => $teamPeople,
            'teamOpenTotal' => $teamPeople->sum('open'),
            'teamPeopleCount' => $teamPeople->count(),
        ];
    }
```

Replace with:

```php
        return [
            'teamRows' => $teamRows,
            'teamPeople' => $teamPeople,
            'teamOpenTotal' => $teamPeople->sum('open'),
            'teamPeopleCount' => $teamPeople->count(),
            // Same three roles WorkItemController::ASSIGNER_ROLES and the profile
            // page's own "Assign task" button check — director included, via
            // hasTenantRole()'s effectiveRole() fold.
            'canAssign' => $this->hasTenantRole($request, ['manager', 'management', 'hr']),
            // Every active employee, tenant-wide, minus the viewer themselves —
            // deliberately NOT the DataScope-restricted $employees above, and
            // deliberately not limited to people already in $teamPeople (a
            // brand-new hire with zero tasks must still be assignable). This
            // matches assign()'s own authorization boundary exactly: role + tenant
            // + not-archived, no DataScope check — see the design doc's "Data"
            // section for why a narrower roster here would just be confusing.
            'assignableEmployees' => Employee::active()
                ->where('id', '!=', $self?->id)
                ->orderBy('name')
                ->get(['id', 'name', 'nickname', 'initials', 'avatar_color'])
                ->map(fn (Employee $e) => ['id' => $e->id, 'name' => $e->display_name, 'initials' => $e->initials, 'color' => $e->avatar_color])
                ->values(),
        ];
    }
```

`Employee` is already imported at the top of this file (`use App\Models\Employee;`), and `$self` is already defined earlier in `teamBoardData()` (`$self = $request->attributes->get('employee');`) — no new imports or variables needed.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=TeamBoardDataTest`
Expected: PASS (all 7 tests in the file, including the 3 pre-existing ones untouched).

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Concerns/BuildsWorkData.php tests/Feature/TeamBoardDataTest.php
git commit -m "feat(team-board): add canAssign flag and assignable-employee roster"
```

---

## Task 2: Assign-task UI — buttons, modal, CSS, JS state

**Files:**
- Modify: `resources/css/app.css` (near `.tb-win-modal`, lines 907-929)
- Modify: `resources/js/team-board.js`
- Modify: `resources/views/screens/team-board.blade.php`
- Test: `tests/Feature/TeamBoardScreenTest.php`

**Interfaces:**
- Consumes: `canAssign` (bool) and `assignableEmployees` (collection of `{id, name, initials, color}`) from Task 1.
- Produces: `teamBoard()` Alpine component gains an `assign` state object (`{show, open, employeeId, trigger, _closeTimer}`) and two methods, `openAssign(employeeId = null, triggerEl = null)` and `closeAssign()` — not consumed by any later task in this plan, but this is the public surface if the feature is extended later.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/TeamBoardScreenTest.php`, inside the `TeamBoardScreenTest` class (after `test_plain_employee_with_no_direct_reports_gets_403`, before the closing `}`):

```php
    public function test_assign_button_and_modal_render_for_assign_permitted_role(): void
    {
        $alice = $this->makeEmployee('Alice');
        $this->makeCard($alice);

        $response = $this->actingAsManager()->get('/app/team-board');
        $response->assertOk();

        $html = $response->getContent();
        $this->assertStringContainsString('tb-assign-modal', $html);
        $this->assertStringContainsString('openAssign(', $html);
        // The roster's <option> for a person with no current tasks must be present too.
        $bob = $this->makeEmployee('Bob');
        $response = $this->actingAsManager()->get('/app/team-board');
        $this->assertStringContainsString('value="'.$bob->id.'"', $response->getContent());
    }

    public function test_assign_button_absent_for_viewer_without_assign_role(): void
    {
        $leadUser = User::create(['name' => 'Lead', 'email' => 'lead3@example.com', 'password' => Hash::make('password')]);
        $leadUser->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $lead = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $leadUser->id,
            'name' => 'Lead', 'status' => 'active', 'workload' => 'green',
        ]);
        $this->makeEmployee('Report', ['reports_to_id' => $lead->id]);
        $this->makeCard($lead);

        $response = $this->actingAs($leadUser)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/team-board');
        $response->assertOk();

        $this->assertStringNotContainsString('tb-assign-modal', $response->getContent());
    }

    public function test_guide_copy_no_longer_calls_the_screen_read_only(): void
    {
        $this->makeCard($this->managerEmployee);

        $response = $this->actingAsManager()->get('/app/team-board');
        $response->assertOk();

        $this->assertStringNotContainsString('read-only', strtolower($response->getContent()));
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=TeamBoardScreenTest`
Expected: FAIL — `tb-assign-modal`/`openAssign(` not in the markup yet; guide copy still says "read-only".

- [ ] **Step 3: Add the CSS shell**

In `resources/css/app.css`, immediately after the `.tb-win-modal:focus { outline: none; }` rule (currently line 929, right before the `.wd-head {` block), insert:

```css

/* Assign-task modal (team board): can be open while the person window
   (.tb-win-modal) is also open — its own "Assign task" button opens this on
   top of it. That's a real nested-overlay case, not hypothetical, so this
   gets its own scrim/shell classes above the existing .wd-scrim/.wd/
   .tb-win-modal stack (all z-index 60/61) rather than reusing them — see
   "Board .wd z-index trap" for why three overlays sharing one z-index once
   collided. Same 280ms cubic-bezier(.32,.72,0,1) timing as the rest of this
   family, just narrower: one form, not a 4-column kanban. */
.tb-assign-scrim {
    position: fixed; inset: 0; background: rgba(31,30,26,.18); z-index: 62;
    opacity: 0; transition: opacity 260ms cubic-bezier(.32,.72,0,1);
}
.tb-assign-scrim[data-open] { opacity: 1; }
.tb-assign-modal {
    position: fixed; top: 50%; left: 50%; z-index: 63;
    width: min(94vw, 480px); max-height: 86vh; overflow-y: auto;
    background: var(--card); border: 1px solid var(--hairline); border-radius: 14px;
    box-shadow: 0 28px 64px rgba(31,30,26,.22);
    transform: translate(-50%, -50%) scale(.96); opacity: 0;
    transition: transform 280ms cubic-bezier(.32,.72,0,1), opacity 280ms cubic-bezier(.32,.72,0,1);
}
.tb-assign-modal[data-open] { transform: translate(-50%, -50%) scale(1); opacity: 1; }
.tb-assign-modal:focus { outline: none; }
```

- [ ] **Step 4: Add Alpine state to `team-board.js`**

In `resources/js/team-board.js`, change the factory signature (currently line 27):

```js
    Alpine.data('teamBoard', (people = []) => ({
```

to:

```js
    Alpine.data('teamBoard', (people = [], assignInit = { defaultId: null, show: false, employeeId: null }) => ({
```

Then, immediately after the existing `winVisibleCount: 0,` line (currently line 62), insert:

```js

        // ── Assign-task modal: reachable from the top-of-page button (any
        // active employee) or a person window's header button (that person
        // preselected). `show`/`open` follow the exact two-stage dance `win`
        // uses above (mount, then a frame later flip the transform/opacity
        // state) so the 280ms CSS transition has something to animate from.
        // `assignInit.show`/`employeeId` come from a validation error
        // ($errors->getBag('assign')) reopening the modal on page reload —
        // in that case it should just appear already-open, no animation.
        assign: {
            show: assignInit.show,
            open: assignInit.show,
            employeeId: assignInit.employeeId ?? assignInit.defaultId,
            trigger: null,
            _closeTimer: null,
        },
```

Then, immediately after the existing `closeWindow() { ... }` method (currently lines 207-216, ending `},`), insert:

```js

        // employeeId omitted (top-of-page button): falls back to the
        // roster's first person. Provided (window header button): always
        // wins, even if a previous open left a different person selected.
        openAssign(employeeId = null, triggerEl = null) {
            this.assign.employeeId = employeeId ?? assignInit.defaultId;
            this.assign.trigger = triggerEl;
            clearTimeout(this.assign._closeTimer);
            this.assign.show = true;
            this.$nextTick(() => requestAnimationFrame(() => {
                this.assign.open = true;
            }));
            this.$nextTick(() => {
                this.$refs.assignTitleEl?.focus({ preventScroll: true });
            });
        },

        closeAssign() {
            if (!this.assign.show) return;
            this.assign.open = false;
            const trigger = this.assign.trigger;
            clearTimeout(this.assign._closeTimer);
            this.assign._closeTimer = setTimeout(() => {
                this.assign.show = false;
                trigger?.focus?.({ preventScroll: true });
            }, 280);
        },
```

Finally, guard the person window's own Escape handler so Escape closes the (topmost) assign modal first when both are open — this is a Blade-side change (the `@keydown.escape.window` attribute lives in `team-board.blade.php`, not this file), done in Step 5 below.

- [ ] **Step 5: Update `team-board.blade.php`**

**5a. Pass the new constructor argument.** Change the root `x-data` (currently line 65):

```blade
<div x-data="teamBoard(@js($tbPeopleByOpen))">
```

to:

```blade
<div x-data="teamBoard(@js($tbPeopleByOpen), @js([
    'defaultId' => $assignableEmployees->first()['id'] ?? null,
    'show' => $errors->getBag('assign')->any(),
    'employeeId' => old('employee_id') ? (int) old('employee_id') : null,
]))">
```

**5b. Top-of-page button.** Replace the block at the top of the file (currently lines 7-11):

```blade
<div style="display:flex;justify-content:flex-end;margin-bottom:14px;">
    <a href="{{ route('app.screen', 'board') }}" class="uj-btn-ghost" style="font-size:12px;padding:7px 12px;text-decoration:none;">
        <span x-text="$store.ui.lang==='en' ? '← My tasks' : '← Tugasan saya'">← My tasks</span>
    </a>
</div>
```

with:

```blade
<div style="display:flex;justify-content:flex-end;gap:8px;margin-bottom:14px;">
    @if (($canAssign ?? false) && $assignableEmployees->isNotEmpty())
        <button type="button" class="uj-btn-primary" style="font-size:12px;padding:7px 14px;" @click="openAssign(null, $event.currentTarget)">
            <span x-text="$store.ui.lang==='en' ? '+ Assign task' : '+ Beri tugas'">+ Assign task</span>
        </button>
    @endif
    <a href="{{ route('app.screen', 'board') }}" class="uj-btn-ghost" style="font-size:12px;padding:7px 12px;text-decoration:none;">
        <span x-text="$store.ui.lang==='en' ? '← My tasks' : '← Tugasan saya'">← My tasks</span>
    </a>
</div>
```

**5c. Guide copy.** In the `@include('partials.guide', ...)` call, change the EN `body` (currently line 16):

```
        'body'  => 'A read-only, company-wide view of every staff member\'s work: one row per person, showing what they are carrying. Click a person to see their tasks in a window, without leaving this screen.',
```

to:

```
        'body'  => 'A company-wide view of every staff member\'s work: one row per person, showing what they are carrying. Click a person to see their tasks in a window, without leaving this screen.',
```

and the MS `body` (currently line 28):

```
        'body'  => 'Paparan baca-sahaja seluruh syarikat bagi kerja setiap staf: satu baris bagi setiap orang, menunjukkan apa yang mereka pikul. Klik seseorang untuk lihat tugasan mereka dalam satu tetingkap, tanpa perlu tinggalkan skrin ini.',
```

to:

```
        'body'  => 'Paparan seluruh syarikat bagi kerja setiap staf: satu baris bagi setiap orang, menunjukkan apa yang mereka pikul. Klik seseorang untuk lihat tugasan mereka dalam satu tetingkap, tanpa perlu tinggalkan skrin ini.',
```

**5d. Window header button.** In the floating window's `wd-head` block (currently lines 181-191):

```blade
            <div class="wd-head" style="gap:12px;">
                <span class="tb-av" :style="'background:' + (win.person ? (win.person.avatar_color || 'var(--muted)') : 'var(--muted)')"
                      x-text="win.person ? win.person.initials : ''"></span>
                <div style="min-width:0;flex:1;">
                    <h2 id="tb-win-name" class="wd-title" style="margin:0;font-size:16px;" x-text="win.person ? win.person.name : ''"></h2>
                    <p class="wd-sub" style="margin:2px 0 0;" x-text="winPersonSub"></p>
                </div>
                <button type="button" class="wd-ico" @click="closeWindow()" :aria-label="$store.ui.lang==='en' ? 'Close' : 'Tutup'">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                </button>
            </div>
```

add an assign button before the close button:

```blade
            <div class="wd-head" style="gap:12px;">
                <span class="tb-av" :style="'background:' + (win.person ? (win.person.avatar_color || 'var(--muted)') : 'var(--muted)')"
                      x-text="win.person ? win.person.initials : ''"></span>
                <div style="min-width:0;flex:1;">
                    <h2 id="tb-win-name" class="wd-title" style="margin:0;font-size:16px;" x-text="win.person ? win.person.name : ''"></h2>
                    <p class="wd-sub" style="margin:2px 0 0;" x-text="winPersonSub"></p>
                </div>
                @if (($canAssign ?? false) && $assignableEmployees->isNotEmpty())
                    <button type="button" class="uj-btn-ghost" style="height:30px;padding:0 12px;font-size:12px;flex-shrink:0;"
                            @click="openAssign(win.person.id, $event.currentTarget)">
                        <span x-text="$store.ui.lang==='en' ? 'Assign task' : 'Beri tugas'">Assign task</span>
                    </button>
                @endif
                <button type="button" class="wd-ico" @click="closeWindow()" :aria-label="$store.ui.lang==='en' ? 'Close' : 'Tutup'">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                </button>
            </div>
```

**5e. Escape guard.** On the same window `<aside>` element (currently line 179):

```blade
               @keydown.escape.window="win.show && closeWindow()" @keydown.tab="trapFocusWindow($event)">
```

change to:

```blade
               @keydown.escape.window="win.show && !assign.show && closeWindow()" @keydown.tab="trapFocusWindow($event)">
```

**5f. The modal itself.** Immediately after the existing window's `</template>` (currently line 252), before the closing `</div>` of the root (currently line 253), insert a second teleport block:

```blade

    {{-- ═══════ Assign-task modal — teleported to body, its own scrim/shell
         (see .tb-assign-scrim/.tb-assign-modal in app.css) so it can layer
         above the person window when opened from that window's own button.
         A plain form POST to the existing work.assign route/controller —
         submitting reloads the page, same as the profile screen's own
         assign form does today. ═══════ --}}
    <template x-teleport="body">
    <div>
        <div class="tb-assign-scrim" x-show="assign.show" x-cloak :data-open="assign.open ? '' : null" @click="closeAssign()"></div>

        <div class="tb-assign-modal" x-show="assign.show" x-cloak :data-open="assign.open ? '' : null"
             role="dialog" aria-modal="true" aria-labelledby="tb-assign-title"
             @keydown.escape.window="assign.show && closeAssign()">
            <form method="post" :action="'/app/board/assign/' + assign.employeeId">
                @csrf
                {{-- Never read by the controller (the URL path segment above is what it
                     acts on) — this is purely so a validation error's back()-redirect
                     can reopen the modal already pointed at the right person, via
                     old('employee_id') feeding assignInit above. --}}
                <input type="hidden" name="employee_id" :value="assign.employeeId" />

                <div class="wd-head">
                    <span id="tb-assign-title" style="font-size:13px;font-weight:600;color:var(--ink);flex:1;"
                          x-text="$store.ui.lang==='en' ? 'Assign a task' : 'Beri tugas'">Assign a task</span>
                    <button type="button" class="wd-ico" @click="closeAssign()" :aria-label="$store.ui.lang==='en' ? 'Close' : 'Tutup'">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                    </button>
                </div>

                <div class="wd-body">
                    @if ($errors->getBag('assign')->any())
                        <div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:8px 11px;margin-bottom:14px;">{{ $errors->getBag('assign')->first() }}</div>
                    @endif

                    <input type="text" name="title" maxlength="160" required value="{{ old('title') }}" x-ref="assignTitleEl"
                           class="wd-title" style="width:100%;border-color:var(--hairline);"
                           x-bind:placeholder="$store.ui.lang==='en' ? 'Task title' : 'Tajuk tugas'" />

                    <div class="wd-props" style="margin-top:14px;">
                        <span class="wd-plabel" x-text="$store.ui.lang==='en' ? 'Assign to' : 'Beri kepada'">Assign to</span>
                        <span class="wd-pval">
                            <select class="wd-inline" x-model.number="assign.employeeId" required>
                                @foreach ($assignableEmployees as $e)
                                    <option value="{{ $e['id'] }}">{{ $e['name'] }}</option>
                                @endforeach
                            </select>
                        </span>

                        <span class="wd-plabel" x-text="$store.ui.lang==='en' ? 'Type' : 'Jenis'">Type</span>
                        <span class="wd-pval">
                            <select name="type" class="wd-inline">
                                @foreach (['adhoc' => 'Adhoc', 'task' => 'Task', 'assignment' => 'Assignment'] as $v => $l)
                                    <option value="{{ $v }}" @selected(old('type', 'adhoc') === $v)>{{ $l }}</option>
                                @endforeach
                            </select>
                        </span>

                        <span class="wd-plabel" x-text="$store.ui.lang==='en' ? 'Priority' : 'Keutamaan'">Priority</span>
                        <span class="wd-pval">
                            <select name="priority" class="wd-inline">
                                @foreach (['high' => 'High', 'medium' => 'Medium', 'low' => 'Low'] as $v => $l)
                                    <option value="{{ $v }}" @selected(old('priority', 'medium') === $v)>{{ $l }}</option>
                                @endforeach
                            </select>
                        </span>

                        <span class="wd-plabel" x-text="$store.ui.lang==='en' ? 'Due' : 'Tarikh akhir'">Due</span>
                        <span class="wd-pval">
                            <input type="date" name="due_at" value="{{ old('due_at') }}" class="wd-inline" />
                        </span>
                    </div>

                    <textarea name="description" rows="3" maxlength="5000" class="wd-desc"
                              x-bind:placeholder="$store.ui.lang==='en' ? 'Description (optional)' : 'Penerangan (pilihan)'">{{ old('description') }}</textarea>

                    <button type="submit" class="uj-btn-primary" style="height:40px;font-size:13px;width:100%;margin-top:16px;">
                        <span x-text="$store.ui.lang==='en' ? 'Assign task' : 'Beri tugas'">Assign task</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
    </template>
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=TeamBoardScreenTest`
Expected: PASS (all tests in the file, including pre-existing ones untouched).

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/css/app.css resources/js/team-board.js resources/views/screens/team-board.blade.php tests/Feature/TeamBoardScreenTest.php
git commit -m "feat(team-board): assign a task from the screen itself"
```

---

## Task 3: Full regression pass

**Files:** none (verification only)

**Interfaces:** none — this task consumes Tasks 1–2's finished code and produces no new interface.

- [ ] **Step 1: Run every team-board-related test file**

Run: `php artisan test --compact tests/Feature/TeamBoardDataTest.php tests/Feature/TeamBoardScreenTest.php tests/Feature/TeamBoardAccessTest.php tests/Feature/AllScreensRenderTest.php`
Expected: PASS. `AllScreensRenderTest` catches any Blade syntax error introduced by Task 2's edits across every screen, not just team board.

- [ ] **Step 2: Rebuild frontend assets**

Run: `bun run build`
Expected: completes without error — confirms `team-board.js`'s new syntax is valid and `app.css`'s new rules parse.

- [ ] **Step 3: Run the full suite**

Run: `php artisan test --compact`
Expected: PASS. If anything outside team board fails, stop and investigate before proceeding — do not attribute an unrelated failure to this change without checking.

- [ ] **Step 4: Note on browser verification**

This worktree cannot be reached through the local dev server (Lerd serves the `main` checkout, not this worktree's vhost, and the sandbox can't reach the host MySQL instance either) — so an interactive click-through (open both entry points, submit, confirm the redirect shows the new card, trigger and recover from a validation error) isn't reliable from here. The PHPUnit coverage above exercises the server-rendered markup and gating; if visual/interaction confidence is wanted before merging, run through the flow manually against the `main` checkout's own Lerd site (`http://localhost:9100`) once this branch lands there, or ask the user to click through it.

- [ ] **Step 5: Commit if Step 2 changed build output**

```bash
git status
```

If `public/build` changed, stage and commit it alongside a note — otherwise skip (no commit needed for a verification-only task).
