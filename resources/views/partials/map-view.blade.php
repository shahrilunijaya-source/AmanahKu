{{-- Read-only Leaflet view of where a punch was recorded. One instance per screen;
     any row opens it by dispatching the window `open-map-view` event with its points.
     Never editable — see the note in resources/js/map-view.js. --}}
<div x-data="mapView" x-cloak>
    <template x-teleport="body">
        <div x-show="open" x-transition.opacity @keydown.escape.window="close()"
             class="uj-dialog-overlay"
             style="position:fixed;inset:0;z-index:1000;background:rgba(15,18,20,.55);padding:20px;">
            <div @click.outside="close()" class="uj-mv-panel">
                <div class="uj-mv-head">
                    <div style="min-width:0;">
                        <div class="uj-mv-title" x-text="title"></div>
                        <div class="uj-mv-sub"
                             x-text="$store.ui.lang==='en'
                                ? 'Where this punch was recorded. Read-only.'
                                : 'Lokasi clock ini direkodkan. Baca sahaja.'">Where this punch was recorded. Read-only.</div>
                    </div>
                    <button type="button" @click="close()" class="uj-btn-ghost"
                            style="height:32px;padding:0 12px;font-size:14px;flex-shrink:0;"
                            :aria-label="$store.ui.lang==='en' ? 'Close' : 'Tutup'">✕</button>
                </div>
                <div x-ref="canvas" class="uj-mv-canvas"></div>
            </div>
        </div>
    </template>
</div>
