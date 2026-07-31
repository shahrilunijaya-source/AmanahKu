---
name: Amanahku
description: A warm-paper HR workspace where one red is the only voice that asks for action.
colors:
  red: "#d6232b"
  red-active: "#b01b22"
  red-tint: "#fcebec"
  canvas: "#f6f6f3"
  card: "#ffffff"
  shelf: "#ece9e1"
  shelf-line: "#ddd9cf"
  sidebar: "#1f1e1a"
  sidebar-shell: "#211f1b"
  sidebar-soft: "#2b2a25"
  sidebar-line: "#322f29"
  sidebar-text: "#b8b6ad"
  sidebar-dim: "#a5a297"
  ink: "#26251e"
  body: "#5a5852"
  muted: "#6f6c61"
  muted-soft: "#8b887e"
  hairline: "#e6e5e0"
  hairline-soft: "#efeee8"
  success: "#1f8a65"
  success-ink: "#14614a"
  amber: "#c08532"
  amber-ink: "#7a5210"
  error: "#cf2d56"
  info: "#3a6ea5"
typography:
  display:
    fontFamily: "JetBrains Mono, ui-monospace, monospace"
    fontSize: "54px"
    fontWeight: 600
    lineHeight: 1
    letterSpacing: "-0.03em"
  headline:
    fontFamily: "Poppins, ui-sans-serif, system-ui, sans-serif"
    fontSize: "22px"
    fontWeight: 500
    lineHeight: 1.25
    letterSpacing: "-0.025em"
  title:
    fontFamily: "Poppins, ui-sans-serif, system-ui, sans-serif"
    fontSize: "16px"
    fontWeight: 600
    lineHeight: 1.35
    letterSpacing: "normal"
  body:
    fontFamily: "Poppins, ui-sans-serif, system-ui, sans-serif"
    fontSize: "14px"
    fontWeight: 400
    lineHeight: 1.5
    letterSpacing: "normal"
  subline:
    fontFamily: "Poppins, ui-sans-serif, system-ui, sans-serif"
    fontSize: "12.5px"
    fontWeight: 400
    lineHeight: 1.4
    letterSpacing: "normal"
  label:
    fontFamily: "Poppins, ui-sans-serif, system-ui, sans-serif"
    fontSize: "11px"
    fontWeight: 700
    lineHeight: 1.2
    letterSpacing: "0.13em"
  figure:
    fontFamily: "JetBrains Mono, ui-monospace, monospace"
    fontSize: "26px"
    fontWeight: 600
    lineHeight: 1.1
    letterSpacing: "-0.02em"
rounded:
  xs: "5px"
  sm: "7px"
  md: "9px"
  lg: "10px"
  xl: "12px"
  xxl: "14px"
  full: "999px"
spacing:
  xs: "6px"
  sm: "8px"
  md: "11px"
  lg: "16px"
  xl: "20px"
  xxl: "28px"
  section: "34px"
components:
  button-primary:
    backgroundColor: "{colors.red}"
    textColor: "{colors.card}"
    rounded: "{rounded.sm}"
    padding: "0 18px"
    height: "40px"
  button-primary-hover:
    backgroundColor: "{colors.red-active}"
  button-ghost:
    backgroundColor: "{colors.card}"
    textColor: "{colors.ink}"
    rounded: "{rounded.sm}"
    padding: "0 16px"
    height: "40px"
  card:
    backgroundColor: "{colors.card}"
    rounded: "{rounded.xl}"
    padding: "18px"
  shelf:
    backgroundColor: "{colors.shelf}"
    rounded: "{rounded.xxl}"
    padding: "24px 26px 22px"
  input:
    backgroundColor: "{colors.card}"
    textColor: "{colors.ink}"
    rounded: "{rounded.md}"
    padding: "0 14px"
    height: "44px"
  input-focus:
    backgroundColor: "{colors.card}"
    textColor: "{colors.ink}"
  pill:
    backgroundColor: "{colors.hairline-soft}"
    textColor: "{colors.muted}"
    rounded: "{rounded.full}"
    padding: "2px 9px"
  stamp:
    backgroundColor: "{colors.hairline-soft}"
    textColor: "{colors.muted}"
    rounded: "6px"
    padding: "3px 8px"
  nav-row:
    backgroundColor: "{colors.sidebar}"
    textColor: "{colors.sidebar-text}"
    rounded: "{rounded.sm}"
    padding: "8px 10px"
  nav-row-active:
    textColor: "{colors.card}"
  toast:
    backgroundColor: "{colors.card}"
    textColor: "{colors.ink}"
    rounded: "{rounded.xl}"
    padding: "12px"
---

# Design System: Amanahku

The tokens themselves live in [resources/css/app.css](../resources/css/app.css), and that file
stays the source of truth. This document records the intent, so the reasoning is not lost the
next time someone adds a colour.

## Overview

**Creative North Star: "The Quiet Ledger"**

Amanahku is a record of work, kept on paper. The page is a warm off-white sheet (`#f6f6f3`), never pure white and never grey-blue. Text is ink, not black. Edges are drawn with a 1px hairline the way a ruled ledger draws them, not with a shadow. Every number, timestamp, percentage and duration is set in mono with tabular figures, so a column of them stacks like a hand-kept account book instead of dancing as the digits change.

One red (`#d6232b`) carries the whole voice of the system. It marks the primary action, the focus ring, the active nav icon, and nothing else. Tabs, selected segments and current filters use a white pill on a neutral track, not red, because red answers the question "what do I do here" and never "where am I". This restraint is what makes a screen with twenty rows of leave requests still tell you in one glance where the one button is.

The interface is dense but not cramped. Type runs small (14px body, 11px labels) because these screens hold a working day's worth of rows; the compensation is generous line-height, a hard 920px reading measure, and whitespace between sections rather than inside them. The dark sidebar (`#211f1b`) is the one heavy element on the page: it holds navigation and the clock, so the paper stays undisturbed for content. Nothing decorates. Every visual weight in the system is either a status, a hierarchy, or an action.

**Key Characteristics:**
- Warm paper canvas, ink text, never a cold grey
- One red, used rarely and only for action and focus
- Hairlines draw edges; shadows only mean distance
- Mono, tabular figures for every number
- Five type sizes, one role each, no sizes in between
- Two materials: `--card` white for content, `--shelf` for the one live task
- Status is a stamped word, never a colour alone

## Colors

A warm neutral field with a single saturated accent and four semantic tones that appear only as small stamped surfaces.

### Primary
- **Signal Red** (`#d6232b`): The primary action, the focus ring, the active navigation icon, the sender's own message bubble, and the PWA theme colour. Never a background field, never a tab indicator.
- **Pressed Red** (`#b01b22`): The hover and active state of a red fill. Darker, not lighter, so pressure reads as pressure.
- **Red Tint** (`#fcebec`): The 3px focus glow around a field, error alert backgrounds, and the cursor cell in the roster picker. The only place red covers real area.

### Neutral (the paper stack)
- **Paper** (`#f6f6f3`): The page canvas and the header, which takes the canvas rather than a colour of its own.
- **Card** (`#ffffff`): Content surfaces. Anything you read, review or scroll.
- **Shelf** (`#ece9e1`): A warmer, heavier paper for the one thing you must do now: today's attendance shelf, the current reporting figure, the live thread. It is a material, not a card variant.
- **Shelf Line** (`#ddd9cf`): The hairline that belongs to a shelf, one step darker than the page hairline.
- **Hairline** (`#e6e5e0`) / **Soft Hairline** (`#efeee8`): Every card border and row divider. The soft one is for dividers inside a card, the standard one for the card's own edge.
- **Ink** (`#26251e`): Headings, values, primary row text.
- **Body** (`#5a5852`): Running copy and default control text.
- **Muted** (`#6f6c61`) / **Muted Soft** (`#8b887e`): Sublines, captions, chevrons, disabled affordances.

### Sidebar (the dark column)
- **Sidebar** (`#1f1e1a`): The near-black of the brand. A dark with the same warm bias as the paper, so the two read as one family rather than a light UI with a black bar bolted on. The token is what the wizard header, the register split panel and the tenant secondary-colour default all use. The app shell's own column paints one step lighter (`#211f1b`, hardcoded on `.uj-sidebar`); treat the token as canonical and prefer it for anything new.
- **Sidebar Soft** (`#2b2a25`): Rail flyout panels and the active child row.
- **Sidebar Line** (`#322f29`): Group rules, the nesting rule under an expanded row, the footer divider.
- **Sidebar Text** (`#b8b6ad`) / **Sidebar Dim** (`#a5a297`): Row labels and, one step quieter, section eyebrows and counts. White (`#fff`) is reserved for the hovered or active row and the clock.

### Semantic
- **Success** (`#1f8a65`): Fills, progress bars, rules and icons only.
- **Success Ink** (`#14614a`): Success used as *words*. `--success` measures 3.97:1 on the canvas and 3.5:1 on a shelf, so it fails WCAG AA the moment it carries text rather than a dot or a border.
- **Amber** (`#c08532`): Owed, pending, partial. The timesheet bar, the "action needed" stamp.
- **Amber Ink** (`#7a5210`): Amber used as *words*, on tab counts and warn chips. Same split as success.
- **Error** (`#cf2d56`): Rejections and field-level validation, distinct from Signal Red so a failure never looks like a call to action.
- **Info** (`#3a6ea5`): Neutral notices and the customise-mode selection ring.

### Named Rules

**The One Voice Rule.** Red means "act" or "you are focused here". It never marks the current tab, the selected segment, or a decorative accent. If two red things are visible on one screen, one of them is wrong.

**The Text-Safe Tone Rule.** A semantic colour used as a fill and the same colour used as text are two different tokens. Use `--success-ink` (and the paired dark tones inside stamps) whenever the colour carries words.

**The Tint-By-Mix Rule.** Tone backgrounds are mixed at 7–14% of the tone into white with `color-mix()`, never hand-picked. A new tone gets its surface for free and stays in family.

## Typography

**Display / Figure Font:** JetBrains Mono (self-hosted, `ui-monospace` fallback)
**Body Font:** Poppins (self-hosted, `ui-sans-serif, system-ui` fallback)

**Character:** Poppins gives the interface a rounded, plain-spoken voice that stays legible at 11px, which most geometric sans faces do not. JetBrains Mono does all the arithmetic: clocks, percentages, durations, counts, IDs. The pairing is the whole system in miniature, friendly prose next to unarguable numbers.

### Hierarchy

The ramp is five steps, one role each: `11 / 12.5 / 14 / 16 / 22`. It is deliberately finer at the small end than a modular scale would be, the same reasoning dense operational UI on iOS uses.

- **Label** (700, 11px, `.13em`, uppercase): Sidebar eyebrows, nav section headers, stamps, mono meta, counts, timestamps.
- **Subline** (400–500, 12.5px): Captions, secondary rows, table sublines, link text.
- **Body** (400–500, 14px, 1.5): Nav rows, buttons, inputs, running copy. Prose caps at 52–64ch; the page itself caps at 920px.
- **Title** (600, 16px): Card titles and section headings.
- **Headline** (500, 22px, `-0.025em`, `text-wrap: balance`): The page `h1` and the sidebar clock. There is exactly one per screen.
- **Figure** (600, 26px or 54px mono, tabular): The one number a screen exists to report. 26px inside a stat card, 54px as the lead figure on a reporting shelf.

### Named Rules

**The Five Steps Rule.** Do not add a size between the ramp steps. If something needs to sit between 14px and 16px, it needs a different weight or colour, not a sixth size.

**The Tabular Number Rule.** Any digit that can change on screen (clock, percentage, count, duration) is mono with `font-variant-numeric: tabular-nums`. Numbers must not shift their neighbours when they update.

**The Inherit Rule.** `button, input, select, textarea { font: inherit }` is load-bearing. A bare control otherwise drops to 13.33px Arial and falls off the ramp entirely.

## Layout

**The shell.** A fixed 248px dark sidebar, collapsible to a 64px rail above 900px (Ctrl/Cmd+B, state in `localStorage`), and off-canvas below 900px behind a `rgba(31,30,26,.32)` backdrop. The main pane scrolls under a 56px header that floats on the page canvas: no border under it, instead a 26px gradient-plus-blur fade (`.uj-hd-fade`) that dissolves content passing beneath.

**The measure.** Every screen, its heading and its body, sits in one centred column. Focused screens cap at **920px**; data-dense screens (tables, reports, directory, messages) opt into **1280px**; the board and org chart take the full width. Gutters are 28px desktop, 16px mobile. The heading block scrolls away with the content rather than sitting in a second fixed band.

**Rhythm.** Spacing is convention, not tokens: 6/8/11/16/20/28 with 34px between major sections. Cards pad 16–20px; shelves pad 24–26px; the head stack uses a 16px `gap` so a conditionally hidden banner leaves no residue.

**Responsive strategy.** The header is a container query context (`container-type: inline-size`), so its controls fold on the header's own width, not the viewport: labels drop at 960px, secondary actions fold into a More menu at 720px, people-search drops at 520px. Everything else uses viewport media queries on purpose, since `container-type` implies layout containment and would trap the fixed-position modals that screens render into `<main>`. Breakpoints in use: 480 / 560 / 640 / 700 / 820 / 900 / 1024. Below 900px the sidebar becomes an off-canvas drawer behind a hamburger and a backdrop; the layout is verified down to 390px.

### Named Rules

**The One Column Rule.** A screen never stretches edge to edge and never pins left. Header and body share the same measure, so screens read consistently as you move between them.

**The Fold, Don't Drop Rule.** When the header narrows, controls relocate into the More menu. Only the desktop-oriented people-search is genuinely removed, and only on phones.

## Elevation & Depth

The system is **flat by default**. A 1px hairline draws every edge; a shadow is not decoration but a statement of distance, and its size is proportional to how far the element actually floats above the page. A card at rest has no shadow at all. Depth also comes tonally, canvas → shelf → card, before it ever comes from a shadow.

### Shadow Vocabulary
- **Menu** (`0 5px 14px rgba(31,30,26,.13)`, token `--shadow-menu`): Dropdowns and popovers. They already carry a hairline that defines the edge, so the shadow only lifts them.
- **Hover lift** (`0 4px 16px rgba(31,30,26,.07)` + `translateY(-1px)`): A clickable card responding to the cursor.
- **Toast** (`0 12px 34px rgba(38,37,30,.15), 0 2px 6px rgba(38,37,30,.06)`): Transient, above everything.
- **Drawer** (`-14px 0 44px rgba(31,30,26,.12)`) and **mobile sidebar** (`8px 0 44px rgba(31,30,26,.28)`): Panels sliding over the page; the distance is the point.
- **Rail flyout** (`0 6px 16px rgba(0,0,0,.42)`): A dark panel over light content, no border, because the contrast already separates it.
- **Inset selection** (`inset 0 0 0 1.5px`, or `0 0 0 2px`): Selection and today-markers are rings, not glows.

### Named Rules

**The Border-Then-Shadow Rule.** If an element has a hairline, its shadow may only lift it. A wide soft blur under a bordered element reads as a generic halo, not elevation.

**The Distance Rule.** Shadow size tracks real distance: 5px blur for a menu, 34–44px for a drawer or toast. Never the reverse.

## Shapes

Corners are consistently soft but never pill-shaped unless the element *is* a pill. The working scale is **5px** (focus ring), **7px** (small chips, nav children), **9–10px** (buttons, inputs, header controls, rows), **12px** (cards, toasts, the sidebar today dock), **14px** (shelves and large panels), **999px** (pills, progress tracks, status dots, counts). Radius grows with the surface: the bigger the paper, the softer the corner.

Borders are always 1px and always a hairline token; 2px appears only as an active tab underline and a selection ring. Avatars are 30–32px squircles (8–9px radius), not circles, except for status dots and the reaction pills.

### Named Rules

**The Radius-With-Size Rule.** Small controls take 7–9px, cards take 12px, shelves take 14px. Do not put a 14px radius on a 32px control or a 6px radius on a full-width panel.

## Components

### Buttons
- **Shape:** Softly rounded (8px), 32–46px tall depending on context, horizontal padding 13–26px. Height and padding are set at the call site; the class carries colour and motion only.
- **Primary:** Signal Red fill, white text, 500–600 weight. Hover deepens to Pressed Red; press scales to `0.97` so the button feels physical.
- **Ghost:** White fill, hairline border, ink text. Hover darkens the border to Muted Soft; press scales identically.
- **Focus:** 2px Signal Red outline at 2px offset, globally, on every focusable element.

### Cards and shelves
- **Card:** White, 1px hairline, 12px radius, no shadow at rest. Header strip pads 16×20px with a bottom hairline; body pads 18px. A clickable card adds the hover lift.
- **Shelf:** `--shelf` fill, `--shelf-line` border, 14px radius, 24–26px padding. Reserved for the one live task or the lead figure on a screen. One shelf per screen.
- **Bare card:** `.uj-card-bare` strips the frame entirely when a section needs rhythm without a box.

### Inputs
- **Style:** White, 1px hairline, 9px radius, 44px tall, 14px ink text, 0 14px padding. Labels are 12.5px/600 ink, sitting 6px above.
- **Focus:** Border turns Signal Red and a 3px `--red-tint` glow appears. Errors print 12px in `--error` under the field.

### Status: stamps, pills and tone chips
The system marks state with a **stamped word**, never colour alone.
- **Stamp** (`.uj-stamp`): 10.5px/600, `.05em` tracking, 6px radius, tone-tinted background with a text-safe dark foreground. Default is neutral grey; `data-tone` sets red, amber, success, error.
- **Pill** (`.uj-pill`): 11px/600, fully round, for counts and short statuses inside rows.
- **Tone chip** (`.uj-chip-tone`): For elements too small to stamp, the tone fills the chip at 7–8% with a 22–26% border.

### Navigation
- **Sidebar rows:** 14px/500, 8px radius, 11px gap, sidebar-text colour. Hover washes 6% white; active washes 9% white and turns the icon Signal Red. It is never a solid red pill: at twenty-plus rows a filled bar dominates the column.
- **Children** hang off a 1px left rule so nesting reads at a glance; the chevron rotates 90° when expanded.
- **Rail:** Collapsing snaps rather than animates. Hovering a rail icon opens a fixed dark flyout carrying that row and its children.
- **Segmented control:** Neutral track (`--hairline-soft`), white active pill with a 1px shadow. Never red.

### Toast
Bottom-centre on phones, bottom-right from 640px. White, 12px radius, hairline, the toast shadow, a 22px tone-tinted icon disc, 13.5px/500 message, and a 2px `currentColor` progress bar that drains left to right and **pauses on hover**. Flashed page alerts (`.uj-alert`) use the same icon disc and dismiss button so a flash and a toast read as one family.

### Disclosure row
The recurring interaction of the whole app: a full-width button row (title + subline + status + chevron) that expands a panel below it. The panel animates `grid-template-rows: 0fr → 1fr` rather than height, so no measurement is needed and content never jumps.

## Motion

Motion is functional and short. The house curve is `--ease: cubic-bezier(.23, 1, .32, 1)`; overlays use a longer settle (`cubic-bezier(0.32, 0.72, 0, 1)`) and are slower in than out (250ms / 180ms), because opening earns a beat of anticipation and closing should get out of the way. State changes on controls run 120–160ms; presses scale to `0.97`; lists rise into place with a 42ms per-item stagger; disclosures animate `grid-template-rows`, never height. Two utilities carry the rest: `.uj-slide` (`.22s`, a panel entering from the right) and `.uj-fade` (`.15s`, everything else). Nothing animates `width` or `top`.

**Partial navigation.** Moving between screens swaps only the screen body, which dims to `opacity: .55` while the next one is fetched. The sidebar and header never react. The dim is delayed 90ms on purpose, so a fast swap never flickers.

Every animated block carries a `prefers-reduced-motion: reduce` branch that keeps a fade and drops the transform, so movement goes and feedback stays. The sidebar collapse is deliberately instant regardless: it is a keyboard action repeated dozens of times a day, and animating `width` would relayout the main pane every frame.

## Do's and Don'ts

### Do:
- **Do** use Signal Red only for the primary action, the focus ring, and the active nav icon.
- **Do** set every changeable number in JetBrains Mono with tabular figures.
- **Do** stay on the five type steps (11 / 12.5 / 14 / 16 / 22) and change weight or colour instead of inventing a size.
- **Do** draw edges with a 1px hairline token and let tone (canvas → shelf → card) carry depth.
- **Do** stamp a word next to a status colour, so the state survives colour blindness and greyscale printing.
- **Do** use `--success-ink` and the paired dark tone foregrounds whenever a semantic colour carries text.
- **Do** mix tone surfaces with `color-mix()` at 7–14% into white.
- **Do** keep every screen inside the shared measure (920px, or 1280px for data-dense screens).
- **Do** pair every animation with a `prefers-reduced-motion` branch.
- **Do** swap only the affected region for an in-screen action (`resources/js/partial-nav.js`), let `pushState` keep the URL honest, and fetch the Blade partial the server already renders.
- **Do** keep the global `:focus-visible` ring, use `.uj-sr-only` for screen-reader-only text, and give every icon-only control an aria-label.
- **Do** put row layout in a class, not an inline style, on any element `x-show` controls: Alpine wipes the inline `display` on reveal.

### Don't:
- **Don't** use red for tabs, selected segments, or decorative accents.
- **Don't** put a shadow on a resting surface, or a wide soft blur under an element that already has a border.
- **Don't** introduce pure white (`#fff`) as a page background or pure black as text; the canvas is warm paper and the text is ink.
- **Don't** use a cold grey. Every neutral in this system carries a warm bias.
- **Don't** animate `width`, `height`, `top` or `left`; use transforms and `grid-template-rows`.
- **Don't** add a sixth type size or a radius outside the 5 / 7 / 9–10 / 12 / 14 / 999 scale.
- **Don't** make the header a container query context's victim: keep fixed-position modals out of any element that sets `container-type`.
- **Don't** rely on colour alone for a status; if there is no room for a stamp, use a tone chip with a border.
- **Don't** reload the whole page for an in-screen action, and don't duplicate server-rendered markup inside Alpine to avoid it.
