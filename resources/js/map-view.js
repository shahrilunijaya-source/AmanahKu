const SINGLE_ZOOM = 17;

// Leaflet (+ its css) loads on demand the first time a map opens, mirroring
// map-picker.js — see the note in app.js, neither may sit in the app-wide bundle.
let L = null;
let pinIcon = null;

async function loadLeaflet() {
    if (L) return;
    const mod = await import('leaflet');
    await import('leaflet/dist/leaflet.css');
    L = mod.default;

    // A CSS-only pin (no image files) keeps us within the strict CSP (img-src is
    // limited to OSM tiles) and sidesteps the Leaflet + Vite broken default-marker
    // problem entirely.
    pinIcon = L.divIcon({
        className: 'uj-mv-pin',
        html: '<span style="display:block;width:16px;height:16px;border-radius:50%;background:#c8102e;border:3px solid #fff;box-shadow:0 0 0 1px rgba(0,0,0,.35),0 2px 6px rgba(0,0,0,.4);"></span>',
        iconSize: [16, 16],
        iconAnchor: [8, 8],
    });
}

/**
 * Read-only counterpart to mapPicker: plots where a punch was recorded and offers
 * no way to move it. Deliberately a separate component rather than a `readonly`
 * flag on the picker — a reviewer must not be able to alter where somebody
 * punched, so read-only is structural rather than a setting that can be flipped.
 *
 * A row opens it by firing a window `open-map-view` event:
 *   detail: { title: 'Ravi Kumar · Tue, 12 Aug', points: [{ lat, lng, label }] }
 */
export function registerMapView(Alpine) {
    Alpine.data('mapView', () => ({
        open: false,
        title: '',
        points: [],
        map: null,
        markers: [],

        init() {
            this._onOpen = (ev) => this.show(ev.detail || {});
            window.addEventListener('open-map-view', this._onOpen);
        },

        destroy() {
            window.removeEventListener('open-map-view', this._onOpen);
        },

        async show({ title = '', points = [] }) {
            if (!points.length) return;

            this.title = title;
            this.points = points;
            await loadLeaflet();
            this.open = true;
            this.$nextTick(() => this.render());
        },

        close() {
            this.open = false;
        },

        render() {
            const first = this.points[0];

            if (!this.map) {
                // Leaflet throws "Set map center and zoom first" if a layer is added
                // before the view exists, so the centre goes on at construction.
                this.map = L.map(this.$refs.canvas, { zoomControl: true })
                    .setView([first.lat, first.lng], SINGLE_ZOOM);

                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19,
                    attribution: '&copy; OpenStreetMap contributors',
                }).addTo(this.map);
            }

            this.markers.forEach((m) => this.map.removeLayer(m));
            this.markers = [];

            this.points.forEach((p) => {
                const marker = L.marker([p.lat, p.lng], { icon: pinIcon }).addTo(this.map);
                marker.bindTooltip(p.label, { permanent: true, direction: 'top', offset: [0, -10] });
                this.markers.push(marker);
            });

            if (this.points.length === 1) {
                this.map.setView([first.lat, first.lng], SINGLE_ZOOM);
            } else {
                // Both punches on screen at once makes drift between them obvious.
                this.map.fitBounds(
                    L.latLngBounds(this.points.map((p) => [p.lat, p.lng])).pad(0.35)
                );
            }

            // The modal was display:none until now, so Leaflet sized to 0×0.
            this.map.invalidateSize();
        },
    }));
}
