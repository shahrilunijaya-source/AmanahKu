# QA grade: S03 CR-04 (roles on a card)

Verdict: **PASS**.

Graded in the browser at `http://worktree-change-request-tracker.amanahku.localhost`
(Playwright; the integrated browser could not reach the vhost this session) as Kussairi
(manager, card owner and PM in one) and Shazwan (employee, the tagged / reviewing person),
plus tinker on the dev database. The spec's Adri / Emysha / Yati have no quick-login, so
Kussairi stands in for Adri and the PM, Shazwan for Emysha and Yati.

## Acceptance items

| # | Item | Result |
|---|------|--------|
| 1 | Helper tag shows on her board with 'Tagged – Helper', Assigned counters unchanged, 'helping on 1' | **PASS** |
| 2 | FYI tag shows 'Tagged – FYI', no credit, no counter | **PASS** |
| 3 | Reviewer sees the card under Reviewing, only she moves In Review to Done | **PASS** |
| 4 | Assigned filter hides tagged and reviewing items | **PASS** |

Item 1 detail. Kussairi's "+ Add a card" composer created card 315 (due 01 Sep, so overdue
on the day). In the drawer, "+ Add someone" lists each name with Helper and FYI buttons;
Helper on Shazwan gave the chip "Shazwan · Helper ×". Team board row for Shazwan went from
open 5 / overdue 0 / helping 0 to open 5 / overdue 0 / helping 1 with the line
"helping on 1" under his name; the overdue tagged card did not count against him. On
Shazwan's own board the card carries `data-role="helper"` and the label "Tagged – Helper",
visible under the Tagged chip ("Tagged 1"), `grade-helper-board.png`.

Item 2 detail. The chip's Helper button flipped to FYI on click (one PATCH, no error). Team
board row: helping 0, no "helping on" line; the person window lists card 315 with
"Tagged – FYI" and its summary reads "5 open · 0 overdue · 0 blocked · 0 in review ·
reviewing 1", `grade-fyi-team-window.png`. Shazwan's board shows "Tagged – FYI".

Item 3 detail. Card 316 moved to In Review from the drawer; the Reviewer select (shown to
Kussairi as manager tier) set Shazwan. Kussairi, owner and PM, got 403 "Only the reviewer
can move this card from In Review to Done." from `POST /app/board/316/move`, and the
drawer's Done button left the card in review. Shazwan's board lists the card under
Reviewing with the "Reviewer" label; `PATCH` of the title as Shazwan is 403; the API move
to done is 200, and after Kussairi put it back to review, dragging it into Done on
Shazwan's board moved it (status done on reload), `grade-reviewer-done.png`.

Item 4 detail. Shazwan's board opens on Assigned: 5 own cards visible, cards 315 and 316
hidden, badges To Do 3 / In Progress 2 / In Review 0 / Done 0 unchanged by the tagged and
reviewed cards, `grade-assigned-filter.png`. Tagged shows exactly 315, Reviewing exactly
316, All shows 7.

## Every-session checks

| Check | Result |
|-------|--------|
| `php artisan test --compact tests/Acceptance/CR04Test.php` | PASS (6 pass); all of `tests/Acceptance` 21 pass, 8 incomplete by design |
| Change a Task due date via API, must be rejected | PASS (422 on card 310, lock message) |
| Edit an audit-log row, must be rejected | PASS (update and delete both refused, "audit_logs rows are append-only") |
| Dashboard as plain staff on a quiet day matches baseline | PASS (same eleven cards in the same order; only the rotating greeting and header badge counts differ, as in S01 and S02) |
| Toggle Keep it plain | PASS ("Good morning, Shazwan.", no confetti; the remaining animations are the base page fade and tile entrance transitions that predate the run, same as S02) |
| Grep diff for Google / Track / mail SDK or outbound HTTP | PASS, no hits in the S02+S03 diff |
| New OPEN.md entries name alternatives and reversal cost | PASS (QA shapes entry, S03 default-view entry) |
| No edits to `CLAUDE.md`, `docs/build/RULES.md`, `docs/build/contracts/*`, `tests/Acceptance/*` | PASS (no diff on those paths since S01; acceptance files committed once, as written by QA) |
| Full suite | PASS (2673 passed, 5 skipped, 8 incomplete) |

## Failures for the generator

None.

## Notes, not failures

- When an owner or PM is refused the In Review to Done move, the personal-board drawer
  shows the generic "Could not move this card." rather than the server's reviewer message.
  The drag path shows nothing. Worth surfacing the 403 text; not in acceptance.
- The reviewer roster in the team board drawer is the people already on the team board,
  not every active employee (OPEN entry). Fine for Unijaya's size.
- Dev database after grade: cards 315 (Shazwan FYI) and 316 (done, reviewer Shazwan) remain
  on Kussairi's board; no probe rows deleted. Dev clock reset, plain pref false.
