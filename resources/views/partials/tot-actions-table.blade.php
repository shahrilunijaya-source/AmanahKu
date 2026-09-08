{{-- CR-09/CR-10: Keputusan/Tindakan Susulan. "Create T.A.A. task" is a fetch
     (totActionCard, resources/js/tot-action.js) because tot.actions.card answers JSON only,
     unlike the rest of this drawer's plain form posts; add/edit/delete stay plain form posts
     with a redirect back, same as the rest of this drawer. --}}
@php $previousSession = $session->previousSession(); @endphp
@if ($previousSession && $previousSession->actions->isNotEmpty())
    <div style="margin-bottom:16px;">
        <div class="wd-sech" style="margin-bottom:8px;" x-text="$store.ui.lang==='en' ? 'Tindakan bulan lepas' : 'Tindakan bulan lepas'">Tindakan bulan lepas</div>
        @foreach ($previousSession->actions as $prevAction)
            <div style="font-size:13px;color:var(--body);padding:4px 0;border-bottom:1px solid var(--line);">
                {{ $prevAction->action }}
                ·
                {{ $prevAction->owner?->display_name ?? '—' }}
                ·
                <span class="tot-presenter-tag">{{ $prevAction->statusLabel() }}</span>
            </div>
        @endforeach
    </div>
@endif

<div style="margin-bottom:16px;">
    <div class="wd-sech" style="margin-bottom:8px;" x-text="$store.ui.lang==='en' ? 'Tindakan' : 'Tindakan'">Tindakan</div>

    @forelse ($session->actions as $action)
        <div style="display:flex;align-items:flex-start;gap:10px;flex-wrap:wrap;padding:8px 0;border-bottom:1px solid var(--line);"
             x-data="totActionCard({
                 sessionId: {{ $session->id }},
                 actionId: {{ $action->id }},
                 workItemId: {{ $action->work_item_id ?? 'null' }},
                 dueAt: {{ \Illuminate\Support\Js::from($action->workItem?->due_at?->format('Y-m-d')) }},
                 dueText: {{ \Illuminate\Support\Js::from($action->workItem?->due_at?->format('j M Y')) }},
             })">
            <div style="flex:1;min-width:0;">
                <div style="font-size:13.5px;color:var(--ink);">{{ $action->action }}</div>
                <div class="wd-sub" style="margin:2px 0 0;">
                    {{ $action->owner?->display_name ?? '—' }}
                    @if ($action->helpers->isNotEmpty())
                        · {{ $action->helpers->pluck('display_name')->join(', ') }}
                    @endif
                    ·
                    <template x-if="!dueAt">
                        <span>{{ $action->target_date?->format('j M Y') ?? 'Bulan hadapan' }}</span>
                    </template>
                    <template x-if="dueAt">
                        <span x-text="dueText"></span>
                    </template>
                    @if ($action->slot)
                        · <span class="tot-presenter-tag">{{ $action->slot->title }}</span>
                    @endif
                    · <span class="tot-presenter-tag">{{ $action->statusLabel() }}</span>
                </div>
            </div>
            <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                @if ($action->canCreateCardBy($role, $employee))
                    <button type="button" class="tot-pillbtn" :disabled="busy || workItemId" @click="createCard()"
                            x-text="workItemId ? ($store.ui.lang==='en' ? 'Task created' : 'Tugasan dicipta') : ($store.ui.lang==='en' ? 'Create T.A.A. task' : 'Cipta tugasan T.A.A.')">
                        Create T.A.A. task
                    </button>
                @endif
                @if ($canManageSession)
                    <details>
                        <summary class="tot-pillbtn" style="display:inline-block;cursor:pointer;" x-text="$store.ui.lang==='en' ? 'Edit' : 'Sunting'">Edit</summary>
                        <form method="post" action="{{ route('tot.actions.update', [$session, $action]) }}" style="max-width:340px;margin-top:6px;">
                            @csrf
                            <input class="tot-field" name="action" value="{{ $action->action }}">
                            <select class="tot-field" name="owners[]" style="margin-top:6px;">
                                @foreach ($assignableEmployees as $e)
                                    <option value="{{ $e->id }}" @selected($e->id === $action->owner_employee_id)>{{ $e->name }}</option>
                                @endforeach
                            </select>
                            <select class="tot-field" name="owners[]" multiple style="margin-top:6px;">
                                @foreach ($assignableEmployees as $e)
                                    <option value="{{ $e->id }}" @selected($action->helpers->contains('id', $e->id))>{{ $e->name }}</option>
                                @endforeach
                            </select>
                            <input type="date" class="tot-field" name="target_date" value="{{ $action->target_date?->format('Y-m-d') }}" style="margin-top:6px;" @disabled($action->work_item_id !== null)>
                            <button type="submit" class="tot-btn-g" style="margin-top:6px;" x-text="$store.ui.lang==='en' ? 'Save' : 'Simpan'">Save</button>
                        </form>
                    </details>
                    <form method="post" action="{{ route('tot.actions.delete', [$session, $action]) }}" onsubmit="return confirm('Delete this tindakan?');">
                        @csrf
                        <button type="submit" class="tot-pillbtn" x-text="$store.ui.lang==='en' ? 'Delete' : 'Padam'">Delete</button>
                    </form>
                @endif
            </div>
        </div>
    @empty
        <div class="tot-note" x-text="$store.ui.lang==='en' ? 'No tindakan yet.' : 'Belum ada tindakan.'">No tindakan yet.</div>
    @endforelse

    @if ($canManageSession)
        <details style="margin-top:8px;">
            <summary class="tot-pillbtn" style="display:inline-block;cursor:pointer;" x-text="$store.ui.lang==='en' ? 'Add tindakan' : 'Tambah tindakan'">Add tindakan</summary>
            <form method="post" action="{{ route('tot.actions.store', $session) }}" style="max-width:560px;margin-top:8px;">
                @csrf
                <label class="tot-lbl">Tindakan</label>
                <input class="tot-field" name="action">
                <label class="tot-lbl" style="margin-top:8px;">Pemilik</label>
                <select class="tot-field" name="owners[]">
                    <option value="">—</option>
                    @foreach ($assignableEmployees as $e)
                        <option value="{{ $e->id }}">{{ $e->name }}</option>
                    @endforeach
                </select>
                <label class="tot-lbl" style="margin-top:8px;" x-text="$store.ui.lang==='en' ? 'Helpers' : 'Pembantu'">Helpers</label>
                <select class="tot-field" name="owners[]" multiple>
                    @foreach ($assignableEmployees as $e)
                        <option value="{{ $e->id }}">{{ $e->name }}</option>
                    @endforeach
                </select>
                <label class="tot-lbl" style="margin-top:8px;">Target date (leave blank for Bulan hadapan)</label>
                <input type="date" class="tot-field" name="target_date">
                {{-- QA F2: the spec's "linked slot" had no picker on the screen. --}}
                <label class="tot-lbl" style="margin-top:8px;">Linked slot</label>
                <select class="tot-field" name="slot_id">
                    <option value="">—</option>
                    @foreach ($session->slots as $slot)
                        <option value="{{ $slot->id }}">{{ $slot->title }}</option>
                    @endforeach
                </select>
                <label style="display:flex;align-items:center;gap:6px;margin-top:8px;font-size:12.5px;color:var(--body);">
                    <input type="checkbox" name="create_card" value="1" checked>
                    <span x-text="$store.ui.lang==='en' ? 'Create the T.A.A. task straight away' : 'Cipta tugasan T.A.A. serta-merta'">Create the T.A.A. task straight away</span>
                </label>
                <div style="margin-top:8px;">
                    <button type="submit" class="tot-btn-g" x-text="$store.ui.lang==='en' ? 'Add' : 'Tambah'">Add</button>
                </div>
            </form>
        </details>
    @endif
</div>
