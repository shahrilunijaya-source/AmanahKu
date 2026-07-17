@extends('layouts.app')

@php
    $items = $items ?? [];
    $positions = $positions ?? collect();
    // Readable label for a position band: "Sr Developer · Marketing".
    $posLabel = fn ($p) => trim(($p->title ?? '').' · '.($p->department?->name ?? ''), ' ·');
    $sections = ['general' => ['General onboarding', 'Onboarding umum'], 'position' => ['Position-specific', 'Khusus jawatan']];
    $muted = 'font-size:12px;color:var(--muted);';
@endphp

@section('screen')
@include('partials.guide', [
    'key' => 'onboarding-content',
    'en'  => [
        'title' => 'Onboarding content',
        'body'  => 'Behind every onboarding checklist item sits content the new hire opens — text to read, a video to watch, a file to download, or a box to acknowledge. Write it once here. General items are the same for everyone; position-specific items can carry a company-wide default plus a different version per position.',
        'who'   => 'HR & management author the content',
        'steps' => [
            'Fill in any mix of text, a video link, or an attachment for each item — you do not need all three.',
            'Tick "Require acknowledgement" for policies the hire must confirm they read.',
            'For position-specific items, set a default, then add per-position overrides where the content differs (e.g. systems access for Developers vs Marketing).',
            'Leaving every field blank and saving removes that content again.',
        ],
    ],
    'ms'  => [
        'title' => 'Kandungan onboarding',
        'body'  => 'Di sebalik setiap item senarai semak onboarding ada kandungan yang dibuka pekerja baharu — teks untuk dibaca, video untuk ditonton, fail untuk dimuat turun, atau kotak untuk diakui. Tulis sekali di sini. Item umum sama untuk semua; item khusus jawatan boleh ada versi lalai syarikat serta versi berbeza bagi setiap jawatan.',
        'who'   => 'HR & pengurusan menulis kandungan',
        'steps' => [
            'Isikan gabungan teks, pautan video, atau lampiran bagi setiap item — tidak perlu ketiga-tiganya.',
            'Tanda "Wajib pengakuan" untuk polisi yang wajib disahkan pekerja telah dibaca.',
            'Bagi item khusus jawatan, tetapkan versi lalai, kemudian tambah versi khusus jawatan di mana kandungan berbeza.',
            'Membiarkan semua medan kosong lalu menyimpan akan membuang kandungan itu semula.',
        ],
    ],
])

@foreach ($sections as $track => [$secLabel, $secLabelMs])
    <h3 style="font-size:13px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin:22px 0 12px;"><span x-text="$store.ui.lang==='en' ? @json($secLabel) : @json($secLabelMs)">{{ $secLabel }}</span></h3>

    @foreach ($items as $key => $item)
        @continue($item['track'] !== $track)
        @php
            $default = $item['default'];
            $overrides = $item['overrides'];
            $available = $positions->filter(fn ($p) => ! $overrides->has($p->id));
            $overrideCount = $overrides->count();
        @endphp
        <div class="uj-card" style="padding:20px;margin-bottom:14px;" x-data="{ addOverride: false }">
            <h4 style="font-size:15px;font-weight:600;color:var(--ink);margin:0 0 14px;">{{ $item['title'] }}</h4>

            {{-- Company-wide default (position_id NULL). General items only ever use this. --}}
            <form method="post" action="{{ route('onboarding.content.save') }}" enctype="multipart/form-data">
                @csrf
                <input type="hidden" name="item_key" value="{{ $key }}" />
                @if ($track === 'position')
                    <div style="{{ $muted }}margin-bottom:10px;"><span x-text="$store.ui.lang==='en' ? 'Default — shown to any position without its own version' : 'Lalai — ditunjuk kepada mana-mana jawatan tanpa versi sendiri'">Default — shown to any position without its own version</span></div>
                @endif
                @include('partials.onboarding-content-fields', ['res' => $default])
                <button type="submit" class="uj-btn-primary" style="height:38px;padding:0 18px;font-size:13px;margin-top:14px;"><span x-text="$store.ui.lang==='en' ? 'Save' : 'Simpan'">Save</span></button>
            </form>

            @if ($track === 'position')
                <div style="margin-top:18px;border-top:1px dashed var(--hairline);padding-top:16px;">
                    <div style="{{ $muted }}font-weight:600;margin-bottom:12px;"><span x-text="$store.ui.lang==='en' ? 'Per-position overrides' : 'Versi khusus jawatan'">Per-position overrides</span>@if ($overrideCount) · {{ $overrideCount }}@endif</div>

                    {{-- Existing overrides — collapsed; clearing all fields and saving removes the override. --}}
                    @foreach ($positions as $p)
                        @php $ov = $overrides->get($p->id); @endphp
                        @if ($ov)
                            <details style="border:1px solid var(--hairline);border-radius:10px;padding:10px 14px;margin-bottom:10px;">
                                <summary style="cursor:pointer;font-size:13px;font-weight:500;color:var(--ink);">{{ $posLabel($p) }}</summary>
                                <form method="post" action="{{ route('onboarding.content.save') }}" enctype="multipart/form-data" style="margin-top:12px;">
                                    @csrf
                                    <input type="hidden" name="item_key" value="{{ $key }}" />
                                    <input type="hidden" name="position_id" value="{{ $p->id }}" />
                                    @include('partials.onboarding-content-fields', ['res' => $ov])
                                    <button type="submit" class="uj-btn-primary" style="height:36px;padding:0 16px;font-size:12.5px;margin-top:12px;"><span x-text="$store.ui.lang==='en' ? 'Save override' : 'Simpan versi'">Save override</span></button>
                                </form>
                            </details>
                        @endif
                    @endforeach

                    {{-- Add a new override for a position that has none yet. --}}
                    @if ($available->count())
                        <button type="button" @click="addOverride = ! addOverride" style="background:none;border:none;padding:0;color:var(--accent, #c08532);font-size:13px;font-weight:500;cursor:pointer;display:inline-flex;align-items:center;gap:6px;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 5v14M5 12h14"></path></svg>
                            <span x-text="$store.ui.lang==='en' ? 'Add override for a position' : 'Tambah versi untuk jawatan'">Add override for a position</span>
                        </button>
                        <div x-show="addOverride" x-cloak style="margin-top:12px;border:1px solid var(--hairline);border-radius:10px;padding:14px;">
                            <form method="post" action="{{ route('onboarding.content.save') }}" enctype="multipart/form-data">
                                @csrf
                                <input type="hidden" name="item_key" value="{{ $key }}" />
                                <div style="margin-bottom:12px;">
                                    <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Position' : 'Jawatan'">Position</span> *</label>
                                    <select name="position_id" required style="height:38px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;background:#fff;color:var(--ink);width:100%;max-width:320px;">
                                        <option value="" x-text="$store.ui.lang==='en' ? 'Select a position…' : 'Pilih jawatan…'">Select a position…</option>
                                        @foreach ($available as $p)
                                            <option value="{{ $p->id }}">{{ $posLabel($p) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                @include('partials.onboarding-content-fields', ['res' => null])
                                <button type="submit" class="uj-btn-primary" style="height:36px;padding:0 16px;font-size:12.5px;margin-top:12px;"><span x-text="$store.ui.lang==='en' ? 'Save override' : 'Simpan versi'">Save override</span></button>
                            </form>
                        </div>
                    @endif
                </div>
            @endif
        </div>
    @endforeach
@endforeach
@endsection
