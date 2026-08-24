{{-- Ticket detail as a side drawer — same .wd-* grammar as the Knowledge Bank / T.O.T. panels
     (do not add new .wd- rules). Teleported so it is never clipped by the row and only one
     paints at a time. $privileged adds the manage form (status / assignee / resolution). --}}
<template x-teleport="body">
    <div x-show="drawerOpen" x-cloak>
        <div class="wd-scrim" :data-open="drawerOpen ? '' : null" @click="drawerOpen = false"></div>
        <aside class="wd" :data-open="drawerOpen ? '' : null" role="dialog" aria-modal="true"
               @keydown.escape.window="drawerOpen = false">

            <div class="wd-head">
                <span class="uj-stamp" @if ($statusTone[$t->status] ?? null) data-tone="{{ $statusTone[$t->status] }}" @endif
                      x-text="$store.ui.lang==='en' ? @js($statusMeta[$t->status]['label'] ?? ucfirst($t->status)) : @js($statusMs[$t->status] ?? ucfirst($t->status))">{{ $statusMeta[$t->status]['label'] ?? ucfirst($t->status) }}</span>
                <button type="button" class="wd-ico" style="margin-left:auto;" @click="drawerOpen = false"
                        :aria-label="$store.ui.lang==='en' ? 'Close' : 'Tutup'">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="wd-body">
                <h2 class="wd-title">{{ $t->subject }}</h2>
                <p class="wd-sub">
                    @if ($privileged)
                        @if ($t->employee?->name){{ $t->employee->display_name }}@else<span x-text="$store.ui.lang==='en' ? 'Unknown' : 'Tidak diketahui'">Unknown</span>@endif ·
                    @endif
                    {{ $t->category }} · <span style="color:{{ $priorityColor[$t->priority] ?? 'var(--muted)' }};font-weight:600;">{{ ucfirst($t->priority) }}</span> ·
                    {{ $t->created_at?->format('j M Y') }}
                    @if ($t->assignee) · → {{ $t->assignee->name }}@endif
                </p>

                @include('partials.ticket-description')
                @php $safeUrl = $t->page_url && preg_match('~^https?://~i', $t->page_url) ? $t->page_url : null; @endphp
                @if ($safeUrl)
                    <div style="font-size:12px;color:var(--muted);margin-top:8px;">
                        <span x-text="$store.ui.lang==='en' ? 'Reported from' : 'Dilapor dari'">Reported from</span>
                        <a href="{{ $safeUrl }}" style="color:var(--red);text-decoration:none;">{{ $safeUrl }}</a>
                    </div>
                @endif
                {{-- Thumbnails and download chips both point at the auth-gated stream route,
                     never a public URL — the file lives on the private 'local' disk. --}}
                @if ($t->attachments->isNotEmpty())
                    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:10px;">
                        @foreach ($t->attachments as $att)
                            @if ($att->isImage())
                                <a href="{{ route('helpdesk.attachment', $att) }}" target="_blank" rel="noopener noreferrer"
                                   style="display:block;width:64px;height:64px;border-radius:8px;overflow:hidden;border:1px solid var(--hairline-soft);">
                                    <img src="{{ route('helpdesk.attachment', $att) }}" alt="{{ $att->name }}" loading="lazy"
                                         style="width:100%;height:100%;object-fit:cover;">
                                </a>
                            @else
                                <a href="{{ route('helpdesk.attachment', $att) }}" target="_blank" rel="noopener noreferrer"
                                   style="display:inline-flex;align-items:center;gap:7px;height:34px;padding:0 12px;border-radius:8px;border:1px solid var(--hairline-soft);font-size:12.5px;color:var(--body);text-decoration:none;">
                                    <span style="font-weight:700;font-size:10.5px;color:var(--muted);">{{ strtoupper(pathinfo($att->name, PATHINFO_EXTENSION)) }}</span>
                                    <span>{{ $att->name }}</span>
                                </a>
                            @endif
                        @endforeach
                    </div>
                @endif
                @if ($t->resolution)
                    <div style="background:var(--canvas);border:1px solid var(--hairline-soft);border-radius:8px;padding:10px 12px;margin-top:12px;">
                        <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Resolution' : 'Penyelesaian'">Resolution</div>
                        <div style="font-size:13px;color:var(--body);white-space:pre-line;">{{ $t->resolution }}</div>
                    </div>
                @endif

                @if ($privileged)
                    <hr class="wd-rule">
                    <form method="post" action="{{ route('helpdesk.update', $t) }}">
                        @csrf
                        <input type="hidden" name="_ticket" value="{{ $t->id }}" />
                        @if ($errors->any() && (int) old('_ticket') === $t->id)
                            <div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:9px 12px;margin-bottom:12px;">{{ $errors->first() }}</div>
                        @endif
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                            <div>
                                <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Status *' : 'Status *'">Status *</label>
                                <select name="status" required class="uj-lv-in">
                                    @foreach ($statuses as $opt)
                                        <option value="{{ $opt }}" @selected(old('status', $t->status) === $opt) x-text="$store.ui.lang==='en' ? @js($statusMeta[$opt]['label']) : @js($statusMs[$opt] ?? $statusMeta[$opt]['label'])">{{ $statusMeta[$opt]['label'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Assignee' : 'Ditugaskan kepada'">Assignee</label>
                                <select name="assignee_employee_id" class="uj-lv-in">
                                    <option value="" x-text="$store.ui.lang==='en' ? 'Unassigned' : 'Belum ditugaskan'">Unassigned</option>
                                    @foreach ($employees as $e)
                                        <option value="{{ $e->id }}" @selected((string) old('assignee_employee_id', (string) $t->assignee_employee_id) === (string) $e->id)>{{ $e->display_name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div style="margin-top:12px;">
                            <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Resolution note' : 'Nota penyelesaian'">Resolution note</label>
                            <textarea name="resolution" maxlength="2000" rows="3" placeholder="What was done to resolve this." :placeholder="$store.ui.lang==='en' ? 'What was done to resolve this.' : 'Apa yang dibuat untuk menyelesaikannya.'" class="uj-lv-in">{{ old('resolution', $t->resolution) }}</textarea>
                        </div>
                        <button type="submit" class="uj-btn-primary" style="height:38px;padding:0 18px;font-size:13px;margin-top:14px;" x-text="$store.ui.lang==='en' ? 'Save changes' : 'Simpan perubahan'">Save changes</button>
                    </form>
                @endif
            </div>
        </aside>
    </div>
</template>
