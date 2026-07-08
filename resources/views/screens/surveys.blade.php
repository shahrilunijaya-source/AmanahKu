@extends('layouts.app')

@php
    $typeLabel = ['scale' => 'Rating 1–5', 'enps' => 'eNPS 0–10', 'text' => 'Open feedback'];
    $answered = collect($answeredIds);
@endphp

@section('screen')
@include('partials.guide', [
    'key' => 'surveys',
    'en'  => [
        'title' => 'Pulse surveys',
        'body'  => 'Short, quick surveys to check how the team is feeling. Answer the open ones below — you respond once per survey, and individual answers feed into anonymous team results.',
        'who'   => 'Staff respond · HR launch & read results',
        'steps' => [
            'Open each survey on the left and read its question.',
            'Give your answer — a rating, an eNPS score, or written feedback depending on the type.',
            'Submit. You can respond once per survey; HR sees the combined results, not who said what.',
        ],
    ],
    'ms'  => [
        'title' => 'Survey pulse',
        'body'  => 'Survey ringkas dan pantas untuk menyemak perasaan pasukan. Jawab yang terbuka di bawah — anda jawab sekali setiap survey, dan jawapan individu disatukan menjadi keputusan pasukan tanpa nama.',
        'who'   => 'Staf jawab · HR lancar & baca keputusan',
        'steps' => [
            'Buka setiap survey di sebelah kiri dan baca soalannya.',
            'Beri jawapan anda — penilaian, skor eNPS, atau maklum balas bertulis bergantung pada jenisnya.',
            'Hantar. Anda boleh jawab sekali setiap survey; HR nampak keputusan gabungan, bukan siapa kata apa.',
        ],
    ],
])
@if (session('ok'))
    <div style="background:#e7f4ee;border:1px solid var(--success);color:var(--success);font-size:12.5px;border-radius:8px;padding:10px 14px;margin-bottom:16px;">{{ session('ok') }}</div>
@endif

<div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;">
    {{-- Open surveys + respond --}}
    <div style="flex:2;min-width:340px;display:flex;flex-direction:column;gap:16px;">
        <div class="uj-card" style="padding:0;">
            <div class="uj-card-head"><h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Open pulse surveys' : 'Survey pulse terbuka'">Open pulse surveys</h3><span style="font-size:12.5px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Respond once per survey' : 'Jawab sekali setiap survey'">Respond once per survey</span></div>
            @forelse ($openSurveys as $s)
                @php $hasAnswered = $answered->contains($s->id); @endphp
                <div style="padding:18px 20px;border-bottom:1px solid var(--hairline-soft);">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
                        <span class="uj-pill" style="background:var(--hairline-soft);color:var(--muted);">{{ $typeLabel[$s->type] ?? $s->type }}</span>
                        <span style="font-size:13.5px;font-weight:600;color:var(--ink);">{{ $s->title }}</span>
                    </div>
                    <p style="font-size:13px;color:var(--muted);margin:0 0 12px;">{{ $s->question }}</p>

                    @if ($hasAnswered)
                        <div style="font-size:12.5px;color:var(--success);font-weight:500;"><span x-text="$store.ui.lang==='en' ? '✓ You have responded. Thank you.' : '✓ Anda telah menjawab. Terima kasih.'">✓ You have responded. Thank you.</span></div>
                    @elseif (! $canRespond)
                        <div style="font-size:12.5px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'No employee profile in this workspace — responses are disabled.' : 'Tiada profil pekerja dalam workspace ini — jawapan dimatikan.'">No employee profile in this workspace — responses are disabled.</span></div>
                    @else
                        <form method="post" action="{{ route('surveys.respond', $s) }}">
                            @csrf
                            @if ($errors->any())<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:9px 12px;margin-bottom:12px;">{{ $errors->first() }}</div>@endif

                            @if ($s->type === 'scale')
                                <div style="display:flex;gap:8px;margin-bottom:12px;">
                                    @for ($n = 1; $n <= 5; $n++)
                                        <label style="flex:1;cursor:pointer;">
                                            <input type="radio" name="score" value="{{ $n }}" required style="position:absolute;opacity:0;" />
                                            <span style="display:block;text-align:center;padding:11px 0;border:1px solid var(--hairline);border-radius:8px;font-size:14px;font-weight:600;font-family:var(--font-mono);color:var(--ink);">{{ $n }}</span>
                                        </label>
                                    @endfor
                                </div>
                            @elseif ($s->type === 'enps')
                                <div style="display:flex;gap:5px;margin-bottom:8px;flex-wrap:wrap;">
                                    @for ($n = 0; $n <= 10; $n++)
                                        <label style="cursor:pointer;">
                                            <input type="radio" name="score" value="{{ $n }}" required style="position:absolute;opacity:0;" />
                                            <span style="display:flex;width:34px;height:34px;align-items:center;justify-content:center;border:1px solid var(--hairline);border-radius:8px;font-size:13px;font-weight:600;font-family:var(--font-mono);color:var(--ink);">{{ $n }}</span>
                                        </label>
                                    @endfor
                                </div>
                                <p style="font-size:11px;color:var(--muted-soft);margin:0 0 12px;"><span x-text="$store.ui.lang==='en' ? '0 = not at all likely · 10 = extremely likely' : '0 = langsung tidak mungkin · 10 = amat mungkin'">0 = not at all likely · 10 = extremely likely</span></p>
                            @endif

                            <textarea name="comment" maxlength="1000" rows="2" :placeholder="$store.ui.lang==='en' ? @js($s->type === 'text' ? 'Your feedback…' : 'Optional comment…') : @js($s->type === 'text' ? 'Maklum balas anda…' : 'Komen pilihan…')" {{ $s->type === 'text' ? 'required' : '' }} style="width:100%;padding:9px 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;resize:vertical;outline:none;margin-bottom:12px;font-family:inherit;">{{ old('comment') }}</textarea>

                            <button type="submit" class="uj-btn-primary" style="height:38px;padding:0 18px;font-size:13px;"><span x-text="$store.ui.lang==='en' ? 'Submit response' : 'Hantar jawapan'">Submit response</span></button>
                        </form>
                    @endif
                </div>
            @empty
                <div style="padding:28px 20px;text-align:center;">
                    <div style="font-size:13px;color:var(--ink);font-weight:500;margin-bottom:3px;"><span x-text="$store.ui.lang==='en' ? 'No open surveys right now' : 'Tiada survey terbuka sekarang'"></span></div>
                    <div style="font-size:12px;color:var(--muted);line-height:1.5;">@if ($privileged)<span x-text="$store.ui.lang==='en' ? 'Launch one with \'New pulse survey\' on the right and it will appear here for staff to answer.' : 'Lancarkan satu dengan \'New pulse survey\' di sebelah kanan dan ia akan muncul di sini untuk dijawab staf.'"></span>@else <span x-text="$store.ui.lang==='en' ? 'Nothing to answer at the moment. When HR opens a pulse survey, it will show up here.' : 'Tiada apa untuk dijawab buat masa ini. Apabila HR membuka survey pulse, ia akan muncul di sini.'"></span>@endif</div>
                </div>
            @endforelse
        </div>
    </div>

    {{-- Privileged: create + results dashboard --}}
    <div style="flex:1;min-width:300px;display:flex;flex-direction:column;gap:16px;">
        @if ($privileged)
            <div class="uj-card" style="padding:20px;">
                <h3 class="uj-card-title" style="margin-bottom:14px;" x-text="$store.ui.lang==='en' ? 'New pulse survey' : 'Survey pulse baharu'">New pulse survey</h3>
                <form method="post" action="{{ route('surveys.store') }}">
                    @csrf
                    @if ($errors->any())<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:9px 12px;margin-bottom:14px;">{{ $errors->first() }}</div>@endif

                    <label style="display:block;font-size:12.5px;font-weight:500;color:var(--ink);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Title' : 'Tajuk'">Title</label>
                    <input name="title" value="{{ old('title') }}" required maxlength="160" :placeholder="$store.ui.lang==='en' ? 'e.g. June engagement pulse' : 'cth. Pulse penglibatan Jun'" style="width:100%;height:40px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13.5px;margin-bottom:13px;outline:none;" />

                    <label style="display:block;font-size:12.5px;font-weight:500;color:var(--ink);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Question' : 'Soalan'">Question</label>
                    <textarea name="question" required maxlength="500" rows="2" :placeholder="$store.ui.lang==='en' ? 'e.g. How likely are you to recommend us as a place to work?' : 'cth. Sejauh mana anda mungkin mengesyorkan kami sebagai tempat kerja?'" style="width:100%;padding:9px 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;resize:vertical;outline:none;margin-bottom:13px;font-family:inherit;">{{ old('question') }}</textarea>

                    <label style="display:block;font-size:12.5px;font-weight:500;color:var(--ink);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Type' : 'Jenis'">Type</label>
                    <select name="type" required style="width:100%;height:40px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13.5px;background:#fff;color:var(--ink);margin-bottom:16px;">
                        <option value="scale" @selected(old('type') === 'scale') x-text="$store.ui.lang==='en' ? 'Rating scale (1–5)' : 'Skala penilaian (1–5)'">Rating scale (1–5)</option>
                        <option value="enps" @selected(old('type') === 'enps') x-text="$store.ui.lang==='en' ? 'eNPS (0–10)' : 'eNPS (0–10)'">eNPS (0–10)</option>
                        <option value="text" @selected(old('type') === 'text') x-text="$store.ui.lang==='en' ? 'Open feedback (text)' : 'Maklum balas terbuka (teks)'">Open feedback (text)</option>
                    </select>
                    @include('partials.hint', ['en' => 'Rating 1–5 for quick satisfaction checks · eNPS 0–10 for "would you recommend us" · Open feedback for free-text answers.', 'ms' => 'Penilaian 1–5 untuk semakan kepuasan pantas · eNPS 0–10 untuk "adakah anda mengesyorkan kami" · Maklum balas terbuka untuk jawapan teks bebas.'])

                    <button type="submit" class="uj-btn-primary" style="width:100%;height:42px;font-size:13.5px;"><span x-text="$store.ui.lang==='en' ? 'Launch survey' : 'Lancarkan survey'">Launch survey</span></button>
                </form>
            </div>

            <div class="uj-card" style="padding:20px;">
                <h3 class="uj-card-title" style="margin-bottom:3px;" x-text="$store.ui.lang==='en' ? 'Results' : 'Keputusan'">Results</h3>
                <p style="font-size:12px;color:var(--muted);margin:0 0 14px;" x-text="$store.ui.lang==='en' ? 'Live counts across all surveys.' : 'Kiraan langsung merentas semua survey.'">Live counts across all surveys.</p>
                @forelse ($dashboard as $d)
                    @php $s = $d['survey']; @endphp
                    <div style="padding:14px 0;border-bottom:1px solid var(--hairline-soft);">
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px;">
                            <span style="font-size:13px;font-weight:600;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $s->title }}</span>
                            <span class="uj-pill" style="background:{{ $s->status === 'open' ? '#e7f4ee' : 'var(--hairline-soft)' }};color:{{ $s->status === 'open' ? 'var(--success)' : 'var(--muted)' }};flex-shrink:0;">{{ ucfirst($s->status) }}</span>
                        </div>
                        <div style="display:flex;gap:16px;font-size:12px;color:var(--muted);margin-bottom:8px;">
                            <span><strong style="color:var(--ink);font-family:var(--font-mono);">{{ $d['count'] }}</strong> <span x-text="$store.ui.lang==='en' ? 'responses' : 'jawapan'">responses</span></span>
                            @if (! is_null($d['avg']))<span><span x-text="$store.ui.lang==='en' ? 'avg' : 'purata'">avg</span> <strong style="color:var(--ink);font-family:var(--font-mono);">{{ $d['avg'] }}</strong></span>@endif
                            @if (! is_null($d['enps']))<span>eNPS <strong style="color:var(--ink);font-family:var(--font-mono);">{{ $d['enps'] > 0 ? '+' : '' }}{{ $d['enps'] }}</strong></span>@endif
                        </div>

                        @if (! is_null($d['enps']))
                            @php $pct = (int) (($d['enps'] + 100) / 2); @endphp
                            <div style="height:7px;border-radius:4px;background:var(--hairline-soft);overflow:hidden;margin-bottom:6px;" title="eNPS {{ $d['enps'] }}">
                                <div style="height:100%;width:{{ $pct }}%;background:{{ $d['enps'] >= 30 ? 'var(--success)' : ($d['enps'] >= 0 ? 'var(--amber)' : 'var(--red)') }};"></div>
                            </div>
                        @elseif (! is_null($d['avg']))
                            <div style="height:7px;border-radius:4px;background:var(--hairline-soft);overflow:hidden;margin-bottom:6px;">
                                <div style="height:100%;width:{{ (int) ($d['avg'] / 5 * 100) }}%;background:var(--info);"></div>
                            </div>
                        @endif

                        @if ($s->status === 'open')
                            <form method="post" action="{{ route('surveys.close', $s) }}" style="margin-top:6px;">
                                @csrf
                                <button type="submit" style="background:none;border:none;color:var(--muted);font-size:11.5px;cursor:pointer;padding:0;text-decoration:underline;"><span x-text="$store.ui.lang==='en' ? 'Close survey' : 'Tutup survey'">Close survey</span></button>
                            </form>
                        @endif
                    </div>
                @empty
                    <div style="font-size:12.5px;color:var(--muted);line-height:1.5;"><span x-text="$store.ui.lang==='en' ? 'No surveys created yet. Launch one above — response counts and scores will appear here as people reply.' : 'Belum ada survey dicipta. Lancarkan satu di atas — bilangan jawapan dan skor akan muncul di sini apabila orang menjawab.'"></span></div>
                @endforelse
            </div>
        @endif
    </div>
</div>
@endsection
