// Kuala Lumpur as a sensible default when a row has no coordinates yet.
const DEFAULT_CENTER = [3.139, 101.6869];
const DEFAULT_ZOOM = 16;
const FALLBACK_ZOOM = 11;

// Leaflet (+its css) loads on demand the first time a picker opens — only the
// attendance-admin screen uses it, so it must never sit in the app-wide bundle.
let L = null;
let pinIcon = null;

async function loadLeaflet() {
    if (L) return;
    const mod = await import('leaflet');
    await import('leaflet/dist/leaflet.css');
    L = mod.default;

    // A CSS-only pin (no image files) keeps us within the strict CSP (img-src is
    // limited to OSM tiles) and sidesteps the well-known Leaflet + Vite broken
    // default-marker problem entirely.
    pinIcon = L.divIcon({
        className: 'uj-map-pin',
        html: '<span style="display:block;width:16px;height:16px;border-radius:50%;background:#c8102e;border:3px solid #fff;box-shadow:0 0 0 1px rgba(0,0,0,.35),0 2px 6px rgba(0,0,0,.4);"></span>',
        iconSize: [16, 16],
        iconAnchor: [8, 8],
    });
}

/**
 * Registers a single page-level Alpine component (`mapPicker`) that owns one
 * Leaflet modal shared by every geofence row. A row asks for it by firing a
 * window `open-map-picker` event carrying the ids of its latitude/longitude
 * inputs; on confirm the chosen point is written back to those inputs.
 */
export function registerMapPicker(Alpine) {
    Alpine.data('mapPicker', () => ({
        open: false,
        title: '',
        latId: null,
        lngId: null,
        lat: null,
        lng: null,
        map: null,
        marker: null,
        query: '',
        results: [],
        searching: false,
        searchError: '',
        submitOnConfirm: false,

        init() {
            this._onOpen = (e) => this.launch(e.detail || {});
            window.addEventListener('open-map-picker', this._onOpen);
        },

        destroy() {
            window.removeEventListener('open-map-picker', this._onOpen);
        },

        async launch({ latId, lngId, title, submit }) {
            await loadLeaflet();

            this.latId = latId;
            this.lngId = lngId;
            this.title = title || 'Pick location';
            // When set, confirming the location also submits the owning form (one-tap
            // register for single-purpose rows like work-from-home addresses).
            this.submitOnConfirm = !!submit;

            const lat = parseFloat(document.getElementById(latId)?.value);
            const lng = parseFloat(document.getElementById(lngId)?.value);
            this.lat = Number.isFinite(lat) ? lat : null;
            this.lng = Number.isFinite(lng) ? lng : null;

            this.query = '';
            this.results = [];
            this.searchError = '';
            this.searching = false;

            this.open = true;
            this.$nextTick(() => this.renderMap());
        },

        renderMap() {
            const hasPoint = this.lat !== null && this.lng !== null;
            const center = hasPoint ? [this.lat, this.lng] : DEFAULT_CENTER;
            const zoom = hasPoint ? DEFAULT_ZOOM : FALLBACK_ZOOM;

            if (!this.map) {
                this.map = L.map(this.$refs.canvas, { zoomControl: true }).setView(center, zoom);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19,
                    attribution: '&copy; OpenStreetMap contributors',
                }).addTo(this.map);
                this.map.on('click', (ev) => this.place(ev.latlng.lat, ev.latlng.lng));
            } else {
                this.map.setView(center, zoom);
            }

            if (hasPoint) {
                this.place(this.lat, this.lng, false);
            } else if (this.marker) {
                this.map.removeLayer(this.marker);
                this.marker = null;
            }

            // The modal was display:none until now, so Leaflet sized to 0×0.
            this.map.invalidateSize();
        },

        place(lat, lng, recenter = true) {
            this.lat = lat;
            this.lng = lng;

            if (!this.marker) {
                this.marker = L.marker([lat, lng], { icon: pinIcon, draggable: true }).addTo(this.map);
                this.marker.on('dragend', () => {
                    const p = this.marker.getLatLng();
                    this.lat = p.lat;
                    this.lng = p.lng;
                });
            } else {
                this.marker.setLatLng([lat, lng]);
            }

            if (recenter) this.map.panTo([lat, lng]);
        },

        // Free-text address lookup via OpenStreetMap's Nominatim geocoder.
        // Search runs only on submit (Enter / button) to respect Nominatim's
        // 1 request/second usage policy — no debounced typeahead.
        async runSearch() {
            const q = this.query.trim();
            if (!q || this.searching) return;

            this.searching = true;
            this.searchError = '';
            this.results = [];

            try {
                const params = new URLSearchParams({
                    format: 'json',
                    q,
                    limit: '5',
                    addressdetails: '1',
                });
                // Bias results toward the area currently on screen without hard-
                // restricting them (bounded omitted ⇒ viewbox is a preference only).
                if (this.map) {
                    const b = this.map.getBounds();
                    params.set('viewbox', [b.getWest(), b.getNorth(), b.getEast(), b.getSouth()].join(','));
                }

                const res = await fetch(`https://nominatim.openstreetmap.org/search?${params}`, {
                    headers: { Accept: 'application/json' },
                });
                if (!res.ok) throw new Error(`HTTP ${res.status}`);

                const data = await res.json();
                this.results = Array.isArray(data) ? data : [];
                if (this.results.length === 0) {
                    this.searchError = 'No matching place found.';
                }
            } catch (err) {
                this.searchError = 'Search failed. Try again.';
            } finally {
                this.searching = false;
            }
        },

        pickResult(r) {
            const lat = parseFloat(r.lat);
            const lng = parseFloat(r.lon);
            if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;

            this.results = [];
            this.query = r.display_name || this.query;
            this.map.setView([lat, lng], 17);
            this.place(lat, lng, false);
        },

        confirm() {
            if (this.lat === null || this.lng === null) return;

            this.writeBack(this.latId, this.lat.toFixed(7));
            this.writeBack(this.lngId, this.lng.toFixed(7));

            const latId = this.latId;
            const submit = this.submitOnConfirm;
            this.close();

            // One-tap rows (work-from-home addresses) save the moment a location is
            // chosen — no separate Save click to forget.
            if (submit) {
                document.getElementById(latId)?.closest('form')?.requestSubmit();
            }
        },

        writeBack(id, value) {
            const el = document.getElementById(id);
            if (!el) return;
            el.value = value;
            // Let Alpine / native listeners on the input react to the change.
            el.dispatchEvent(new Event('input', { bubbles: true }));
        },

        close() {
            this.open = false;
        },

        get coordLabel() {
            if (this.lat === null || this.lng === null) return 'Click the map to drop a pin';
            return `${this.lat.toFixed(6)}, ${this.lng.toFixed(6)}`;
        },
    }));
}
