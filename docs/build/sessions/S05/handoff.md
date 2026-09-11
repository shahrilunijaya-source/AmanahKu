# Session S05 handoff: CR-30

## Delivered
- Per-tenant reaction set (`reactions` table, `Reaction` model) seeded with the eight defaults on first read; `GET /app/reactions` lists the active set in order, verified by acceptance item 1.
- One picker partial (`partials/reaction-picker.blade.php`, `data-reaction-pick`) and one tally partial (`partials/reaction-tally.blade.php`, `data-reaction-count`) wired into the TOT session drawer, the Knowledge Bank lesson drawer and the dashboard birthday wish rows; the TOT list top-3 line reads icon and label from the set, verified by item 1 and in the browser (`s05-tot-picker.png`, `s05-tot-tally.png`, `s05-kb-tally.png`, `s05-wish-react.png`).
- The three react endpoints (TOT, Knowledge Bank, birthday wish) take `reaction` = key, 422 on `emoji`, an unknown key or a retired key; one reaction per person per item, same key undoes, different key replaces; JSON carries `reactions` and `mine` (the birthday endpoint gains both beside its `html`), verified by items 1, 2 and 5.
- Send Help is a plain reaction: it notifies nobody, verified by item 2.
- Request Help: `POST /app/board/{id}/request-help {employee_id, message ≤ 200}` for anyone who may edit the card; tags the person as Helper through `syncParticipants` (its generic "added you" notice suppressed), writes one `app_notifications` row "<asker> asked for your help" with the card title, the message and a link to the card, and the `participants` audit row; asking again re-notifies without a second tag. The card drawer gains a "Request help" row (person select, message, Ask) under People, verified by item 3 and in the browser as Kussairi on card 310 (`s05-request-help.png`, `s05-request-help-done.png`).
- HR and management manage the set from a "Reactions" card on Company Settings (`?only=reactions` works too): add (key, label, icon; 422 past ten active or on a duplicate key) and retire (kept on old items, dropped from every picker, 422 on a new use); no rename route exists and every verb on `/app/admin/reactions/{key}` is refused; both actions write an audit row, verified by items 4 and 5 and in the browser as Hidayah (`s05-settings-reactions.png`, a `goat` reaction was added then retired on the dev DB).
- Keep it plain: the picker's entrance animation is off inside `.uj-db[data-plain]`; nothing else animated was added.

## Schema changes
- `reactions`: id, tenant_id, key (40), label (60), icon (16), sort, retired_at, timestamps, unique (tenant_id, key).
- `tot_reactions.emoji`, `knowledge_reactions.emoji`, `birthday_wish_reactions.emoji`: widened to 40 so a key fits; the key is stored there.
- Migration `2026_09_10_100000_create_reactions_table.php`, run on the dev database.

## Contracts touched
- none

## Port calls stubbed
- none (in-app notifications only; no mail, Google or Track)

## Deferred
- Wall, Events, Awards, Wins and Office Requests do not exist yet; the sessions that build them include `partials.reaction-picker` and `partials.reaction-tally` the same way (see `tot-actions.blade.php` for the live-count variant and `birthday-wish-row.blade.php` for the server-rendered one).
- Chief Hype Officer (CR-14) is not built; reactions feed no award.

## OPEN, decided without Shazwan
- Icons are text glyphs, the key lives in the existing `emoji` column, Request Help follows the card's edit gate and needs no due date, and the double-press case is an undo, see OPEN entry "S05 / CR-30 / icons, storage, Request Help scope, and a contradiction in CR30Test".

## Requested contract change (generator may not make it itself)
- none

## Traps for the next session
- `tests/Acceptance/CR30Test.php` lines 102 to 104 contradict themselves (after the same person presses `power` twice it asserts the tally is 1 and that `mine` is empty). The suite is otherwise green (2684 passed, 1 failed on that line). QA owns the fix during `/qa grade CR-30`; a session may not edit that file.
- The birthday wish region swaps `$root` on every react. Do not add an inner `x-data` inside a wish row: `$root` then resolves to that inner element and the whole region nests inside itself. The row passes the viewer's keys to the picker as a JSON literal instead.
- `@js(...)` inside a double-quoted `x-text="..."` breaks the attribute when the string carries a double quote. Two pre-existing spots in `screens/settings.blade.php` (the features card, lines ~499 and ~518) throw "Alpine Expression Error" on the settings page today; not touched, outside CR-30.
- `vendor/bin/phpstan analyse` reports 17 errors, all on lines outside this session's diff (`BuildsWorkData`, `WorkItem`, `ProfileWall`, older lines in `WorkItemController`); the S05 files pass on their own.
- `TotSession::EMOJI` and `KnowledgeEntry::EMOJI` are no longer used for validation; the birthday composer still uses `TotSession::EMOJI` to offer emoji into the wish text, which is unrelated to reactions.
- The reaction flyout (`.tot-fly-react`) opens downward, unlike the rating flyout, because eight labelled picks do not fit in the space above the heart inside a drawer.
- Dev DB state after this session: card 310 has Nabil and Adri tagged as helpers with two help notifications; Hidayah's wish row 4 carries a `legend` reaction from Kussairi; TOT session 1 (Solehin) has a `chefs_kiss` from Shazwan; the Knowledge Bank entry opened first has a `legend` from Shazwan; reaction `goat` exists retired. Dev clock reset.
