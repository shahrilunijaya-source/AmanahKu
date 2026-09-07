# Contract: dashboard slots

Frozen by S00 from `app/Support/DashboardWidgets.php`, `app/Support/DashboardBands.php`, `resources/views/screens/dash.blade.php` and `resources/views/partials/dash/`. Appendix B of the tracker drew the layout from memory; where it disagrees with the code, the code wins and the difference is listed at the bottom.

## Bands (full width, above the grid, not in the picker, not draggable)

`DashboardBands::SLOTS = ['moments', 'management', 'awards']`, composed in `BuildsDashboardWidgets::dashboardBands()`, rendered by `partials/dash/bands.blade.php`. A slot with no content renders nothing.

| Slot | Fills it | State today |
|---|---|---|
| `moments` | one rotating slot: birthday (CR-13), holiday eve (CR-20), later Big Deal (CR-24), Victory Bell (CR-28), Wrapped (CR-22) | built, rotates by day-of-year, pill steps through |
| `management` | CR-17 lateness today + overdue by Primary Owner, `FINAL_APPROVAL_ROLES` only | hardcoded `null`, placeholder comment in the view |
| `awards` | CR-14 carousel, 1st working day to the 7th | hardcoded `null`, placeholder comment in the view |

A new moment = one more `Moment` array from a builder in `DashboardBands`, appended to the `moments` list. Never a new band, never a new view file at the top of the page.

## Widget grid

Registry `DashboardWidgets::REGISTRY`. Each widget: `title`, `title_ms`, `category`, `roles`, `screen`, `column`, optional `after` (anchor id, inserts after that widget in the default order). Per-user drag order and hide list live in `users.dashboard_prefs`; `tasks` is pinned. Do not rename, remove or reorder existing ids.

| id | Title | Column | Roles | Anchor |
|---|---|---|---|---|
| `summary` | Current month summary | left | all | |
| `clock` | Daily clock log | left | all | |
| `tasks` | Pending tasks | left | all, pinned | |
| `leave` | My leave summary | left | all | |
| `stuck` | Reaching nobody | left | `FINAL_APPROVAL_ROLES` | |
| `calendar` | My calendar | right | all | |
| `attendance` | Team attendance | right | `OVERSIGHT_ROLES` | |
| `notices` | Notice board | right | all | |
| `flowers` | Flowers | right | all | `after: notices` |
| `claims` | My claim summary | right | all | |
| `work` | My work summary | right | all | |
| `style` | My working style | right | all | `after: work` |
| `pulse` | Company pulse | right | `FINAL_APPROVAL_ROLES` | |

New widgets this run, each a registry entry with an `after` anchor plus one Blade file under `partials/dash/widgets/<id>.blade.php`:

| CR | id | Column | Anchor | Visible |
|---|---|---|---|---|
| CR-29 Friday sign-off | `friday` | left | `after: tasks` | Friday 15:00 to Monday 09:00 |
| CR-11 Events | `events` | right | `after: attendance` | when there is an upcoming or just-past event |
| CR-25 Plot Twist | none, renders inside `notices` | | | Fridays |

Greeting line: `BuildsDashboardData::meHead()`, CR-33 bank already feeds it; CR-31 easter eggs plug into `activeGreetingTriggers()` and the bank, not a new element.

"Keep it plain": `DashboardPrefs` key `plain`, checkbox in the dashboard picker (`dash.blade.php`), read by the bands view and `meHead()`. Every new band, widget or moment must render a text-only version when `plain` is true. CR-31 adds the same switch to the profile screen bound to the same key; it does not add a second flag.

## Where the code disagrees with Appendix B

- `work` (My work summary) and `style` (My working style) are in the **right** column in the registry, Appendix B draws them left. Right column stands.
- `stuck` and `pulse` exist and are not in Appendix B. They stay.
- Widgets are draggable per user, so "position" means default order, not a fixed pixel slot.
