@extends('layouts.app')

@php
    use App\Support\ArchetypeCatalog;
    $animals     = ['rabbit', 'tortoise', 'fox', 'sloth'];
    $animalEmoji = ['rabbit' => '🐇', 'tortoise' => '🐢', 'fox' => '🦊', 'sloth' => '🦥'];
@endphp

@section('screen')
@include('partials.guide', [
    'key' => 'profile-test-admin',
    'en'  => [
        'title' => 'Profile test editor',
        'body'  => 'Manage the single set of questions every employee answers in their Profile Test. "Working style" questions are scored — each option is tagged with one of four animals (Rabbit, Tortoise, Fox, Sloth) and the most-picked animal becomes the person\'s working style. "Colour" questions are light icebreakers and are not scored.',
        'who'   => 'HR & Management',
        'steps' => [
            'Press "Add question", choose a section, and write the prompt.',
            'For working-style questions, write up to four options and tag each with an animal.',
            'Use Edit to change a question or Delete to remove it. Changes apply to everyone immediately.',
        ],
    ],
    'ms'  => [
        'title' => 'Editor ujian profil',
        'body'  => 'Urus satu set soalan yang dijawab oleh setiap pekerja dalam Ujian Profil mereka. Soalan "Gaya kerja" diberi markah — setiap pilihan ditanda dengan satu daripada empat haiwan (Rabbit, Tortoise, Fox, Sloth) dan haiwan paling banyak dipilih menjadi gaya kerja orang itu. Soalan "Colour" hanya pencair suasana dan tidak diberi markah.',
        'who'   => 'HR & Pengurusan',
        'steps' => [
            'Tekan "Tambah soalan", pilih bahagian dan tulis soalan.',
            'Untuk soalan gaya kerja, tulis sehingga empat pilihan dan tandakan setiap satu dengan haiwan.',
            'Guna Sunting untuk menukar soalan atau Padam untuk membuangnya. Perubahan terpakai kepada semua orang dengan serta-merta.',
        ],
    ],
])

{{-- Animal legend --}}
<div class="uj-card" style="padding:14px 18px;margin-bottom:16px;display:flex;gap:20px;flex-wrap:wrap;">
    @foreach ($animals as $a)
        @php $m = ArchetypeCatalog::get($a); @endphp
        <span style="display:inline-flex;align-items:center;gap:9px;font-size:12.5px;color:var(--body);">
            <span style="width:30px;height:30px;border-radius:8px;background:{{ $m['accent'] }};display:flex;align-items:center;justify-content:center;font-size:16px;">{{ $animalEmoji[$a] }}</span>
            <span><strong style="color:var(--ink);">{{ $m['label'] }}</strong> · {{ $m['tagline_en'] }}</span>
        </span>
    @endforeach
</div>

{{-- Add question (collapsible) --}}
<div class="uj-card" style="padding:0;margin-bottom:20px;" x-data="{ open: false }">
    <button @click="open = ! open" type="button"
        style="width:100%;display:flex;align-items:center;justify-content:space-between;gap:10px;padding:15px 20px;background:none;cursor:pointer;border:0;">
        <span style="display:flex;align-items:center;gap:9px;font-size:13.5px;font-weight:600;color:var(--ink);">
            <span style="width:24px;height:24px;border-radius:7px;background:var(--red-tint);color:var(--red);display:flex;align-items:center;justify-content:center;font-size:16px;line-height:1;">+</span>
            <span x-text="$store.ui.lang==='en' ? 'Add question' : 'Tambah soalan'">Add question</span>
        </span>
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" :style="open ? 'transform:rotate(180deg);transition:.15s' : 'transition:.15s'"><path d="M6 9l6 6 6-6"/></svg>
    </button>
    <div x-show="open" x-cloak style="padding:20px 22px;border-top:1px solid var(--hairline);">
        @include('partials.pt-question-form', [
            'question' => null,
            'action' => route('profile-test.questions.store'),
            'submitLabel' => 'Add question',
        ])
    </div>
</div>

{{-- Working style section --}}
<div style="display:flex;align-items:center;gap:9px;margin:0 0 11px;">
    <h2 style="font-size:14px;font-weight:600;color:var(--ink);margin:0;"><span x-text="$store.ui.lang==='en' ? 'Working style' : 'Gaya kerja'">Working style</span></h2>
    <span style="font-size:11px;font-weight:600;color:var(--muted);background:var(--canvas);border:1px solid var(--hairline);padding:2px 9px;border-radius:9999px;">{{ $working->count() }}</span>
    <span style="font-size:12px;color:var(--muted-soft);"><span x-text="$store.ui.lang==='en' ? 'scored — maps to a working personality' : 'diberi markah — dipetakan kepada personaliti kerja'">scored — maps to a working personality</span></span>
</div>
@forelse ($working as $q)
    <div class="uj-card" style="padding:16px 20px;margin-bottom:12px;" x-data="{ edit: false }">
        <div style="display:flex;gap:12px;align-items:flex-start;">
            <span style="width:26px;height:26px;border-radius:7px;background:var(--canvas);border:1px solid var(--hairline);color:var(--muted);font-size:12px;font-weight:600;font-family:var(--font-mono);display:flex;align-items:center;justify-content:center;flex-shrink:0;">{{ $q->position }}</span>
            <p style="flex:1;min-width:0;font-size:13.5px;color:var(--ink);font-weight:500;margin:2px 0 0;">{{ $q->prompt_en }}</p>
            <button @click="edit = ! edit" type="button" class="uj-btn-ghost" style="height:32px;font-size:12px;padding:0 13px;"><span x-text="edit ? ($store.ui.lang==='en' ? 'Close' : 'Tutup') : ($store.ui.lang==='en' ? 'Edit' : 'Sunting')">Edit</span></button>
            <form method="post" action="{{ route('profile-test.questions.destroy', $q) }}" onsubmit="return confirm('Delete this question?')">
                @csrf
                <button type="submit" class="uj-btn-ghost" style="height:32px;font-size:12px;padding:0 13px;color:var(--error);"><span x-text="$store.ui.lang==='en' ? 'Delete' : 'Padam'">Delete</span></button>
            </form>
        </div>
        @if ($q->options->isNotEmpty())
            <div style="margin-top:11px;display:flex;flex-wrap:wrap;gap:8px;padding-left:38px;">
                @foreach ($q->options as $opt)
                    <span style="display:inline-flex;align-items:center;gap:6px;font-size:12px;color:var(--body);background:var(--canvas);border:1px solid var(--hairline);padding:5px 11px;border-radius:9999px;">
                        <span>{{ $animalEmoji[$opt->animal] ?? '•' }}</span>{{ $opt->label_en }}
                    </span>
                @endforeach
            </div>
        @endif
        <div x-show="edit" x-cloak style="margin-top:14px;padding-top:14px;border-top:1px solid var(--hairline-soft);">
            @include('partials.pt-question-form', [
                'question' => $q,
                'action' => route('profile-test.questions.update', $q),
                'submitLabel' => 'Save changes',
            ])
        </div>
    </div>
@empty
    <div class="uj-card" style="padding:28px;text-align:center;font-size:13px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'No working-style questions yet.' : 'Tiada soalan gaya kerja lagi.'">No working-style questions yet.</span></div>
@endforelse

{{-- Colour section --}}
<div style="display:flex;align-items:center;gap:9px;margin:24px 0 11px;">
    <h2 style="font-size:14px;font-weight:600;color:var(--ink);margin:0;">Colour</h2>
    <span style="font-size:11px;font-weight:600;color:var(--muted);background:var(--canvas);border:1px solid var(--hairline);padding:2px 9px;border-radius:9999px;">{{ $colour->count() }}</span>
    <span style="font-size:12px;color:var(--muted-soft);"><span x-text="$store.ui.lang==='en' ? 'icebreakers — flavour only, unscored' : 'pencair suasana — perisa sahaja, tiada markah'">icebreakers — flavour only, unscored</span></span>
</div>
@forelse ($colour as $q)
    <div class="uj-card" style="padding:14px 20px;margin-bottom:12px;" x-data="{ edit: false }">
        <div style="display:flex;gap:12px;align-items:flex-start;">
            <span style="width:26px;height:26px;border-radius:7px;background:var(--canvas);border:1px solid var(--hairline);color:var(--muted);font-size:12px;font-weight:600;font-family:var(--font-mono);display:flex;align-items:center;justify-content:center;flex-shrink:0;">{{ $q->position }}</span>
            <p style="flex:1;min-width:0;font-size:13.5px;color:var(--ink);font-weight:500;margin:2px 0 0;">{{ $q->prompt_en }}</p>
            <button @click="edit = ! edit" type="button" class="uj-btn-ghost" style="height:32px;font-size:12px;padding:0 13px;"><span x-text="edit ? ($store.ui.lang==='en' ? 'Close' : 'Tutup') : ($store.ui.lang==='en' ? 'Edit' : 'Sunting')">Edit</span></button>
            <form method="post" action="{{ route('profile-test.questions.destroy', $q) }}" onsubmit="return confirm('Delete this question?')">
                @csrf
                <button type="submit" class="uj-btn-ghost" style="height:32px;font-size:12px;padding:0 13px;color:var(--error);"><span x-text="$store.ui.lang==='en' ? 'Delete' : 'Padam'">Delete</span></button>
            </form>
        </div>
        <div x-show="edit" x-cloak style="margin-top:14px;padding-top:14px;border-top:1px solid var(--hairline-soft);">
            @include('partials.pt-question-form', [
                'question' => $q,
                'action' => route('profile-test.questions.update', $q),
                'submitLabel' => 'Save changes',
            ])
        </div>
    </div>
@empty
    <div class="uj-card" style="padding:28px;text-align:center;font-size:13px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'No colour questions yet.' : 'Tiada soalan colour lagi.'">No colour questions yet.</span></div>
@endforelse
@endsection
