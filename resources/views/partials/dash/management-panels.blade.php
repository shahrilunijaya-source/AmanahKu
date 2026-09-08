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
@endphp
<div class="uj-mgmt" x-data="{
        lateOpen: true,
        overdueOpen: true,
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
    <div class="uj-mgmt-panel" data-panel="lateness">
        <button type="button" class="uj-mgmt-head" @click="lateOpen = ! lateOpen">
            <span x-text="$store.ui.lang==='en' ? 'Lateness today' : 'Lewat hari ini'">Lateness today</span>
            <span aria-hidden="true" x-text="lateOpen ? '−' : '+'">&minus;</span>
        </button>
        <div class="uj-mgmt-body" x-show="lateOpen">
            @forelse ($lateness as $row)
                <div class="uj-mgmt-row" data-late-row="{{ $row['employee_id'] }}">
                    <span class="uj-mgmt-name">{{ $row['name'] }}</span>
                    <span class="uj-mgmt-status" x-text="$store.ui.lang==='en' ? @js($row['status_en']) : @js($row['status_ms'])">{{ $row['status_en'] }}</span>
                </div>
            @empty
                <div class="uj-mgmt-empty" x-text="$store.ui.lang==='en' ? 'Nobody late today.' : 'Tiada yang lewat hari ini.'">Nobody late today.</div>
            @endforelse
        </div>
    </div>
    <div class="uj-mgmt-panel" data-panel="overdue">
        <button type="button" class="uj-mgmt-head" @click="overdueOpen = ! overdueOpen">
            <span x-text="$store.ui.lang==='en' ? 'Overdue by Primary Owner' : 'Tertunggak mengikut Pemilik Utama'">Overdue by Primary Owner</span>
            <span aria-hidden="true" x-text="overdueOpen ? '−' : '+'">&minus;</span>
        </button>
        <div class="uj-mgmt-body" x-show="overdueOpen">
            @forelse ($overdue as $group)
                <div class="uj-mgmt-owner" data-overdue-owner="{{ $group['owner_id'] }}">
                    <span class="uj-mgmt-owner-name">{{ $group['owner_name'] }}</span>
                    @foreach ($group['cards'] as $card)
                        @php
                            $nudgeUrl = url('/app/management/overdue/'.$card['id'].'/nudge');
                            $reassignUrl = url('/app/management/overdue/'.$card['id'].'/reassign');
                        @endphp
                        <div class="uj-mgmt-card" data-card="{{ $card['id'] }}">
                            <span class="uj-mgmt-card-title">{{ $card['title'] }}</span>
                            <span class="uj-mgmt-days" x-text="$store.ui.lang==='en' ? @js($card['days_overdue'].' days overdue') : @js($card['days_overdue'].' hari tertunggak')">{{ $card['days_overdue'] }} days overdue</span>
                            <button type="button" class="uj-mgmt-btn" data-nudge-url="{{ $nudgeUrl }}" @click="nudge('{{ $nudgeUrl }}')" x-text="$store.ui.lang==='en' ? 'Nudge' : 'Ingatkan'">Nudge</button>
                            @if ($card['can_reassign'] ?? true)
                                <button type="button" class="uj-mgmt-btn" data-reassign-url="{{ $reassignUrl }}" @click="reassign('{{ $reassignUrl }}')" x-text="$store.ui.lang==='en' ? 'Reassign' : 'Tugas semula'">Reassign</button>
                            @endif
                        </div>
                    @endforeach
                </div>
            @empty
                <div class="uj-mgmt-empty" x-text="$store.ui.lang==='en' ? 'Nothing overdue.' : 'Tiada yang tertunggak.'">Nothing overdue.</div>
            @endforelse
        </div>
    </div>
</div>
