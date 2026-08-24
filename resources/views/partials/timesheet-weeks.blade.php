{{-- Read-only week-by-week timesheet viewer. Two callers: the Review tab of the
     personal timesheet screen (own weeks, entries link back into Record), and the
     all-staff report's staff viewer (somebody else's weeks, no links — there is no
     edit path into another person's sheet).

     $weeks   list of week blocks from TimesheetController::buildWeekBlocks()
     $baseUrl the timesheet screen to link an entry back to, or null for no links --}}
<div x-data="timesheetReview({
        baseUrl: @js($baseUrl ?? null),
        weeks: @js($weeks),
     })">
        <template x-if="weeks.length === 0">
            <div class="uj-tr-panel">
                <div class="uj-tr-empty" x-text="$store.ui.lang==='en' ? 'No weeks yet.' : 'Belum ada minggu.'"></div>
            </div>
        </template>
        <template x-if="weeks.length > 0">
            <div class="uj-tr-panel">
                <div class="uj-tr-weeknav-hd">
                    <button type="button" class="uj-tr-weeknav-btn" @click="prevWeek()" :disabled="weekIdx === 0"
                        :aria-label="$store.ui.lang==='en' ? 'Previous week' : 'Minggu sebelum'">&lsaquo;</button>
                    <span class="uj-tr-weeknav-pos" x-text="(weekIdx + 1) + ' / ' + weeks.length"></span>
                    <button type="button" class="uj-tr-weeknav-btn" @click="nextWeek()" :disabled="weekIdx === weeks.length - 1"
                        :aria-label="$store.ui.lang==='en' ? 'Next week' : 'Minggu seterusnya'">&rsaquo;</button>
                </div>
                <select class="uj-tr-weekpick" x-model.number="weekIdx"
                    :aria-label="$store.ui.lang==='en' ? 'Jump to week' : 'Lompat ke minggu'">
                    <template x-for="(w, i) in weeks" :key="i">
                        <option :value="i" x-text="w.label + ': ' + w.dates"></option>
                    </template>
                </select>
                <template x-for="wk in (currentWeek ? [currentWeek] : [])" :key="weekIdx">
                    <div class="uj-tr-wk" :data-dir="weekDir">
                        <div class="hdr">
                            <span x-text="wk.label + ' · ' + wk.dates"></span>
                            {{-- No status and no lines = no sheet was ever started for that
                                 week. Calling that "Draft" would claim a draft exists. --}}
                            <span class="uj-tr-status-badge" :data-status="wk.status || 'draft'"
                                x-text="wk.status === 'submitted' ? ($store.ui.lang==='en' ? 'Submitted' : 'Dihantar')
                                      : (!wk.status && wk.lines.length === 0 ? ($store.ui.lang==='en' ? 'Nothing yet' : 'Belum ada')
                                      : ($store.ui.lang==='en' ? 'Draft' : 'Draf'))"></span>
                        </div>
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
                                <div class="uj-tr-ent-day" :style="'color:' + dayColor(grp.day)" x-text="grp.day"></div>
                                <template x-for="(line, lidx) in grp.lines" :key="lidx">
                                    {{-- `.uj-tr-ent` is a flex row (align-items: stretch by
                                         default) — the pills must sit inside their own block
                                         wrapper, not as direct flex children, or they stretch
                                         to the note's full height instead of staying pill-sized. --}}
                                    <a class="uj-tr-ent" :href="entryUrl(line)">
                                        <div>
                                            <template x-if="line.category">
                                                <span class="uj-pill" style="background:var(--hairline-soft);color:var(--ink);margin-right:4px;" x-text="line.category"></span>
                                            </template>
                                            <template x-if="line.project">
                                                <span class="uj-pill" style="background:var(--hairline-soft);color:var(--muted);" x-text="line.project"></span>
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
