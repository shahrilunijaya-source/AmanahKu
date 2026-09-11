# CR-19 mockup, approved look (S19 builds exactly this)

Screenshots in this folder were taken on the live board with the additions injected. The
session reproduces them in `partials/work-card.blade.php`, `screens/board.blade.php` (drawer)
and `resources/css/app.css`. No new type sizes, no new colours, no motion.

## 1. "Auto" chip on the card face (`board-auto-badge.png`, `done-column-zoom.png`)
- Sits in `.wc-top`, `margin-left:auto`, the same slot `.wc-pri` / `.wc-role` use; after
  them when both show (`+ 6px` gap, as `.wc-pri + .wc-role`).
- Pill, not stamp: `--hairline-soft` fill, `--muted` text, `--t-micro` 600, radius 9999px,
  padding `1px 7px 1px 5px`; drawn bolt SVG 11px, stroke 2.25, `--muted-soft`.
- `title` attribute carries the full reason ("Closed automatically – Attended").
- Attribute for tests: `data-auto-closed="1"` on the `[data-card]` element.
- Reopened card: chip gone, attribute gone (marker cleared).

```css
.wc-auto { margin-left: auto; display: inline-flex; align-items: center; gap: 4px; font-size: var(--t-micro); font-weight: 600; letter-spacing: .2px; color: var(--muted); background: var(--hairline-soft); padding: 1px 7px 1px 5px; border-radius: 9999px; flex-shrink: 0; }
.wc-auto svg { color: var(--muted-soft); }
.wc-pri + .wc-auto, .wc-role + .wc-auto { margin-left: 6px; }
```

## 2. "Pending attendance" on an event card (`todo-column-zoom.png`, `board-phone.png`)
- Footer, right after the date, in the `.wc-when-badge` slot (event cards never show
  "+N days", so nothing competes for it).
- Sentence case. Amber as words: `--amber-ink` text on `color-mix(var(--amber) 11%, #fff)`
  with a 22% inset hairline, radius 6px, padding `1px 7px`, weight 600.
- Attribute for tests: `data-pending-attendance="1"` on the card; the text "Pending
  Attendance" is matched case-insensitively by the acceptance test, so the sentence-case
  rendering is fine as long as the words are there.

```css
.wc-when-badge.wc-when--pending { color: var(--amber-ink); background: color-mix(in srgb, var(--amber) 11%, #fff); box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--amber) 22%, transparent); padding: 1px 7px; border-radius: 6px; font-weight: 600; font-variant-numeric: normal; }
```

## 3. Drawer (`drawer-activity-line.png`)
- Meta line under the title gains " · ⚡ Closed automatically 29 Sep" (bolt SVG, `--muted`).
  No pill in the top bar; the status segment shows Done as it already does.
- The activity line is a comment row (`.wd-cmt`) with a system mark instead of an avatar:
  28px circle, `--hairline-soft`, bolt 13px `--muted`; name "Amanahku" (`.wd-cmt-name`),
  time in mono tabular (`.wd-cmt-at`), body "Closed automatically – <reason>" (en dash).
  It is a real `work_item_comments` row with `employee_id` null, so it lists in order with
  human comments; the view renders the mark when `employee_id` is null.

```css
.wd-cmt--system .wd-cmt-mark { width: 28px; height: 28px; border-radius: 50%; background: var(--hairline-soft); color: var(--muted); display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
.wd-meta-auto { display: inline-flex; align-items: center; gap: 4px; color: var(--muted); }
.wd-meta-auto svg { color: var(--muted-soft); }
```

## 4. Event page
- Attendance select gains "Did not attend" (`did_not_attend`), listed after "Attended".
  Per-attendee status column reads "Did not attend" in `--muted`.

## Not in scope
- No animation on close/reopen (the card simply moves columns via the existing path).
- Keep it plain: nothing here is cheeky or animated, no plain variant needed.
