{{-- Solo / team presenter picker, shared by the create form and the slot editor.
     Mode is chosen first and is a UI affordance only: nothing stores it, a slot is "solo"
     because it has one presenter. Switching to solo keeps the first person picked and drops
     the rest, so the posted list always matches what the buttons say.

     Search matches the nickname the app shows and the legal name payroll uses, because the
     person filling the roster knows people by one or the other (same rule as the board's
     people picker). --}}
@php
    $pickerSelected = $session->presenterList()->map(fn ($person) => ['id' => $person->id, 'name' => $person->display_name])->values();
    $pickerRoster = $assignableEmployees->map(fn ($person) => [
        'id' => $person->id,
        'name' => $person->display_name,
        'legal' => $person->name,
    ])->values();
@endphp
<div style="max-width:620px;" x-data="{
    mode: {{ \Illuminate\Support\Js::from($session->isTeam() || $pickerSelected->count() > 1 ? 'team' : 'solo') }},
    chosen: {{ \Illuminate\Support\Js::from($pickerSelected) }},
    roster: {{ \Illuminate\Support\Js::from($pickerRoster) }},
    query: '',
    open: false,
    get matches() {
        const q = this.query.trim().toLowerCase();
        return q
            ? this.roster.filter((p) => p.name.toLowerCase().includes(q) || (p.legal || '').toLowerCase().includes(q))
            : this.roster;
    },
    has(id) {
        return this.chosen.some((p) => p.id === id);
    },
    pick(person) {
        if (this.mode === 'solo') {
            this.chosen = this.has(person.id) ? [] : [person];
            this.open = false;
            this.query = '';
            return;
        }
        // Team mode keeps the list open so several people go in without reopening it.
        this.chosen = this.has(person.id)
            ? this.chosen.filter((p) => p.id !== person.id)
            : [...this.chosen, person];
        this.query = '';
    },
    remove(id) {
        this.chosen = this.chosen.filter((p) => p.id !== id);
    },
    setMode(next) {
        this.mode = next;
        if (next === 'solo' && this.chosen.length > 1) {
            this.chosen = [this.chosen[0]];
        }
    },
}" @click.outside="open = false">
    <label class="tot-lbl" x-text="$store.ui.lang==='en' ? 'Presenter' : 'Pembentang'">Presenter</label>

    {{-- Tells the server this request decided the presenters even when nobody is picked,
         so clearing the last one is a real change and not a form that never showed them. --}}
    <input type="hidden" name="presenters_submitted" value="1">
    {{-- The mode is stored, not derived: a team nobody has been picked for yet holds no
         presenters at all, and the board still has to call it a team. --}}
    <input type="hidden" name="presenter_mode" :value="mode">

    <div style="display:flex;gap:6px;margin-bottom:8px;">
        <button type="button" class="tot-pillbtn" :aria-pressed="mode === 'solo'"
                :style="mode === 'solo' ? 'border-color:var(--ink);color:var(--ink);' : ''"
                @click="setMode('solo')">
            <span x-text="$store.ui.lang==='en' ? 'Solo' : 'Sendiri'">Solo</span>
        </button>
        <button type="button" class="tot-pillbtn" :aria-pressed="mode === 'team'"
                :style="mode === 'team' ? 'border-color:var(--ink);color:var(--ink);' : ''"
                @click="setMode('team')">
            <span x-text="$store.ui.lang==='en' ? 'Team' : 'Berkumpulan'">Team</span>
        </button>
    </div>

    <template x-for="person in chosen" :key="person.id">
        <input type="hidden" name="presenter_employee_ids[]" :value="person.id">
    </template>

    <div x-show="chosen.length" style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px;">
        <template x-for="person in chosen" :key="person.id">
            <span class="tot-lk" style="display:inline-flex;align-items:center;gap:6px;">
                <span x-text="person.name"></span>
                <button type="button" class="wd-ico" style="width:18px;height:18px;"
                        @click="remove(person.id)"
                        :aria-label="$store.ui.lang==='en' ? 'Remove' : 'Buang'">&times;</button>
            </span>
        </template>
    </div>

    <div style="position:relative;">
        <button type="button" class="tot-field" style="text-align:left;cursor:pointer;" @click="open = ! open">
            <span x-show="! chosen.length" x-text="$store.ui.lang==='en' ? 'Nobody yet' : 'Belum ada'">Nobody yet</span>
            <span x-show="chosen.length"
                  x-text="mode === 'solo'
                      ? chosen[0]?.name
                      : ($store.ui.lang==='en' ? chosen.length + ' people' : chosen.length + ' orang')"></span>
        </button>

        {{-- .wd-menu is anchored top:46px/right:12px for the drawer's overflow menu; this
             one hangs off its own trigger instead, so those two are overridden here rather
             than by touching the shared rule (never its z-index). --}}
        <div x-show="open" x-cloak class="wd-menu" style="top:100%;right:0;left:0;margin-top:4px;max-height:260px;overflow-y:auto;">
            <input class="tot-field" style="margin-bottom:6px;" x-model="query"
                   :placeholder="$store.ui.lang==='en' ? 'Search name or nickname' : 'Cari nama atau nama panggilan'">
            <template x-for="person in matches" :key="person.id">
                <button type="button" style="display:flex;align-items:center;" @click="pick(person)">
                    <span x-text="person.name"></span>
                    <span x-show="has(person.id)" style="margin-left:auto;">&check;</span>
                </button>
            </template>
            <div x-show="! matches.length" class="tot-note" style="padding:6px;"
                 x-text="$store.ui.lang==='en' ? 'Nobody by that name.' : 'Tiada nama begitu.'">Nobody by that name.</div>
        </div>
    </div>
</div>
