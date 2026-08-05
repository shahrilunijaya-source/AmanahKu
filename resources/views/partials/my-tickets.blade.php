{{-- The raiser's own tickets, with status and resolution note. Rendered by BOTH branches of
     the Helpdesk screen: a plain employee has nothing else, and a privileged viewer needs it
     because the board filters Bug/Idea away from anyone outside FEEDBACK_VIEW_ROLES — without
     this a manager could not see the bug report they filed themselves. --}}
<div class="uj-card">
    <div class="uj-card-head"><h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'My tickets' : 'Ticket saya'">My tickets</h3></div>
    @forelse ($myTickets as $t)
        <div style="padding:14px 20px;border-bottom:1px solid var(--hairline-soft);">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;">
                <div style="min-width:0;">
                    <div style="font-size:14px;font-weight:600;color:var(--ink);">{{ $t->subject }}</div>
                    <div style="font-size:12px;color:var(--muted);margin-top:3px;">{{ $t->category }} · <span style="color:{{ $priorityColor[$t->priority] ?? 'var(--muted)' }};font-weight:600;">{{ ucfirst($t->priority) }}</span> · {{ $t->created_at?->format('j M Y') }}</div>
                </div>
                {!! $pill($t->status) !!}
            </div>
            <div style="font-size:13px;color:var(--body);margin-top:8px;white-space:pre-line;">{{ $t->description }}</div>
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
            @if ($t->assignee)
                <div style="font-size:12px;color:var(--muted);margin-top:8px;"><span x-text="$store.ui.lang==='en' ? 'Assigned to' : 'Ditugaskan kepada'">Assigned to</span> {{ $t->assignee->name }}</div>
            @endif
            @if ($t->resolution)
                <div style="background:var(--canvas);border:1px solid var(--hairline-soft);border-radius:8px;padding:10px 12px;margin-top:10px;">
                    <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Resolution' : 'Penyelesaian'">Resolution</div>
                    <div style="font-size:13px;color:var(--body);white-space:pre-line;">{{ $t->resolution }}</div>
                </div>
            @endif
        </div>
    @empty
        <div style="padding:28px 20px;text-align:center;">
            <div style="font-size:13px;color:var(--ink);font-weight:500;margin-bottom:3px;"><span x-text="$store.ui.lang==='en' ? 'No tickets yet' : 'Belum ada ticket'"></span></div>
            <div style="font-size:12px;color:var(--muted);line-height:1.5;"><span x-text="$store.ui.lang==='en' ? 'Use \'+ New ticket\' above to raise your first one. It will appear here so you can track its progress.' : 'Guna \'+ New ticket\' di atas untuk buka yang pertama. Ia akan muncul di sini supaya anda boleh jejak kemajuannya.'"></span></div>
        </div>
    @endforelse
</div>
