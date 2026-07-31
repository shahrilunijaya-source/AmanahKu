{{-- Shared Leaflet map picker — one modal per screen, opened by any row that dispatches
     the window `open-map-picker` event with the ids of its latitude/longitude inputs.
     Used by Company Settings (branch geofences) and Attendance Setup (client sites, homes). --}}
<div x-data="mapPicker" x-cloak>
    <template x-teleport="body">
    <div x-show="open" x-transition.opacity @keydown.escape.window="close()"
         style="position:fixed;inset:0;z-index:1000;background:rgba(15,18,20,.55);display:flex;align-items:center;justify-content:center;padding:20px;">
        <div @click.outside="close()"
             style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border-radius:14px;width:calc(100% - 40px);max-width:720px;max-height:90vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 24px 60px rgba(0,0,0,.35);">
            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:16px 20px;border-bottom:1px solid var(--hairline);">
                <div>
                    <div style="font-size:15px;font-weight:700;color:var(--ink);" x-text="title"></div>
                    <div style="font-size:12px;color:var(--muted);margin-top:2px;" x-text="$store.ui.lang==='en' ? 'Click the map (or drag the pin) to set the exact location.' : 'Klik peta (atau seret pin) untuk tetapkan lokasi tepat.'">Click the map (or drag the pin) to set the exact location.</div>
                </div>
                <button type="button" @click="close()" class="uj-btn-ghost" style="height:32px;padding:0 12px;font-size:14px;">✕</button>
            </div>
            <div style="position:relative;z-index:1100;padding:12px 20px;border-bottom:1px solid var(--hairline);">
                <div style="display:flex;gap:8px;">
                    <input type="text" x-model="query" @keydown.enter.prevent="runSearch()" @keydown.escape.stop="results = []"
                           :placeholder="$store.ui.lang==='en' ? 'Search address or place…' : 'Cari alamat atau tempat…'" placeholder="Search address or place…"
                           style="flex:1;height:38px;border:1px solid var(--hairline);border-radius:9px;padding:0 12px;font-size:13px;color:var(--ink);background:#fff;" />
                    <button type="button" @click="runSearch()" :disabled="searching || !query.trim()" class="uj-btn-ghost" style="height:38px;padding:0 16px;font-size:13px;">
                        <span x-show="!searching" x-text="$store.ui.lang==='en' ? 'Search' : 'Cari'">Search</span><span x-show="searching" x-cloak>…</span>
                    </button>
                </div>
                <div x-show="searchError" x-text="searchError" x-cloak style="font-size:12px;color:#c8102e;margin-top:6px;"></div>
                <div x-show="results.length" x-cloak @click.outside="results = []"
                     style="position:absolute;left:20px;right:20px;top:54px;z-index:10;background:#fff;border:1px solid var(--hairline);border-radius:10px;box-shadow:0 12px 30px rgba(0,0,0,.18);max-height:220px;overflow-y:auto;">
                    <template x-for="(r, i) in results" :key="i">
                        <button type="button" @click="pickResult(r)"
                                style="display:block;width:100%;text-align:left;padding:10px 14px;font-size:12.5px;line-height:1.4;color:var(--ink);background:transparent;border:none;border-bottom:1px solid var(--hairline);cursor:pointer;"
                                x-text="r.display_name"></button>
                    </template>
                </div>
            </div>
            <div x-ref="canvas" style="height:420px;width:100%;background:#e8eef1;"></div>
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 20px;border-top:1px solid var(--hairline);">
                <span style="font-size:12.5px;color:var(--muted);font-family:var(--font-mono);" x-text="coordLabel"></span>
                <div style="display:flex;gap:8px;">
                    <button type="button" @click="close()" class="uj-btn-ghost" style="height:38px;padding:0 16px;font-size:13px;"><span x-text="$store.ui.lang==='en' ? 'Cancel' : 'Batal'">Cancel</span></button>
                    <button type="button" @click="confirm()" :disabled="lat === null" class="uj-btn-primary" style="height:38px;padding:0 18px;font-size:13px;"><span x-text="$store.ui.lang==='en' ? 'Use this location' : 'Guna lokasi ini'">Use this location</span></button>
                </div>
            </div>
        </div>
    </div>
    </template>
</div>
