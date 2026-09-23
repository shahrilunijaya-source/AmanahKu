{{-- Wizard step 2, Condition. Nothing in here is posted: it only decides who lands in step 3. --}}
<div x-show="step === 2">
    <div style="border:1px solid var(--hairline);border-radius:12px;padding:18px 20px;margin-bottom:18px;display:grid;grid-template-columns:auto 1fr auto;gap:14px;align-items:center;">
        <button type="button" class="pw-pillbtn" style="padding:0 26px;" @click="step = 1" x-text="t('Back', 'Kembali')">Back</button>
        <div style="text-align:center;">
            <div style="font-size:14px;font-weight:600;color:var(--ink);margin-bottom:12px;" x-text="t('Choose one of the selection method below:', 'Pilih satu kaedah pemilihan di bawah:')">Choose one of the selection method below:</div>
            <div class="pw-row" style="justify-content:center;">
                <button type="button" class="pw-tile" :aria-pressed="method === 'all'" @click="method = 'all'">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><circle cx="17" cy="9" r="2.5"/><path d="M16 14.5a5 5 0 0 1 6 5.5"/></svg>
                    <span x-text="t('All Available Users', 'Semua Pengguna Tersedia')">All Available Users</span>
                </button>
                <button type="button" class="pw-tile" disabled title="Not available yet / Belum tersedia">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="3" width="16" height="18" rx="2"/><circle cx="12" cy="10" r="3"/><path d="M7.5 18a4.5 4.5 0 0 1 9 0"/></svg>
                    <span x-text="t('Teams', 'Pasukan')">Teams</span>
                    <span class="pw-soon" style="margin:0;" x-text="t('Not available yet', 'Belum tersedia')">Not available yet</span>
                </button>
                <button type="button" class="pw-tile" :aria-pressed="method === 'manual'" @click="select('manual')">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="10" r="3"/><path d="M6.5 18.5a6 6 0 0 1 11 0"/></svg>
                    <span x-text="t('Manual Selections', 'Pilihan Manual')">Manual Selections</span>
                </button>
                <button type="button" class="pw-tile" disabled title="Not available yet / Belum tersedia">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3v12m0 0-4-4m4 4 4-4M4 17v3h16v-3"/></svg>
                    <span x-text="t('Import Selections', 'Import Pilihan')">Import Selections</span>
                    <span class="pw-soon" style="margin:0;" x-text="t('Not available yet', 'Belum tersedia')">Not available yet</span>
                </button>
            </div>
        </div>
        <button type="button" class="uj-btn-primary" style="height:34px;padding:0 28px;font-size:13px;border-radius:9999px;" @click="select('all')" x-text="t('Next', 'Seterusnya')">Next</button>
    </div>

    <div style="display:grid;grid-template-columns:230px 1fr;gap:18px;align-items:start;">
        {{-- Filter Options: Worksy's buttons in Worksy's order. --}}
        <div style="border:1px solid var(--hairline);border-radius:12px;padding:14px;">
            <div style="font-size:14px;font-weight:600;color:var(--ink);margin-bottom:10px;" x-text="t('Filter Options', 'Pilihan Penapis')">Filter Options</div>
            <input type="search" x-model="filterQ" :placeholder="t('Search', 'Cari')" class="pw-input uj-field" style="height:36px;font-size:13px;margin-bottom:10px;" />
            <div style="max-height:620px;overflow-y:auto;padding-right:2px;">
                <template x-for="d in filterDefs.filter(d => (d.en + ' ' + d.ms).toLowerCase().includes(filterQ.toLowerCase()))" :key="d.key">
                    <button type="button" class="pw-fbtn" :disabled="d.type === 'off'" :aria-pressed="hasFilter(d.key)" @click="addFilter(d)"
                            :title="d.type === 'off' ? 'Not available yet / Belum tersedia' : ''">
                        <span x-text="t(d.en, d.ms)"></span><span class="pw-soon" x-show="d.type === 'off'" x-text="t('Not available yet', 'Belum tersedia')"></span>
                    </button>
                </template>
            </div>
        </div>

        {{-- Filter Added --}}
        <div>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;min-height:32px;">
                <div style="font-size:14px;font-weight:600;color:var(--ink);" x-text="t('Filter Added', 'Penapis Ditambah')">Filter Added</div>
                <button type="button" class="uj-btn-primary" style="height:30px;padding:0 16px;font-size:12px;border-radius:9999px;" x-show="filters.length" @click="filters = []" x-text="t('Clear All', 'Kosongkan Semua')">Clear All</button>
            </div>
            <p x-show="! filters.length" style="font-size:12.5px;color:var(--muted);margin:0;" x-text="t('No filter added. Next takes every available employee for this cycle.', 'Tiada penapis ditambah. Seterusnya mengambil semua pekerja tersedia untuk kitaran ini.')"></p>
            <template x-for="f in filters" :key="f.key">
                <div style="border:1px solid var(--hairline);border-radius:10px;margin-bottom:12px;overflow:hidden;">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;padding:9px 14px;background:var(--red);color:#fff;">
                        <b style="font-size:13px;" x-text="t(f.def.en, f.def.ms)"></b>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <button type="button" style="height:26px;padding:0 14px;border-radius:9999px;border:0;background:#fff;color:var(--red);font-size:12px;font-weight:500;cursor:pointer;" @click="removeFilter(f.key)" x-text="t('Clear', 'Kosongkan')">Clear</button>
                            <button type="button" style="background:none;border:0;color:#fff;cursor:pointer;display:flex;" @click="f.open = ! f.open" :aria-expanded="f.open" :aria-label="t('Show or hide', 'Tunjuk atau sembunyi')">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" :style="{ transform: f.open ? 'rotate(180deg)' : 'none' }"><path d="m6 9 6 6 6-6"/></svg>
                            </button>
                        </div>
                    </div>
                    <div x-show="f.open" style="padding:14px;">
                        {{-- List filter: Available / Included dual list. --}}
                        <template x-if="f.def.type === 'list'">
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;">
                                <template x-for="side in ['a', 'i']" :key="side">
                                    <div>
                                        <div style="font-size:12.5px;font-weight:500;color:var(--ink);margin-bottom:6px;" x-text="t(f.def.en, f.def.ms) + ' ' + (side === 'a' ? t('Available', 'Tersedia') : t('Included', 'Dimasukkan'))"></div>
                                        <input type="search" x-model="f['q' + side]" :placeholder="t('Search...', 'Cari...')" class="pw-input uj-field" style="height:34px;font-size:13px;margin-bottom:6px;" />
                                        <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:6px;">
                                            <button type="button" style="background:none;border:0;padding:0;color:var(--red);cursor:pointer;font-size:12px;" @click="f['p' + side] = side === 'a' ? avail(f) : incl(f)" x-text="t('Select All', 'Pilih Semua')">Select All</button>
                                            <span style="color:var(--muted);" x-text="f['p' + side].length + ' ' + t('selected', 'dipilih')"></span>
                                        </div>
                                        <div class="pw-box" style="border:0;padding:0;">
                                            <template x-for="o in (side === 'a' ? avail(f) : incl(f))" :key="o">
                                                <label class="pw-opt"><input type="checkbox" :value="o" x-model="f['p' + side]" style="accent-color:var(--red);"><span x-text="o"></span></label>
                                            </template>
                                            <p x-show="! (side === 'a' ? avail(f) : incl(f)).length" style="font-size:12px;color:var(--muted);margin:6px 2px;" x-text="t('Nothing here.', 'Tiada apa-apa.')"></p>
                                        </div>
                                        <div style="text-align:right;margin-top:8px;">
                                            <button type="button" class="pw-pillbtn" @click="moveFilter(f, side === 'a')" x-text="t('Move to', 'Pindah ke') + ' ⇄'">Move to</button>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </template>
                        {{-- Range filter: min / max. --}}
                        <template x-if="f.def.type === 'range'">
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;max-width:460px;">
                                <label><span class="pw-label" x-text="t('Minimum', 'Minimum')">Minimum</span><input type="number" min="0" x-model="f.min" class="pw-input uj-field" /></label>
                                <label><span class="pw-label" x-text="t('Maximum', 'Maksimum')">Maximum</span><input type="number" min="0" x-model="f.max" class="pw-input uj-field" /></label>
                            </div>
                        </template>
                        {{-- Date filter: from / to. --}}
                        <template x-if="f.def.type === 'date'">
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;max-width:460px;">
                                <label><span class="pw-label" x-text="t('From', 'Dari')">From</span><input type="date" x-model="f.from" class="pw-input uj-field" /></label>
                                <label><span class="pw-label" x-text="t('To', 'Hingga')">To</span><input type="date" x-model="f.to" class="pw-input uj-field" /></label>
                            </div>
                        </template>
                        <p style="font-size:11.5px;color:var(--muted);margin:10px 0 0;" x-text="eligible().filter(p => matches(p)).length + ' ' + t('employees match every filter', 'pekerja sepadan dengan semua penapis')"></p>
                    </div>
                </div>
            </template>
        </div>
    </div>
</div>
