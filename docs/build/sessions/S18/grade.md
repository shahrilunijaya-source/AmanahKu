# QA grade: S18 / CR-14b (awards carousel, Awards screen, nominations, manual picks, tasks, badge, override)

**Verdict: PASS after fixes F1 to F8.** The S18 build passed `CR14bTest` as delivered, but
driving the full September cycle on the dev database (43 auto tasks, two nominations, two
manual picks, freeze, publish, carousel, override) exposed eight defects the acceptance test
could not see: a probation person left out of the publish notice, plain HTML forms landing
on a JSON body, no way for the Director to reach the override from the screen, a
backslash-escaped Alpine string, manual picks filed against the wrong month, the Chosen One
slide not first, reaction buttons showing raw keys and no winner role, and a carousel with
no arrows, dots or swipe. All eight are fixed in this grade, each pinned by a feature test
in `tests/Feature/AwardsTest.php` (`f5_`, `s18_f2_a_`, `s18_f3_`, `s18_f4_`, `s18_f5_`,
`s18_f6_f7_f8_`).

Graded on `http://worktree-change-request-tracker.amanahku.localhost` with the quick-login
accounts. Cast: Shahril is the Director, Kussairi the manager, Haryati the branch senior
manager, Shazwan plain staff. The scheduler commands (`awards:tasks`, `awards:freeze`,
`awards:publish`) were driven with `Carbon::setTestNow(...)` + `Artisan::call(...)` in one
tinker process; the browser dev clock drove the window, the nominations, the picks and the
carousel.

## Fixes made during the grade

| # | Defect (S18 tree) | Fix | Test |
|---|-------------------|-----|------|
| F1 | S17 F5 sent the publish notice to `status='active'` only; the five `probation` staff (still employed, still on the boards) got nothing | `AwardsPublish` recipients: `Employee::active()` minus `resigned`, with a user | `f5_publish_notifies_active_staff_only` (probation person now asserted told) |
| F2 | Nominate and Select forms on the Awards screen are plain HTML `POST`s; the controller always answered JSON, so the browser landed on `{"ok":true}` and a validation error was a bare JSON 422 | `nominate`/`select`/`adjust` return `back()->with('ok', ...)->with('tab', ...)` for a non-JSON request, JSON otherwise; the screen opens on the tab it came from and shows the flash or `$errors->first()` | `s18_f2_a_plain_form_nomination_comes_back_to_the_tab_with_a_flash_or_the_error` |
| F3 | Global Clause 3 override existed only as a route; nothing on the screen let the Director use it | `canAdjust` (director) in `screenData`; each result row carries a `<details>` "Adjust result" form (`select[name=employee_id]`, `input[name=reason]`) posting to `/app/awards/{result}/adjust`; hidden for everyone else | `s18_f3_only_the_director_sees_an_adjust_form_on_a_result` |
| F4 | `screens/awards.blade.php` line 35 wrote `'This month\'s winners'` inside an Alpine expression, which Blade emitted as `\"`; Alpine logged "Alpine Expression Error" and the tab title rendered wrong | `@js("This month's winners")` | `s18_f4_the_awards_screen_carries_no_backslash_escaped_alpine_strings` |
| F5 | `selectionMonth()` returned the current month whenever the day was past the 20th, so a pick made on Tuesday 2026-09-29 before the last Monday of a five-Monday month, or on the 21st, filed against the wrong month | picks go to the current month from its last Monday (the `awards:tasks` day) onward, and to the previous month before that | `s18_f5_a_pick_goes_to_the_current_month_from_its_last_monday_and_to_the_previous_month_before_that` |
| F6 | `AwardCatalog::order()` put the nominated awards before `chosen_one`, so the Director's pick was the third slide | order is `chosen_one`, `main_character`, `office_yoda`, `new_but_dangerous`, then `Awards::KEYS` (the spec's slide order) | `s18_f6_f7_f8_…` |
| F7 | Reaction buttons rendered the raw CR-30 keys (`power`, `legend`) and the winner line showed no role | buttons render `Reaction::describe($key)` icon + label; `AwardBoard` loads `position_id` so `Employee::position` resolves; the winner line shows the role | `s18_f6_f7_f8_…` |
| F8 | The carousel had a timer only: no arrows, no dots, no swipe, so a reader could not go back to a slide | prev/next buttons (`data-carousel-prev/next`), one dot per slide, `@touchstart`/`@touchend` swipe with a 40px threshold, all plain CSS and Alpine | `s18_f6_f7_f8_…` |

## Acceptance items

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | Peer nominations tallied on publish (`main_character`, `office_yoda`) | PASS | `awards:tasks` at Monday 2026-09-21 08:00 created 0; at the last Monday 2026-09-28 08:00 created 43 cards (34 nominate due 09-30, 9 select due 10-01), `source='awards'`, label `system`; a second run created 0. Shazwan nominated Adri (main_character) and Kussairi (office_yoda) through the Nominate tab (`grade-cr14b-4-awards-screen.png`); a second main_character nomination was refused on the page with the one-per-award message (`grade-cr14b-4-duplicate-rejected.png`); his nominate card 316 auto-closed. `awards:freeze` at 09-30 23:59 wrote 11 snapshot rows; `awards:publish` at 10-01 08:00 wrote 12 auto/nomination rows plus the 2 manual picks, audit row 1144, 34 notifications (incl. the 5 probation staff after F1). Adri and Kussairi appear as `source='nomination'` winners on the screen. |
| 2 | Dashboard carousel from the 1st to the 7th, one award per slide, react and comment in place | PASS | Shazwan at dev clock 2026-10-01 10:00: band `data-band="awards"` above the grid, 9 slides in the F6 order, auto-rotates after ~6 s, arrows and dots move it, react → `data-reactions="1"`, comment "Well deserved, Dzul!" → `data-comments="1"` without leaving `/app/dash`; no console errors (`grade-cr14b-2-carousel.png`). Band absent at 2026-09-30 and 2026-10-08, present on 10-01 and 10-07. Keep it plain: band stays, no rotation after 7 s, greeting drops to "Good morning, Shazwan." (`grade-cr14b-plain-dash.png`). The swipe gesture itself is the `markTestIncomplete` human check; the arrows and dots cover a mouse. |
| 3 | Rule 10 visible on the screen, `?month=` for past months | PASS | `/app/awards` lists 9 awards with their winners and counts (chosen_one 1 reaction / 1 comment matching the dashboard), Past winners tab shows `data-month="2026-09-01"`, View all links to `?month=2026-09-01` which renders the same 9 rows (`grade-cr14b-3-winners.png`). |
| 4 | Awards screen in The Playground, `awards:tasks`, nominate and select gating, auto-close | PASS | Nav item "Awards" under The Playground. Kussairi's Select tab offered only `new_but_dangerous`; picking Haikal (joined within 6 months) saved and closed his select card 5 (`grade-cr14b-4-select-manager.png`); picking Haryati was refused with "Must have joined within the 6 months…". Shahril's tab offered `chosen_one`; DzulHazly with reason "Turned the KPT audit around in a week" saved, audit 1145, card 24 closed (`grade-cr14b-4-select-director.png`). Both picks filed against September after F5. Shazwan has no Select tab; a manager cannot pick `chosen_one`. |
| 5 | Profile badge and hall of fame | PASS | `/app/profile?emp=3` (Hakime, deadline_who + done_and_dusted) shows `data-award-badge` for both; Shazwan's own profile shows none; `data-hall-of-fame` absent everywhere (nobody holds three wins yet; the three-win case is pinned by `CR14bTest::test_acceptance_5`). |
| 6 to 10 | Freeze, publish, computation, rule 9, rule 10 | pinned by CR14aTest | Graded in S17; unchanged by S18 (`CR14aTest` green, see below). |
| GC 3 | Director override with reason, audited | PASS | Shahril at 2026-10-01 opened "Adjust result" on Deadline Who?, moved it from Hakime to Shazwan with a reason: flash "Result adjusted.", row and dashboard slide show "Result adjusted – Hakime was on leave the whole month, Shazwan carried the deadlines", `award_results` 35 `source='adjusted'`, audit row 1148 `field=employee_id` old 3 new 26 with the reason (`grade-cr14b-gc3-adjusted.png`). No adjust form for Shazwan, Kussairi or Haryati. |

## Every-session checks

| Check | Result |
|-------|--------|
| `php artisan test --compact tests/Acceptance/CR14bTest.php` | 8 passed, 1 incomplete (item 2 swipe/timer human check). `tests/Feature/AwardsTest.php` + `CR14bTest` + `CR32Test` + `CR14aTest`: 45 passed, 5 incomplete. Full suite after the fixes: see the commit message. |
| Task due date via API | As Kussairi `PATCH /app/board/205 {due_at: 2026-12-24}` → 422 "Due dates are locked after the first save…". |
| Edit an audit-log row | `AuditLog::orderByDesc('id')->first()->update(['action' => 'tampered'])` → RuntimeException "audit_logs rows are append-only". |
| Dashboard as plain staff on a quiet day | Shazwan, clock 2026-09-09 09:00: no band, the same ten widgets in the same order as `docs/build/baseline/dashboard.png` (summary, clock, tasks, leave, style; calendar, notices, flowers, claims, work) (`grade-cr14b-dashboard-staff.png`). Clock reset to real. |
| Keep it plain | Toggled on for Shazwan: greeting plain, carousel frozen on its first slide, Awards screen has no cheeky text and no animation (`grade-cr14b-plain-awards.png`). Toggled back off. |
| Outbound calls in the diff | None. The only `fetch` calls in the S18 diff are same-origin (react/comment on the engagement partial); the only timer is the carousel `setInterval`. No `Http::`, guzzle, googleapis, `Mail::` or curl in `app/`, `routes/`, `bootstrap/`, `resources/`, `database/`. |
| OPEN entries | The QA shapes entry, the two S18 entries (`@js(csrf_token())` in the layout; band unconditional on the window per CR32Test) and this grade's entry name alternatives and a reversal cost. |
| Protected files | `CLAUDE.md`, `docs/build/RULES.md`, `docs/build/contracts/*`, `tests/Acceptance/*` untouched by S18 (`git diff 63a89a3b..HEAD --stat` on those paths is empty) and by this grade. |
| `layouts/app.blade.php` change | S18 swapped three `csrf-token` meta reads to `@js(csrf_token())` so `CR14bTest::test_acceptance_4`'s `assertDontSee('querySelector')` passes. Same value, same behaviour; accepted with the S18 OPEN entry as the record. |
| Assets | Blade-only changes with inline styles; no new Tailwind classes, `public/build` unchanged, no rebuild needed. |

## Notes for the next session
- Dev DB end state: September `award_results`, `award_snapshots`, `award_nominations`, `award_reactions`, `award_comments`, the 43 `source='awards'` cards and the publish notifications were deleted after the grade. Audit rows 1141 to 1148 stay (append-only), including 1144 `awards.published` for 2026-09-01, so September cannot be re-published on dev without a different month. Dev clock real; Keep it plain off.
- `AwardController::selectionMonth()` is the single place that decides which month a pick belongs to; `awards:tasks` runs on the same last-Monday rule, keep them together if either moves.
- The carousel swipe (`@touchstart`/`@touchend` on the band section) is untested on a real phone; the acceptance test's `markTestIncomplete` still names that human check.
