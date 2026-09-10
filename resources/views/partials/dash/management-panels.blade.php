{{--
    CR-17: lateness today + overdue by Primary Owner. Shared by the dashboard's
    `management` band (partials.dash.bands) and the dedicated `/app/management/exceptions`
    page (screens.management-exceptions) so the two never render different markup for the
    same figures. Text only — nothing here needs a "Keep it plain" variant.

    $mgmt  DashboardBands::managementSlot() shape:
           ['lateness' => list<{employee_id,name,status_en,status_ms}>,
            'overdue' => list<{owner_id,owner_name,cards:list<{id,title,days_overdue}>}>]
--}}
@php
    $lateness = $mgmt['lateness'] ?? [];
    $overdue = $mgmt['overdue'] ?? [];
    // On the dashboard only the first few rows show; every row is still in the markup
    // (CR17Test reads them) and "Show all" or the exceptions page reveals the rest.
    $compact = $compact ?? false;
    $peek = 5;
@endphp
<div class="uj-mgmt {{ $extraClass ?? '' }}" x-data="{
        lateOpen: true,
        overdueOpen: true,
        lateAll: {{ $compact ? 'false' : 'true' }},
        overdueAll: {{ $compact ? 'false' : 'true' }},
        // Page mode: a search box over names and card titles, owners folded until
        // clicked (a search unfolds whoever matches). The dashboard band has neither.
        q: '',
        open: {},
        hit(text) { const q = this.q.trim().toLowerCase(); return ! q || text.toLowerCase().includes(q); },
        unfolded(id) { return {{ $compact ? 'true' : 'false' }} || this.q.trim() !== '' || !! this.open[id]; },
        busy: false,
        csrf() { return document.querySelector('meta[name=csrf-token]').content; },
        async nudge(url) {
            if (this.busy) { return; }
            this.busy = true;
            try {
                const r = await fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': this.csrf(), 'Accept': 'application/json' } });
                if (r.ok) { window.location.reload(); return; }
                const d = await r.json().catch(() => ({}));
                alert(d.message || (this.$store.ui.lang === 'en' ? 'Could not nudge.' : 'Gagal mengingatkan.'));
            } finally { this.busy = false; }
        },
        async reassign(url) {
            if (this.busy) { return; }
            const employeeId = window.prompt(this.$store.ui.lang === 'en' ? 'Reassign to employee ID:' : 'Tugaskan kepada ID pekerja:');
            if (! employeeId) { return; }
            const reason = window.prompt(this.$store.ui.lang === 'en' ? 'Reason:' : 'Sebab:');
            if (! reason) { return; }
            this.busy = true;
            try {
                const r = await fetch(url, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': this.csrf(), 'Accept': 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify({ employee_id: Number(employeeId), reason }),
                });
                if (r.ok) { window.location.reload(); return; }
                const d = await r.json().catch(() => ({}));
                alert(d.message || (this.$store.ui.lang === 'en' ? 'Could not reassign.' : 'Gagal menugaskan semula.'));
            } finally { this.busy = false; }
        },
    }">
    @if (! $compact)
        <label class="uj-mgmt-search">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
            <input type="search" x-model="q" :placeholder="$store.ui.lang==='en' ? 'Filter by name or card' : 'Tapis ikut nama atau kad'" autocomplete="off">
        </label>
    @endif
    <div class="uj-mgmt-panel" data-panel="lateness">
        <button type="button" class="uj-mgmt-head" @click="lateOpen = ! lateOpen">
            <span><span x-text="$store.ui.lang==='en' ? 'Lateness today' : 'Lewat hari ini'">Lateness today</span> <span class="uj-mgmt-n">{{ count($lateness) }}</span></span>
            <span aria-hidden="true" x-text="lateOpen ? '−' : '+'">&minus;</span>
        </button>
        <div class="uj-mgmt-body" x-show="lateOpen">
            @forelse ($lateness as $i => $row)
                <div class="uj-mgmt-row" data-late-row="{{ $row['employee_id'] }}" x-show="{{ $i >= $peek ? 'lateAll && ' : '' }}hit(@js($row['name']))">
                    <span class="uj-mgmt-name">{{ $row['name'] }}</span>
                    <span class="uj-mgmt-status" x-text="$store.ui.lang==='en' ? @js($row['status_en']) : @js($row['status_ms'])">{{ $row['status_en'] }}</span>
                </div>
            @empty
                <div class="uj-mgmt-empty" x-text="$store.ui.lang==='en' ? 'Nobody late today.' : 'Tiada yang lewat hari ini.'">Nobody late today.</div>
            @endforelse
            @if ($compact && count($lateness) > $peek)
                <div class="uj-mgmt-more">
                    <button type="button" class="uj-mgmt-btn" @click="lateAll = ! lateAll"
                            x-text="lateAll ? ($store.ui.lang==='en' ? 'Show fewer' : 'Tunjuk kurang') : ($store.ui.lang==='en' ? @js('Show all '.count($lateness)) : @js('Tunjuk semua '.count($lateness)))">Show all {{ count($lateness) }}</button>
                    <a href="{{ route('management.exceptions') }}" x-text="$store.ui.lang==='en' ? 'Open exceptions page' : 'Buka halaman pengecualian'">Open exceptions page</a>
                </div>
            @endif
        </div>
    </div>
    <div class="uj-mgmt-panel" data-panel="overdue">
        <button type="button" class="uj-mgmt-head" @click="overdueOpen = ! overdueOpen">
            <span><span x-text="$store.ui.lang==='en' ? 'Overdue tasks' : 'Tugasan tertunggak'">Overdue tasks</span> <span class="uj-mgmt-n">{{ array_sum(array_map(fn ($g) => count($g['cards']), $overdue)) }}</span></span>
            <span aria-hidden="true" x-text="overdueOpen ? '−' : '+'">&minus;</span>
        </button>
        <div class="uj-mgmt-body" x-show="overdueOpen">
            @php $shown = 0; $totalCards = array_sum(array_map(fn ($g) => count($g['cards']), $overdue)); @endphp
            @forelse ($overdue as $group)
                {{-- Peek counts cards, not owners: one owner can hold twenty. --}}
                @php $haystack = $group['owner_name'].' '.implode(' ', array_column($group['cards'], 'title')); $worst = max(array_column($group['cards'], 'days_overdue') ?: [0]); @endphp
                <div class="uj-mgmt-owner" data-overdue-owner="{{ $group['owner_id'] }}" x-show="{{ $shown >= $peek ? 'overdueAll && ' : '' }}hit(@js($haystack))">
                    @if ($compact)
                        <span class="uj-mgmt-owner-name">{{ $group['owner_name'] }}</span>
                    @else
                        <button type="button" class="uj-mgmt-owner-name uj-mgmt-owner-toggle" @click="open[{{ $group['owner_id'] }}] = ! open[{{ $group['owner_id'] }}]" :aria-expanded="unfolded({{ $group['owner_id'] }})">
                            <span class="uj-mgmt-chev" aria-hidden="true" :data-open="unfolded({{ $group['owner_id'] }}) ? '' : null">&#9656;</span>
                            {{ $group['owner_name'] }}
                            <span class="uj-mgmt-n">{{ count($group['cards']) }}</span>
                            <span class="uj-mgmt-worst" x-text="$store.ui.lang==='en' ? @js('worst '.$worst.' days') : @js('paling teruk '.$worst.' hari')">worst {{ $worst }} days</span>
                        </button>
                    @endif
                    @foreach ($group['cards'] as $card)
                        @php
                            $hide = $shown++ >= $peek;
                            $nudgeUrl = url('/app/management/overdue/'.$card['id'].'/nudge');
                            $reassignUrl = url('/app/management/overdue/'.$card['id'].'/reassign');
                        @endphp
                        <div class="uj-mgmt-card" data-card="{{ $card['id'] }}" x-show="{{ $hide ? 'overdueAll && ' : '' }}unfolded({{ $group['owner_id'] }}) && hit(@js($group['owner_name'].' '.$card['title']))">
                            <span class="uj-mgmt-card-title">{{ $card['title'] }}</span>
                            <span class="uj-mgmt-days" x-text="$store.ui.lang==='en' ? @js($card['days_overdue'].' days overdue') : @js($card['days_overdue'].' hari tertunggak')">{{ $card['days_overdue'] }} days overdue</span>
                            <span class="uj-mgmt-acts">
                                <button type="button" class="uj-mgmt-btn" data-nudge-url="{{ $nudgeUrl }}" @click="nudge('{{ $nudgeUrl }}')" x-text="$store.ui.lang==='en' ? 'Nudge' : 'Ingatkan'">Nudge</button>
                                @if ($card['can_reassign'] ?? true)
                                    <button type="button" class="uj-mgmt-btn" data-reassign-url="{{ $reassignUrl }}" @click="reassign('{{ $reassignUrl }}')" x-text="$store.ui.lang==='en' ? 'Reassign' : 'Tugas semula'">Reassign</button>
                                @endif
                            </span>
                        </div>
                    @endforeach
                </div>
            @empty
                <div class="uj-mgmt-empty" x-text="$store.ui.lang==='en' ? 'Nothing overdue.' : 'Tiada yang tertunggak.'">Nothing overdue.</div>
            @endforelse
            @if ($compact && $totalCards > $peek)
                <div class="uj-mgmt-more">
                    <button type="button" class="uj-mgmt-btn" @click="overdueAll = ! overdueAll"
                            x-text="overdueAll ? ($store.ui.lang==='en' ? 'Show fewer' : 'Tunjuk kurang') : ($store.ui.lang==='en' ? @js('Show all '.$totalCards.' cards') : @js('Tunjuk semua '.$totalCards.' kad'))">Show all {{ $totalCards }} cards</button>
                    <a href="{{ route('management.exceptions') }}" x-text="$store.ui.lang==='en' ? 'Open exceptions page' : 'Buka halaman pengecualian'">Open exceptions page</a>
                </div>
            @endif
        </div>
    </div>
</div>
