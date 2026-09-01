@php
    /** @var bool $canEdit */
    $canEdit = $canEdit ?? false;
    $profileBase = route('app.screen', ['screen' => 'profile']);
@endphp

{{-- The panel under the chart. It sits below rather than beside because the shell already
     spends width on the sidebar, and the fan the user just clicked is at the bottom of the
     canvas — the eye is already travelling down. One panel serves three jobs: read who is
     selected, fill an open slot, or change who verifies. --}}
<div class="uj-card" style="padding:18px 20px;">

    {{-- Filling an open slot. --}}
    <template x-if="picking">
        <div>
            <div style="display:flex;align-items:baseline;gap:10px;flex-wrap:wrap;margin-bottom:4px;">
                <h3 class="uj-card-title" style="margin:0;"
                    x-text="focus === null
                        ? ($store.ui.lang==='en' ? 'Add someone at the top level' : 'Tambah orang di peringkat atasan')
                        : (($store.ui.lang==='en' ? 'Who reports to ' : 'Siapa melapor kepada ') + subject.name + '?')"></h3>
                <button type="button" class="uj-btn-ghost" style="height:30px;padding:0 12px;font-size:12.5px;margin-left:auto;"
                        @click="cancel()" x-text="$store.ui.lang==='en' ? 'Cancel' : 'Batal'"></button>
            </div>
            <p style="font-size:12.5px;color:var(--muted);margin:0 0 12px;line-height:1.5;"
               x-text="focus === null
                    ? ($store.ui.lang==='en' ? 'Someone at the top level has no manager, so only the directors can approve their requests.' : 'Orang di peringkat atasan tiada pengurus, jadi hanya pengarah boleh meluluskan permohonan mereka.')
                    : ($store.ui.lang==='en' ? 'The person you choose will report to this seat. Whoever sits here then verifies their leave, claims and overtime.' : 'Orang yang anda pilih akan melapor kepada kerusi ini. Sesiapa di sini kemudian mengesahkan cuti, tuntutan dan kerja lebih masa mereka.')"></p>

            <input type="search" x-model="q" x-ref="search"
                   :placeholder="$store.ui.lang==='en' ? 'Search name, position, department' : 'Cari nama, pangkat, bahagian'"
                   :aria-label="$store.ui.lang==='en' ? 'Search people' : 'Cari orang'"
                   style="width:100%;height:38px;border:1px solid var(--hairline);border-radius:9px;background:var(--canvas);padding:0 12px;font-size:13px;color:var(--ink);">

            <div style="display:flex;flex-direction:column;gap:2px;max-height:320px;overflow-y:auto;margin-top:10px;">
                <template x-for="p in candidates" :key="p.id">
                    <button type="button" @click="place(p.id)" :disabled="busy"
                            style="display:flex;align-items:center;gap:11px;width:100%;padding:8px 10px;border:1px solid transparent;border-radius:10px;background:transparent;text-align:left;cursor:pointer;"
                            @mouseenter="$el.style.background='var(--canvas)'" @mouseleave="$el.style.background='transparent'">
                        <template x-if="p.photo">
                            <img :src="p.photo" alt="" width="34" height="34" style="width:34px;height:34px;border-radius:50%;object-fit:cover;flex-shrink:0;">
                        </template>
                        <template x-if="! p.photo">
                            <span style="width:34px;height:34px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:#fff;"
                                  :style="{ background: p.color }" x-text="p.initials"></span>
                        </template>
                        <span style="flex:1;min-width:0;">
                            <span style="display:block;font-size:13px;font-weight:600;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" x-text="p.name"></span>
                            <span style="display:block;font-size:11.5px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"
                                  x-text="[p.role, p.dept].filter(Boolean).join(' · ')"></span>
                        </span>
                        {{-- Says what moving this person costs: a fresh line, or one taken off
                             somebody else. Without it a re-parent looks like a plain add. --}}
                        <span style="flex-shrink:0;font-family:var(--font-mono);font-size:10.5px;color:var(--muted);"
                              x-text="(parents[p.id] ?? null) === null
                                ? ($store.ui.lang==='en' ? 'no manager' : 'tiada pengurus')
                                : (($store.ui.lang==='en' ? 'moves from ' : 'dari ') + person(parents[p.id]).name)"></span>
                    </button>
                </template>
                <template x-if="! candidates.length">
                    <p style="padding:22px 8px;text-align:center;font-size:12.5px;color:var(--muted-soft);line-height:1.5;"
                       x-text="q
                            ? ($store.ui.lang==='en' ? 'Nobody matches that search.' : 'Tiada sesiapa sepadan dengan carian itu.')
                            : ($store.ui.lang==='en' ? 'Everybody available already reports to this seat.' : 'Semua orang yang ada sudah melapor kepada kerusi ini.')"></p>
                </template>
            </div>
        </div>
    </template>

    {{-- Adding an extra verifier. --}}
    <template x-if="pickingVerifier">
        <div>
            <div style="display:flex;align-items:baseline;gap:10px;flex-wrap:wrap;margin-bottom:4px;">
                <h3 class="uj-card-title" style="margin:0;"
                    x-text="($store.ui.lang==='en' ? 'Who else may verify for ' : 'Siapa lagi boleh mengesahkan untuk ') + subject.name + '?'"></h3>
                <button type="button" class="uj-btn-ghost" style="height:30px;padding:0 12px;font-size:12.5px;margin-left:auto;"
                        @click="cancel()" x-text="$store.ui.lang==='en' ? 'Cancel' : 'Batal'"></button>
            </div>
            <p style="font-size:12.5px;color:var(--muted);margin:0 0 12px;line-height:1.5;"
               x-text="$store.ui.lang==='en' ? 'An extra verifier can verify this person\'s leave, claims and overtime without being their manager. The reporting line does not change.' : 'Pengesah tambahan boleh mengesahkan cuti, tuntutan dan kerja lebih masa orang ini tanpa menjadi pengurus mereka. Talian pelaporan tidak berubah.'"></p>

            <input type="search" x-model="q" x-ref="search"
                   :placeholder="$store.ui.lang==='en' ? 'Search name, position, department' : 'Cari nama, pangkat, bahagian'"
                   :aria-label="$store.ui.lang==='en' ? 'Search people' : 'Cari orang'"
                   style="width:100%;height:38px;border:1px solid var(--hairline);border-radius:9px;background:var(--canvas);padding:0 12px;font-size:13px;color:var(--ink);">

            <div style="display:flex;flex-wrap:wrap;gap:8px;max-height:260px;overflow-y:auto;margin-top:12px;">
                <template x-for="p in verifierCandidates" :key="p.id">
                    <button type="button" @click="addVerifier(p.id)" :disabled="busy"
                            style="display:inline-flex;align-items:center;gap:8px;padding:5px 12px 5px 6px;border:1px solid var(--hairline);border-radius:9999px;background:var(--card);font-size:12.5px;color:var(--ink);cursor:pointer;">
                        <span style="width:22px;height:22px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:9.5px;font-weight:700;color:#fff;"
                              :style="{ background: p.color }" x-text="p.initials"></span>
                        <span x-text="p.name"></span>
                    </button>
                </template>
            </div>
        </div>
    </template>

    {{-- Reading the chart. --}}
    <template x-if="! picking && ! pickingVerifier">
        <div>
            {{-- Who is missing a manager, and the nudge to fix it, is work only the
                 people who can edit the chart can act on. Everyone else opens this
                 screen to read the shape of the company, so they get the one line
                 that tells them how. --}}
            <template x-if="focus === null">
                <div>
                    @if ($canEdit)
                        <h3 class="uj-card-title" style="margin:0 0 4px;"
                            x-text="$store.ui.lang==='en' ? 'Top of the chart' : 'Atas carta'"></h3>
                    @endif
                    <p style="font-size:12.5px;color:var(--muted);margin:0;line-height:1.5;"
                       x-text="! @js($canEdit)
                            ? ($store.ui.lang==='en' ? 'Choose a circle to walk down the chart.' : 'Pilih bulatan untuk turun dalam carta.')
                            : (unmanaged.length
                                ? (unmanaged.length + ($store.ui.lang==='en'
                                    ? ' people report to nobody. Only the directors can approve their requests — fill a slot to give them a manager.'
                                    : ' orang tidak melapor kepada sesiapa. Hanya pengarah boleh meluluskan permohonan mereka — isi slot untuk beri mereka pengurus.'))
                                : ($store.ui.lang==='en' ? 'Everybody has a manager. Choose a circle to walk down the chart.' : 'Semua orang ada pengurus. Pilih bulatan untuk turun dalam carta.'))"></p>
                </div>
            </template>

            <template x-if="focus !== null">
                <div style="display:flex;flex-wrap:wrap;align-items:center;gap:14px;">
                    <template x-if="subject.photo">
                        <img :src="subject.photo" alt="" width="44" height="44" style="width:44px;height:44px;border-radius:50%;object-fit:cover;flex-shrink:0;">
                    </template>
                    <template x-if="! subject.photo">
                        <span style="width:44px;height:44px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:600;color:#fff;"
                              :style="{ background: subject.color }" x-text="subject.initials"></span>
                    </template>

                    <div style="min-width:0;flex:1;">
                        <div style="font-size:15px;font-weight:600;color:var(--ink);" x-text="subject.name"></div>
                        <div style="font-size:12.5px;color:var(--muted);margin-top:1px;"
                             x-text="[subject.role, subject.dept].filter(Boolean).join(' · ')"></div>
                        <div style="font-family:var(--font-mono);font-size:10.5px;color:var(--muted);margin-top:5px;"
                             x-text="reports.length + ($store.ui.lang==='en'
                                ? (reports.length === 1 ? ' direct report' : ' direct reports')
                                : ' orang bawahan terus')"></div>
                    </div>

                    <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
                        <span x-show="busy" x-cloak style="font-size:12px;color:var(--muted);"
                              x-text="$store.ui.lang==='en' ? 'Saving…' : 'Menyimpan…'"></span>
                        <a :href="'{{ $profileBase }}?emp=' + focus" class="uj-btn-ghost"
                           style="height:36px;padding:0 14px;font-size:12.5px;display:inline-flex;align-items:center;text-decoration:none;"
                           x-text="$store.ui.lang==='en' ? 'Open profile' : 'Buka profil'"></a>
                        @if ($canEdit)
                            <button type="button" class="uj-btn-ghost" style="height:36px;padding:0 14px;font-size:12.5px;"
                                    @click="pickingVerifier = true; q = ''; $nextTick(() => $refs.search?.focus())" :disabled="busy"
                                    x-text="$store.ui.lang==='en' ? 'Add a verifier' : 'Tambah pengesah'"></button>
                            <button type="button" x-show="parents[focus] !== null" :disabled="busy"
                                    @click="detach(focus)"
                                    style="height:36px;padding:0 14px;border:1px solid #f0cdcf;border-radius:9px;background:var(--red-tint);color:var(--red-active);font-size:12.5px;font-weight:500;cursor:pointer;"
                                    x-text="$store.ui.lang==='en' ? 'Remove reporting line' : 'Buang talian pelaporan'"></button>
                        @endif
                    </div>
                </div>
            </template>
        </div>
    </template>
</div>
