@extends('layouts.app')

@php
    $tag = ['assignment' => ['Assignment', 'var(--red)'], 'task' => ['Task', 'var(--info)'], 'adhoc' => ['Adhoc', 'var(--amber)']];
    $pri = ['high' => 'var(--error)', 'medium' => 'var(--amber)', 'low' => 'var(--muted)'];
    $priLabel = ['high' => 'High', 'medium' => 'Medium', 'low' => 'Low'];
    $statusLabels = ['todo' => 'To Do', 'prog' => 'In Progress', 'review' => 'In Review', 'done' => 'Done'];
    $fieldStyle = 'width:100%;height:38px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;background:#fff;color:var(--ink);outline:none;';
    $labelStyle = 'display:block;font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px;';
    $boardType = $boardType ?? 'core';
@endphp

@section('screen')
@include('partials.see-all-btn', ['target' => 'team-board', 'label' => 'See all staff tasks', 'labelMs' => 'Lihat tugasan semua staf'])
@include('partials.guide', [
    'key' => 'board',
    'en'  => [
        'title' => 'Work board',
        'body'  => 'Plan and track work as cards that move across four columns — To Do, In Progress, In Review, then Done. Drag a card to move it, or click it to add detail and comments.',
        'who'   => 'Anyone adds work · Owner moves their cards',
        'steps' => [
            'Click "+ Add a card" at the bottom of a column and type what needs doing.',
            'Click a card to open it — set type, priority, a due label, and write a description.',
            'Drag the card across columns as the work progresses, or use the status menu inside it.',
            'Leave comments on a card to keep the back-and-forth in one place.',
        ],
    ],
    'ms'  => [
        'title' => 'Papan kerja',
        'body'  => 'Rancang dan jejak kerja sebagai kad yang bergerak melalui empat lajur — To Do, In Progress, In Review, kemudian Done. Seret kad untuk gerakkannya, atau klik untuk tambah butiran dan komen.',
        'who'   => 'Sesiapa boleh tambah kerja · Pemilik gerak kad sendiri',
        'steps' => [
            'Klik "+ Tambah kad" di bahagian bawah lajur dan taip apa yang perlu dibuat.',
            'Klik kad untuk buka — tetapkan jenis, keutamaan, label tarikh akhir, dan tulis penerangan.',
            'Seret kad merentas lajur apabila kerja maju, atau guna menu status di dalamnya.',
            'Tinggalkan komen pada kad supaya perbualan kekal di satu tempat.',
        ],
    ],
])

<style>
    .uj-drag-ghost { opacity: .4; }
    .uj-wi { cursor: pointer; transition: box-shadow .12s, transform .12s; }
    .uj-wi:hover { box-shadow: 0 4px 14px rgba(20,20,40,.08); transform: translateY(-1px); }
</style>

<div x-data="workBoard(@js($boardType))">
    {{-- One board, all work types. Chips filter the cards live — no page reload. --}}
    <div style="display:flex;align-items:center;gap:7px;margin-bottom:16px;flex-wrap:wrap;">
        @foreach (['all' => ['All work', 'Semua kerja'], 'task' => ['Tasks', 'Tugas'], 'assignment' => ['Assignments', 'Tugasan'], 'adhoc' => ['Adhoc', 'Adhoc']] as $fk => $fl)
            <button type="button" @click="setFilter('{{ $fk }}')"
                    :style="filter === '{{ $fk }}'
                        ? { background: 'var(--red)', color: '#fff', borderColor: 'var(--red)' }
                        : { background: '#fff', color: 'var(--body)', borderColor: 'var(--hairline)' }"
                    style="padding:7px 14px;font-size:12.5px;font-weight:600;border:1px solid var(--hairline);border-radius:9999px;cursor:pointer;display:inline-flex;align-items:center;gap:7px;">
                <span x-text="$store.ui.lang==='en' ? @js($fl[0]) : @js($fl[1])">{{ $fl[0] }}</span>
                <span x-text="counts['{{ $fk }}']" style="font-size:11px;opacity:.7;font-family:var(--font-mono);"></span>
            </button>
        @endforeach
    </div>

    <div style="display:flex;gap:14px;align-items:flex-start;overflow-x:auto;padding-bottom:8px;">
        @foreach ($columns as $key => $col)
            <div style="flex:1;min-width:272px;">
                <div style="display:flex;align-items:center;gap:8px;padding:0 4px 12px;">
                    <span style="font-size:13px;font-weight:600;color:var(--ink);">{{ $col['title'] }}</span>
                    <span data-count="{{ $key }}" style="font-size:11px;font-weight:600;color:var(--muted);background:var(--hairline-soft);padding:1px 8px;border-radius:9999px;">{{ $col['cards']->count() }}</span>
                </div>

                <div data-list="{{ $key }}" style="display:flex;flex-direction:column;gap:10px;min-height:24px;">
                    @forelse ($col['cards'] as $c)
                        @php [$tlabel, $tcolor] = $tag[$c->type] ?? ['Task', 'var(--info)']; @endphp
                        <div class="uj-card uj-wi" data-card data-id="{{ $c->id }}" data-status="{{ $c->status }}" data-type="{{ $c->type }}" @if ($c->assigned_by_id) data-assigned="1" @endif style="padding:13px 14px;">
                            <div class="wi-head">
                                <span class="wi-tag" style="--wi-tag:{{ $tcolor }};">{{ $tlabel }}</span>
                                <span class="wi-pri">@if ($c->priority)<span class="wi-pri-txt" style="--wi-pri:{{ $pri[$c->priority] }};">{{ $priLabel[$c->priority] ?? ucfirst($c->priority) }}</span>@endif</span>
                            </div>
                            @if ($c->assigned_by_id)
                                <div class="wi-assigned">Assigned by {{ $c->assignedBy?->name ?? '—' }}</div>
                            @endif
                            <div class="wi-title">{{ $c->title }}</div>
                            <div class="wi-foot">
                                <span class="wi-due">{{ $c->dueText() }}</span>
                                <span class="wi-meta">
                                    <span class="wi-comments">@if (($c->comments_count ?? 0) > 0)<span class="wi-comment-chip"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>{{ $c->comments_count }}</span>@endif</span>
                                    <span class="wi-est">{{ $c->estimate_hours ? $c->estimate_hours.'h' : '' }}</span>
                                </span>
                            </div>
                        </div>
                    @empty
                        <div data-empty class="wi-empty">
                            <span x-text="$store.ui.lang==='en' ? 'Nothing here yet.' : 'Belum ada apa-apa.'"></span>
                        </div>
                    @endforelse
                </div>

                @if ($employee)
                    <div style="margin-top:10px;">
                        <button type="button" x-show="!open['{{ $key }}']" @click="toggleComposer('{{ $key }}')"
                                style="width:100%;text-align:left;padding:9px 12px;border:1px dashed var(--hairline);border-radius:10px;background:transparent;font-size:12.5px;font-weight:500;color:var(--muted);cursor:pointer;">
                            <span x-text="$store.ui.lang==='en' ? '+ Add a card' : '+ Tambah kad'"></span>
                        </button>
                        <div x-show="open['{{ $key }}']" x-cloak class="uj-card" style="padding:10px;">
                            <textarea x-ref="draft_{{ $key }}" x-model="draft['{{ $key }}']"
                                      @keydown.enter.prevent="submitAdd('{{ $key }}')"
                                      @keydown.escape="toggleComposer('{{ $key }}')"
                                      rows="2" maxlength="160"
                                      :placeholder="$store.ui.lang==='en' ? 'What needs doing?' : 'Apa yang perlu dibuat?'"
                                      style="width:100%;border:1px solid var(--hairline);border-radius:8px;padding:8px 10px;font-size:13px;color:var(--ink);outline:none;resize:vertical;font-family:inherit;"></textarea>
                            <div style="display:flex;gap:8px;margin-top:8px;">
                                <button type="button" @click="submitAdd('{{ $key }}')" :disabled="busy" class="uj-btn-primary" style="height:34px;padding:0 14px;font-size:12.5px;">
                                    <span x-text="$store.ui.lang==='en' ? 'Add card' : 'Tambah'"></span>
                                </button>
                                <button type="button" @click="toggleComposer('{{ $key }}')" style="height:34px;padding:0 10px;font-size:12.5px;color:var(--muted);background:transparent;cursor:pointer;">×</button>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    {{-- ───────── Card detail modal ───────── --}}
    @if ($employee)
    <template x-teleport="body">
    <div x-show="modal.show" x-cloak @click.self="closeModal()"
         style="position:fixed;inset:0;z-index:120;display:flex;padding:40px 16px;background:rgba(18,18,30,.42);overflow-y:auto;"
         @keydown.escape.window="closeModal()">
        <div class="uj-card"
             style="width:100%;max-width:620px;margin:auto;padding:0;overflow:hidden;">

            {{-- header --}}
            <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--hairline);">
                <span style="font-size:12px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;" x-text="$store.ui.lang==='en' ? 'Card detail' : 'Butiran kad'"></span>
                <button type="button" @click="closeModal()" style="font-size:20px;line-height:1;color:var(--muted);background:transparent;cursor:pointer;">×</button>
            </div>

            <div style="padding:20px;max-height:70vh;overflow-y:auto;">
                <template x-if="modal.loading">
                    <div style="text-align:center;padding:30px;color:var(--muted);font-size:13px;" x-text="$store.ui.lang==='en' ? 'Loading…' : 'Memuatkan…'"></div>
                </template>

                <div x-show="!modal.loading">
                    <div x-show="modal.error" x-cloak style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:9px 12px;margin-bottom:14px;" x-text="modal.error"></div>

                    {{-- title --}}
                    <label style="{{ $labelStyle }}" x-text="$store.ui.lang==='en' ? 'Title' : 'Tajuk'"></label>
                    <input x-model="modal.card.title" maxlength="160" :disabled="modal.locked" style="{{ $fieldStyle }}margin-bottom:14px;font-weight:500;" />

                    {{-- type / priority / due / estimate --}}
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">
                        <div>
                            <label style="{{ $labelStyle }}" x-text="$store.ui.lang==='en' ? 'Type' : 'Jenis'"></label>
                            <select x-model="modal.card.type" :disabled="modal.locked" style="{{ $fieldStyle }}">
                                @foreach (['assignment' => 'Assignment', 'task' => 'Task', 'adhoc' => 'Adhoc'] as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                            </select>
                        </div>
                        <div>
                            <label style="{{ $labelStyle }}" x-text="$store.ui.lang==='en' ? 'Priority' : 'Keutamaan'"></label>
                            <select x-model="modal.card.priority" :disabled="modal.locked" style="{{ $fieldStyle }}">
                                @foreach ($priLabel as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                            </select>
                        </div>
                        <div>
                            <label style="{{ $labelStyle }}" x-text="$store.ui.lang==='en' ? 'Due label' : 'Label tarikh'"></label>
                            <input x-model="modal.card.due_label" maxlength="60" :disabled="modal.locked" :placeholder="$store.ui.lang==='en' ? 'e.g. Fri 26 Jun' : 'cth. Jum 26 Jun'" style="{{ $fieldStyle }}" />
                        </div>
                        <div>
                            <label style="{{ $labelStyle }}" x-text="$store.ui.lang==='en' ? 'Estimate (h)' : 'Anggaran (j)'"></label>
                            <input x-model="modal.card.estimate_hours" type="number" min="0" max="500" :disabled="modal.locked" style="{{ $fieldStyle }}font-family:var(--font-mono);" />
                        </div>
                    </div>

                    {{-- status --}}
                    <label style="{{ $labelStyle }}" x-text="$store.ui.lang==='en' ? 'Column' : 'Lajur'"></label>
                    <select x-model="modal.card.status" @change="changeStatus($event.target.value)" style="{{ $fieldStyle }}margin-bottom:14px;">
                        @foreach ($statusLabels as $sv => $sl)<option value="{{ $sv }}">{{ $sl }}</option>@endforeach
                    </select>

                    {{-- description --}}
                    <label style="{{ $labelStyle }}" x-text="$store.ui.lang==='en' ? 'Description' : 'Penerangan'"></label>
                    <textarea x-model="modal.card.description" rows="4" maxlength="5000" :disabled="modal.locked"
                              :placeholder="$store.ui.lang==='en' ? 'Add more detail…' : 'Tambah butiran…'"
                              style="width:100%;border:1px solid var(--hairline);border-radius:8px;padding:9px 11px;font-size:13px;color:var(--ink);outline:none;resize:vertical;font-family:inherit;line-height:1.5;margin-bottom:16px;"></textarea>

                    {{-- actions --}}
                    <div x-show="!modal.locked" style="display:flex;gap:10px;align-items:center;margin-bottom:22px;">
                        <button type="button" @click="saveCard()" :disabled="modal.saving" class="uj-btn-primary" style="height:38px;padding:0 18px;font-size:13px;">
                            <span x-text="modal.saving ? ($store.ui.lang==='en'?'Saving…':'Menyimpan…') : ($store.ui.lang==='en'?'Save changes':'Simpan perubahan')"></span>
                        </button>
                        <button type="button" @click="deleteCard()" style="height:38px;padding:0 14px;font-size:13px;color:var(--red);background:transparent;cursor:pointer;margin-left:auto;">
                            <span x-text="$store.ui.lang==='en' ? 'Delete' : 'Padam'"></span>
                        </button>
                    </div>

                    {{-- comments --}}
                    <div style="border-top:1px solid var(--hairline);padding-top:16px;">
                        <div style="font-size:12px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;margin-bottom:12px;">
                            <span x-text="$store.ui.lang==='en' ? 'Comments' : 'Komen'"></span>
                            <span x-text="'(' + modal.comments.length + ')'"></span>
                        </div>

                        <template x-if="modal.comments.length === 0">
                            <p style="font-size:12.5px;color:var(--muted-soft);margin:0 0 14px;" x-text="$store.ui.lang==='en' ? 'No comments yet.' : 'Tiada komen lagi.'"></p>
                        </template>

                        <div style="display:flex;flex-direction:column;gap:12px;margin-bottom:14px;">
                            <template x-for="c in modal.comments" :key="c.id">
                                <div style="display:flex;gap:10px;">
                                    <span :style="'flex-shrink:0;width:28px;height:28px;border-radius:9999px;display:flex;align-items:center;justify-content:center;font-size:10.5px;font-weight:600;color:#fff;background:'+c.color" x-text="c.initials"></span>
                                    <div style="flex:1;min-width:0;">
                                        <div style="display:flex;align-items:baseline;gap:8px;">
                                            <span style="font-size:12.5px;font-weight:600;color:var(--ink);" x-text="c.author"></span>
                                            <span style="font-size:11px;color:var(--muted-soft);" x-text="c.when"></span>
                                            <button type="button" x-show="c.mine" @click="deleteComment(c.id)" style="margin-left:auto;font-size:11px;color:var(--muted);background:transparent;cursor:pointer;" x-text="$store.ui.lang==='en' ? 'Delete' : 'Padam'"></button>
                                        </div>
                                        <div style="font-size:13px;color:var(--body);line-height:1.5;white-space:pre-wrap;margin-top:2px;" x-text="c.body"></div>
                                    </div>
                                </div>
                            </template>
                        </div>

                        <div style="display:flex;gap:8px;align-items:flex-end;">
                            <textarea x-model="modal.newComment" @keydown.enter.meta.prevent="addComment()" rows="2" maxlength="2000"
                                      :placeholder="$store.ui.lang==='en' ? 'Write a comment…' : 'Tulis komen…'"
                                      style="flex:1;border:1px solid var(--hairline);border-radius:8px;padding:8px 10px;font-size:13px;color:var(--ink);outline:none;resize:vertical;font-family:inherit;"></textarea>
                            <button type="button" @click="addComment()" class="uj-btn-primary" style="height:38px;padding:0 14px;font-size:12.5px;">
                                <span x-text="$store.ui.lang==='en' ? 'Post' : 'Hantar'"></span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </template>
    @endif
</div>
@endsection
