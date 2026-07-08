@extends('layouts.app')

@php
    use App\Support\ArchetypeCatalog;
    use App\Support\ArchetypeScorer;

    $fs   = 'width:100%;padding:10px 12px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;background:#fff;color:var(--ink);outline:none;font-family:inherit;';
    $ws   = $result?->working_style_answers ?? [];
    $ca   = $result?->colour_answers ?? [];
    $tot  = $result?->totals ?? [];
    $answered = array_sum($tot);
    $animalEmoji = ['rabbit' => '🐇', 'tortoise' => '🐢', 'fox' => '🦊', 'sloth' => '🦥'];
@endphp

@section('screen')
@include('partials.guide', [
    'key' => 'profile-test',
    'en'  => [
        'title' => 'Profile test',
        'body'  => 'A short personality check that shows how you naturally work. Answer honestly — there are no right or wrong answers and it is never used as a pass/fail gate. When you save, your working-style "spirit animal" and trait bars appear on your Employee Profile.',
        'who'   => 'Everyone',
        'steps' => [
            'Fill in the "About yourself" tab — your goal, strengths and (optional) MBTI.',
            'Pick the option that fits you best for each working-style question.',
            'Add a few fun "Colour" answers, then press Save to see your result.',
        ],
    ],
    'ms'  => [
        'title' => 'Ujian profil',
        'body'  => 'Semakan personaliti ringkas yang menunjukkan cara semula jadi anda bekerja. Jawab dengan jujur — tiada jawapan betul atau salah dan ia tidak sekali-kali digunakan sebagai lulus/gagal. Apabila disimpan, "haiwan semangat" gaya kerja dan bar trait anda akan dipaparkan pada Profil Pekerja anda.',
        'who'   => 'Semua orang',
        'steps' => [
            'Isi tab "Tentang anda" — matlamat, kekuatan dan (pilihan) MBTI anda.',
            'Pilih pilihan yang paling sesuai untuk setiap soalan gaya kerja.',
            'Tambah beberapa jawapan "Colour" yang menyeronokkan, kemudian tekan Simpan untuk melihat keputusan.',
        ],
    ],
])

@if (! $canSubmit)
    @include('partials.empty-state', ['variantNote' => 'Profile test'])
@else

{{-- Result card — shows once the test has been scored --}}
@if ($archetype && $result?->animal_archetype)
    <div class="uj-card" style="padding:22px 24px;margin-bottom:16px;border-left:3px solid {{ $archetype['accent'] }};background:linear-gradient(0deg,var(--canvas),#fff);">
        <div style="display:flex;gap:18px;align-items:center;flex-wrap:wrap;">
            <div style="width:60px;height:60px;border-radius:14px;background:{{ $archetype['accent'] }};display:flex;align-items:center;justify-content:center;font-size:30px;flex-shrink:0;">{{ $animalEmoji[$result->animal_archetype] ?? '•' }}</div>
            <div style="flex:1;min-width:200px;">
                <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.6px;"><span x-text="$store.ui.lang==='en' ? 'Your working style' : 'Gaya kerja anda'">Your working style</span></div>
                <div style="font-size:21px;font-weight:600;color:var(--ink);margin:2px 0 3px;">{{ $archetype['label'] }}</div>
                <div style="font-size:13px;color:var(--body);">{{ $archetype['tagline_en'] }}</div>
            </div>
            <div style="flex:1;min-width:220px;display:flex;flex-direction:column;gap:8px;">
                @foreach (ArchetypeScorer::ORDER as $a)
                    @php $pct = $answered ? (int) round(($tot[$a] ?? 0) / $answered * 100) : 0; @endphp
                    <div>
                        <div style="display:flex;justify-content:space-between;margin-bottom:3px;"><span style="font-size:11.5px;color:var(--body);">{{ $animalEmoji[$a] }} {{ ucfirst($a) }}</span><span style="font-size:11px;color:var(--muted);font-family:var(--font-mono);">{{ $pct }}%</span></div>
                        <div class="uj-progress"><span style="width:{{ $pct }}%;background:{{ ArchetypeCatalog::get($a)['accent'] }};"></span></div>
                    </div>
                @endforeach
            </div>
        </div>
        <div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--hairline-soft);font-size:12.5px;color:var(--body);line-height:1.55;">{{ $archetype['plays_well_en'] }} · <span style="color:var(--muted);">{{ $archetype['watch_outs_en'] }}</span></div>
    </div>
@endif

<form method="post" action="{{ route('profile-test.submit') }}" x-data="{ tab: 'about' }">
    @csrf

    {{-- Tabs --}}
    <div class="uj-card" style="padding:0;margin-bottom:16px;">
        <div style="display:flex;gap:4px;padding:6px;border-bottom:1px solid var(--hairline);overflow-x:auto;">
            @php $tabs = [['about', 'About yourself', 'Tentang anda'], ['working', 'Working style', 'Gaya kerja'], ['colour', 'Colour', 'Colour']]; @endphp
            @foreach ($tabs as $t)
                @if ($t[0] === 'working' && $working->isEmpty()) @continue @endif
                @if ($t[0] === 'colour' && $colour->isEmpty()) @continue @endif
                <button type="button" @click="tab = '{{ $t[0] }}'"
                    style="font-size:13px;padding:8px 16px;border-radius:7px;white-space:nowrap;cursor:pointer;border:0;transition:background .12s;"
                    :style="tab === '{{ $t[0] }}' ? { color:'#fff', background:'var(--red)', fontWeight:'600' } : { color:'var(--body)', background:'transparent', fontWeight:'400' }"
                    x-text="$store.ui.lang==='en' ? @js($t[1]) : @js($t[2])">{{ $t[1] }}</button>
            @endforeach
        </div>

        {{-- Part 1: About yourself --}}
        <div x-show="tab === 'about'" style="padding:22px 24px;display:flex;flex-direction:column;gap:16px;">
            @php
                $fields = [
                    ['self_goal', 'Your goal', 'Matlamat anda', 'What are you working towards?', 'Apa yang anda usahakan?'],
                    ['self_strengths', 'Your strengths', 'Kekuatan anda', 'What do you do well?', 'Apa yang anda lakukan dengan baik?'],
                    ['self_weaknesses', 'Your weaknesses', 'Kelemahan anda', 'Where do you want to grow?', 'Di mana anda mahu berkembang?'],
                    ['self_interests', 'Your interests', 'Minat anda', 'What energises you at work?', 'Apa yang memberi tenaga kepada anda di tempat kerja?'],
                ];
            @endphp
            @foreach ($fields as $f)
                <div>
                    <label style="display:block;font-size:12px;font-weight:600;color:var(--ink);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? @js($f[1]) : @js($f[2])">{{ $f[1] }}</span></label>
                    <textarea name="{{ $f[0] }}" rows="2" style="{{ $fs }}resize:vertical;">{{ old($f[0], $result?->{$f[0]}) }}</textarea>
                    @include('partials.hint', ['en' => $f[3], 'ms' => $f[4]])
                </div>
            @endforeach
            <div>
                <label style="display:block;font-size:12px;font-weight:600;color:var(--ink);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Your MBTI type (optional)' : 'Jenis MBTI anda (pilihan)'">Your MBTI type (optional)</span></label>
                <input type="text" name="self_mbti" maxlength="10" placeholder="e.g. INTJ" value="{{ old('self_mbti', $result?->self_mbti) }}" style="{{ $fs }}max-width:200px;">
                @include('partials.hint', ['en' => "Don't know yours? Take a free test at 16personalities.com.", 'ms' => 'Tidak tahu jenis anda? Ambil ujian percuma di 16personalities.com.'])
            </div>
        </div>

        {{-- Part 2: Working style --}}
        @if ($working->isNotEmpty())
        <div x-show="tab === 'working'" x-cloak style="padding:8px 0;">
            @foreach ($working as $q)
                @php $saved = old("working_style.{$q->id}", $ws[$q->id] ?? null); @endphp
                <div style="padding:16px 24px;border-bottom:1px solid var(--hairline-soft);">
                    <p style="font-size:13.5px;font-weight:600;color:var(--ink);margin:0 0 11px;">{{ $loop->iteration }}. {{ $q->prompt_en }}</p>
                    <div style="display:flex;flex-direction:column;gap:8px;">
                        @foreach ($q->options as $opt)
                            <label style="display:flex;align-items:center;gap:10px;padding:9px 12px;border:1px solid var(--hairline);border-radius:9px;cursor:pointer;font-size:13px;color:var(--body);transition:border-color .12s,background .12s;"
                                   onmouseover="this.style.borderColor='var(--red)'" onmouseout="this.style.borderColor=(this.querySelector('input').checked?'var(--red)':'var(--hairline)')">
                                <input type="radio" name="working_style[{{ $q->id }}]" value="{{ $opt->id }}" {{ (string) $saved === (string) $opt->id ? 'checked' : '' }}
                                       onchange="document.querySelectorAll('[name=&quot;working_style[{{ $q->id }}]&quot;]').forEach(r=>r.closest('label').style.borderColor=r.checked?'var(--red)':'var(--hairline)')"
                                       style="accent-color:var(--red);width:16px;height:16px;flex-shrink:0;">
                                <span>{{ $opt->label_en }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
        @endif

        {{-- Part 3: Colour (icebreakers) --}}
        @if ($colour->isNotEmpty())
        <div x-show="tab === 'colour'" x-cloak style="padding:8px 0;">
            @foreach ($colour as $q)
                @php $saved = old("colour.{$q->id}", $ca[$q->id] ?? null); @endphp
                <div style="padding:14px 24px;border-bottom:1px solid var(--hairline-soft);">
                    <p style="font-size:13.5px;font-weight:600;color:var(--ink);margin:0 0 9px;">{{ $q->prompt_en }}</p>
                    @if ($q->options->isNotEmpty())
                        <div style="display:flex;flex-wrap:wrap;gap:8px;">
                            @foreach ($q->options as $opt)
                                <label style="display:inline-flex;align-items:center;gap:7px;padding:7px 12px;border:1px solid var(--hairline);border-radius:9999px;cursor:pointer;font-size:13px;color:var(--body);">
                                    <input type="radio" name="colour[{{ $q->id }}]" value="{{ $opt->id }}" {{ (string) $saved === (string) $opt->id ? 'checked' : '' }} style="accent-color:var(--red);">
                                    <span>{{ $opt->label_en }}</span>
                                </label>
                            @endforeach
                        </div>
                    @else
                        <input type="text" name="colour[{{ $q->id }}]" maxlength="1000" value="{{ $saved }}" style="{{ $fs }}" placeholder="…">
                    @endif
                </div>
            @endforeach
        </div>
        @endif
    </div>

    <div style="display:flex;justify-content:flex-end;gap:10px;">
        <button type="submit" class="uj-btn-primary" style="height:42px;padding:0 22px;font-size:13.5px;"><span x-text="$store.ui.lang==='en' ? 'Save & see my result' : 'Simpan & lihat keputusan saya'">Save &amp; see my result</span></button>
    </div>
</form>
@endif
@endsection
