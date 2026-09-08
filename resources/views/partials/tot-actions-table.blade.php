{{-- CR-09: Keputusan/Tindakan Susulan. "Create T.A.A. task" is a fetch (totActionCard,
     resources/js/tot-action.js) because tot.actions.card answers JSON only, unlike the
     rest of this drawer's plain form posts. --}}
<div style="margin-bottom:16px;">
    <div class="wd-sech" style="margin-bottom:8px;" x-text="$store.ui.lang==='en' ? 'Tindakan' : 'Tindakan'">Tindakan</div>

    @forelse ($session->actions as $action)
        <div style="display:flex;align-items:flex-start;gap:10px;padding:8px 0;border-bottom:1px solid var(--line);"
             x-data="totActionCard({
                 sessionId: {{ $session->id }},
                 actionId: {{ $action->id }},
                 workItemId: {{ $action->work_item_id ?? 'null' }},
                 dueAt: {{ \Illuminate\Support\Js::from($action->workItem?->due_at?->format('Y-m-d')) }},
             })">
            <div style="flex:1;min-width:0;">
                <div style="font-size:13.5px;color:var(--ink);">{{ $action->action }}</div>
                <div class="wd-sub" style="margin:2px 0 0;">
                    {{ $action->owner?->display_name ?? '—' }}
                    ·
                    <template x-if="!dueAt">
                        <span>{{ $action->target_date?->format('j M Y') ?? 'Bulan hadapan' }}</span>
                    </template>
                    <template x-if="dueAt">
                        <span x-text="dueAt"></span>
                    </template>
                </div>
            </div>
            @if ($action->canCreateCardBy($role, $employee))
                <button type="button" class="tot-pillbtn" :disabled="busy || workItemId" @click="createCard()"
                        x-text="workItemId ? ($store.ui.lang==='en' ? 'Task created' : 'Tugasan dicipta') : ($store.ui.lang==='en' ? 'Create T.A.A. task' : 'Cipta tugasan T.A.A.')">
                    Create T.A.A. task
                </button>
            @endif
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
                <label class="tot-lbl" style="margin-top:8px;">Owner</label>
                <select class="tot-field" name="owner_employee_id">
                    <option value="">—</option>
                    @foreach ($assignableEmployees as $e)
                        <option value="{{ $e->id }}">{{ $e->name }}</option>
                    @endforeach
                </select>
                <label class="tot-lbl" style="margin-top:8px;">Target date (leave blank for Bulan hadapan)</label>
                <input type="date" class="tot-field" name="target_date">
                <div style="margin-top:8px;">
                    <button type="submit" class="tot-btn-g" x-text="$store.ui.lang==='en' ? 'Add' : 'Tambah'">Add</button>
                </div>
            </form>
        </details>
    @endif
</div>
