{{--
    Recommended-actions list for the AI Workforce Intelligence (workload) screen and the
    manager dashboard. Each card's Apply button POSTs its recommendation type to
    WorkforceController::apply, which sends the matching in-app nudge. Expects: $recs.
--}}
@forelse ($recs as $r)
    <form method="POST" action="{{ route('workforce.apply') }}" style="padding:12px 0;border-bottom:1px solid #322f29;margin:0;">
        @csrf
        <input type="hidden" name="type" value="{{ $r['type'] }}">
        <div style="font-size:13px;color:#fff;line-height:1.45;margin-bottom:6px;">{{ $r['t'] }}</div>
        <div style="display:flex;align-items:center;justify-content:space-between;">
            <span style="font-size:11px;color:#807d72;">{{ $r['impact'] }}</span>
            <button type="submit" style="font-size:11px;font-weight:600;color:var(--red);background:#fff;padding:4px 10px;border-radius:6px;cursor:pointer;" x-text="$store.ui.lang==='en' ? 'Apply' : 'Guna'">Apply</button>
        </div>
    </form>
@empty
    <div style="padding:14px 0;font-size:12.5px;color:#a8a599;line-height:1.5;" x-text="$store.ui.lang==='en' ? 'All clear — nothing needs action right now.' : 'Semua baik — tiada tindakan diperlukan buat masa ini.'">All clear — nothing needs action right now.</div>
@endforelse
