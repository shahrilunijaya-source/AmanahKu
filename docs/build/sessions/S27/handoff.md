# Session S27 handoff: CR-27 (Mystery Award)

## Delivered
- Director or that month's rotating 3-person committee seals a Mystery Award pick (`POST /app/awards/mystery {employee_id, category, explanation}`) for the current selection month, verified by acceptance items 1 and 3.
- Category, winner and explanation stay hidden from everyone (including the picker and the director) on `/app/dash`, `/app/awards` (both forms), `/app/profile` and `/app/profile?emp=` until `awards:publish` stamps `published_at` on the 1st, verified by acceptance item 2 for three of its four secrets (see Traps below for the fourth) and fully by `tests/Feature/MysteryAwardTest.php` with a non-colliding secret pair.
- On reveal, the dashboard awards band renders `data-slide="mystery"` as the LAST slide, and `/app/awards` renders `data-award="mystery"`, both with the category as the award name, `data-winner="<id>"`, the winner's name and the explanation, verified by acceptance items 1 and 4.
- No back-to-back winner (previous month's winner refused, 422 on `employee_id`), no rubric field, never an `award_results` row (so no Hall of Fame, no profile badge, never seen by the CR-14 rule-9/10 resolver even after three wins), every pick/committee write audited (`award.mystery_picked`, `award.mystery_committee`), verified by acceptance item 3.
- Keep it plain: the slide keeps category/winner/explanation, drops only the reveal animation (`[data-plain] .uj-ma-reveal { animation:none; }`), no `uj-db-art`/`<canvas`/`<audio`, verified by acceptance item 4.
- Director-only committee roster (`POST /app/awards/mystery/committee {employee_ids:[3]}`), exactly 3 distinct active employees or 422 on `employee_ids` (covers both wrong-count and duplicate-value cases under the same key), verified by acceptance item 3 and `MysteryAwardTest::test_committee_refuses_an_archived_employee`.
- Committee-member self-picks refused server-side (422 on `employee_id`) — resolves a "left open" item from the QA/CR-27 OPEN.md entry, see `MysteryAwardTest::test_self_pick_is_refused_server_side`.
- Category (max 80) / explanation (max 500) length limits enforced at the boundary, verified by `MysteryAwardTest::test_category_over_80_chars_and_explanation_over_500_chars_are_rejected`.
- A month with only a mystery pick and no regular `award_results` still opens the band with the mystery slide as its sole entry, verified by `MysteryAwardTest::test_mystery_alone_renders_as_the_sole_slide_with_no_regular_award_that_month`.

## Schema changes
- `mystery_awards` (tenant_id, month, employee_id, category max 80, explanation max 500, picked_by, published_at nullable, timestamps; unique `tenant_id`+`month`) — migration `2026_09_09_182408_create_mystery_award_tables.php`.
- `mystery_committee` (tenant_id, month, employee_id, timestamps; unique `tenant_id`+`month`+`employee_id`) — same migration. No Eloquent models for either (see OPEN.md `S27 / CR-27 / no Eloquent models`); applied to the dev DB via `lerd artisan migrate --no-interaction`.

## Contracts touched
- none (`docs/build/contracts/*` is frozen input, not edited).

## Port calls stubbed
- none — no outbound HTTP/SDK/email in this CR.

## Deferred
- CR-30 reactions/comments on the mystery slide — deferred indefinitely by design, see OPEN.md `S27 / CR-27 / no CR-30 reactions/comments on the mystery slide`.
- A non-plain reveal animation beyond the mockup's simple fade-in (`uj-ma-reveal`/`uj-ma-in` keyframes) — the mockup's approved look is a plain fade, nothing more elaborate was specified or built.

## OPEN, decided without Shazwan
- Mockup's category `<datalist>` of the spec's own example names dropped (guaranteed leak on the Select tab) — OPEN.md `S27 / CR-27 / mockup's category <datalist> dropped`.
- Self-picks refused server-side — OPEN.md `S27 / CR-27 / committee-member self-picks refused server-side`.
- No CR-30 reactions/comments on the mystery slide — OPEN.md `S27 / CR-27 / no CR-30 reactions/comments on the mystery slide`.
- No Eloquent models for the two new tables — OPEN.md `S27 / CR-27 / mystery_awards/mystery_committee have no Eloquent models`.
- `test_acceptance_2` left red, root cause is a pre-existing CR-31/S21 easter egg, not this session's code — OPEN.md `QA / CR-27 / S27 CR27Test's test_acceptance_2 cannot be made green: pre-existing CR-31 easter egg collides with the spec's own example category name`.

## Requested contract change (generator may not make it itself)
- none.

## Traps for the next session

**`tests/Acceptance/CR27Test.php::test_acceptance_2_category_is_visible_nowhere_before_publish` is red and this session could not fix it.** Root cause: the test's secret list includes the literal string `'Professional Tab Collector'`, taken verbatim from `docs/specs/CR-27.md`'s own example category list. `resources/views/layouts/app.blade.php`'s inline `<script>` (the CR-31/S21 `tab_collector` easter egg) hardcodes the toast text `'Professional Tab Collector detected.'` unconditionally into every page's server-rendered HTML — the `data-plain`/tab-count gate is client-side only and does not keep the literal string out of the markup PHPUnit scans. This fires on `/app/dash` regardless of anything the Mystery Award feature does, and both frozen files involved (`layouts/app.blade.php` is CR-31 scope; `tests/Acceptance/CR27Test.php` is frozen) are off-limits to this session per RULES rules 3 and 5.

I verified this is not masking a real defect in this session's own code: the other three secrets in that same test (`'Forty-three tabs open'`, `'data-slide="mystery"'`, `'data-award="mystery"'`) do not leak anywhere in the same five-page/four-viewer/two-timestamp matrix, and `tests/Feature/MysteryAwardTest.php::test_category_stays_hidden_across_every_page_and_viewer_with_a_non_colliding_secret` re-runs the identical matrix with a non-colliding category/explanation pair end to end (including the post-publish reveal) and passes. I also found and fixed one independent, real bug of the same shape in my own first draft — a `<datalist>` of the spec's ten example names in the mystery pick form — before concluding the CR-31 collision was the sole remaining cause; removing my datalist did not change `test_acceptance_2`'s failure line.

The precedent for how to resolve this is already in `docs/build/OPEN.md` (`QA / CR-26 / S26 grade PASS, ... Wins assertion was a QA test defect`): a QA pass may fix its own frozen test's defect, a build session may not. Whoever runs the next QA pass on CR-27 should either scope `test_acceptance_2`'s HTML scan the way the CR-26 fix scoped its assertion to `<main>`, or swap the spec's colliding example string for a non-colliding one in the test. Until then, `test_acceptance_2` will fail on any correct implementation of this spec, not just this one.

**Two `public/build/assets/app-*.css` files churned across this commit** (`app-DlnIBYmj.css` deleted, `app-Bf5rqVVH.css` added) — this is the normal content-hash rename from `bun run build` after the CSS additions, not a stray extra build. `app-*.js` did not change (no JS added this session).

**`AwardBoard::slidesForMonth()`'s `@return` docblock was widened to a union type this session** (regular result-slide shape | mystery-slide shape) — deliberate, because the collection now genuinely holds two different object shapes once a mystery slide is pushed onto it, and both `bands.blade.php` and `awards.blade.php` branch on `award_key === 'mystery'` for exactly that reason. It does not silence the pre-existing PHPStan error below (still present after the widen) — it documents real behavior this CR introduced, not a cosmetic tidy-up.

**PHPStan on `app/Support/AwardBoard.php` shows one pre-existing `return.type` error** (line 72, the `slidesForMonth()` docblock vs. the Eloquent-derived object literal it returns) that already existed before this session's changes — confirmed by isolating the pre-CR27 version of the file and re-running phpstan against it alone, and by a full-repo run showing 79 similar pre-existing `return.type`/property-access errors across many unrelated files (`Awards.php`, `ProfileWall.php`, `DashboardBands.php`, etc.) — this codebase's Eloquent dynamic-property pattern routinely produces this class of error at level 5 and it is not something S27 introduced or is in scope to fix. `AwardController.php`, `AwardsPublish.php` and `MysteryAwardTest.php` show zero phpstan errors.
