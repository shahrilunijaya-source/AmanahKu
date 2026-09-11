# Session S26 contract: CR-26 Side Quests

## Files to touch

New:
- `database/migrations/<ts>_create_side_quest_tables.php` — 4 tables.
- `app/Models/SideQuest.php`, `app/Models/SideQuestPost.php` — route-model binding + tenant scope; reactions/badges stay `DB::table()` like `big_deal_reactions`/`big_deal_photos`.
- `app/Http/Controllers/SideQuestController.php` — store/retire/approve/suggest/complete/photo/react/screenData.
- `resources/views/screens/side-quests.blade.php` — the screen.
- `resources/views/partials/dash/side-quest-react.blade.php` — CR-30 picker+tally region, copied from `victory-bell-react.blade.php` (first-text-node count, fetch-and-swap).
- `resources/views/partials/awards/side-quest-badges.blade.php` — badge pills, included next to `partials.awards.badges` in both profile.blade.php slots.
- `tests/Feature/SideQuestTest.php` — cross-tenant 404s, badge expiry boundary, live-cap counts only `live`, reaction replace/remove.

Edited:
- `routes/web.php` — 7 new routes near the CR-25/CR-28/CR-29 block.
- `app/Http/Controllers/AppController.php` — `screenData()` match arm `'side-quests' => app(SideQuestController::class)->screenData(...)`.
- `app/Support/Amanahku.php` — sidebar entry under The Playground (after `wins`, alongside `plot-twist`), `PAGES` map entry (title/sub/crumb).
- `resources/views/screens/profile.blade.php` — two call sites (slim card, full profile) get one extra `@include('partials.awards.side-quest-badges', ...)` beside the existing `partials.awards.badges` include.
- `app/Http/Controllers/Concerns/BuildsPeopleData.php` — add a `questBadges` entry to the array built alongside `awardBadges` (~line 242), same shape gate (outside `canViewFull`).

## Schema

Migration `create_side_quest_tables`:
- `side_quests`: id, tenant_id FK cascade, title, blurb nullable, status string (live|retired|suggested), suggested_by nullable FK employees nullOnDelete, created_by nullable FK employees nullOnDelete, timestamps.
- `side_quest_posts`: id, tenant_id FK cascade, quest_id FK side_quests cascade, employee_id FK employees cascade, note nullable text, photo_path nullable string, timestamps. Unique (quest_id, employee_id) — one post per person per quest.
- `side_quest_badges`: id, tenant_id FK cascade, employee_id FK employees cascade, quest_id FK side_quests cascade, post_id FK side_quest_posts cascade, earned_at datetime, expires_at datetime. No unique needed (post_id already unique-per-person-per-quest via side_quest_posts).
- `side_quest_reactions`: id, post_id FK side_quest_posts cascade (no tenant_id — post already carries it, same trade-off notes are irrelevant here since we filter by post_id which is already tenant-checked), employee_id FK employees cascade, reaction string(40), timestamps. Unique (post_id, employee_id, reaction) — same shape as `big_deal_reactions`.

Applied to dev DB via `lerd artisan migrate --no-interaction` after tests pass on sqlite.

## Acceptance item verification map (test docblock / CR26Test.php)

1. `test_acceptance_1_two_to_three_quests_live_in_the_playground` — publish (hr/director only, 403 for manager/employee), cap of 3 live, retiring frees a slot, screen shows `[data-quest]` + title + blurb to any viewer. Verified by running this test.
2. `test_acceptance_2_yati_completes_a_quest_by_posting_and_it_shows_in_the_feed` — complete writes a post + audit, feed newest-first `[data-quest-post]`, photo route 200/404, reaction picker present. Verified by running this test.
3. `test_acceptance_3_badge_on_her_profile_for_30_days` — badge row earned_at=now, expires_at=+30d, `[data-quest-badge]` on own profile and `?emp=`, gone from expiry moment on (row kept). Verified by running this test + a boundary case added in `SideQuestTest.php` (second before/after expiry).
4. `test_acceptance_4_...` — validation (need note or photo), one post per person per quest, retired quest blocks completion but old posts stay, CR-30 react semantics (422 unknown key, toggle remove, replace), suggest/approve flow with cap re-checked on approve, no `award_results` row, route hygiene (no export/report, only 2 GET shapes), cross-tenant 404. Verified by running this test.
5. `test_acceptance_5_keep_it_plain` — kicker swap, no `uj-sq-art`/`uj-db-confetti`/canvas/audio on screen and profile badge. Verified by running this test.
6. `test_always_checks_from_s26` — due-date lock, audit immutability, dashboard unchanged, keep-it-plain honoured (framework checks, already generic). Verified by running this test.

## Non-negotiables mapping

- Audit: `side_quest.published/retired/approved/suggested/completed`, via `AuditLog::record()`.
- Roles: `hr`, `director` (via `authorizeTenantRole`, which already collapses `director` through `Permissions::effectiveRole`).
- No dashboard change: screen only lives at `/app/side-quests` in The Playground; nothing touches `dashboard-slots.md` widgets/bands.
- No outbound calls: none needed, no port involved.
- Tenant check: `assertSameTenant()` on every bound `{quest}`/`{post}` route, same pattern as `BigDealController`/`PlotTwistController`.
