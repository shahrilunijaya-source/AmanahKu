{{-- Chronology timeline for one leave request. Expects $r with verifiedBy / approvedBy /
     rejectedBy loaded. Shared by the applicant's "My requests" and the superior/management
     review queues so everyone reads the same trail. --}}
@php
    $steps = [['state' => 'done', 'en' => 'Submitted', 'ms' => 'Dihantar', 'who' => null, 'at' => $r->created_at]];
    if ($r->status === 'rejected') {
        if ($r->verified_at) { $steps[] = ['state' => 'done', 'en' => 'Verified by superior', 'ms' => 'Disahkan oleh penyelia', 'who' => $r->verifiedBy?->name, 'at' => $r->verified_at]; }
        $steps[] = ['state' => 'rejected', 'en' => 'Declined', 'ms' => 'Ditolak', 'who' => $r->rejectedBy?->name, 'at' => $r->rejected_at];
    } else {
        $steps[] = ['state' => $r->verified_at ? 'done' : 'pending', 'en' => 'Verified by superior', 'ms' => 'Disahkan oleh penyelia', 'who' => $r->verifiedBy?->name, 'at' => $r->verified_at];
        $steps[] = ['state' => $r->status === 'approved' ? 'done' : 'pending', 'en' => 'Approved by management', 'ms' => 'Diluluskan oleh pengurusan', 'who' => $r->approvedBy?->name, 'at' => $r->approved_at];
    }
    $nextEn = ['submitted' => 'Waiting for the immediate superior to verify.', 'verified' => 'Waiting for management’s final approval.', 'approved' => 'Approved — days deducted from balance.', 'rejected' => 'Declined.'][$r->status] ?? '';
    $nextMs = ['submitted' => 'Menunggu penyelia terdekat mengesahkan.', 'verified' => 'Menunggu kelulusan akhir pengurusan.', 'approved' => 'Diluluskan — hari ditolak daripada baki.', 'rejected' => 'Ditolak.'][$r->status] ?? '';
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
                    @if ($st['state'] === 'pending')<span style="font-weight:400;color:var(--muted-soft);"> · <span x-text="$store.ui.lang==='en' ? 'pending' : 'menunggu'">pending</span></span>@endif
                </div>
                <div style="font-size:11.5px;color:var(--muted);">
                    @if ($st['at']){{ $st['at']->format('j M Y, g:ia') }}@endif
                    @if ($st['who']) · {{ $st['who'] }}@endif
                    @if (! $st['at'] && ! $st['who'])<span x-text="$store.ui.lang==='en' ? 'not yet' : 'belum'">not yet</span>@endif
                </div>
            </div>
        </div>
    @endforeach
    <div style="font-size:11.5px;color:{{ $r->status === 'rejected' ? 'var(--error)' : 'var(--info)' }};padding-left:21px;">
        <span x-text="$store.ui.lang==='en' ? '{{ $nextEn }}' : '{{ $nextMs }}'">{{ $nextEn }}</span>
    </div>
</div>
