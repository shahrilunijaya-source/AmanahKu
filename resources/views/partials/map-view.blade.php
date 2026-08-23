{{-- Read-only Leaflet view of where a punch was recorded. One instance per screen;
     any row opens it by dispatching the window `open-map-view` event with its points.
     Never editable — see the note in resources/js/map-view.js. --}}
<div x-data="mapView" x-cloak>
    <template x-teleport="body">
        <div x-show="open" x-transition.opacity @keydown.escape.window="close()"
             class="uj-dialog-overlay"
             style="position:fixed;inset:0;z-index:1000;background:rgba(15,18,20,.55);padding:20px;">
            <div @click.outside="close()" class="uj-mv-panel" role="dialog" aria-modal="true" aria-labelledby="uj-mv-title">
                <div class="uj-mv-head">
                    <div style="min-width:0;">
                        <div class="uj-mv-title" id="uj-mv-title" x-text="title"></div>
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

                {{-- The figures behind the pin. A pin on a map says "somewhere near
                     here"; whether that is a problem depends on how far it is from the
                     site and how wide that site's fence is, so all three are stated
                     rather than left to be eyeballed off the tiles. --}}
                <div class="uj-mv-foot" x-show="points.length">
                    <template x-for="p in points" :key="p.lat + ',' + p.lng + p.labelEn">
                        <span class="mf">
                            <b x-text="coords(p)"></b>
                            <span x-text="labelFor(p)"></span>
                        </span>
                    </template>
                    <template x-for="p in points.filter(p => p.awayM !== null && p.awayM !== undefined)"
                              :key="'d' + p.lat + p.labelEn">
                        <span class="mf" :data-t="beyondFence(p) ? 'far' : null">
                            <b x-text="p.awayM.toLocaleString() + ' m'"></b>
                            <span x-text="$store.ui.lang==='en'
                                ? 'from the registered site'
                                : 'dari lokasi berdaftar'"></span>
                        </span>
                    </template>
                    <template x-if="site && site.hasGeofence">
                        <span class="mf">
                            <b x-text="site.radiusM.toLocaleString() + ' m'"></b>
                            <span x-text="($store.ui.lang==='en' ? 'Geofence radius' : 'Radius geofence') + ' · ' + site.name"></span>
                        </span>
                    </template>
                </div>
            </div>
        </div>
    </template>
</div>
