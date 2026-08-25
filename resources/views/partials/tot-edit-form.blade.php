<div class="tot-authoring">
<div class="tot-rule">
    <button type="button" class="tot-pillbtn" @click="editing = !editing">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
        <span x-text="$store.ui.lang==='en' ? 'Edit slot' : 'Sunting slot'">Edit slot</span>
    </button>

    <div x-show="editing" x-cloak style="margin-top:14px;">
        @if ($slotFailed)
            <div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:9px 12px;margin-bottom:14px;max-width:620px;">{{ $errors->first() }}</div>
        @endif
        <form method="post" action="{{ route('tot.update', $session) }}">
            @csrf
            <input type="hidden" name="totform" value="{{ $session->id }}">
            @if ($canAssignPresenter)
                @include('partials.tot-presenter-picker', ['session' => $session, 'assignableEmployees' => $assignableEmployees])
                @if (filled($session->presenter_name) && $session->presenterList()->isEmpty())
                    {{-- An imported slot whose historical name never matched an employee.
                         Shown so it is not silently lost; picking somebody above replaces it. --}}
                    <div class="tot-note" style="margin-top:6px;max-width:620px;" x-text="$store.ui.lang==='en' ? @js('Imported as “'.$session->presenter_name.'”, not yet matched to anybody. Picking a presenter above replaces it.') : @js('Diimport sebagai “'.$session->presenter_name.'”, belum dipadankan dengan sesiapa. Memilih pembentang di atas menggantikannya.')">Imported as &ldquo;{{ $session->presenter_name }}&rdquo;.</div>
                @endif
            @endif
            @if ($canManage)
                <div style="margin-top:12px;max-width:620px;"><label class="tot-lbl" x-text="$store.ui.lang==='en' ? 'Status' : 'Status'">Status</label>
                    <select class="tot-field" name="status">
                        @foreach (\App\Models\TotSession::STATUSES as $st)
                            <option value="{{ $st }}" @selected($session->status === $st) x-text="$store.ui.lang==='en' ? @js($statusLabels[$st]['en']) : @js($statusLabels[$st]['ms'])">{{ $statusLabels[$st]['en'] }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            @php $totSeesMaterial = $canManage || $isPresenterOfSlot; @endphp
            {{-- The material (topic/description/hours) belongs to the presenter of this
                 slot or to a privileged role, matching TotController::update(), which gives
                 a tot.assign holder no rule for any of these. Rendering them to a holder
                 would show fields the save silently discards. Links are gated separately
                 below: a tot.assign holder — the moderator — edits the Nota Perbincangan
                 row without needing any of this. --}}
            @if ($totSeesMaterial)
            <div style="margin-top:12px;max-width:620px;"><label class="tot-lbl" x-text="$store.ui.lang==='en' ? 'Topic' : 'Topik'">Topic</label><input class="tot-field" name="title" value="{{ $session->title }}"></div>
            <div style="margin-top:12px;max-width:620px;"><label class="tot-lbl" x-text="$store.ui.lang==='en' ? 'Description' : 'Penerangan'">Description</label><textarea class="tot-field" name="description" style="height:64px;padding-top:9px;resize:vertical;">{{ $session->description }}</textarea></div>

            {{-- Blank both fields to keep the usual hour: the mail and the board fall back
                 to TotSession::DEFAULT_START/DEFAULT_END when the columns are null. --}}
            <div style="margin-top:12px;display:grid;grid-template-columns:1fr 1fr;gap:12px;max-width:620px;">
                <div><label class="tot-lbl" x-text="$store.ui.lang==='en' ? 'Starts' : 'Bermula'">Starts</label><input class="tot-field" type="time" name="starts_at" value="{{ $session->starts_at ? substr((string) $session->starts_at, 0, 5) : '' }}" placeholder="{{ \App\Models\TotSession::DEFAULT_START }}"></div>
                <div><label class="tot-lbl" x-text="$store.ui.lang==='en' ? 'Ends' : 'Tamat'">Ends</label><input class="tot-field" type="time" name="ends_at" value="{{ $session->ends_at ? substr((string) $session->ends_at, 0, 5) : '' }}" placeholder="{{ \App\Models\TotSession::DEFAULT_END }}"></div>
            </div>
            <div class="tot-note" style="margin-top:6px;max-width:620px;" x-text="$store.ui.lang==='en' ? @js('Leave both blank for the usual '.\App\Models\TotSession::DEFAULT_START.'–'.\App\Models\TotSession::DEFAULT_END.'. Set them only for a month that moved.') : @js('Biarkan kedua-duanya kosong untuk waktu biasa '.\App\Models\TotSession::DEFAULT_START.'–'.\App\Models\TotSession::DEFAULT_END.'. Tetapkan hanya untuk bulan yang berubah.')">Leave both blank for the usual {{ \App\Models\TotSession::DEFAULT_START }}&ndash;{{ \App\Models\TotSession::DEFAULT_END }}.</div>
            @endif

            {{-- A slot with no links opens on the two rows a TOT always has, labelled
                 already so the presenter only pastes URLs. A row left with its label and
                 no URL is dropped on save (TotController::isUntouchedLinkRow). --}}
            @if ($totSeesMaterial)
                @php
                    $totEditorLinks = ! empty($session->links) ? $session->links : array_map(fn ($label) => ['label' => $label, 'url' => ''], \App\Models\TotSession::DEFAULT_LINK_LABELS);
                    $totHasModeratorLink = collect($totEditorLinks)->contains(fn ($link) => ($link['label'] ?? null) === \App\Models\TotSession::MODERATOR_LINK_LABEL);
                    if ($canAssignPresenter && ! $totHasModeratorLink) {
                        $totEditorLinks[] = ['label' => \App\Models\TotSession::MODERATOR_LINK_LABEL, 'url' => ''];
                    }
                    if (! $canAssignPresenter) {
                        $totEditorLinks = array_values(array_filter($totEditorLinks, fn ($link) => ($link['label'] ?? null) !== \App\Models\TotSession::MODERATOR_LINK_LABEL));
                    }
                @endphp
                {{-- The moderator row (Nota Perbincangan) only renders for $canAssignPresenter
                     (privileged roles plus a tot.assign holder) — TotController::update()
                     merges it back in on a save from a viewer who cannot see it. --}}
                <div style="margin-top:16px;max-width:620px;" x-data="{ links: {{ \Illuminate\Support\Js::from($totEditorLinks) }} }">
                    <label class="tot-lbl" x-text="$store.ui.lang==='en' ? 'Links' : 'Pautan'">Links</label>
                    <template x-for="(link, idx) in links" :key="idx">
                        <div style="display:grid;grid-template-columns:150px 1fr 38px;gap:8px;margin-bottom:8px;">
                            <input class="tot-field" :name="`links[${idx}][label]`" x-model="link.label" placeholder="Label">
                            <input class="tot-field" :name="`links[${idx}][url]`" x-model="link.url" placeholder="https://...">
                            <button type="button" class="tot-btn-g" style="padding:0;width:38px;" @click="links.splice(idx, 1)">&times;</button>
                        </div>
                    </template>
                    <button type="button" class="tot-pillbtn" @click="links.push({ label: '', url: '' })"><span x-text="$store.ui.lang==='en' ? '+ Add a link' : '+ Tambah pautan'">+ Add a link</span></button>
                </div>
            @elseif ($canAssignPresenter)
                {{-- A tot.assign holder with no other access to this slot (the moderator) —
                     the one link they edit, fixed to this label, nothing to add or remove.
                     TotController::update() preserves every other stored link untouched. --}}
                <div style="margin-top:16px;max-width:620px;">
                    <label class="tot-lbl">{{ \App\Models\TotSession::MODERATOR_LINK_LABEL }}</label>
                    <input type="hidden" name="links[0][label]" value="{{ \App\Models\TotSession::MODERATOR_LINK_LABEL }}">
                    <input class="tot-field" type="url" name="links[0][url]" placeholder="https://..." value="{{ collect($session->links ?? [])->firstWhere('label', \App\Models\TotSession::MODERATOR_LINK_LABEL)['url'] ?? '' }}">
                </div>
            @endif

            @if ($totSeesMaterial)
            {{-- Presenting is the contribution. There is no lesson to point at and none to
                 write: TotController::creditContribution marks the month when the slot is
                 marked done. --}}
            <div class="tot-note" style="margin-top:16px;max-width:620px;" x-text="$store.ui.lang==='en' ? @js('Presenting this session is your Knowledge Bank contribution for '.$session->session_date->format('F').'. It is credited once the slot is marked Done, so you do not also write a lesson.') : @js('Membentangkan sesi ini adalah sumbangan Bank Pengetahuan anda untuk '.$session->session_date->format('F').'. Ia dikreditkan setelah slot ditanda Selesai, jadi anda tidak perlu menulis pengajaran lain.')">Presenting this session is your Knowledge Bank contribution for {{ $session->session_date->format('F') }}. It is credited once the slot is marked Done, so you do not also write a lesson.</div>
            @endif

            <div class="tot-rule" style="max-width:620px;display:flex;gap:8px;align-items:center;">
                <button type="submit" class="tot-btn-p" x-text="$store.ui.lang==='en' ? 'Save slot' : 'Simpan slot'">Save slot</button>
                <button type="button" class="tot-btn-g" @click="editing = false" x-text="$store.ui.lang==='en' ? 'Cancel' : 'Batal'">Cancel</button>
                @if ($canManage && $session->status !== 'done')
                    <div class="tot-note" style="margin-left:auto;"><span x-text="$store.ui.lang==='en' ? @js('Marking this Done credits '.($session->presenter?->display_name ?? 'the presenter').'’s Knowledge Bank month.') : @js('Menandakan ini Selesai mengkredit bulan Bank Pengetahuan '.($session->presenter?->display_name ?? 'pembentang').'.')">Marking this <b style="color:var(--ink);font-weight:600;">Done</b> credits {{ $session->presenter?->display_name ?? 'the presenter' }}&rsquo;s Knowledge Bank month.</span></div>
                @endif
            </div>
        </form>

        @if ($canManage)
            <form method="post" action="{{ route('tot.destroy', $session) }}" style="margin-top:10px;" @submit="if (! confirm($store.ui.lang==='en' ? 'Remove this slot? This cannot be undone.' : 'Buang slot ini? Tindakan ini tidak boleh dibatalkan.')) $event.preventDefault();">
                @csrf
                <button type="submit" class="tot-btn-g" style="color:var(--error);" x-text="$store.ui.lang==='en' ? 'Delete slot' : 'Padam slot'">Delete slot</button>
            </form>
        @endif
    </div>
</div>
</div>
<div class="wd-locked tot-authoring-note">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>
    <span x-text="$store.ui.lang==='en' ? 'Editing your slot needs a wider screen. Your topic, description and links are set from a laptop. Everything else here works on a phone.' : 'Menyunting slot anda memerlukan skrin lebih lebar. Topik, penerangan dan pautan ditetapkan dari komputer riba. Selebihnya di sini berfungsi pada telefon.'">Editing your slot needs a wider screen.</span>
</div>
