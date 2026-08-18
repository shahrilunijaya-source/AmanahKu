# Off-site punch location: show HR where an out-of-radius clock-in/out happened

**Date:** 2026-08-18
**Status:** design approved, not implemented

## Problem

When someone clocks in or out beyond their site's geofence, `ClockService` already records everything needed to say where they were: `latitude`/`longitude` for the clock-in, `clock_out_latitude`/`clock_out_longitude` for the clock-out, the `in_radius`/`out_radius` booleans, and the `out_of_radius_in` / `out_of_radius_out` flags (app/Attendance/ClockService.php:88-96, 176-187). The punch is never blocked — the design deliberately costs a typed justification and a selfie instead, on the reasoning that bad GPS should not strand staff.

None of it is ever shown. The Attendance Reports drill-down renders the flag badges and the typed justification (resources/views/screens/attendance-report.blade.php:115-151) but not the coordinates, so the reviewer reads "Off-site in" plus a free-text reason and has no way to check the reason against reality. The coordinates sit in the table unread.

This is a surfacing job, not new tracking. Nothing additional is collected.

## Decision

Add a **📍 View location** control to off-site rows in the drill-down, opening a **read-only** Leaflet map pinned to where the punch actually happened.

| Question | Decision |
|----------|----------|
| Track continuously, or show the punch point? | **Punch point only.** No background location, no new table, no retention policy, no new consent question. Uses data already recorded. |
| Where does it appear? | **Attendance Reports drill-down** only. Not the employee's own attendance screen, not attendance-admin. |
| Who can see it? | **`['hr', 'manager', 'management', 'director']`.** See "Permissions" below — deliberately narrower than the screen itself. |
| Map, address, or coordinates? | **Map pin.** "Where exactly were they" is a question humans answer visually; raw coordinates would just get pasted into Google Maps. |
| Reverse-geocode to a street address? | **No.** Deferred, not rejected — see "Out of scope". |
| Draw the site's geofence circle? | **No.** See "Why no geofence circle". |
| One control per punch, or per row? | **One per row**, opening one map carrying up to two pins (in and out). |
| New columns or migration? | **None.** No write path changes at all. |

## Permissions

The drill-down is gated by `Permissions::canSeeAll()` (app/Support/Permissions.php:136), which admits `manager`, `management`, `hr`, `director` — **and any `employee`-role user who has at least one active direct report**, granted purely by the org chart.

Location gets its own tighter gate rather than inheriting that:

```php
private const LOCATION_ROLES = ['hr', 'manager', 'management', 'director'];
```

resolved the same way `canReversePunch` already is, via `hasTenantRole()`, which matches on both the raw role and `effectiveRole()` — so a `director` matches whether or not the list names it. This mirrors the existing precedent in the same controller, where `REVERSE_ROLES = ['hr', 'director']` is deliberately narrower than the screen that hosts it.

The single case this excludes is the org-chart route: someone whose role is `employee` but who has a direct report. They keep the drill-down, the off-site badge and the typed reason; they do not get coordinates.

`DataScope` still applies on top, unchanged. A `manager` reaches only their own reports, so the gate widens nobody's reach — it only decides whether location is included for records they can already read.

### Known pre-existing issue (not introduced here, not fixed here)

`defaultScopeForRole()` (app/Support/Permissions.php:64) narrows only the `manager` role to `team` scope; every other role defaults to `company`. An `employee` who gains oversight through the org chart therefore defaults to **company-wide** scope — broader than a real manager, who is restricted to their team. That inversion affects all attendance data today, not just location. The `LOCATION_ROLES` gate keeps this feature clear of it; the underlying scope question is left alone deliberately and should be looked at on its own terms.

## Why no geofence circle

Drawing the site's fence around the pin would answer "how far off were they" for free, and was the original reason for preferring a map. It is dropped because the record cannot support it honestly.

`attendance_records` stores `expected_site_type`, `expected_start`, `expected_end` and `expected_min_hours`, but **not** the site's coordinates or radius. Those live on the Branch / WorkSite / Employee-home rows, which hold *current* configuration. A punch from three months ago would be drawn against where the office sits today. If a branch moved or its radius changed, the circle would be wrong — and a confidently-wrong circle on an audit screen is worse than no circle.

The map therefore shows the pin plus the `location` label already stored on the record ("Unijaya Resources"), which *was* correct at punch time.

Capturing the geofence onto the record at punch time would fix this properly, but needs a migration and a change to the clock path, and would still leave every existing record without it. Out of scope here.

## Design

**Trigger.** An off-site row gains a 📍 control. It renders only when there is genuinely something to plot: an off-site flag **and** usable coordinates for that punch.

The two cases that produce no control are structural rather than defensive. `within()` (app/Attendance/ClockService.php:207) returns `null` — never `false` — both when the punch carries no coordinates and when the site has no geofence configured, and `out_of_radius_*` is only set on an explicit `false`. So a `no_location` punch, and any punch against an unfenced site, can never be flagged off-site in the first place. The control cannot be asked to plot a point that does not exist.

**One map, up to two pins.** A record holds two independent positions and two independent flags — someone can be off-site on the way in, on the way out, or both. Rather than two buttons, one control opens one map plotting whichever off-site points exist, each labelled with its time ("Clocked in 09:31", "Clocked out 18:05"). Both on one map also makes drift between the two visible at a glance.

**Component boundary.** A new read-only `map-view` partial and Alpine component, deliberately **not** a `readonly` flag on the existing `map-picker`. The picker exists to move pins, drag markers and search addresses (resources/js/map-picker.js); this must never be able to alter where someone punched. Separate components make read-only structural rather than a setting that can be flipped by accident. Leaflet and the OSM tile layer are reused; nothing new is added to the bundle.

**Data flow.** `$drillRecords` already reaches the view and the coordinates are already on the model, so the Blade reads fields nobody was displaying. The controller adds one boolean (`canSeeLocation`) alongside the existing `canReversePunch`. No query changes, no new data, no writes.

## Privacy notes

- **No new collection.** Every coordinate shown is one the app already stored at punch time.
- **Tile requests.** Map tiles load from `*.tile.openstreetmap.org`, so opening a map tells OSM's servers roughly which area is being viewed. The app already does this on Company Settings and Attendance Setup; this adds a third caller, not a new dependency. Both hosts are already allowed in `SecurityHeaders.php:52` and `:62` — no CSP change.
- **Minimal DOM exposure.** Coordinates are emitted only for rows that are actually off-site, and only for viewers passing `LOCATION_ROLES`. They are not rendered for ordinary on-site punches.

## Testing

| Case | Expectation |
|------|-------------|
| Off-site clock-in with coordinates, HR viewer | Control renders; coordinates present |
| Ordinary on-site punch | No control; **no coordinates in the HTML** |
| `no_location` punch (null coordinates) | No control, no broken map |
| Off-site on clock-out only | Control renders, plotting the clock-out point |
| Off-site on both in and out | One control, two pins |
| Viewer is `employee` with a direct report | Drill-down and badge visible; **no control, no coordinates** |
| Viewer is `manager` | Control renders, still bounded by `DataScope` |

## Out of scope

- **Reverse-geocoded street address.** Deferred rather than rejected. Nominatim's usage policy tolerates the user-initiated search the app does today, but reverse-geocoding punches drifts toward bulk use; doing it once at clock-in and storing the result would stay within policy at the cost of a column and a write-path change. Revisit if the map proves too slow to scan.
- **Storing the geofence on the record** (see "Why no geofence circle").
- **Repeat-offender patterns** — "same person, same spot, every Friday" is a reporting feature, not this one.
- **Fixing `defaultScopeForRole()`** (see "Known pre-existing issue").
