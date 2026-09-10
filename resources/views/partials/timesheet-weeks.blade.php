{{-- Read-only week-by-week timesheet viewer. Two callers: the Review tab of the
     personal timesheet screen (own weeks, entries link back into Record), and the
     all-staff report's staff viewer (somebody else's weeks, no links — there is no
     edit path into another person's sheet).

     $weeks   list of week blocks from TimesheetController::buildWeekBlocks()
     $baseUrl the timesheet screen to link an entry back to, or null for no links
     $manage  the employee id the viewer may act on as a manager (return/approve/
              unlock/approve-week, CR-03), or null when this is the personal Review
              tab / the viewer isn't a manager for this person --}}
<div x-data="timesheetReview({
        baseUrl: @js($baseUrl ?? null),
        weeks: @js($weeks),
        manage: @js($manage ?? null),
     })">
        <template x-if="weeks.length === 0">
            <div class="uj-tr-panel">
                <div class="uj-tr-empty" x-text="$store.ui.lang==='en' ? 'No weeks yet.' : 'Belum ada minggu.'"></div>
            </div>
        </template>
        <template x-if="weeks.length > 0">
            <div class="uj-tr-panel">
                <template x-for="wk in (currentWeek ? [currentWeek] : [])" :key="weekIdx">
                    <div class="uj-tr-wk" :data-dir="weekDir">
                        {{-- Pager inside the header of the week it pages, and the week
                             label reading as a heading — same shape the all-staff report's
                             person panel uses, because it is the same object. --}}
                        <div class="uj-tr-wk-hd">
                            <button type="button" class="uj-tr-weeknav-btn" @click="prevWeek()" :disabled="weekIdx === 0"
                                :aria-label="$store.ui.lang==='en' ? 'Previous week' : 'Minggu sebelum'">&lsaquo;</button>
                            {{-- The week's name IS the picker: the arrows step one week,
                                 clicking the name jumps to any of them. A real <select> lies
                                 over the label at zero opacity, so this is the platform's own
                                 picker (and its keyboard behaviour) rather than a menu built
                                 by hand. With only one week there is nothing to pick, so the
                                 label renders plain. --}}
                            <div class="lbl uj-tr-wkpick" :data-pick="weeks.length > 1 || null">
                                <b x-text="wk.label"></b>
                                <span x-text="wk.dates"></span>
                                <svg x-show="weeks.length > 1" class="chev" width="11" height="11" viewBox="0 0 24 24"
                                     fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"
                                     stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
                                <select x-show="weeks.length > 1" x-model.number="weekIdx"
                                    :aria-label="$store.ui.lang==='en' ? 'Jump to week' : 'Lompat ke minggu'">
                                    <template x-for="(w, i) in weeks" :key="i">
                                        <option :value="i" x-text="w.label + ': ' + w.dates"></option>
                                    </template>
                                </select>
                            </div>
                            {{-- The week's own total, same as the all-staff report's person
                                 panel. "Did this week add up to five days" is the question
                                 the tab exists to answer, and the day totals below only
                                 answered it one day at a time. No RM here: this partial is
                                 also the employee's own Review tab, and cost is a
                                 management figure (see TimesheetController::canSeeCost). --}}
                            <span class="tot" x-show="wk.lines.length > 0">
                                <b x-text="md(wk.days) + ' md'"></b>
                            </span>
                            {{-- No status and no lines = no sheet was ever started for that
                                 week. Calling that "Draft" would claim a draft exists. --}}
                            <span class="uj-tr-status-badge" :data-status="wk.status || 'draft'"
                                x-text="wk.status === 'submitted' ? ($store.ui.lang==='en' ? 'Submitted' : 'Dihantar')
                                      : (!wk.status && wk.lines.length === 0 ? ($store.ui.lang==='en' ? 'Nothing yet' : 'Belum ada')
                                      : ($store.ui.lang==='en' ? 'Draft' : 'Draf'))"></span>
                            <button type="button" class="uj-tr-weeknav-btn" @click="nextWeek()" :disabled="weekIdx === weeks.length - 1"
                                :aria-label="$store.ui.lang==='en' ? 'Next week' : 'Minggu seterusnya'">&rsaquo;</button>
                        </div>

                        {{-- Manager actions (CR-03): only rendered for a viewer who manages this
                             person's timesheet days (cfg.manage set — see person-weeks.blade.php). --}}
                        <div x-show="manage && anySubmitted()" x-cloak style="margin:8px 0;">
                            <button type="button" class="uj-btn-ghost" style="height:30px;padding:0 12px;font-size:12px;" :disabled="dayBusy" @click="approveWeek()">
                                <span x-text="$store.ui.lang==='en' ? 'Approve week' : 'Luluskan minggu'">Approve week</span>
                            </button>
                        </div>

                        {{-- Per-day status strip (CR-03): submit status, late/resubmitted flags,
                             return reason, zero-hour reason, unlocked note, plus manager actions. --}}
                        <template x-if="dayList(wk).length">
                            <div class="uj-tr-day-status-strip" style="display:flex;flex-direction:column;gap:8px;margin-bottom:12px;">
                                <template x-for="d in dayList(wk)" :key="d.iso">
                                    <div style="border:1px solid var(--hairline);border-radius:9px;padding:8px 10px;">
                                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                            <span style="font-size:12px;font-weight:600;" x-text="d.label"></span>
                                            <span class="uj-tr-status-badge" :data-status="d.status" x-text="
                                                d.status === 'submitted' ? ($store.ui.lang==='en' ? 'Submitted' : 'Dihantar')
                                                : d.status === 'approved' ? ($store.ui.lang==='en' ? 'Approved' : 'Diluluskan')
                                                : d.status === 'returned' ? ($store.ui.lang==='en' ? 'Returned' : 'Dikembalikan')
                                                : ($store.ui.lang==='en' ? 'Draft' : 'Draf')"></span>
                                            <span x-show="d.late" x-cloak style="font-size:11px;font-weight:600;color:var(--error);" x-text="$store.ui.lang==='en' ? 'Late submission' : 'Lewat dihantar'"></span>
                                            <span x-show="d.resubmitted" x-cloak style="font-size:11px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Resubmitted' : 'Dihantar semula'"></span>
                                            <span x-show="d.unlocked" x-cloak style="font-size:11px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Unlocked' : 'Dibuka kunci'"></span>

                                            <div x-show="manage" x-cloak style="display:flex;gap:6px;margin-left:auto;">
                                                <button type="button" x-show="canReturn(d)" class="uj-btn-ghost" style="height:26px;padding:0 10px;font-size:11px;" @click="openReason('return', d.iso)">
                                                    <span x-text="$store.ui.lang==='en' ? 'Return' : 'Kembalikan'">Return</span>
                                                </button>
                                                <button type="button" x-show="canApprove(d)" class="uj-btn-ghost" style="height:26px;padding:0 10px;font-size:11px;" :disabled="dayBusy" @click="approveDay(d.iso)">
                                                    <span x-text="$store.ui.lang==='en' ? 'Approve' : 'Luluskan'">Approve</span>
                                                </button>
                                                <button type="button" x-show="canUnlock(d)" class="uj-btn-ghost" style="height:26px;padding:0 10px;font-size:11px;" @click="openReason('unlock', d.iso)">
                                                    <span x-text="$store.ui.lang==='en' ? 'Unlock' : 'Buka kunci'">Unlock</span>
                                                </button>
                                            </div>
                                        </div>
                                        <div x-show="d.status === 'returned' && d.return_reason" x-cloak style="font-size:11.5px;color:var(--muted);margin-top:4px;" x-text="d.return_reason"></div>
                                        <div x-show="d.zero_reason" x-cloak style="font-size:11.5px;color:var(--muted);margin-top:4px;" x-text="($store.ui.lang==='en' ? 'No lines: ' : 'Tiada baris: ') + d.zero_reason"></div>

                                        {{-- One inline reason box (Return / Unlock), same pattern as
                                             the capture screen's zero-hour reason box. --}}
                                        <div x-show="isReasonOpen('return', d.iso) || isReasonOpen('unlock', d.iso)" x-cloak
                                            style="margin-top:8px;padding:10px;border:1px solid var(--hairline);border-radius:8px;background:var(--canvas);">
                                            <textarea x-model="reasonText" rows="2"
                                                :placeholder="$store.ui.lang==='en' ? 'Reason' : 'Sebab'"
                                                style="width:100%;padding:7px 9px;border:1px solid var(--hairline);border-radius:7px;font-size:12px;font-family:inherit;resize:vertical;outline:none;"></textarea>
                                            <div style="display:flex;gap:8px;margin-top:6px;">
                                                <button type="button" class="uj-btn-primary" style="height:30px;padding:0 12px;font-size:11.5px;" :disabled="!reasonText.trim() || dayBusy"
                                                    @click="isReasonOpen('return', d.iso) ? returnDay(d.iso) : unlockDay(d.iso)">
                                                    <span x-text="$store.ui.lang==='en' ? 'Confirm' : 'Sahkan'">Confirm</span>
                                                </button>
                                                <button type="button" class="uj-btn-ghost" style="height:30px;padding:0 12px;font-size:11.5px;" @click="closeReason()">
                                                    <span x-text="$store.ui.lang==='en' ? 'Cancel' : 'Batal'">Cancel</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </template>
                                <div x-show="dayError" x-cloak class="uj-tr-empty" style="padding:8px;" x-text="dayError"></div>
                            </div>
                        </template>

                        <template x-if="wk.lines.length === 0">
                            <div class="uj-tr-empty" x-text="$store.ui.lang==='en' ? 'No entries this week.' : 'Tiada entri minggu ini.'"></div>
                        </template>
                        {{-- One heading per day, however many entries fall on it (backend
                             `lines` is a flat array shared with the all-staff report —
                             daysInWeek() groups it client-side, Review-only). A single <a>
                             per entry — x-for needs one root element per iteration. Lines
                             with no id (system-generated: leave/holiday) get entryUrl()
                             === null, so :href binds to nothing and the tag renders as a
                             plain, non-interactive anchor: no href, no underline needed,
                             not in tab order, not clickable. --}}
                        <template x-for="grp in daysInWeek(wk)" :key="grp.day">
                            <div class="uj-tr-day-grp">
                                <div class="uj-tr-day">
                                    <span class="d" x-text="grp.day"></span>
                                    <span class="rule" aria-hidden="true"></span>
                                    <span class="t" :data-short="grp.days < 1 || null" x-text="md(grp.days)"></span>
                                </div>
                                <template x-for="(line, lidx) in grp.lines" :key="lidx">
                                    {{-- `.uj-tr-ent` is a flex row (align-items: stretch by
                                         default) — the pills must sit inside their own block
                                         wrapper, not as direct flex children, or they stretch
                                         to the note's full height instead of staying pill-sized. --}}
                                    <a class="uj-tr-ent" :href="entryUrl(line)">
                                        <div>
                                            {{-- One filled thing per row. The category is the
                                                 axis a week is read along, so it keeps the pill
                                                 and carries its own colour (the same one the
                                                 capture picker's dot and the Projects register
                                                 use). The project hangs off it, so it drops the
                                                 fill: two identical grey pills side by side
                                                 read as one object and neither told you which
                                                 was which. --}}
                                            {{-- The board card this line came from, named first:
                                                 the taxonomy under it says how the line is costed,
                                                 the card says what the work actually was. --}}
                                            <template x-if="line.card">
                                                <span class="c" x-text="line.card"></span>
                                            </template>
                                            <template x-if="line.category">
                                                <span class="uj-tr-cat"
                                                    {{-- The tone-chip recipe from docs/DESIGN.md: the
                                                         hue lives in the fill and the ring, the word
                                                         stays ink. Set as text on its own tint, four of
                                                         these colours measure under 4.5:1 at 11px. --}}
                                                    :style="line.categoryColour
                                                        ? 'background:color-mix(in srgb, ' + line.categoryColour + ' 8%, var(--card));border-color:color-mix(in srgb, ' + line.categoryColour + ' 30%, var(--hairline))'
                                                        : ''"
                                                    x-text="line.category"></span>
                                            </template>
                                            <template x-if="line.project">
                                                <span class="uj-tr-proj" x-text="line.project"></span>
                                            </template>
                                            <template x-if="line.note">
                                                <span class="n" x-html="line.note"></span>
                                            </template>
                                        </div>
                                    </a>
                                </template>
                            </div>
                        </template>
                        <template x-if="wk.status === 'submitted' && baseUrl">
                            <div class="uj-tr-note" x-text="$store.ui.lang==='en'
                                ? 'This week is submitted. Click an entry to open it on the Record tab — reopen it there to make changes.'
                                : 'Minggu ini telah dihantar. Ketik satu entri untuk membukanya di tab Rekod — buka semula di sana untuk membuat perubahan.'"></div>
                        </template>
                    </div>
                </template>
            </div>
        </template>
</div>
