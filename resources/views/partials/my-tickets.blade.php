{{-- The raiser's own tickets, with status and resolution note. Rendered by BOTH branches of
     the Helpdesk screen: a plain employee has nothing else, and a privileged viewer needs it
     because the board filters Bug/Idea away from anyone outside FEEDBACK_VIEW_ROLES — without
     this a manager could not see the bug report they filed themselves.

     Shares $statusMeta/$statusTone/$statusMs/$priorityColor/$categoryMeta from the parent
     screen's PHP block (helpdesk.blade.php) — a Blade include exposes the caller's scope. --}}
<div class="uj-card">
    <div class="uj-card-head"><h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'My tickets' : 'Ticket saya'">My tickets</h3></div>
    @forelse ($myTickets as $t)
        @include('partials.ticket-row', ['t' => $t, 'privileged' => false])
    @empty
        <div class="uj-lv-empty">
            <b x-text="$store.ui.lang==='en' ? 'No tickets yet' : 'Belum ada ticket'"></b>
            <span x-text="$store.ui.lang==='en' ? 'Use \'+ New ticket\' above to raise your first one. It will appear here so you can track its progress.' : 'Guna \'+ New ticket\' di atas untuk buka yang pertama. Ia akan muncul di sini supaya anda boleh jejak kemajuannya.'"></span>
        </div>
    @endforelse
</div>
