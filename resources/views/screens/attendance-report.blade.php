@extends('layouts.app')

@section('screen')
{{-- `staged` holds what the phone's filter sheet has been set to but not yet
     applied. On desktop the sheet never opens, `filters` stays false, and every
     control behaves as the plain link it is. --}}
<div class="uj-ar-ledger" data-gran="{{ $gran }}"
     x-data="{
        filters: false,
        gran: @js($gran),
        offset: {{ $offset }},
        sort: @js($sort),
        stepLabels: @js($stepLabels),
        drawer: '',
        loadingPerson: null,
        busy: false,
        /* The server-rendered drawer is for the first paint only. The moment this
           component opens, closes or syncs one, it owns the drawer — otherwise
           closing a fetched drawer would reveal the server's own, still showing
           whoever the URL named when the page loaded. */
        tookOver: false,

        init() {
            /* Back and forward move between filter and drawer states, so both follow
               the URL rather than only the clicks. partial-nav ignores these entries —
               they carry no partialNav flag — so it will not rebuild the page. */
            this.onPop = () => { this.reloadBody(location.href); this.syncDrawer() };
            window.addEventListener('popstate', this.onPop);
        },

        /* Every filter on this screen is a link or a GET form, which is what makes the
           screen work without JavaScript. With JavaScript, following one wholesale
           re-rendered the sidebar, the header and the app shell to change some table
           rows. This intercepts them and swaps only the ledger's own body.

           Anything that already handled its own click — a person link, Fix, a drawer
           close — has set defaultPrevented by the time this bubbles, so it is left
           alone. So is the export link, which must reach the browser to download. */
        onNavigate(event) {
            if (event.defaultPrevented || event.metaKey || event.ctrlKey || event.shiftKey || event.button) return;

            const link = event.target.closest?.('a[href]');
            if (! link || link.hasAttribute('data-full-nav') || link.target) return;
            if (link.origin !== location.origin) return;
            if (! link.pathname.endsWith('/app/attendance-report')) return;

            event.preventDefault();
            this.go(link.href);
        },

        onFilterSubmit(event) {
            event.preventDefault();
            const form = event.target;
            const query = new URLSearchParams(new FormData(form));
            this.filters = false;
            this.go(`${form.action}?${query}`);
        },

        async go(href) {
            if (await this.reloadBody(href)) {
                history.pushState({ ledgerBody: true }, '', href);
            } else {
                window.location.assign(href);
            }
        },

        /** @returns {Promise<boolean>} false when the caller should fall back to a real navigation. */
        async reloadBody(href) {
            const url = new URL(href, location.origin);
            this.busy = true;
            try {
                const res = await fetch(
                    `/app/attendance-report/body${url.search}`,
                    { headers: { 'X-Requested-With': 'XMLHttpRequest' } },
                );
                if (! res.ok) return false;
                this.$refs.body.innerHTML = await res.text();
                // The staged sheet state belongs to the URL, not to the old markup.
                this.gran = url.searchParams.get('gran') ?? 'month';
                this.offset = Number(url.searchParams.get('offset') ?? 0);
                this.sort = url.searchParams.get('sort') ?? 'date';
                this.$refs.body.scrollIntoView({ block: 'nearest' });

                return true;
            } catch {
                return false;
            } finally {
                this.busy = false;
            }
        },

        destroy() {
            window.removeEventListener('popstate', this.onPop);
        },

        /* The table behind the drawer does not change when you open one, so it is
           left alone and only the drawer comes over the wire. pushState keeps the
           URL honest, so a refresh, a share or Back all still land where they say. */
        async openPerson(href) {
            const url = new URL(href, location.origin);
            const id = url.searchParams.get('emp');

            this.loadingPerson = id;
            this.tookOver = true;
            const ok = await this.fetchDrawer(url);
            this.loadingPerson = null;

            if (ok) {
                history.pushState({ ledgerDrawer: true }, '', href);
            } else {
                window.location.assign(href);   // offline, blocked, or refused: let the browser do it
            }
        },

        closePerson(url) {
            this.tookOver = true;
            this.drawer = '';
            history.pushState({ ledgerDrawer: false }, '', url);
        },

        /** Bring the drawer into line with whatever the URL currently says. */
        async syncDrawer() {
            this.tookOver = true;
            const url = new URL(location.href);
            if (! url.searchParams.get('emp')) {
                this.drawer = '';
                return;
            }
            await this.fetchDrawer(url);
        },

        /** @returns {Promise<boolean>} false when the caller should fall back to a real navigation. */
        async fetchDrawer(url) {
            const id = url.searchParams.get('emp');
            const query = new URLSearchParams(url.search);
            query.delete('emp');

            try {
                const res = await fetch(
                    `/app/attendance-report/person/${id}?${query}`,
                    { headers: { 'X-Requested-With': 'XMLHttpRequest' } },
                );
                if (! res.ok) return false;
                this.drawer = await res.text();

                return true;
            } catch {
                return false;
            }
        },
        get periodLabel() {
            const set = this.stepLabels[this.gran];
            const at = set && set[this.offset];
            return at ? at[$store.ui.lang] : null;
        },
     }"
     @click="onNavigate($event)"
     @submit="onFilterSubmit($event)"
     :aria-busy="busy || null">

    @include('partials.guide', [
        'key' => 'attendance-report',
        'en' => [
            'title' => 'Attendance Reports',
            'body'  => 'One row for every active employee on every working day, whether they clocked in or not. Someone who never clocked still fills the period with rows saying No punch, so nothing hides by simply being absent from the data. The totals above the table count the period you picked; the chips beside them only change which rows you are looking at.',
            'who'   => 'Management and HR only',
            'steps' => [
                'Pick Day, Week or Month, and step back with the arrows. Custom takes any two dates.',
                'Narrow by department or by name. Those move the totals, because they change whose period this is.',
                'Click a chip to pull just the broken rows to the front. The totals stay put — they still describe the whole period.',
                'A missing clock-out is tinted red. Fix opens that person on that day so you can type the time in.',
                'Export downloads exactly what the table is showing, as a file Excel opens.',
            ],
        ],
        'ms' => [
            'title' => 'Laporan Kehadiran',
            'body'  => 'Satu baris untuk setiap pekerja aktif pada setiap hari bekerja, sama ada mereka clock in atau tidak. Sesiapa yang tidak pernah clock in tetap memenuhi tempoh dengan baris Tiada clock in, jadi tiada siapa hilang hanya kerana tiada data. Jumlah di atas jadual mengira tempoh yang anda pilih; cip di sebelahnya hanya menukar baris yang anda lihat.',
            'who'   => 'Pengurusan dan HR sahaja',
            'steps' => [
                'Pilih Hari, Minggu atau Bulan, dan undur dengan anak panah. Tersuai menerima mana-mana dua tarikh.',
                'Tapis ikut jabatan atau nama. Kedua-duanya menggerakkan jumlah, kerana ia menukar tempoh siapa yang dilihat.',
                'Klik satu cip untuk membawa baris bermasalah ke hadapan. Jumlah tidak berubah — ia masih menerangkan seluruh tempoh.',
                'Clock out yang tiada diwarnakan merah. Betulkan membuka orang itu pada hari itu supaya masa boleh ditaip.',
                'Eksport memuat turun tepat apa yang jadual tunjukkan, sebagai fail yang Excel boleh buka.',
            ],
        ],
    ])

    {{-- Swapped in place on every filter change; see reloadBody() above. --}}
    <div x-ref="body">
        @include('partials.attendance-report.ledger-body')
    </div>

    {{-- Both drawers live inside this x-data, not beside it: their close controls call
         closePerson(), and the host below needs `drawer` in scope. Each is
         position:fixed, so nesting costs nothing in layout. --}}

    {{-- Server-rendered for a direct ?emp= hit, a reload, or JavaScript being off.
         Steps aside as soon as a fetched one exists, so landing on ?emp= and then
         clicking somebody else does not leave two drawers stacked. --}}
    <div x-show="! drawer && ! tookOver">
        @include('partials.attendance-report.person-drawer')
    </div>

    {{-- The host for one fetched in place. Alpine initialises what x-html injects. --}}
    <div x-html="drawer"></div>
</div>

@include('partials.map-view')
@endsection
