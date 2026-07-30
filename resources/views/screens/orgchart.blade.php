@extends('layouts.app')

@section('screen')
@include('partials.guide', [
    'key' => 'orgchart',
    'en'  => [
        'title' => 'Organisation chart',
        'body'  => 'Shows the company\'s reporting lines — who reports to whom. The chart shows one seat at a time: the manager above, the person in the middle, and their direct reports below. Choose any circle to move the chart onto that person. HR and management can fill an empty slot to set who someone reports to, because that line is what decides who verifies their leave, claims and overtime.',
    ],
    'ms'  => [
        'title' => 'Carta organisasi',
        'body'  => 'Menunjukkan talian pelaporan syarikat — siapa melapor kepada siapa. Carta menunjukkan satu kerusi pada satu masa: pengurus di atas, orang itu di tengah, dan orang bawahan terus di bawah. Pilih mana-mana bulatan untuk alihkan carta kepada orang itu. HR dan pengurusan boleh isi slot kosong untuk tetapkan siapa seseorang melapor kepadanya, kerana talian itu yang menentukan siapa mengesahkan cuti, tuntutan dan kerja lebih masa mereka.',
    ],
])

<div style="display:flex;flex-direction:column;gap:16px;"
     x-data="orgChart(@js($chart), @js($canEdit))">

    {{-- One mono line in place of the three stat cards. Same three numbers; the cards
         were a metric template that said nothing the chart itself could not. --}}
    <div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px;font-family:var(--font-mono);font-size:var(--t-sm);color:var(--muted);">
        <span>{{ $headcount }} <span x-text="$store.ui.lang==='en' ? 'staff' : 'kakitangan'">staff</span></span>
        <span style="color:var(--oc-line);">/</span>
        <span>{{ $maxDepth }} <span x-text="$store.ui.lang==='en' ? 'levels deep' : 'aras'">levels deep</span></span>
        <span style="color:var(--oc-line);">/</span>
        <span :style="kidsOf(null).length ? 'color:var(--amber)' : ''"
              x-text="kidsOf(null).length + ($store.ui.lang==='en' ? ' with no manager' : ' tiada pengurus')"></span>
    </div>

    {{-- Department lens: choose a chip to build the chart from one department only. --}}
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

    <div x-show="error" x-cloak role="alert"
         style="border:1px solid #f0cdcf;background:var(--red-tint);color:var(--red-active);border-radius:10px;padding:11px 14px;font-size:var(--t-sm);line-height:1.5;"
         x-text="error"></div>

    @if ($headcount === 0 && $directors->isEmpty())
        <div class="uj-card" style="padding:28px 20px;text-align:center;">
            <div style="font-size:13px;color:var(--ink);font-weight:500;margin-bottom:3px;"><span x-text="$store.ui.lang==='en' ? 'Nothing to chart yet' : 'Tiada apa untuk dipetakan lagi'"></span></div>
            <div style="font-size:12px;color:var(--muted);line-height:1.5;"><span x-text="$store.ui.lang==='en' ? 'Add employees first. The chart builds itself from who reports to whom.' : 'Tambah pekerja dahulu. Carta akan terbina sendiri daripada siapa melapor kepada siapa.'"></span></div>
        </div>
    @else
        <div class="oc-canvas">
            <div class="oc-stage" x-ref="stage">

                {{-- Trail: where am I, and one click back to any level above. --}}
                <nav class="oc-trail" :aria-label="$store.ui.lang==='en' ? 'Chart position' : 'Kedudukan dalam carta'">
                    <button type="button" @click="dive(null, -1)"
                            x-text="$store.ui.lang==='en' ? 'Top' : 'Atas'"></button>
                    <template x-for="a in (focus === null ? [] : ancestors(focus))" :key="a">
                        <span style="display:flex;align-items:center;gap:7px;">
                            <span class="oc-trail-sep" aria-hidden="true">/</span>
                            <button type="button" @click="dive(a, -1)" x-text="person(a).name"></button>
                        </span>
                    </template>
                    <template x-if="focus !== null">
                        <span style="display:flex;align-items:center;gap:7px;">
                            <span class="oc-trail-sep" aria-hidden="true">/</span>
                            <span class="oc-trail-here" x-text="subject.name"></span>
                        </span>
                    </template>
                </nav>

                {{-- Ring above: the manager, or the band that gives final approval. --}}
                <template x-if="focus !== null && (parents[focus] !== null || directors.length)">
                    <div class="oc-up">
                        <div class="oc-uplabel"
                             x-text="parents[focus] !== null
                                ? ($store.ui.lang==='en' ? 'reports to' : 'melapor kepada')
                                : ($store.ui.lang==='en' ? 'final approval' : 'kelulusan akhir')"></div>
                        <button type="button" class="oc-circle"
                                :data-name="parents[focus] !== null ? person(parents[focus]).name : null"
                                @click="dive(parents[focus] ?? null, -1)"
                                :aria-label="parents[focus] !== null ? person(parents[focus]).name : ($store.ui.lang==='en' ? 'Top of the chart' : 'Atas carta')">
                            <template x-if="parents[focus] !== null">
                                @include('partials.org-face', ['p' => 'person(parents[focus])'])
                            </template>
                            {{-- The band is not a person, so this one seat keeps a word. --}}
                            <template x-if="parents[focus] === null">
                                <span class="oc-name" x-text="$store.ui.lang==='en' ? 'Directors' : 'Pengarah'"></span>
                            </template>
                        </button>
                        <div class="oc-stem" style="height:30px;" aria-hidden="true"></div>
                    </div>
                </template>

                {{-- The subject. At the top of the chart that is the directors band. --}}
                <template x-if="focus === null">
                    @include('partials.org-band')
                </template>

                <template x-if="focus !== null">
                    <div class="oc-subject">
                        <button type="button" class="oc-circle"
                                :class="{ 'is-sel': sel === focus, 'is-new': justPlaced === focus }"
                                @click="sel = focus"
                                :data-name="subject.name"
                                :aria-label="subject.name">
                            @include('partials.org-face', ['p' => 'subject'])
                            <template x-if="subject.swatch">
                                <span class="oc-dot" :style="`background:${subject.swatch}`" aria-hidden="true"></span>
                            </template>
                        </button>
                        <div class="oc-subrole"
                             x-text="[subject.role, subject.dept].filter(Boolean).join(' · ')
                                || ($store.ui.lang==='en' ? 'No position or department set' : 'Tiada pangkat atau bahagian')"></div>

                        {{-- Extra verifiers: approval power without a reporting line, so they
                             orbit the seat rather than sitting in it. --}}
                        <template x-if="subjectVerifiers.length">
                            <div class="oc-also">
                                <span class="oc-also-label"
                                      x-text="$store.ui.lang==='en' ? 'also verified by' : 'juga disahkan oleh'"></span>
                                <template x-for="p in subjectVerifiers" :key="p.id">
                                    <span class="oc-chip">
                                        <template x-if="p.photo">
                                            <img :src="p.photo" alt="" width="20" height="20">
                                        </template>
                                        <template x-if="! p.photo">
                                            <span class="oc-chip-av" :style="`background:${p.color}`" x-text="p.initials"></span>
                                        </template>
                                        <span x-text="p.name"></span>
                                        @if ($canEdit)
                                            <button type="button" @click="removeVerifier(p.id)" :disabled="busy"
                                                    :aria-label="($store.ui.lang==='en' ? 'Remove ' : 'Buang ') + p.name">&times;</button>
                                        @endif
                                    </span>
                                </template>
                            </div>
                        </template>
                    </div>
                </template>

                {{-- Ring below: direct reports, plus one open slot while editing. --}}
                <template x-if="reports.length || canEdit">
                    <div style="display:flex;flex-direction:column;align-items:center;">
                        <div class="oc-stem" style="height:32px;" aria-hidden="true"></div>
                        <div class="oc-fan">
                            <template x-for="(id, i) in reports" :key="id">
                                <div class="oc-branch">
                                    <div class="oc-conn" aria-hidden="true">
                                        <div class="oc-conn-l" :class="i > 0 && 'is-on'"></div>
                                        <div class="oc-conn-r" :class="(i < reports.length - 1 || canEdit) && 'is-on'"></div>
                                    </div>
                                    <div class="oc-col">
                                        <button type="button" class="oc-circle"
                                                :class="{ 'is-sel': sel === id, 'is-new': justPlaced === id }"
                                                @click="dive(id, 1)"
                                                :data-name="person(id).name"
                                                :aria-label="($store.ui.lang==='en' ? 'Move chart to ' : 'Alih carta ke ') + person(id).name">
                                            @include('partials.org-face', ['p' => 'person(id)'])
                                            <template x-if="person(id).swatch">
                                                <span class="oc-dot" :style="`background:${person(id).swatch}`" aria-hidden="true"></span>
                                            </template>
                                        </button>
                                        {{-- Falls back to the name: a face over a blank line, with the
                                             name only on hover, leaves nothing to identify on a touch
                                             screen. Plenty of staff have no position set. --}}
                                        <div class="oc-role" x-text="person(id).role || person(id).name"></div>
                                    </div>
                                </div>
                            </template>

                            @if ($canEdit)
                                <div class="oc-branch">
                                    <div class="oc-conn is-open" aria-hidden="true">
                                        <div class="oc-conn-l" :class="reports.length > 0 && 'is-on'"></div>
                                        <div class="oc-conn-r"></div>
                                    </div>
                                    <div class="oc-col">
                                        <button type="button" class="oc-circle is-open"
                                                :class="picking && picking.parent === focus && 'is-sel'"
                                                @click="openSlot(focus)"
                                                :aria-label="$store.ui.lang==='en' ? 'Add someone who reports here' : 'Tambah orang yang melapor di sini'">
                                            <span class="oc-plus" aria-hidden="true">+</span>
                                        </button>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                </template>

                <template x-if="! reports.length && ! canEdit">
                    <p class="oc-empty"
                       x-text="$store.ui.lang==='en' ? 'Nobody reports to this person.' : 'Tiada sesiapa melapor kepada orang ini.'"></p>
                </template>
            </div>
        </div>

        @include('partials.org-panel', ['canEdit' => $canEdit])
    @endif
</div>
@endsection
