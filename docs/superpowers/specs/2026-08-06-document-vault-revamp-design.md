# Document vault revamp — design

## Context

`resources/views/screens/documents.blade.php` predates the DESIGN.md design system
(`docs/DESIGN.md`). It uses ad-hoc inline styles (`$fs` style-string constant, raw
`style="..."` grids for rows), emoji category icons, three boxy stat cards, and raw
`var(--red)` text-as-signal instead of the app's stamp/tone convention. Other screens
(helpdesk, knowledge-bank) have since converged on a shared row/drawer idiom. This
revamp brings the document vault screen onto that idiom. No backend or route changes.

## Scope

Pure view-layer change. Same routes and controller (`app/Http/Controllers/DocumentController.php`):
`documents.store`, `documents.download`, `documents.destroy`. Same validation
(`title` required/max:160, `category` in the 4-value enum, `employee_id`,
`file` mimes:pdf,jpg,jpeg,png,doc,docx max 8MB). Same authorization
(`authorizeAccess()`: privileged role OR own document, tenant-scoped). No new
Eloquent columns, no new controller actions.

## Components

1. **Stat row (3 cards) → dropped.** Replace with a single line in the card head:
   total document count as a `.uj-pill`, scope ("All employees" / "My documents")
   as a `.uj-stamp` (`data-tone="info"`), next to the "Document vault" title.
2. **Upload form**: keep the existing toggle-open card and Alpine `add` boolean.
   Replace the `$fs` inline-style inputs with `.uj-lv-in` (matches every other
   form in the app, see `resources/css/app.css:2862`).
3. **Category icons**: emoji map (`$catIcon`) → SVG tinted tile, following
   helpdesk's `$categoryMeta` convention (`resources/views/screens/helpdesk*.blade.php:32-39`) —
   32px `.uj-lv-rw-ico` tile, tinted background, category-colored icon.
4. **List rows**: replace the raw inline-style grid rows with `.uj-lv-rw` /
   `.uj-lv-rw-head` / `.uj-lv-rw-ico` / `.uj-lv-rw-t` / `.uj-lv-rw-1` / `.uj-lv-rw-2`
   (`resources/css/app.css:2919-2930`). Category grouping header stays, restyled
   as a `.uj-lv` section label.
5. **Row detail → side-drawer.** Clicking a row opens the shared `.wd-*` drawer
   grammar (`resources/css/app.css:869-966`, pattern established in
   `resources/views/partials/ticket-drawer.blade.php`): title, category stamp,
   owner (privileged view only — non-privileged users only ever see their own
   documents so the owner field is redundant for them), file chip (extension +
   filename, reusing the ticket-drawer attachment-chip markup at
   `ticket-drawer.blade.php:38-58`), size, upload date, and Download + Delete
   actions in the drawer footer. Delete keeps its `confirm()` step (or the
   drawer-native confirm pattern already used elsewhere, whichever
   `ticket-drawer.blade.php`/knowledge-bank drawer actually implements —
   match that, don't invent a third).
6. **Empty state**: restyled to match current card conventions, no behavior
   change (same copy, same bilingual `x-text` pattern).

## Data flow

Unchanged. `DocumentController::screenData()` still returns `privileged`,
`documents` (grouped by category), `employees` (privileged-only picker),
`categories`. The Blade view only changes how this data is rendered.

## Error handling

Unchanged — validation errors still surface via `$errors` at the top of the
upload card (existing `@if ($errors->any())` block, restyled to the app's
alert convention if one exists, otherwise kept as-is).

## Testing

No backend logic changes, so existing feature tests covering
`DocumentController` (upload validation, download/delete authorization,
tenant scoping) remain the source of truth — run them to confirm no
regression. No new tests needed since this is a pure render-layer change;
if a `dusk`/browser check exists for this screen, run that too. Manual
verification: open the screen as `hr@amanahku.test` (privileged, all-tenant
scope) and `employee@amanahku.test` (own documents only) via dev-login,
confirm upload, drawer open/download/delete, and category grouping all work
at desktop and down to 390px width.

## Out of scope

- No new document metadata, no bulk actions, no rename/edit action (none
  exist today).
- No change to the upload flow's owner-forcing security behavior for
  non-privileged users (`store()` server-side owner override stays).
