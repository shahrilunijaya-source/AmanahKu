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
            @if ($canManage || $canAssignPresenter)
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;max-width:620px;">
                    @if ($canManage)
                        <div><label class="tot-lbl" x-text="$store.ui.lang==='en' ? 'Presenter name' : 'Nama pembentang'">Presenter name</label><input class="tot-field" name="presenter_name" value="{{ $session->presenter_name }}"></div>
                    @endif
                    @if ($canAssignPresenter)
                        <div><label class="tot-lbl" x-text="$store.ui.lang==='en' ? 'Presenter' : 'Pembentang'">Presenter</label>
                            <select class="tot-field" name="presenter_employee_id">
                                {{-- Blank first, so a presenter can be cleared: an empty value nulls
                                     both presenter_employee_id and presenter_name server-side. --}}
                                <option value="" x-text="$store.ui.lang==='en' ? 'Nobody yet' : 'Belum ada'">Nobody yet</option>
                                @foreach ($assignableEmployees as $person)
                                    <option value="{{ $person->id }}" @selected($session->presenter_employee_id === $person->id)>{{ $person->display_name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                </div>
            @endif
            @if ($canManage)
                <div class="tot-note" style="margin-top:6px;max-width:620px;" x-text="$store.ui.lang==='en' ? 'Picking a presenter overrides the presenter name above, everywhere this session is shown.' : 'Memilih pembentang mengatasi nama pembentang di atas, di mana sahaja sesi ini dipaparkan.'">Picking a presenter overrides the presenter name above, everywhere this session is shown.</div>
                <div style="margin-top:12px;max-width:620px;"><label class="tot-lbl" x-text="$store.ui.lang==='en' ? 'Status' : 'Status'">Status</label>
                    <select class="tot-field" name="status">
                        @foreach (\App\Models\TotSession::STATUSES as $st)
                            <option value="{{ $st }}" @selected($session->status === $st) x-text="$store.ui.lang==='en' ? @js($statusLabels[$st]['en']) : @js($statusLabels[$st]['ms'])">{{ $statusLabels[$st]['en'] }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            {{-- The material belongs to the presenter of this slot or to a
                 privileged role, matching TotController::update(), which gives
                 a tot.assign holder no rule for any of these. Rendering them
                 to a holder would show fields the save silently discards. --}}
            @if ($canManage || $isPresenterOfSlot)
            <div style="margin-top:12px;max-width:620px;"><label class="tot-lbl" x-text="$store.ui.lang==='en' ? 'Topic' : 'Topik'">Topic</label><input class="tot-field" name="title" value="{{ $session->title }}"></div>
            <div style="margin-top:12px;max-width:620px;"><label class="tot-lbl" x-text="$store.ui.lang==='en' ? 'Description' : 'Penerangan'">Description</label><textarea class="tot-field" name="description" style="height:64px;padding-top:9px;resize:vertical;">{{ $session->description }}</textarea></div>

            <div style="margin-top:16px;max-width:620px;" x-data="{ links: {{ \Illuminate\Support\Js::from(! empty($session->links) ? $session->links : [['label' => '', 'url' => '']]) }} }">
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

            <div style="margin-top:16px;max-width:620px;">
                <label class="tot-lbl" x-text="$store.ui.lang==='en' ? 'Related Knowledge Bank entry ID' : 'ID entri Bank Pengetahuan berkaitan'">Related Knowledge Bank entry ID</label>
                <input class="tot-field" type="number" name="entry_id" value="{{ $session->entry_id }}">
                <div class="tot-note" style="margin-top:6px;"><span x-text="$store.ui.lang==='en' ? @js('Optional. Links this session to a lesson the presenter already wrote. It never creates one.'.($session->entry ? ' Currently: '.$session->entry->title.'.' : '')) : @js('Pilihan. Kaitkan sesi ini dengan pengajaran yang telah ditulis pembentang. Ia tidak pernah mencipta satu.'.($session->entry ? ' Sekarang: '.$session->entry->title.'.' : ''))">Optional. Links this session to a lesson the presenter already wrote. It never creates one.@if ($session->entry) Currently: {{ $session->entry->title }}.@endif</span></div>
            </div>
            @endif

            <div class="tot-rule" style="max-width:620px;display:flex;gap:8px;align-items:center;">
                <button type="submit" class="tot-btn-p" x-text="$store.ui.lang==='en' ? 'Save slot' : 'Simpan slot'">Save slot</button>
                <button type="button" class="tot-btn-g" @click="editing = false" x-text="$store.ui.lang==='en' ? 'Cancel' : 'Batal'">Cancel</button>
                @if ($canManage && $session->status !== 'done')
                    <div class="tot-note" style="margin-left:auto;"><span x-text="$store.ui.lang==='en' ? @js('Marking this Done credits '.($session->presenter?->name ?? 'the presenter').'’s Knowledge Bank month.') : @js('Menandakan ini Selesai mengkredit bulan Bank Pengetahuan '.($session->presenter?->name ?? 'pembentang').'.')">Marking this <b style="color:var(--ink);font-weight:600;">Done</b> credits {{ $session->presenter?->name ?? 'the presenter' }}&rsquo;s Knowledge Bank month.</span></div>
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
