{{-- Teleported so the drawer is never clipped by a row, and so only one is ever
     painted at a time. Built from the .wd-* classes the T.A.A. board shipped;
     do not add new .wd- rules. --}}
<template x-teleport="body">
    <div x-show="drawerOpen" x-cloak>
        <div class="wd-scrim" :data-open="drawerOpen ? '' : null" @click="drawerOpen = false"></div>
        <aside class="wd" :data-open="drawerOpen ? '' : null" role="dialog" aria-modal="true"
               @keydown.escape.window="flyout ? (flyout = null) : (drawerOpen = false)"
               :aria-label="$store.ui.lang==='en' ? @js($session->session_date->format('F Y')) : @js($session->session_date->format('F Y'))">

            <div class="wd-head">
                <span style="font:600 13.5px var(--font-sans);color:var(--ink);">{{ $session->session_date->format('F Y') }}</span>
                <button type="button" class="wd-ico" style="margin-left:auto;" @click="drawerOpen = false"
                        :aria-label="$store.ui.lang==='en' ? 'Close' : 'Tutup'">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="wd-body">
                @if ($session->exists)
                    @php
                        $presenterName = $session->presenter?->name ?? $session->presenter_name;
                        $isEvent = in_array($session->status, ['not_tot', 'skipped'], true);
                    @endphp

                    @if (! $isEvent)
                        <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;">
                            @if ($presenterName)
                                <span class="tot-av-c" style="background:{{ '#' . substr(md5($presenterName), 0, 6) }};">{{ mb_strtoupper(mb_substr($presenterName, 0, 1)) }}</span>
                            @endif
                            <div>
                                <div style="font-size:15px;font-weight:600;color:var(--ink);">{{ $presenterName ?: 'Nobody assigned' }}</div>
                                <div class="wd-sub" style="margin:1px 0 0;">{{ $session->session_date->format('l j F Y') }}</div>
                            </div>
                            @if ($isPresenterOfSlot)
                                <span class="tot-presenter-tag" style="margin-left:auto;"
                                      x-text="$store.ui.lang==='en' ? 'You present' : 'Anda membentang'">You present</span>
                            @endif
                        </div>
                    @else
                        <p class="wd-sub">{{ $session->session_date->format('l j F Y') }}</p>
                    @endif

                    <h2 class="wd-title @if (! filled($session->title)) wd-inline--empty @endif">{{ $session->title ?: 'No topic yet' }}</h2>

                    @if (filled($session->description))
                        <p style="font-size:13.5px;color:var(--body);line-height:1.65;margin:0 0 18px;">{{ $session->description }}</p>
                    @endif

                    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;">
                        @forelse ($session->links ?? [] as $link)
                            <a class="tot-lk" href="{{ $link['url'] }}" target="_blank" rel="noopener noreferrer">{{ $link['label'] }}</a>
                        @empty
                            <span class="tot-note" x-text="$store.ui.lang==='en' ? 'No material uploaded yet.' : 'Belum ada bahan dimuat naik.'">No material uploaded yet.</span>
                        @endforelse
                    </div>

                    @include('partials.tot-actions', ['session' => $session, 'canParticipate' => $canParticipate])

                    @if ($canEditSlot)
                        <hr class="wd-rule">
                        @include('partials.tot-edit-form', [
                            'session' => $session, 'canManage' => $canManage,
                            'canAssignPresenter' => $canAssignPresenter,
                            'isPresenterOfSlot' => $isPresenterOfSlot,
                            'assignableEmployees' => $assignableEmployees,
                            'statusLabels' => $statusLabels, 'slotFailed' => $slotFailed,
                        ])
                    @endif

                    <hr class="wd-rule">

                    {{-- Anonymous rater notes. Present only for a viewer the server decided may
                         see scores, which is the presenter and management. Never a name. --}}
                    <template x-if="notes.length">
                        <div class="wd-locked" style="display:block;">
                            <div class="wd-sech" style="margin-bottom:6px;"
                                 x-text="$store.ui.lang==='en' ? 'Anonymous notes from raters' : 'Nota tanpa nama daripada penilai'">Anonymous notes from raters</div>
                            <template x-for="(n, i) in notes" :key="i">
                                <div style="font-size:13.5px;color:var(--body);margin-bottom:4px;" x-text="n"></div>
                            </template>
                        </div>
                    </template>

                    <h3 class="wd-sech" x-text="comments ? ($store.ui.lang==='en' ? `Discussion · ${comments}` : `Perbincangan · ${comments}`) : ($store.ui.lang==='en' ? 'Discussion' : 'Perbincangan')">Discussion</h3>

                    <template x-if="thread === null">
                        <div class="tot-note" x-text="$store.ui.lang==='en' ? 'Loading' : 'Memuatkan'">Loading</div>
                    </template>
                    <template x-if="thread !== null && thread.length === 0">
                        <div class="tot-note" x-text="$store.ui.lang==='en' ? 'No comments yet. Start the discussion.' : 'Belum ada komen. Mulakan perbincangan.'">No comments yet.</div>
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
                                        <button type="button" x-show="c.canDelete" class="wd-ico" style="margin-left:auto;width:22px;height:22px;"
                                                @click="removeComment(c.id)"
                                                :aria-label="$store.ui.lang==='en' ? 'Remove comment' : 'Buang komen'">&times;</button>
                                    </div>
                                    <div class="wd-cmt-body" x-text="c.body"></div>
                                </div>
                            </div>
                        </template>
                    </div>
                @else
                    {{-- Unsaved month --}}
                    @if ($canManage || $canAssignPresenter)
                        <form method="post" action="{{ route('tot.store') }}">
                            @csrf
                            <input type="hidden" name="year" value="{{ $session->year }}">
                            <input type="hidden" name="month" value="{{ $session->month }}">
                            <div style="display:grid;grid-template-columns:{{ $canManage ? '1fr 1fr' : '1fr' }};gap:12px;max-width:620px;">
                                @if ($canManage)
                                    <div><label class="tot-lbl" x-text="$store.ui.lang==='en' ? 'Presenter name' : 'Nama pembentang'">Presenter name</label><input class="tot-field" name="presenter_name"></div>
                                @endif
                                <div><label class="tot-lbl" x-text="$store.ui.lang==='en' ? 'Presenter (optional)' : 'Pembentang (pilihan)'">Presenter (optional)</label>
                                    <select class="tot-field" name="presenter_employee_id">
                                        <option value="" x-text="$store.ui.lang==='en' ? 'Nobody yet' : 'Belum ada'">Nobody yet</option>
                                        @foreach ($assignableEmployees as $person)
                                            <option value="{{ $person->id }}">{{ $person->display_name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            @if ($canManage)
                                <div style="margin-top:12px;max-width:620px;"><label class="tot-lbl" x-text="$store.ui.lang==='en' ? 'Topic' : 'Topik'">Topic</label><input class="tot-field" name="title"></div>
                                <div style="margin-top:12px;max-width:620px;"><label class="tot-lbl" x-text="$store.ui.lang==='en' ? 'Status' : 'Status'">Status</label>
                                    <select class="tot-field" name="status">
                                        @foreach (\App\Models\TotSession::STATUSES as $st)
                                            <option value="{{ $st }}" @selected($st === 'planned') x-text="$store.ui.lang==='en' ? @js($statusLabels[$st]['en']) : @js($statusLabels[$st]['ms'])">{{ $statusLabels[$st]['en'] }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif
                            <div class="tot-rule" style="max-width:620px;">
                                <button type="submit" class="tot-btn-p">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;margin-right:4px;"><path d="M12 5v14M5 12h14"/></svg>
                                    <span x-text="$store.ui.lang==='en' ? 'Assign PIC' : 'Tetapkan PIC'">Assign PIC</span>
                                </button>
                            </div>
                        </form>
                    @else
                        <div class="tot-note" x-text="$store.ui.lang==='en' ? 'Nobody has been assigned to this session yet.' : 'Belum ada sesiapa ditugaskan untuk sesi ini.'">Nobody has been assigned to this session yet.</div>
                    @endif
                @endif
            </div>

            @if ($session->exists && $canParticipate && $session->status !== 'skipped')
                <div class="wd-foot">
                    <textarea rows="1" x-ref="composer" maxlength="2000"
                              :placeholder="$store.ui.lang==='en' ? 'Ask a question or add what you learned' : 'Tanya soalan atau kongsi apa yang anda pelajari'"
                              @keydown.enter.prevent="postComment($event.target.value); $event.target.value = ''"></textarea>
                    <button type="button" class="uj-btn-primary wd-post"
                            @click="postComment($refs.composer.value); $refs.composer.value = ''"
                            x-text="$store.ui.lang==='en' ? 'Post' : 'Hantar'">Post</button>
                </div>
            @endif
        </aside>
    </div>
</template>
