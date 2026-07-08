@extends('layouts.app')

@php use App\Support\Amanahku; $overall = $items->count() ? round($items->avg('progress')) : 0; @endphp

@section('screen')
@include('partials.guide', [
    'key' => 'kpi',
    'en'  => [
        'title' => 'KPIs & performance',
        'body'  => 'KPIs are the agreed measures of how you are performing in your role this cycle — each has a target, your actual result, a progress %, and a weight showing how much it counts. Unlike OKRs (which are about stretch goals), KPIs track expected, ongoing performance. The overall score feeds into your review.',
        'who'   => 'You update your actuals · Manager reviews at cycle end',
        'steps' => [
            'Each objective has a target — the number you are expected to hit.',
            'Click "Update" on a row to record your latest actual and progress %.',
            'Higher-weight objectives matter more to your overall score.',
            'At mid-year and year-end, draft your self-assessment for your manager.',
        ],
    ],
    'ms'  => [
        'title' => 'KPI & prestasi',
        'body'  => 'KPI ialah ukuran yang dipersetujui tentang prestasi anda dalam peranan anda untuk kitaran ini — setiap satu ada sasaran, hasil sebenar anda, progress %, dan pemberat yang menunjukkan berapa banyak ia dikira. Berbeza dengan OKR (yang tentang matlamat beraspirasi), KPI menjejak prestasi yang dijangka dan berterusan. Skor keseluruhan disalurkan ke dalam review anda.',
        'who'   => 'Anda kemas kini actual anda · Pengurus review di hujung kitaran',
        'steps' => [
            'Setiap objektif ada sasaran — angka yang anda dijangka capai.',
            'Klik "Update" pada satu baris untuk merekod actual dan progress % terkini anda.',
            'Objektif berpemberat lebih tinggi lebih penting kepada skor keseluruhan anda.',
            'Pada pertengahan tahun dan hujung tahun, sediakan self-assessment anda untuk pengurus.',
        ],
    ],
])
<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:16px;">
    <div class="uj-card uj-stat" style="flex:1;min-width:160px;"><div class="uj-stat-label" x-text="$store.ui.lang==='en' ? 'Overall score' : 'Skor keseluruhan'">Overall score</div><div class="uj-stat-value">{{ $overall }}%</div></div>
    <div class="uj-card uj-stat" style="flex:1;min-width:160px;"><div class="uj-stat-label" x-text="$store.ui.lang==='en' ? 'On track' : 'Mengikut jadual'">On track</div><div class="uj-stat-value">{{ $items->where('status', 'green')->count() }}/{{ $items->count() }}</div></div>
    <div class="uj-card uj-stat" style="flex:1;min-width:160px;"><div class="uj-stat-label" x-text="$store.ui.lang==='en' ? 'Cycle stage' : 'Peringkat kitaran'">Cycle stage</div><div style="font-size:16px;font-weight:600;color:var(--ink);margin-top:6px;" x-text="$store.ui.lang==='en' ? 'Mid-year review' : 'Review pertengahan tahun'">Mid-year review</div></div>
    <div class="uj-card uj-stat" style="flex:1;min-width:160px;"><div class="uj-stat-label" x-text="$store.ui.lang==='en' ? 'Self-assessment due' : 'Self-assessment perlu dihantar'">Self-assessment due</div><div style="font-size:16px;font-weight:600;color:var(--amber);margin-top:6px;">15 Jul</div></div>
</div>

<div class="uj-card">
    <div class="uj-card-head"><h3 class="uj-card-title"><span x-text="$store.ui.lang==='en' ? 'Objectives' : 'Objektif'">Objectives</span> · 2026 H1</h3><button class="uj-btn-ghost" style="height:34px;padding:0 13px;font-size:12.5px;" x-text="$store.ui.lang==='en' ? 'Draft self-assessment' : 'Draf self-assessment'">Draft self-assessment</button></div>
    <div style="display:grid;grid-template-columns:2.4fr 1fr 1fr 1.4fr .6fr auto;gap:8px;padding:10px 20px;font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;border-bottom:1px solid var(--hairline-soft);"><span x-text="$store.ui.lang==='en' ? 'Objective' : 'Objektif'">Objective</span><span x-text="$store.ui.lang==='en' ? 'Target' : 'Sasaran'">Target</span><span x-text="$store.ui.lang==='en' ? 'Actual' : 'Sebenar'">Actual</span><span x-text="$store.ui.lang==='en' ? 'Progress' : 'Kemajuan'">Progress</span><span x-text="$store.ui.lang==='en' ? 'Weight' : 'Pemberat'">Weight</span><span></span></div>
    @forelse ($items as $k)
        @php $reopen = old('row') == $k->id; @endphp
        <div x-data="{ ed: {{ $reopen ? 'true' : 'false' }} }" style="border-bottom:1px solid var(--hairline-soft);">
            <div style="display:grid;grid-template-columns:2.4fr 1fr 1fr 1.4fr .6fr auto;gap:8px;padding:13px 20px;align-items:center;">
                <div><div style="font-size:13.5px;color:var(--ink);font-weight:500;">{{ $k->title }}</div><div style="font-size:11px;color:var(--muted-soft);text-transform:capitalize;">{{ $k->category }}</div></div>
                <span style="font-size:12.5px;color:var(--body);font-family:var(--font-mono);">{{ $k->target }}</span>
                <span style="font-size:12.5px;color:var(--ink);font-weight:500;font-family:var(--font-mono);">{{ $k->actual }}</span>
                <div style="display:flex;align-items:center;gap:8px;"><div class="uj-progress" style="flex:1;"><span style="width:{{ $k->progress }}%;background:{{ Amanahku::SWATCH[$k->status] }};"></span></div><span style="font-size:11px;color:var(--muted);font-family:var(--font-mono);">{{ $k->progress }}%</span></div>
                <span style="font-size:12.5px;color:var(--body);font-family:var(--font-mono);">{{ $k->weight }}</span>
                <button @click="ed = ! ed" class="uj-btn-ghost" style="height:30px;padding:0 11px;font-size:11.5px;"><span x-text="ed ? ($store.ui.lang==='en' ? 'Close' : 'Tutup') : ($store.ui.lang==='en' ? 'Update' : 'Kemas kini')"></span></button>
            </div>
            <form method="post" action="{{ route('kpi.update', $k) }}" x-show="ed" x-cloak style="padding:0 20px 15px;display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
                @csrf
                <input type="hidden" name="row" value="{{ $k->id }}" />
                <div><label style="display:block;font-size:11px;color:var(--muted);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Actual' : 'Sebenar'">Actual</label><input name="actual" value="{{ $reopen ? old('actual', $k->actual) : $k->actual }}" maxlength="60" style="height:36px;padding:0 11px;border:1px solid var(--hairline);border-radius:7px;font-size:13px;font-family:var(--font-mono);outline:none;" /></div>
                <div><label style="display:block;font-size:11px;color:var(--muted);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Progress %' : 'Kemajuan %'">Progress %</label><input name="progress" type="number" min="0" max="100" value="{{ $reopen ? old('progress', $k->progress) : $k->progress }}" required style="width:104px;height:36px;padding:0 11px;border:1px solid var(--hairline);border-radius:7px;font-size:13px;font-family:var(--font-mono);outline:none;" /></div>
                <button type="submit" class="uj-btn-primary" style="height:36px;padding:0 16px;font-size:13px;" x-text="$store.ui.lang==='en' ? 'Save' : 'Simpan'">Save</button>
                <div style="flex-basis:100%;">@include('partials.hint', ['en' => 'Actual = your real result so far (a number or short value). Progress % = how far that is toward the target. Be honest — your manager sees this at review time.', 'ms' => 'Actual = hasil sebenar anda setakat ini (angka atau nilai ringkas). Progress % = sejauh mana ia menghampiri sasaran. Jujur — pengurus anda lihat ini semasa review.'])</div>
            </form>
        </div>
    @empty
        <div style="padding:40px 24px;text-align:center;">
            <div style="font-size:13.5px;color:var(--ink);font-weight:500;margin-bottom:3px;"><span x-text="$store.ui.lang==='en' ? 'No KPI objectives set for this cycle' : 'Tiada objektif KPI ditetapkan untuk kitaran ini'"></span></div>
            <div style="font-size:12.5px;color:var(--muted);line-height:1.5;"><span x-text="$store.ui.lang==='en' ? 'Your manager or HR sets these at the start of the cycle. Once they appear, click &quot;Update&quot; on a row to record your progress.' : 'Pengurus atau HR anda menetapkannya pada permulaan kitaran. Sebaik sahaja ia muncul, klik &quot;Update&quot; pada satu baris untuk merekod kemajuan anda.'"></span></div>
        </div>
    @endforelse
</div>
@endsection
