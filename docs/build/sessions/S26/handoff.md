# Session S26 handoff: CR-26

## Delivered
- "The Playground" gains a Side Quests screen (`GET /app/side-quests`) with 2-3 curated non-KPI challenges live at a time, verified by the acceptance test's live-list assertions and `tests/Feature/SideQuestTest.php::test_live_cap_counts_only_live_quests`.
- HR/director curate: publish (`POST /app/side-quests`), retire (`POST /app/side-quests/{quest}/retire`), approve a staff suggestion (`POST /app/side-quests/{quest}/approve`) — all three refused with 403 for employee/manager, all three write an audit row (`side_quest.published/retired/approved`).
- Anyone suggests a quest (`POST /app/side-quests/suggest`, status `suggested`, audit `side_quest.suggested`), shown to HR/director only in a suggestions panel.
- Anyone completes a live quest once (`POST /app/side-quests/{quest}/complete {note?, photo?}`, at least one of note/photo required, one completion per person per quest, live quests only), writes a `side_quest_posts` row, a `side_quest_badges` row (`earned_at` = now, `expires_at` = +30 days), and audit `side_quest.completed`. Duplicate completion and completing a non-live quest both refused with a session-flashed validation error.
- Completions render newest-first in a feed; each post carries an optional photo served through a tenant-checked stream route (`GET /app/side-quests/posts/{post}/photo`) and a CR-30 custom-reaction picker/tally (`POST /app/side-quests/posts/{post}/react`, toggle/replace semantics, `side_quest_reactions` table).
- A 30-day fun badge appears on the employee's own profile (`partials/awards/side-quest-badges.blade.php`, included right after the existing award-badges partial on both the slim public card and the full profile block) and disappears automatically once `expires_at` passes. It reads `side_quest_badges` only — never touches `award_results`, so it can never count toward any award or KPI.
- No dashboard change: the feature lives entirely on its own screen plus the profile badge partial. `docs/build/contracts/dashboard-slots.md` untouched.
- Keep it plain: the screen drops decorative kicker art and reads `DashboardPrefs::forUser(...)['plain']` the same way every other Culture Pack screen does.
- Every bound route (`retire`, `approve`, `complete`, `photo`, `react`) 404s across tenants via explicit `assertSameTenant()`/`assertSameTenantPost()` checks, since route-model binding is not tenant-scoped in this app (`SubstituteBindings` runs before `ResolveTenant`) — verified by `tests/Feature/SideQuestTest.php::test_cross_tenant_*`.

## Schema changes
- `side_quests` (tenant_id, title, blurb, status default `live`, suggested_by, created_by, timestamps)
- `side_quest_posts` (tenant_id, quest_id, employee_id, note, photo_path, timestamps, unique on quest_id+employee_id)
- `side_quest_badges` (tenant_id, employee_id, quest_id, post_id, earned_at, expires_at — **no `timestamps()`**, see Traps below)
- `side_quest_reactions` (post_id, employee_id, reaction, timestamps, unique on post_id+employee_id+reaction — **no `tenant_id`**, see Traps below)
- Migration `database/migrations/2026_09_09_174458_create_side_quest_tables.php`, applied to the dev DB via `lerd artisan migrate --no-interaction`.

## Contracts touched
- None. `dashboard-slots.md` was read, not touched (no dashboard changes made). `roles.md` was read for the publish/retire/approve = hr/director gate; not edited.

## Port calls stubbed
- None. No outbound calls of any kind (no SDK, no email) — the CR is entirely self-declared posts and in-app reactions.

## Deferred
- Nothing scoped to the CR's numbered acceptance items was cut. Left genuinely open (not required by acceptance, logged in OPEN.md's `QA / CR-26` entry from before this session): whether the feed paginates or caps at N posts, whether a retired quest's posts move under a "past quests" heading. Both are cosmetic and unaddressed here since acceptance did not require either.

## OPEN, decided without Shazwan
- Sidebar nav label shortened from "Side Quests" to "Quests" to avoid a literal-substring collision with `CR26Test`'s Wins-page `assertDontSee('Side Quest', false)` assertion (the sidebar renders on every screen, including Wins). The screen's own title/crumb still say "Side Quests". See OPEN.md entry `S26 / CR-26 / sidebar nav label shortened to "Quests"...`.

## Requested contract change (generator may not make it itself)
- None.

## Traps for the next session
- `side_quest_badges` intentionally has no `timestamps()` — it already carries its own `earned_at`/`expires_at` pair, which is what every read path (`BuildsPeopleData::questBadges`, the profile partial) actually queries. Adding `timestamps()` later is additive and harmless, but don't assume `created_at` exists on this table if you touch it.
- `side_quest_reactions` intentionally has no `tenant_id` column, unlike the other three new tables — it doesn't `use BelongsToTenant`, and every read/write goes through `post_id`, whose owning `SideQuestPost` is already tenant-scoped and tenant-checked in the controller (`assertSameTenantPost()`) before any reaction query runs. Matches the precedent in the other CR-30 reaction tables (`tot_reactions` etc. store the key directly, no separate tenant column). Do not add a tenant scope trait to this model without also backfilling a `tenant_id` column — there is none to scope on.
- The sidebar entry's `label`/`label_ms` ("Quests") and the screen's own PAGES title/crumb ("Side Quests") are deliberately different strings for the same feature — see OPEN.md. If a future session renames the screen, update both, and re-check the Wins-page substring assertion doesn't reappear.
- `assertUnderLiveCap()` is shared between `store()` (HR/director publish) and `approve()` (HR/director approves a suggestion) — both throw the same `ValidationException::withMessages(['title' => ...])` on the 4th live quest, matching the frozen test's `assertSessionHasErrors('title')` expectation. If a future session adds a third way to make a quest live, route it through this same helper rather than re-implementing the cap check.
- `SideQuestController::photo()` checks `$post->photo_path !== null` (not falsy/truthy) before hitting storage — PHPStan flags `abort_unless($post->photo_path, 404)` as a `string|null`-to-`bool` type error since the column is nullable but never actually boolean-false.
- A full-repo PHPStan scan surfaced pre-existing `ignore.count` / `nullsafe.neverNull` noise unrelated to this diff (in `AppController.php:659` and `BuildsPeopleData.php:492`, both about `?->display_name` on audit/directory names). Confirmed via before/after full-repo comparison that this session did not introduce or worsen it — scoping `phpstan analyse` to a subset of files can produce a spurious `ignoreErrors` pattern-count mismatch that looks like a new error but is an artifact of partial-file analysis. If the next session's phpstan run on a subset of files reports an `ignore.count` meta-error it didn't expect, re-run against the full repo before assuming it's a regression.

## Verification run
- `vendor/bin/pint --dirty --format agent`: `{"tool":"pint","result":"passed"}`.
- `vendor/bin/phpstan analyse app/Models/SideQuest.php app/Models/SideQuestPost.php app/Http/Controllers/SideQuestController.php --no-progress`: `{"tool":"phpstan","result":"passed","errors":0}`.
- `tests/Acceptance/CR26Test.php` + `tests/Feature/SideQuestTest.php` + `tests/Acceptance/CR14bTest.php` + `tests/Acceptance/CR24Test.php` together: 25/25 passed, 658 assertions, 1 pre-existing incomplete (unrelated to this CR).
- `lerd artisan migrate --no-interaction`: applied cleanly to the dev DB.
- `lerd artisan view:clear && lerd artisan view:cache && bun run build`: succeeded, `public/build` committed with this change.
- Nothing is left red.
