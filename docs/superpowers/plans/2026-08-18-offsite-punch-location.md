# Off-site punch location Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an authorised reviewer see, on a map, where an off-site clock-in/out physically happened.

**Architecture:** Three independent layers. A controller flag decides who may see coordinates at all. A read-only Leaflet modal, registered once per screen, plots points handed to it via a window event. A trigger in the drill-down row supplies those points. Coordinates are already stored on `attendance_records`; nothing is written, no migration, no new data collected.

**Tech Stack:** Laravel 13, Blade, Alpine.js 3, Leaflet (dynamic import), Tailwind v4 pipeline over hand-written CSS in `resources/css/app.css`, PHPUnit 12.

**Spec:** `docs/superpowers/specs/2026-08-18-offsite-punch-location-design.md`

## Global Constraints

- **Leaflet must NOT enter the app-wide bundle.** `resources/js/app.js` states this explicitly; Leaflet and Quill dynamic-import on first use because each is used on one screen. Copy the `loadLeaflet()` lazy-loader from `resources/js/map-picker.js`.
- **Markers must be CSS-only `L.divIcon`, never image files.** The CSP limits `img-src` to `'self' data: blob: https://*.tile.openstreetmap.org` (`app/Http/Middleware/SecurityHeaders.php:52`). This also sidesteps the known Leaflet + Vite broken default-marker problem.
- **No CSP changes.** `*.tile.openstreetmap.org` is already allowed in `img-src`. Do not add hosts.
- **The map is read-only.** No click-to-place, no draggable markers, no address search. Read-only is structural — do not add a flag to `mapPicker` instead.
- **Every user-facing string needs EN + BM**, via `x-text="$store.ui.lang==='en' ? 'English' : 'Bahasa'"`, matching the surrounding file.
- **Run `vendor/bin/pint --dirty --format agent`** before every commit touching PHP.
- **Do not push.** Commit locally only. `origin`, staging and GitLab are out of scope for this plan.

---

### Task 1: Controller gate — who may see coordinates

**Files:**
- Modify: `app/Http/Controllers/AttendanceReportController.php:33` (add const beside `REVERSE_ROLES`), and the return array at `:315`
- Test: `tests/Feature/AttendanceReportLocationTest.php` (create)

**Interfaces:**
- Consumes: nothing.
- Produces: `screenData()` returns an additional key `'canSeeLocation' => bool`. Task 3's Blade reads `$canSeeLocation`.

**Why this is not simply the screen's own gate:** `Permissions::canSeeAll()` (`app/Support/Permissions.php:136`) also admits an `employee`-role user who merely has one active direct report on the org chart. That route may read the "Off-site" badge and the typed reason, but not coordinates. This mirrors `REVERSE_ROLES` in the same controller, which is likewise narrower than the screen hosting it.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/AttendanceReportLocationTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\AttendanceReportController;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AttendanceReportLocationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Employee $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-07-15 10:00:00'));

        $this->tenant = Tenant::create([
            'slug' => 'alpha',
            'name' => 'Alpha',
            'initials' => 'AL',
        ]);

        $user = User::create([
            'name' => 'Viewer',
            'email' => 'viewer@example.com',
            'password' => Hash::make('password'),
        ]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'hr']);

        $this->viewer = Employee::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'name' => 'Viewer',
            'status' => 'active',
            'workload' => 'green',
        ]);
    }

    private function screenDataAs(string $role): array
    {
        $request = Request::create('/app/attendance-report', 'GET');
        $request->attributes->set('tenantRole', $role);
        $request->attributes->set('tenantScope', 'company');
        $request->attributes->set('employee', $this->viewer);

        return app(AttendanceReportController::class)->screenData($request);
    }

    public function test_oversight_roles_may_see_punch_coordinates(): void
    {
        foreach (['hr', 'manager', 'management', 'director'] as $role) {
            $this->assertTrue(
                $this->screenDataAs($role)['canSeeLocation'],
                "Role [{$role}] should be allowed to see punch locations."
            );
        }
    }

    /**
     * canSeeAll() lets an employee-role user reach this screen purely by having a
     * direct report. That route stops short of coordinates.
     */
    public function test_an_employee_role_viewer_may_not_see_punch_coordinates(): void
    {
        $this->assertFalse($this->screenDataAs('employee')['canSeeLocation']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/AttendanceReportLocationTest.php`
Expected: FAIL — `Undefined array key "canSeeLocation"`.

- [ ] **Step 3: Add the role constant**

In `app/Http/Controllers/AttendanceReportController.php`, directly below the existing `REVERSE_ROLES` constant (line 33):

```php
    /**
     * Seeing where a colleague physically stood is a step beyond reading that they
     * were off-site, so it does not inherit this screen's own gate. canSeeAll() also
     * admits an `employee`-role user who merely has a direct report on the org chart;
     * that route keeps the badge and the typed reason but not the coordinates.
     * Narrower-than-its-host is the same shape as REVERSE_ROLES above.
     */
    private const LOCATION_ROLES = ['hr', 'manager', 'management', 'director'];
```

- [ ] **Step 4: Return the flag**

In the same file, in the `return [...]` array at the end of `screenData()`, directly after the `'canReversePunch' => ...` line:

```php
            'canSeeLocation' => (bool) $request->user()?->isSuperAdmin() || $this->hasTenantRole($request, self::LOCATION_ROLES),
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --compact tests/Feature/AttendanceReportLocationTest.php`
Expected: PASS, 2 tests.

- [ ] **Step 6: Check nothing else broke**

Run: `php artisan test --compact tests/Feature/AttendanceReportDataTest.php tests/Feature/AttendanceReportScreenTest.php tests/Feature/AttendanceReportSummaryTest.php`
Expected: PASS, 31 tests.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/AttendanceReportController.php tests/Feature/AttendanceReportLocationTest.php
git commit -m "feat(attendance): gate punch coordinates to oversight roles"
```

---

### Task 2: The read-only map modal

**Files:**
- Create: `resources/js/map-view.js`
- Create: `resources/views/partials/map-view.blade.php`
- Modify: `resources/js/app.js` (import + register, alongside `registerMapPicker`)
- Modify: `resources/css/app.css` (append after the `uj-ar-` block)
- Modify: `resources/views/screens/attendance-report.blade.php` (include the partial inside the drill branch)

**Interfaces:**
- Consumes: nothing from Task 1.
- Produces: a window event contract that Task 3 fires:
  `window.dispatchEvent(new CustomEvent('open-map-view', { detail: { title: string, points: Array<{lat: number, lng: number, label: string}> } }))`
  Alpine component name: `mapView`. It ignores an empty `points` array.

**Two Leaflet traps this task must avoid:**
1. Adding a tile layer to a map whose view has never been set throws *"Set map center and zoom first"*. The centre therefore goes on at construction (`L.map(...).setView(...)`), before `L.tileLayer(...).addTo(...)` — the same order `map-picker.js` uses.
2. The modal is `display:none` until opened, so Leaflet measures the canvas as 0×0. `map.invalidateSize()` must run after it becomes visible.

- [ ] **Step 1: Create the Alpine component**

Create `resources/js/map-view.js`:

```js
const SINGLE_ZOOM = 17;

// Leaflet (+ its css) loads on demand the first time a map opens, mirroring
// map-picker.js — see the note in app.js, neither may sit in the app-wide bundle.
let L = null;
let pinIcon = null;

async function loadLeaflet() {
    if (L) return;
    const mod = await import('leaflet');
    await import('leaflet/dist/leaflet.css');
    L = mod.default;

    // A CSS-only pin (no image files) keeps us within the strict CSP (img-src is
    // limited to OSM tiles) and sidesteps the Leaflet + Vite broken default-marker
    // problem entirely.
    pinIcon = L.divIcon({
        className: 'uj-mv-pin',
        html: '<span style="display:block;width:16px;height:16px;border-radius:50%;background:#c8102e;border:3px solid #fff;box-shadow:0 0 0 1px rgba(0,0,0,.35),0 2px 6px rgba(0,0,0,.4);"></span>',
        iconSize: [16, 16],
        iconAnchor: [8, 8],
    });
}

/**
 * Read-only counterpart to mapPicker: plots where a punch was recorded and offers
 * no way to move it. Deliberately a separate component rather than a `readonly`
 * flag on the picker — a reviewer must not be able to alter where somebody
 * punched, so read-only is structural rather than a setting that can be flipped.
 *
 * A row opens it by firing a window `open-map-view` event:
 *   detail: { title: 'Ravi Kumar · Tue, 12 Aug', points: [{ lat, lng, label }] }
 */
export function registerMapView(Alpine) {
    Alpine.data('mapView', () => ({
        open: false,
        title: '',
        points: [],
        map: null,
        markers: [],

        init() {
            window.addEventListener('open-map-view', (ev) => this.show(ev.detail || {}));
        },

        async show({ title = '', points = [] }) {
            if (!points.length) return;

            this.title = title;
            this.points = points;
            await loadLeaflet();
            this.open = true;
            this.$nextTick(() => this.render());
        },

        close() {
            this.open = false;
        },

        render() {
            const first = this.points[0];

            if (!this.map) {
                // Leaflet throws "Set map center and zoom first" if a layer is added
                // before the view exists, so the centre goes on at construction.
                this.map = L.map(this.$refs.canvas, { zoomControl: true })
                    .setView([first.lat, first.lng], SINGLE_ZOOM);

                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19,
                    attribution: '&copy; OpenStreetMap contributors',
                }).addTo(this.map);
            }

            this.markers.forEach((m) => this.map.removeLayer(m));
            this.markers = [];

            this.points.forEach((p) => {
                const marker = L.marker([p.lat, p.lng], { icon: pinIcon }).addTo(this.map);
                marker.bindTooltip(p.label, { permanent: true, direction: 'top', offset: [0, -10] });
                this.markers.push(marker);
            });

            if (this.points.length === 1) {
                this.map.setView([first.lat, first.lng], SINGLE_ZOOM);
            } else {
                // Both punches on screen at once makes drift between them obvious.
                this.map.fitBounds(
                    L.latLngBounds(this.points.map((p) => [p.lat, p.lng])).pad(0.35)
                );
            }

            // The modal was display:none until now, so Leaflet sized to 0×0.
            this.map.invalidateSize();
        },
    }));
}
```

- [ ] **Step 2: Register it**

In `resources/js/app.js`, add the import beside the other registrations (keep the existing alphabetical-ish grouping — put it directly after the `registerMapPicker` import):

```js
import { registerMapView } from './map-view';
```

and directly after the `registerMapPicker(Alpine);` call:

```js
registerMapView(Alpine);
```

- [ ] **Step 3: Create the modal partial**

Create `resources/views/partials/map-view.blade.php`:

```blade
{{-- Read-only Leaflet view of where a punch was recorded. One instance per screen;
     any row opens it by dispatching the window `open-map-view` event with its points.
     Never editable — see the note in resources/js/map-view.js. --}}
<div x-data="mapView" x-cloak>
    <template x-teleport="body">
        <div x-show="open" x-transition.opacity @keydown.escape.window="close()"
             class="uj-dialog-overlay"
             style="position:fixed;inset:0;z-index:1000;background:rgba(15,18,20,.55);padding:20px;">
            <div @click.outside="close()" class="uj-mv-panel">
                <div class="uj-mv-head">
                    <div style="min-width:0;">
                        <div class="uj-mv-title" x-text="title"></div>
                        <div class="uj-mv-sub"
                             x-text="$store.ui.lang==='en'
                                ? 'Where this punch was recorded. Read-only.'
                                : 'Lokasi clock ini direkodkan. Baca sahaja.'">Where this punch was recorded. Read-only.</div>
                    </div>
                    <button type="button" @click="close()" class="uj-btn-ghost"
                            style="height:32px;padding:0 12px;font-size:14px;flex-shrink:0;"
                            :aria-label="$store.ui.lang==='en' ? 'Close' : 'Tutup'">✕</button>
                </div>
                <div x-ref="canvas" class="uj-mv-canvas"></div>
            </div>
        </div>
    </template>
</div>
```

- [ ] **Step 4: Add the styles**

In `resources/css/app.css`, append directly after the `uj-ar-` drill-down block (after the `@media (max-width: 720px)` rule that closes it, immediately before the `Timesheet report` banner comment):

```css
/* ═══════════════════════════════════════════════════════════════════════
   Read-only punch-location map (resources/views/partials/map-view.blade.php).
   Namespaced uj-mv- ("map view"). Sibling of the uj-map-* picker, deliberately
   not a variant of it: this one can never move a pin.
   ═══════════════════════════════════════════════════════════════════════ */
.uj-mv-panel { background: var(--card); border-radius: 14px; width: 100%; max-width: 720px; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 24px 60px rgba(0, 0, 0, .35); }
.uj-mv-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; padding: 16px 20px; border-bottom: 1px solid var(--hairline); flex-shrink: 0; }
.uj-mv-title { font-size: 15px; font-weight: 700; color: var(--ink); }
.uj-mv-sub { font-size: 12px; color: var(--muted); margin-top: 2px; }
.uj-mv-canvas { height: 420px; width: 100%; background: #e8eef1; }
```

- [ ] **Step 5: Include the partial on the screen**

In `resources/views/screens/attendance-report.blade.php`, inside the `@if ($drill)` branch, immediately before its closing `</div>` of `class="uj-card"` (i.e. after the `@endforelse`), add:

```blade
        @include('partials.map-view')
```

- [ ] **Step 6: Build and verify manually in the browser**

```bash
php artisan view:cache
npm run build
```

Then sign in at `http://amanahku.test` (quick-login → HR), open `/app/attendance-report`, click any person to reach the drill-down, and in the browser console run:

```js
window.dispatchEvent(new CustomEvent('open-map-view', { detail: {
  title: 'Manual check',
  points: [{ lat: 3.16278, lng: 101.7172189, label: 'Clocked in 09:31' }]
}}));
```

Expected: a modal opens with a red pin over Kuala Lumpur, a permanent "Clocked in 09:31" tooltip, and map tiles that actually load (no CSP errors in the console). ✕ and Escape both close it. Re-run with two points and confirm both pins are visible at once.

- [ ] **Step 7: Restore the local dev environment**

```bash
php artisan view:clear
```

(`view:cache` was needed for the Tailwind scan during the build; leaving views cached freezes later Blade edits.)

- [ ] **Step 8: Commit**

```bash
git add resources/js/map-view.js resources/js/app.js resources/views/partials/map-view.blade.php resources/css/app.css resources/views/screens/attendance-report.blade.php public/build
git commit -m "feat(attendance): add a read-only map for viewing a recorded punch"
```

---

### Task 3: The trigger in the drill-down row

**Files:**
- Modify: `resources/views/screens/attendance-report.blade.php:140` (the `$notes` block inside the drill row)
- Test: `tests/Feature/AttendanceReportLocationTest.php` (extend)

**Interfaces:**
- Consumes: `$canSeeLocation` from Task 1; the `open-map-view` event contract from Task 2.
- Produces: nothing further.

**Why the flag alone is a sufficient guard:** `ClockService::within()` (`app/Attendance/ClockService.php:207`) returns `null` — never `false` — both when a punch has no coordinates and when the site has no geofence configured, and `out_of_radius_*` is only appended on an explicit `false`. An off-site flag therefore guarantees usable coordinates. The null-checks below are belt-and-braces against hand-edited rows, not the normal path.

- [ ] **Step 1: Write the failing tests**

Add these to `tests/Feature/AttendanceReportLocationTest.php`. Add the imports `use App\Models\AttendanceRecord;` at the top:

```php
    private function offSiteRecord(Employee $emp): AttendanceRecord
    {
        return AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $emp->id,
            'date' => '2026-07-14',
            'status' => 'on_time',
            'clock_in' => '09:31:00',
            'latitude' => 3.1627800,
            'longitude' => 101.7172189,
            'in_radius' => false,
            'flags' => ['out_of_radius_in'],
        ]);
    }

    private function subject(): Employee
    {
        return Employee::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Off Site Staff',
            'status' => 'active',
            'workload' => 'green',
        ]);
    }

    private function openDrillAs(string $role, Employee $subject)
    {
        $user = User::where('email', 'viewer@example.com')->firstOrFail();
        $user->tenants()->updateExistingPivot($this->tenant->id, ['role' => $role]);

        return $this->actingAs($user)
            ->withSession(['current_tenant' => $this->tenant->id, 'persona' => $role])
            ->get('/app/attendance-report?period=week&emp='.$subject->id);
    }

    public function test_an_off_site_punch_offers_a_location_control(): void
    {
        $subject = $this->subject();
        $this->offSiteRecord($subject);

        $this->openDrillAs('hr', $subject)
            ->assertOk()
            ->assertSee('open-map-view', false)
            ->assertSee('101.717', false);
    }

    public function test_an_on_site_punch_exposes_no_coordinates(): void
    {
        $subject = $this->subject();

        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $subject->id,
            'date' => '2026-07-14',
            'status' => 'on_time',
            'clock_in' => '08:55:00',
            'latitude' => 3.1627800,
            'longitude' => 101.7172189,
            'in_radius' => true,
            'flags' => [],
        ]);

        $this->openDrillAs('hr', $subject)
            ->assertOk()
            ->assertDontSee('open-map-view', false)
            ->assertDontSee('101.717', false);
    }

    public function test_a_viewer_without_the_role_gets_no_coordinates(): void
    {
        $subject = $this->subject();
        $subject->update(['reports_to_id' => $this->viewer->id]);
        $this->offSiteRecord($subject);

        $this->openDrillAs('employee', $subject)
            ->assertOk()
            ->assertDontSee('open-map-view', false)
            ->assertDontSee('101.717', false);
    }
```

- [ ] **Step 2: Run them to verify they fail**

Run: `php artisan test --compact tests/Feature/AttendanceReportLocationTest.php`
Expected: FAIL — `test_an_off_site_punch_offers_a_location_control` cannot find `open-map-view`. The other two may pass already (nothing renders coordinates yet); that is fine and expected — they are regression guards.

- [ ] **Step 3: Add the trigger**

In `resources/views/screens/attendance-report.blade.php`, inside the drill row, replace the single line:

```blade
                    @php $notes = array_filter(['in' => $r->clock_in_justification, 'out' => $r->clock_out_justification]); @endphp
```

with:

```blade
                    @php
                        $notes = array_filter(['in' => $r->clock_in_justification, 'out' => $r->clock_out_justification]);

                        // Only points that were actually off-site. within() returns null (never
                        // false) when coordinates or the site geofence are missing, so an
                        // out_of_radius_* flag already implies a usable point; the null-checks
                        // guard hand-edited rows, not the normal path.
                        $recFlags = $r->flags ?? [];
                        $locPoints = [];
                        if (in_array('out_of_radius_in', $recFlags, true) && $r->latitude !== null && $r->longitude !== null) {
                            $locPoints[] = [
                                'lat' => (float) $r->latitude,
                                'lng' => (float) $r->longitude,
                                'label' => 'Clocked in '.($r->clock_in ? Str::of($r->clock_in)->limit(5, '') : ''),
                            ];
                        }
                        if (in_array('out_of_radius_out', $recFlags, true) && $r->clock_out_latitude !== null && $r->clock_out_longitude !== null) {
                            $locPoints[] = [
                                'lat' => (float) $r->clock_out_latitude,
                                'lng' => (float) $r->clock_out_longitude,
                                'label' => 'Clocked out '.($r->clock_out ? Str::of($r->clock_out)->limit(5, '') : ''),
                            ];
                        }
                    @endphp
                    @if ($canSeeLocation && $locPoints)
                        <div style="grid-column:1/-1;margin-top:5px;">
                            <button type="button" class="uj-ar-loc" x-data
                                    @click="window.dispatchEvent(new CustomEvent('open-map-view', { detail: {
                                        title: @js($drill->display_name.' · '.$r->date->format('D, j M')),
                                        points: @js($locPoints)
                                    } }))">
                                📍 <span x-text="$store.ui.lang==='en' ? 'View location' : 'Lihat lokasi'">View location</span>
                            </button>
                        </div>
                    @endif
```

- [ ] **Step 4: Add the trigger's style**

In `resources/css/app.css`, inside the `uj-mv-` block added in Task 2, above `.uj-mv-panel`:

```css
.uj-ar-loc { display: inline-flex; align-items: center; gap: 6px; height: 26px; padding: 0 10px; border: 1px solid var(--hairline); border-radius: 999px; background: var(--card); color: var(--body); font: 500 var(--t-sm) var(--font-sans); cursor: pointer; }
.uj-ar-loc:hover { border-color: var(--muted-soft); color: var(--ink); }
.uj-ar-loc:focus-visible { outline: 2px solid var(--red); outline-offset: 2px; }
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/AttendanceReportLocationTest.php`
Expected: PASS, 5 tests.

- [ ] **Step 6: Check nothing else broke**

Run: `php artisan test --compact tests/Feature/AttendanceReportDataTest.php tests/Feature/AttendanceReportScreenTest.php tests/Feature/AttendanceReportSummaryTest.php`
Expected: PASS, 31 tests.

- [ ] **Step 7: Build and verify end-to-end in the browser**

```bash
php artisan view:cache
npm run build
php artisan view:clear
```

The repo's seed data has no off-site punches, so create one to look at:

```bash
php artisan tinker --execute "\$e = App\Models\Employee::where('name','Aisyah Rahman')->first(); App\Models\AttendanceRecord::updateOrCreate(['employee_id'=>\$e->id,'date'=>now()->subDay()->toDateString()],['tenant_id'=>\$e->tenant_id,'status'=>'on_time','clock_in'=>'09:31:00','latitude'=>3.16278,'longitude'=>101.7172189,'in_radius'=>false,'flags'=>['out_of_radius_in']]);"
```

Sign in as HR, open `/app/attendance-report`, click Aisyah Rahman, and confirm: the off-site row shows 📍 **View location**; clicking it opens the map on the right pin with the "Clocked in 09:31" tooltip; ✕ and Escape close it. Toggle **BM** in the header and confirm the button reads *Lihat lokasi* and the subtitle switches too.

- [ ] **Step 8: Remove the throwaway record**

```bash
php artisan tinker --execute "App\Models\AttendanceRecord::whereJsonContains('flags','out_of_radius_in')->where('date',now()->subDay()->toDateString())->delete();"
```

- [ ] **Step 9: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/screens/attendance-report.blade.php resources/css/app.css tests/Feature/AttendanceReportLocationTest.php public/build
git commit -m "feat(attendance): show where an off-site punch was recorded"
```

---

## Self-review notes

**Spec coverage.** Gate → Task 1. Read-only map, one control per row, up to two pins, no geofence circle → Task 2 + 3. No migration, no write path → nothing in any task touches `ClockService` or a migration. Privacy (coordinates only for off-site rows, only for permitted roles) → Task 3 Steps 1–3, asserted by two negative tests.

**Deliberately not covered.** Reverse-geocoded address, storing the geofence on the record, repeat-offender patterns, and the `defaultScopeForRole()` inversion are all listed "Out of scope" in the spec.

**Type consistency.** The event name `open-map-view`, the `{ title, points }` detail shape, and the `{ lat, lng, label }` point shape are identical in Task 2's component, Task 2's manual check, and Task 3's Blade. Alpine component name `mapView` matches `x-data="mapView"` in the partial. `$canSeeLocation` matches the controller key exactly.

**Known limitation, by design.** With `points.length === 1` the map opens at zoom 17. A punch a long way from its site still shows only the punch itself — there is no second reference point on screen, because the spec deliberately drops the geofence circle (the record does not store the site's coordinates). The site's name is on the row above.
