@extends('layouts.app')

@section('screen')
@include('partials.guide', [
    'key' => 'orgchart',
    'en'  => [
        'title' => 'Organisation chart',
        'body'  => 'Shows the company\'s reporting lines — who reports to whom, from the top down. Use it to see team structure, spot where a manager has too many direct reports, or find who covers a role. HR and management can drag people to re-arrange reporting lines.',
    ],
    'ms'  => [
        'title' => 'Carta organisasi',
        'body'  => 'Menunjukkan talian pelaporan syarikat — siapa melapor kepada siapa, dari atas ke bawah. Guna untuk lihat struktur pasukan, kesan di mana seorang pengurus ada terlalu banyak orang bawahan terus, atau cari siapa pegang sesuatu peranan. HR dan pengurusan boleh seret orang untuk susun semula talian pelaporan.',
    ],
])

@if ($canEdit)
    {{-- Arrange-mode affordances: every drop zone reads as a dashed box, cards become
         grab handles. Scoped to .org-arranging so the read-only tree is untouched. --}}
    <style>
        .org-arranging [data-node] { cursor: grab; }
        .org-arranging [data-node]:active { cursor: grabbing; }
        .org-arranging [data-node] a { cursor: grab; }
        .org-arranging [data-children] {
            min-height: 30px !important;
            border: 1px dashed var(--hairline) !important;
            border-radius: 8px;
            margin: 8px 0 0 40px !important;
            padding: 6px 8px !important;
        }
        .org-arranging .uj-drag-ghost { opacity: .45; }
    </style>
@endif

<div style="display:flex;flex-direction:column;gap:18px;" @if ($canEdit) x-data="orgChart()" @endif>
    {{-- Summary strip --}}
    <div style="display:flex;flex-wrap:wrap;gap:12px;">
        @php
            $stats = [
                ['Headcount', 'Bilangan kakitangan', $headcount, 'headcount'],
                ['Top-level roots', 'Punca peringkat atas', $rootCount, 'roots'],
                ['Reporting depth', 'Kedalaman pelaporan', $maxDepth, 'depth'],
            ];
        @endphp
        @foreach ($stats as $stat)
            <div class="uj-card" style="padding:14px 18px;min-width:150px;flex:1;">
                <div style="font-size:12px;color:var(--muted);" x-text="$store.ui.lang==='en' ? '{{ $stat[0] }}' : '{{ $stat[1] }}'">{{ $stat[0] }}</div>
                <div data-stat="{{ $stat[3] }}" style="font-size:24px;font-weight:600;color:var(--ink);line-height:1.2;margin-top:2px;">{{ $stat[2] }}</div>
            </div>
        @endforeach
    </div>

    {{-- Department lens: click a chip to filter the chart to one department; "All" clears it. --}}
    @if ($byDept->isNotEmpty())
        @php
            $chipBase = 'font-size:12px;padding:4px 11px;border-radius:9999px;text-decoration:none;border:1px solid transparent;white-space:nowrap;';
            $chipOn = 'background:var(--ink);color:#fff;';
            $chipOff = 'background:var(--hairline-soft);color:var(--muted);';
        @endphp
        <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
            <a href="{{ route('app.screen', 'orgchart') }}" style="{{ $chipBase }}{{ $selectedDept === null ? $chipOn : $chipOff }}"><span x-text="$store.ui.lang==='en' ? 'All' : 'Semua'">All</span> · {{ $byDept->sum() }}</a>
            @foreach ($byDept as $dept => $n)
                <a href="{{ route('app.screen', ['screen' => 'orgchart', 'dept' => $dept]) }}" style="{{ $chipBase }}{{ $selectedDept === $dept ? $chipOn : $chipOff }}">{{ $dept }} · {{ $n }}</a>
            @endforeach
        </div>
    @endif

    {{-- Arrange + precise-edit toolbar (HR / management). Drag is the intuitive path;
         the dropdown editor below is the precise / keyboard-accessible fallback. --}}
    @if ($canEdit)
        <div x-data="{ orgEdit: false }">
            <div style="display:flex;flex-wrap:wrap;justify-content:flex-end;align-items:center;gap:10px;margin-bottom:6px;">
                <span x-show="busy" x-cloak style="font-size:12px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'Saving…' : 'Menyimpan…'">Saving…</span></span>
                <button @click="toggleArrange()" :class="arranging ? 'uj-btn-primary' : 'uj-btn-ghost'" style="height:38px;padding:0 16px;font-size:13px;">
                    <span x-text="arranging ? ($store.ui.lang==='en' ? 'Done arranging' : 'Selesai susun') : ($store.ui.lang==='en' ? 'Arrange (drag & drop)' : 'Susun (seret & lepas)')"></span>
                </button>
                <button @click="orgEdit = ! orgEdit" class="uj-btn-ghost" style="height:38px;padding:0 16px;font-size:13px;">
                    <span x-text="orgEdit ? ($store.ui.lang==='en' ? 'Close editor' : 'Tutup penyunting') : ($store.ui.lang==='en' ? 'Edit as list' : 'Sunting senarai')"></span>
                </button>
            </div>

            {{-- Drag hint, only while arranging --}}
            <div x-show="arranging" x-cloak style="background:var(--info-tint,var(--hairline-soft));border:1px solid var(--hairline);border-radius:10px;padding:11px 14px;margin-bottom:14px;font-size:12.5px;color:var(--body);line-height:1.5;">
                <span x-text="$store.ui.lang==='en' ? 'Drag a person onto someone to make them report to that person. Drag to the top area to make them a top-level lead. Changes save as you drop.' : 'Seret seseorang ke atas orang lain untuk jadikan mereka melapor kepada orang itu. Seret ke kawasan atas untuk jadikan ketua peringkat atasan. Perubahan disimpan apabila dilepaskan.'"></span>
            </div>

            {{-- Precise list editor --}}
            <div x-show="orgEdit" x-cloak class="uj-card" style="padding:20px;margin-bottom:16px;">
                <h3 class="uj-card-title" style="margin-bottom:4px;"><span x-text="$store.ui.lang==='en' ? 'Reporting lines' : 'Garis pelaporan'">Reporting lines</span></h3>
                <p style="font-size:12.5px;color:var(--muted);margin:0 0 14px;"><span x-text="$store.ui.lang==='en' ? 'Set who each person reports to. Leave a person blank to keep them at the top level. Add extra managers if someone answers to more than one boss — any of them can verify that person\'s leave, claims and overtime. Directors sit in their own band above the chart — flag their position as a Director band on the Position &amp; Manday Rates screen (or assign the Director login role). The chart rebuilds when you save.' : 'Tetapkan siapa setiap orang melapor kepadanya. Biar kosong untuk kekalkan di peringkat atasan. Tambah pengurus tambahan jika seseorang ada lebih daripada satu ketua — mana-mana daripada mereka boleh sahkan cuti, tuntutan dan kerja lebih masa orang itu. Pengarah berada di jalur mereka sendiri di atas carta — tandakan pangkat mereka sebagai Band Pengarah di skrin Pangkat &amp; Kadar Manday (atau berikan peranan Director log masuk). Carta dibina semula bila disimpan.'">Set who each person reports to. Add extra managers for a second boss. Flag their position as a Director band on the Position &amp; Manday Rates screen.</span></p>
                <form method="post" action="{{ route('org.reporting-lines') }}">
                    @csrf
                    <div style="display:flex;flex-direction:column;gap:10px;max-height:60vh;overflow-y:auto;padding-right:4px;">
                        @foreach ($editStaff as $s)
                            <div style="display:flex;flex-direction:column;gap:8px;padding:10px 0;border-bottom:1px solid var(--hairline-soft);">
                                {{-- Name --}}
                                <div style="font-size:13px;color:var(--ink);font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $s['name'] }}</div>
                                {{-- Primary reporting line --}}
                                <div style="display:flex;align-items:center;gap:10px;">
                                    <span style="font-size:11px;color:var(--muted);width:96px;flex-shrink:0;text-align:right;"><span x-text="$store.ui.lang==='en' ? 'reports to' : 'melapor kepada'">reports to</span></span>
                                    <select name="manager[{{ $s['id'] }}]" style="flex:1;min-width:0;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;background:#fff;color:var(--ink);">
                                        <option value="">—</option>
                                        @foreach ($editStaff as $m)
                                            @continue($m['id'] === $s['id'])
                                            <option value="{{ $m['id'] }}" @selected($s['reports_to_id'] === $m['id'])>{{ $m['name'] }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                {{-- Additional (dotted-line) managers — any may verify this person.
                                     Chip picker: chosen people show as removable pills, the dropdown
                                     adds more. Hidden inputs carry the ids on submit. --}}
                                @php
                                    $extraOptions = $editStaff
                                        ->reject(fn ($m) => $m['id'] === $s['id'])
                                        ->map(fn ($m) => ['id' => (string) $m['id'], 'name' => $m['name']])
                                        ->values();
                                    $extraSelected = array_map('strval', $s['extra_manager_ids']);
                                @endphp
                                <div style="display:flex;align-items:flex-start;gap:10px;"
                                     x-data="{
                                        selected: @js($extraSelected),
                                        options: @js($extraOptions),
                                        nameOf(id) { return (this.options.find(o => o.id === id) || {}).name || ''; },
                                        get available() { return this.options.filter(o => ! this.selected.includes(o.id)); },
                                        add(id) { if (id && ! this.selected.includes(id)) this.selected.push(id); },
                                        remove(id) { this.selected = this.selected.filter(x => x !== id); },
                                     }">
                                    <span style="font-size:11px;color:var(--muted);width:96px;flex-shrink:0;text-align:right;padding-top:9px;"><span x-text="$store.ui.lang==='en' ? 'also verified by' : 'juga disahkan oleh'">also verified by</span></span>
                                    <div style="flex:1;min-width:0;">
                                        <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;min-height:24px;">
                                            <template x-for="id in selected" :key="id">
                                                <span style="display:inline-flex;align-items:center;gap:6px;font-size:12px;color:var(--ink);background:var(--hairline-soft);border:1px solid var(--hairline);border-radius:9999px;padding:3px 6px 3px 11px;">
                                                    <span x-text="nameOf(id)"></span>
                                                    <button type="button" @click="remove(id)" aria-label="Remove" style="width:16px;height:16px;line-height:1;border:none;border-radius:50%;background:var(--muted-soft,#e5e5e5);color:var(--ink);font-size:12px;cursor:pointer;display:flex;align-items:center;justify-content:center;">&times;</button>
                                                    <input type="hidden" name="extra_managers[{{ $s['id'] }}][]" :value="id">
                                                </span>
                                            </template>
                                            <span x-show="selected.length === 0" style="font-size:12px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'None' : 'Tiada'"></span>
                                        </div>
                                        <select @change="add($event.target.value); $event.target.value=''" x-show="available.length > 0" style="margin-top:7px;width:100%;min-width:0;height:34px;padding:0 10px;border:1px dashed var(--hairline);border-radius:8px;font-size:12.5px;background:#fff;color:var(--muted);">
                                            <option value="" x-text="$store.ui.lang==='en' ? '+ Add a manager' : '+ Tambah pengurus'"></option>
                                            <template x-for="o in available" :key="o.id">
                                                <option :value="o.id" x-text="o.name"></option>
                                            </template>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <button type="submit" class="uj-btn-primary" style="height:42px;padding:0 20px;font-size:13.5px;margin-top:16px;"><span x-text="$store.ui.lang==='en' ? 'Save reporting lines' : 'Simpan garis pelaporan'">Save reporting lines</span></button>
                </form>
            </div>
        </div>
    @endif

    {{-- Directors band: a FLAT leadership row above the whole chart. Co-equal cards, no
         subtree beneath any of them — directors are the approval authority on top, not tree
         nodes. Their reports appear at the top of the tree below. Not a drop zone (director
         status is the editor checkbox, never a drag). --}}
    @if ($directors->isNotEmpty())
        <div class="uj-card" style="padding:18px 20px;border:1px solid #f2d675;background:linear-gradient(180deg,#fffdf5,#fff);">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px;">
                <span style="font-size:11px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:#8a6d00;" x-text="$store.ui.lang==='en' ? 'Directors' : 'Pengarah'">Directors</span>
                <span style="height:1px;flex:1;background:#f2d675;opacity:.6;"></span>
            </div>
            <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:stretch;">
                @foreach ($directors as $e)
                    <a href="{{ route('app.screen', ['screen' => 'profile', 'emp' => $e->id]) }}" class="uj-card-clickable" style="flex:1 1 320px;min-width:260px;max-width:520px;display:flex;align-items:center;gap:12px;text-decoration:none;border:1px solid #f2d675;border-radius:12px;padding:12px 16px;background:#fff;">
                        @if ($e->photo && str_starts_with($e->photo, '/'))
                            <img src="{{ $e->photo }}" alt="" width="42" height="42" style="width:42px;height:42px;border-radius:50%;object-fit:cover;flex-shrink:0;">
                        @else
                            <div style="width:42px;height:42px;border-radius:50%;background:{{ $e->avatar_color }};color:#fff;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:600;flex-shrink:0;">{{ $e->initials }}</div>
                        @endif
                        <div style="min-width:0;flex:1;">
                            <div style="display:flex;align-items:center;gap:7px;min-width:0;">
                                <span style="font-size:14.5px;font-weight:600;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $e->name }}</span>
                                <span style="flex-shrink:0;font-size:9.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#8a6d00;background:#fdf1c4;border:1px solid #f2d675;border-radius:9999px;padding:1px 7px;line-height:1.4;" x-text="$store.ui.lang==='en' ? 'Director' : 'Pengarah'">Director</span>
                            </div>
                            <div style="font-size:12px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:1px;">{{ collect([$e->position, $e->department?->name])->filter()->implode(' · ') }}</div>
                        </div>
                        <span style="width:8px;height:8px;border-radius:50%;background:{{ \App\Support\Amanahku::SWATCH[$e->workload] ?? 'var(--muted-soft)' }};flex-shrink:0;"></span>
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Recursive reporting tree. The outermost [data-children] is the top level
         (data-parent=""): dropping a card here clears its manager. --}}
    @if (! empty($roots))
        <div class="uj-card" style="padding:20px;">
            <div data-children data-parent="" style="display:flex;flex-direction:column;gap:16px;">
                @foreach ($roots as $node)
                    @include('partials.org-node', ['node' => $node, 'canEdit' => $canEdit])
                @endforeach
            </div>
        </div>
    @elseif ($directors->isEmpty())
        <div class="uj-card" style="padding:28px 20px;text-align:center;">
            <div style="font-size:13px;color:var(--ink);font-weight:500;margin-bottom:3px;"><span x-text="$store.ui.lang==='en' ? 'No reporting lines to chart yet' : 'Tiada talian pelaporan untuk dipetakan lagi'"></span></div>
            <div style="font-size:12px;color:var(--muted);line-height:1.5;"><span x-text="$store.ui.lang==='en' ? 'Add employees and set who each one reports to (in their profile) — the chart builds itself from those links.' : 'Tambah pekerja dan tetapkan siapa setiap seorang melapor kepada (dalam profil mereka) — carta akan terbina sendiri daripada pautan tersebut.'"></span></div>
        </div>
    @endif
</div>
@endsection
