{{-- Chronology timeline for one claim. Mirrors partials.leave-timeline, adapted to the
     claim columns: there is a paid step, and only verified_at / verifiedBy exist as
     recorded actors (no approved_by / rejected_by columns), so the approve and decline
     steps carry a state and date but no name. The verifier name is shown only when the
     relation is already loaded (the approval queue eager-loads it; "My claims" does not,
     so this never fires a per-row query there).

     Params: $c (Claim). --}}
@php
    $verifierName = $c->relationLoaded('verifiedBy') ? $c->verifiedBy?->name : null;

    $steps = [['state' => 'done', 'en' => 'Submitted', 'ms' => 'Dihantar', 'who' => null, 'at' => $c->created_at]];
    if (in_array($c->status, ['rejected', 'cancelled'], true)) {
        if ($c->verified_at) {
            $steps[] = ['state' => 'done', 'en' => 'Verified by superior', 'ms' => 'Disahkan oleh penyelia', 'who' => $verifierName, 'at' => $c->verified_at];
        }
        $cancelled = $c->status === 'cancelled';
        $steps[] = ['state' => 'rejected', 'en' => $cancelled ? 'Cancelled' : 'Declined', 'ms' => $cancelled ? 'Dibatalkan' : 'Ditolak', 'who' => null, 'at' => $c->updated_at];
    } else {
        $steps[] = $c->verified_at
            ? ['state' => 'done', 'en' => 'Verified by superior', 'ms' => 'Disahkan oleh penyelia', 'who' => $verifierName, 'at' => $c->verified_at]
            : ['state' => 'pending', 'en' => 'Verified by superior', 'ms' => 'Disahkan oleh penyelia', 'who' => null, 'at' => null];
        $steps[] = in_array($c->status, ['approved', 'paid'], true)
            ? ['state' => 'done', 'en' => 'Approved by management', 'ms' => 'Diluluskan oleh pengurusan', 'who' => null, 'at' => null]
            : ['state' => 'pending', 'en' => 'Approved by management', 'ms' => 'Diluluskan oleh pengurusan', 'who' => null, 'at' => null];
        $steps[] = $c->status === 'paid'
            ? ['state' => 'done', 'en' => 'Paid', 'ms' => 'Dibayar', 'who' => null, 'at' => $c->paid_at]
            : ['state' => 'pending', 'en' => 'Pays next payroll run', 'ms' => 'Dibayar dalam gaji berikutnya', 'who' => null, 'at' => null];
    }
    $nextEn = ['submitted' => 'Waiting for your manager to verify.', 'verified' => 'Waiting for management’s final approval.', 'approved' => 'Approved — pays in the next payroll run.', 'paid' => 'Paid.', 'rejected' => 'Declined.', 'cancelled' => 'You cancelled this.'][$c->status] ?? '';
    $nextMs = ['submitted' => 'Menunggu pengurus anda mengesahkan.', 'verified' => 'Menunggu kelulusan akhir pengurusan.', 'approved' => 'Diluluskan — dibayar dalam gaji berikutnya.', 'paid' => 'Dibayar.', 'rejected' => 'Ditolak.', 'cancelled' => 'Anda batalkan ini.'][$c->status] ?? '';
    $dotCol = ['done' => 'var(--success)', 'pending' => 'var(--muted-soft)', 'rejected' => 'var(--error)'];
@endphp
<div style="padding:2px 0;">
    @foreach ($steps as $st)
        <div style="display:flex;gap:10px;">
            <div style="display:flex;flex-direction:column;align-items:center;">
                <span style="width:11px;height:11px;border-radius:50%;background:{{ $st['state'] === 'pending' ? '#fff' : $dotCol[$st['state']] }};border:2px solid {{ $dotCol[$st['state']] }};flex-shrink:0;margin-top:2px;"></span>
                @if (! $loop->last)<span style="width:2px;flex:1;min-height:20px;background:var(--hairline);"></span>@endif
            </div>
            <div style="padding-bottom:9px;">
                <div style="font-size:12.5px;font-weight:600;color:{{ $st['state'] === 'rejected' ? 'var(--error)' : ($st['state'] === 'pending' ? 'var(--muted)' : 'var(--ink)') }};">
                    <span x-text="$store.ui.lang==='en' ? '{{ $st['en'] }}' : '{{ $st['ms'] }}'">{{ $st['en'] }}</span>
                    @if ($st['state'] === 'pending')<span style="font-weight:400;color:var(--muted);"> · <span x-text="$store.ui.lang==='en' ? 'pending' : 'menunggu'">pending</span></span>@endif
                </div>
                <div style="font-size:11.5px;color:var(--muted);display:flex;flex-wrap:wrap;gap:6px;align-items:baseline;">
                    @if ($st['at'])<span>{{ $st['at']->format('j M Y, g:ia') }}</span>@endif
                    @if (! empty($st['who']))
                        <span style="color:var(--ink);font-weight:500;">{{ $st['who'] }}</span>
                    @endif
                    @if ($st['state'] === 'pending' && ! $st['at'] && empty($st['who']))<span x-text="$store.ui.lang==='en' ? 'not yet' : 'belum'">not yet</span>@endif
                </div>
            </div>
        </div>
    @endforeach
    <div style="font-size:11.5px;color:{{ in_array($c->status, ['rejected', 'cancelled'], true) ? 'var(--error)' : 'var(--info)' }};padding-left:21px;">
        <span x-text="$store.ui.lang==='en' ? '{{ $nextEn }}' : '{{ $nextMs }}'">{{ $nextEn }}</span>
    </div>
</div>
