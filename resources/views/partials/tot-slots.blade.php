{{-- CR-09: ordered slots, each with its own title/kind/presenters and its own discussion
     thread (totSlotThread Alpine component, resources/js/tot-slot-thread.js — lazy-loaded
     on first open). Editing is a plain toggle form per slot, same "edit in place" pattern
     tot-edit-form already uses; nothing here needs a modal. --}}
<div style="margin-bottom:16px;">
    <div class="wd-sech" style="margin-bottom:8px;" x-text="$store.ui.lang==='en' ? 'Session Slots' : 'Slot Sesi'">Session Slots</div>

    @forelse ($session->slots as $slot)
        @php
            $leadIds = $slot->presenters->filter(fn ($p) => ! $p->pivot->support)->pluck('id')->all();
            $supportIds = $slot->presenters->filter(fn ($p) => (bool) $p->pivot->support)->pluck('id')->all();
        @endphp
        <div style="border:1px solid var(--line);border-radius:10px;padding:12px;margin-bottom:10px;" x-data="{ editing: false }">
            <div style="display:flex;align-items:flex-start;gap:10px;">
                <span class="tot-presenter-tag" style="flex-shrink:0;">{{ ucfirst($slot->kind) }}</span>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:14px;font-weight:600;color:var(--ink);">{{ $slot->title }}</div>
                    <div class="wd-sub" style="margin:2px 0 0;">{{ $slot->presenterLabel() ?: '—' }}</div>
                    @if (filled($slot->summary))
                        <p style="font-size:13px;color:var(--body);line-height:1.6;margin:6px 0 0;">{{ $slot->summary }}</p>
                    @endif
                </div>
                @if ($canManageSession)
                    <button type="button" class="wd-ico" @click="editing = !editing"
                            :aria-label="$store.ui.lang==='en' ? 'Edit slot' : 'Sunting slot'">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                    </button>
                @endif
            </div>

            @if ($canManageSession)
                <div x-show="editing" x-cloak style="margin-top:10px;padding-top:10px;border-top:1px solid var(--line);">
                    <form method="post" action="{{ route('tot.slots.update', [$session, $slot]) }}" style="max-width:560px;">
                        @csrf
                        <label class="tot-lbl">Title</label>
                        <input class="tot-field" name="title" value="{{ $slot->title }}">
                        <label class="tot-lbl" style="margin-top:8px;">Kind</label>
                        <select class="tot-field" name="kind">
                            @foreach (\App\Models\TotSlot::KINDS as $k)
                                <option value="{{ $k }}" @selected($k === $slot->kind)>{{ ucfirst($k) }}</option>
                            @endforeach
                        </select>
                        <label class="tot-lbl" style="margin-top:8px;">Format</label>
                        <select class="tot-field" name="format">
                            <option value="">—</option>
                            @foreach (\App\Models\TotSlot::FORMATS as $f)
                                <option value="{{ $f }}" @selected($f === $slot->format)>{{ ucfirst($f) }}</option>
                            @endforeach
                        </select>
                        <label class="tot-lbl" style="margin-top:8px;">Status</label>
                        <select class="tot-field" name="status">
                            <option value="">—</option>
                            @foreach (\App\Models\TotSlot::STATUSES as $s)
                                <option value="{{ $s }}" @selected($s === $slot->status)>{{ ucfirst($s) }}</option>
                            @endforeach
                        </select>
                        <label class="tot-lbl" style="margin-top:8px;">Presenters</label>
                        <select class="tot-field" name="presenters[]" multiple style="height:auto;min-height:70px;">
                            @foreach ($assignableEmployees as $e)
                                <option value="{{ $e->id }}" @selected(in_array($e->id, $leadIds, true))>{{ $e->name }}</option>
                            @endforeach
                        </select>
                        <label class="tot-lbl" style="margin-top:8px;">Sokongan</label>
                        <select class="tot-field" name="support[]" multiple style="height:auto;min-height:70px;">
                            @foreach ($assignableEmployees as $e)
                                <option value="{{ $e->id }}" @selected(in_array($e->id, $supportIds, true))>{{ $e->name }}</option>
                            @endforeach
                        </select>
                        <label class="tot-lbl" style="margin-top:8px;">Summary</label>
                        <textarea class="tot-field" name="summary" style="height:60px;">{{ $slot->summary }}</textarea>
                        <div style="margin-top:8px;">
                            <button type="submit" class="tot-btn-g" x-text="$store.ui.lang==='en' ? 'Save' : 'Simpan'">Save</button>
                        </div>
                    </form>
                    <form method="post" action="{{ route('tot.slots.delete', [$session, $slot]) }}" style="margin-top:8px;"
                          onsubmit="return confirm('Remove this slot?');">
                        @csrf
                        <button type="submit" class="tot-pillbtn" style="color:#b42318;" x-text="$store.ui.lang==='en' ? 'Delete slot' : 'Padam slot'">Delete slot</button>
                    </form>
                </div>
            @endif

            <div x-data="totSlotThread({ sessionId: {{ $session->id }}, slotId: {{ $slot->id }} })"
                 style="margin-top:10px;padding-top:10px;border-top:1px solid var(--line);">
                <button type="button" class="tot-pillbtn" @click="toggle()"
                        x-text="open ? ($store.ui.lang==='en' ? 'Hide discussion' : 'Sembunyikan perbincangan') : ($store.ui.lang==='en' ? 'Discussion' : 'Perbincangan')">Discussion</button>
                <div x-show="open" x-cloak style="margin-top:8px;">
                    <template x-if="thread === null">
                        <div class="tot-note" x-text="$store.ui.lang==='en' ? 'Loading' : 'Memuatkan'">Loading</div>
                    </template>
                    <template x-if="thread !== null && thread.length === 0">
                        <div class="tot-note" x-text="$store.ui.lang==='en' ? 'No comments yet.' : 'Belum ada komen.'">No comments yet.</div>
                    </template>
                    <div class="wd-cmts">
                        <template x-for="c in (thread || [])" :key="c.id">
                            <div class="wd-cmt">
                                <span class="tot-av" :style="`background:${c.color};color:#fff;`" x-text="c.initials"></span>
                                <div style="min-width:0;flex:1;">
                                    <div class="wd-cmt-who">
                                        <span class="wd-cmt-name" x-text="c.name"></span>
                                        <span class="tot-presenter-tag" x-show="c.presenter"
                                              x-text="$store.ui.lang==='en' ? 'Presenter' : 'Pembentang'">Presenter</span>
                                        <span class="wd-cmt-at" x-text="c.at"></span>
                                    </div>
                                    <div class="wd-cmt-body" x-text="c.body"></div>
                                </div>
                            </div>
                        </template>
                    </div>
                    <textarea rows="1" maxlength="2000" class="tot-field" style="margin-top:6px;"
                              :placeholder="$store.ui.lang==='en' ? 'Add a comment' : 'Tambah komen'"
                              @keydown.enter.prevent="post($event.target.value); $event.target.value = ''"></textarea>
                </div>
            </div>
        </div>
    @empty
        <div class="tot-note" x-text="$store.ui.lang==='en' ? 'No slots yet.' : 'Belum ada slot.'">No slots yet.</div>
    @endforelse

    @if ($canManageSession)
        <details style="margin-top:8px;">
            <summary class="tot-pillbtn" style="display:inline-block;cursor:pointer;" x-text="$store.ui.lang==='en' ? 'Add slot' : 'Tambah slot'">Add slot</summary>
            <form method="post" action="{{ route('tot.slots.store', $session) }}" style="max-width:560px;margin-top:8px;">
                @csrf
                <label class="tot-lbl">Title</label>
                <input class="tot-field" name="title">
                <label class="tot-lbl" style="margin-top:8px;">Kind</label>
                <select class="tot-field" name="kind">
                    @foreach (\App\Models\TotSlot::KINDS as $k)
                        <option value="{{ $k }}">{{ ucfirst($k) }}</option>
                    @endforeach
                </select>
                <div style="margin-top:8px;">
                    <button type="submit" class="tot-btn-g" x-text="$store.ui.lang==='en' ? 'Add' : 'Tambah'">Add</button>
                </div>
            </form>
        </details>
    @endif
</div>
