# Session S05 contract: CR-30 (custom Unijaya reactions)

## Files to touch
- `database/migrations/2026_09_10_100000_create_reactions_table.php`: new `reactions` table; widen the `emoji` column on `tot_reactions`, `knowledge_reactions`, `birthday_wish_reactions` to 40 so a key fits.
- `app/Models/Reaction.php`: new. `DEFAULTS` (the eight), `forTenant()` seeds them once, `active()`, `labels()` (retired included), `validKey()` rule.
- `app/Http/Controllers/ReactionController.php`: new. `index` (`GET /app/reactions`), `store` (`POST /app/admin/reactions`), `retire` (`POST /app/admin/reactions/{key}/retire`), HR and management only for the writes, audit rows through `AuditLog::record`.
- `app/Http/Controllers/TotController.php`, `KnowledgeController.php`, `BirthdayWishController.php`: `react()` validates `reaction` against the active keys and stores the key in the existing `emoji` column; the birthday JSON gains `reactions` and `mine` for the wish.
- `app/Http/Controllers/WorkItemController.php`: `requestHelp()` (`POST /app/board/{workItem}/request-help`), tags Helper through `syncParticipants` (without its generic "added you" notice), notifies the helper, audit via the existing `participants` change.
- `routes/web.php`: the four routes above.
- `resources/views/partials/reaction-picker.blade.php` (new, `data-reaction-pick`), `resources/views/partials/reaction-tally.blade.php` (new, `data-reaction-count`), used by `partials/tot-actions.blade.php`, `partials/knowledge-comments-drawer.blade.php`, `partials/dash/birthday-wish-row.blade.php`; `screens/tot.blade.php` top-3 line shows icons and labels; `screens/settings.blade.php` gains a "Reactions" card for HR.
- `resources/views/partials/work-drawer.blade.php` + `resources/js/work-board.js`: "Request help" control in the card drawer (person + message).
- `resources/js/tot-card.js`, `knowledge-card.js`, `dashboard` birthday wishes JS: send `reaction` instead of `emoji`.
- `resources/css/app.css`: picker and tally chips.
- `app/Models/TotSession.php`, `KnowledgeEntry.php`: `EMOJI` constants retired from validation (kept, unused, for old data readers).
- `tests/Feature/*` that post `emoji`: updated to `reaction` keys.
- `public/build/*`, `docs/build/OPEN.md`, `docs/build/sessions/S05/handoff.md`.

Not touched: `CLAUDE.md`, `docs/build/RULES.md`, `docs/build/contracts/*`, `tests/Acceptance/*`, the dashboard layout.

## Schema changes
- `reactions`: id, tenant_id, key (40), label (60), icon (16), sort (smallint), retired_at (nullable), timestamps; unique (tenant_id, key). Migration `2026_09_10_100000_create_reactions_table.php`. Same migration widens `emoji` on the three reaction tables to 40. Run on the dev database.

## Acceptance items and how each is verified
1. Picker shows the eight everywhere: `CR30Test` item 1 (catalog JSON, picker markup on TOT, Knowledge Bank, dashboard birthday wishes; react-by-key once per person per item; `emoji` field 422). Browser: open the TOT drawer, a lesson drawer, a birthday wish, see the same eight.
2. Send Help notifies nobody: item 2 (`app_notifications` count unchanged after Send Help on a session and a lesson). Browser: react Send Help, bell unchanged.
3. Request Help notifies and tags: item 3 (403 for a stranger, 422 without person or message, Helper tag via `roleFor`, one notification naming the asker with the message and a card link, `participants` audit row, second ask re-notifies without a second tag). Browser: drawer control as Kussairi on his card, Shazwan's bell.
4. HR adds a ninth: item 4 (403 for staff, catalog 9 then 10, 422 on the eleventh and on a duplicate key, no rename by any verb). Browser: Company Settings reactions card.
5. Retired reaction still shows on old items: item 5 (retire route, catalog and picker drop it, tally and label stay on the session, 422 on a new use, count kept). Browser: retire Claim Bila?, old session still shows it.

Culture pack: reactions are opt-in by nature, feed no award (Chief Hype Officer is CR-14's, not built), no management reporting, no animation added beyond the existing flyout, so Keep it plain needs no new wiring.
