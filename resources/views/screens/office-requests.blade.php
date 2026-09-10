@extends('layouts.app')

@php
    $statusMeta = [
        'open' => ['en' => 'Open', 'ms' => 'Buka', 'tone' => null],
        'in_progress' => ['en' => 'In Progress', 'ms' => 'Sedang Diproses', 'tone' => 'amber'],
        'done' => ['en' => 'Done', 'ms' => 'Selesai', 'tone' => 'success'],
    ];
    $urgencyTone = ['low' => null, 'normal' => null, 'urgent' => 'error'];
    $myVotes = $orMyVotes ?? collect();
    $adminIds = $orAdminRequestIds ?? collect();
@endphp

@section('screen')

@include('partials.guide', [
    'key' => 'office-requests',
    'en' => [
        'title' => 'Office Requests',
        'body' => 'Report an office issue (leaking aircond, printer out of toner) or ask for something the pantry needs. Everyone can +1 an existing request instead of raising a duplicate. The Admin team works the list and marks each one Done.',
        'who' => 'Anyone raises · Admin team resolves',
        'steps' => [
            'Click "+ New request", pick a category, and describe what is wrong or needed.',
            'Mark it Urgent only if it needs attention today — that pages the Finance Manager and Director.',
            'Others can +1 your request. The Admin team leaves a note and marks it Done when sorted.',
        ],
    ],
    'ms' => [
        'title' => 'Permintaan Pejabat',
        'body' => 'Laporkan isu pejabat (aircond bocor, dakwat pencetak habis) atau mohon keperluan pantri. Sesiapa boleh +1 permintaan sedia ada dari membuka satu lagi. Pasukan Admin uruskan senarai dan tandakan Selesai apabila siap.',
        'who' => 'Sesiapa boleh mohon · Pasukan Admin selesaikan',
        'steps' => [
            'Klik "+ Permintaan baharu", pilih kategori, dan terangkan apa yang rosak atau diperlukan.',
            'Tanda Segera hanya jika perlu perhatian hari ini — ini akan memaklumkan Pengurus Kewangan dan Pengarah.',
            'Orang lain boleh +1 permintaan anda. Pasukan Admin akan tinggalkan nota dan tanda Selesai apabila siap.',
        ],
    ],
])

<div class="uj-lv" x-data="officeRequests()">
    {{-- The guide above already carries the screen title; this row is just the actions. --}}
    <div style="display:flex;justify-content:flex-end;align-items:center;gap:10px;flex-wrap:wrap;">
        @if ($orCanSeeInsights ?? false)
        <a href="{{ route('office-requests.insights') }}" class="uj-btn-ghost" style="height:36px;padding:0 14px;font-size:12.5px;display:inline-flex;align-items:center;"
           x-text="$store.ui.lang==='en' ? 'Insights' : 'Wawasan'">Insights</a>
        @endif
        <button type="button" @click="open = !open" :class="open ? 'uj-btn-ghost' : 'uj-btn-primary'" class="uj-btn-primary" style="height:36px;padding:0 14px;font-size:12.5px;"
                x-text="open ? ($store.ui.lang==='en' ? 'Cancel' : 'Batal') : ($store.ui.lang==='en' ? '+ New request' : '+ Permintaan baharu')">+ New request</button>
    </div>

    {{-- ── Raise form ─────────────────────────────────────────────────── --}}
    <div class="uj-card uj-or-form" x-show="open" x-cloak>
        <div class="uj-or-form-head">
            <b x-text="$store.ui.lang==='en' ? 'New request' : 'Permintaan baharu'">New request</b>
            <span x-text="$store.ui.lang==='en' ? 'Say what is wrong or needed, and where. The Admin team picks it up from here.' : 'Nyatakan apa yang rosak atau diperlukan, dan di mana. Pasukan Admin ambil alih dari sini.'">Say what is wrong or needed, and where. The Admin team picks it up from here.</span>
        </div>
        <form method="post" action="{{ route('office-requests.store') }}" enctype="multipart/form-data" class="uj-or-fields">
            @csrf
            <div class="uj-lv-row2">
                <div>
                    <label class="uj-lv-field" for="or-category" x-text="$store.ui.lang==='en' ? 'Category' : 'Kategori'">Category</label>
                    <select id="or-category" name="category" x-model="category" required class="uj-lv-in">
                        @foreach ($orCategories as $c)
                            <option value="{{ $c }}" @selected(old('category') === $c) x-text="$store.ui.lang==='en' ? @js(\App\Models\OfficeRequest::CATEGORY_LABELS[$c][0]) : @js(\App\Models\OfficeRequest::CATEGORY_LABELS[$c][1])">{{ \App\Models\OfficeRequest::CATEGORY_LABELS[$c][0] }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="uj-lv-field" for="or-urgency" x-text="$store.ui.lang==='en' ? 'Urgency' : 'Kesegeraan'">Urgency</label>
                    <select id="or-urgency" name="urgency" x-model="urgency" required class="uj-lv-in">
                        @php $urgencyLabels = ['low' => ['Low', 'Rendah'], 'normal' => ['Normal', 'Biasa'], 'urgent' => ['Urgent', 'Segera']]; @endphp
                        @foreach ($orUrgencies as $u)
                            <option value="{{ $u }}" @selected(old('urgency', 'normal') === $u) x-text="$store.ui.lang==='en' ? @js($urgencyLabels[$u][0] ?? ucfirst($u)) : @js($urgencyLabels[$u][1] ?? ucfirst($u))">{{ $urgencyLabels[$u][0] ?? ucfirst($u) }}</option>
                        @endforeach
                    </select>
                    <p class="uj-or-hint" x-show="urgency !== 'urgent'" x-text="$store.ui.lang==='en' ? 'Urgent pages the Finance Manager and Director. Use it for today-only problems.' : 'Segera memaklumkan Pengurus Kewangan dan Pengarah. Guna untuk masalah hari ini sahaja.'"></p>
                </div>
            </div>

            <div x-show="urgency === 'urgent'" x-cloak>
                <label class="uj-lv-field" for="or-urgency-reason" x-text="$store.ui.lang==='en' ? 'Why is this urgent?' : 'Kenapa ini segera?'">Why is this urgent?</label>
                <textarea id="or-urgency-reason" name="urgency_reason" :required="urgency === 'urgent'" maxlength="500" rows="2" class="uj-lv-in"
                          :placeholder="$store.ui.lang==='en' ? 'One line on why it cannot wait until tomorrow.' : 'Satu baris kenapa ia tidak boleh tunggu esok.'">{{ old('urgency_reason') }}</textarea>
                @error('urgency_reason')<p class="uj-or-err">{{ $message }}</p>@enderror
            </div>

            <div>
                <label class="uj-lv-field" for="or-title" x-text="$store.ui.lang==='en' ? 'Title' : 'Tajuk'">Title</label>
                <input id="or-title" name="title" x-model="title" @input.debounce.400ms="checkSimilar()" required maxlength="160" class="uj-lv-in" value="{{ old('title') }}"
                       :placeholder="$store.ui.lang==='en' ? 'Short and searchable, e.g. Printer out of toner' : 'Pendek dan mudah dicari, cth. Dakwat pencetak habis'">
                <template x-if="similar.length">
                    <div class="uj-or-similar">
                        <p x-text="$store.ui.lang==='en' ? 'Already raised. +1 one of these instead?' : 'Sudah dimohon. +1 salah satu ini?'"></p>
                        <template x-for="s in similar" :key="s.id">
                            <div>
                                <span x-text="s.title + ' (' + s.votes + ')'"></span>
                                <button type="button" class="uj-btn-ghost" style="height:26px;padding:0 10px;font-size:11.5px;" @click="upvote(s.id)">+1</button>
                            </div>
                        </template>
                    </div>
                </template>
            </div>

            <div>
                <label class="uj-lv-field" for="or-description" x-text="$store.ui.lang==='en' ? 'Description' : 'Penerangan'">Description</label>
                <textarea id="or-description" name="description" required maxlength="2000" rows="3" class="uj-lv-in"
                          :placeholder="$store.ui.lang==='en' ? 'What happened, since when, anything Admin should know before they come.' : 'Apa yang berlaku, sejak bila, apa yang Admin perlu tahu sebelum datang.'">{{ old('description') }}</textarea>
            </div>

            <div>
                <label class="uj-lv-field" for="or-location" x-text="$store.ui.lang==='en' ? 'Location' : 'Lokasi'">Location</label>
                <input id="or-location" name="location" required maxlength="160" class="uj-lv-in" value="{{ old('location') }}"
                       :placeholder="$store.ui.lang==='en' ? 'e.g. Level 2 pantry, meeting room B' : 'cth. Pantri tingkat 2, bilik mesyuarat B'">
            </div>

            <div x-show="category === 'vehicle'" x-cloak class="uj-or-fields">
                <div class="uj-lv-row2">
                    <div>
                        <label class="uj-lv-field" for="or-plate" x-text="$store.ui.lang==='en' ? 'Vehicle plate' : 'Plat kenderaan'">Vehicle plate</label>
                        <input id="or-plate" name="vehicle_plate" maxlength="20" class="uj-lv-in" value="{{ old('vehicle_plate') }}" placeholder="WXY 1234">
                    </div>
                    <div>
                        <label class="uj-lv-field" for="or-mileage" x-text="$store.ui.lang==='en' ? 'Mileage' : 'Bacaan meter'">Mileage</label>
                        <input id="or-mileage" type="number" name="vehicle_mileage" min="0" class="uj-lv-in" value="{{ old('vehicle_mileage') }}" placeholder="km">
                    </div>
                </div>
                <div>
                    <label class="uj-lv-field" for="or-service" x-text="$store.ui.lang==='en' ? 'Last service date' : 'Tarikh servis terakhir'">Last service date</label>
                    <input id="or-service" type="date" name="vehicle_last_service_at" class="uj-lv-in" value="{{ old('vehicle_last_service_at') }}">
                </div>
            </div>

            <div>
                <label class="uj-lv-field" for="or-photo">
                    <span x-text="$store.ui.lang==='en' ? 'Photo' : 'Gambar'">Photo</span>
                    <span class="uj-lv-opt" x-text="$store.ui.lang==='en' ? '— optional' : '— pilihan'">— optional</span>
                </label>
                <div class="uj-lv-file">
                    <input type="file" id="or-photo" name="photo" accept="image/*">
                </div>
                <p class="uj-or-hint" x-text="$store.ui.lang==='en' ? 'A quick phone photo helps Admin bring the right thing the first time.' : 'Gambar telefon yang ringkas membantu Admin bawa barang yang betul pada kali pertama.'"></p>
            </div>

            <div class="uj-or-foot">
                <button type="button" class="uj-btn-ghost" style="height:38px;padding:0 16px;font-size:13px;" @click="open = false"
                        x-text="$store.ui.lang==='en' ? 'Cancel' : 'Batal'">Cancel</button>
                <button type="submit" class="uj-btn-primary" style="height:38px;padding:0 18px;font-size:13px;"
                        x-text="$store.ui.lang==='en' ? 'Submit request' : 'Hantar permintaan'">Submit request</button>
            </div>
        </form>
    </div>

    {{-- ── Pantry wishlist (top-voted, not yet done) ─────────────────── --}}
    @if (($orPantryWishlist ?? collect())->isNotEmpty())
    <div class="uj-card" style="margin-top:16px;">
        <div class="uj-card-head">
            <h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Pantry wishlist' : 'Senarai hajat pantri'">Pantry wishlist</h3>
        </div>
        @foreach ($orPantryWishlist as $r)
            <div style="display:flex;align-items:center;justify-content:space-between;padding:9px 20px;border-top:1px solid var(--hairline-soft);">
                <span style="font-size:13.5px;color:var(--ink);">{{ $r->title }}</span>
                <span style="font-size:12px;color:var(--muted);">{{ $r->votes }} {{ __('votes') }}</span>
            </div>
        @endforeach
    </div>
    @endif

    {{-- ── Board: Open / In Progress / Done ────────────────────────────── --}}
    @foreach ($statusMeta as $status => $meta)
        @php $bucket = ($orGrouped[$status] ?? collect()); @endphp
        <div class="uj-card" style="margin-top:16px;">
            <div class="uj-card-head">
                <h3 class="uj-card-title" style="display:flex;align-items:center;gap:8px;">
                    <span class="uj-stamp" @if ($meta['tone']) data-tone="{{ $meta['tone'] }}" @endif
                          x-text="$store.ui.lang==='en' ? @js($meta['en']) : @js($meta['ms'])">{{ $meta['en'] }}</span>
                    <span style="color:var(--muted);font-weight:500;font-size:13px;">· {{ $bucket->count() }}</span>
                </h3>
            </div>
            @forelse ($bucket as $r)
                <div style="padding:14px 20px;border-top:1px solid var(--hairline-soft);">
                    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px;">
                        <div>
                            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                <span style="font-size:14px;font-weight:600;color:var(--ink);">{{ $r->title }}</span>
                                <span class="uj-stamp" style="font-size:10.5px;" x-text="$store.ui.lang==='en' ? @js($r->categoryLabel()) : @js($r->categoryLabel(true))">{{ $r->categoryLabel() }}</span>
                                @if ($r->urgency === 'urgent')
                                    <span class="uj-stamp" data-tone="error" x-text="$store.ui.lang==='en' ? 'Urgent' : 'Segera'">Urgent</span>
                                @endif
                            </div>
                            <p style="font-size:12.5px;color:var(--body);margin:5px 0 0;">{{ $r->description }}</p>
                            <p style="font-size:11.5px;color:var(--muted);margin:4px 0 0;">{{ $r->location }} &middot; {{ optional($r->employee)->nickname ?? optional($r->employee)->name }}</p>
                            @if ($r->admin_note)
                                <p style="font-size:12px;color:var(--ink);background:var(--canvas);border-radius:7px;padding:6px 9px;margin:7px 0 0;display:inline-block;">
                                    <strong x-text="$store.ui.lang==='en' ? 'Note: ' : 'Nota: '">Note:</strong>{{ $r->admin_note }}
                                </p>
                            @endif
                            @if ($r->status === 'done' && $r->closing_note)
                                <p style="font-size:12px;color:var(--muted);margin:6px 0 0;">{{ $r->closing_note }}</p>
                            @endif
                        </div>
                        <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;flex-shrink:0;">
                            <button type="button" class="uj-btn-ghost" style="height:28px;padding:0 11px;font-size:11.5px;" :disabled="{{ $myVotes->contains($r->id) ? 'true' : 'false' }}" @click="upvote({{ $r->id }})">
                                &uarr; {{ $r->votes }}
                            </button>
                            @if ($adminIds->contains($r->id) && $r->status !== 'done')
                                <button type="button" class="uj-btn-ghost" style="height:26px;padding:0 10px;font-size:11px;" @click="promptNote({{ $r->id }})" x-text="$store.ui.lang==='en' ? 'Note' : 'Nota'">Note</button>
                                <button type="button" class="uj-btn-ghost" style="height:26px;padding:0 10px;font-size:11px;" @click="promptDone({{ $r->id }})" x-text="$store.ui.lang==='en' ? 'Done' : 'Selesai'">Done</button>
                            @endif
                            @if ($r->status === 'done' && $r->employee_id === ($employee->id ?? null) && $r->withinReopenWindow())
                                <button type="button" class="uj-btn-ghost" style="height:26px;padding:0 10px;font-size:11px;" @click="reopen({{ $r->id }})" x-text="$store.ui.lang==='en' ? 'Reopen' : 'Buka semula'">Reopen</button>
                            @endif
                        </div>
                    </div>
                </div>
            @empty
                <div class="uj-lv-empty">
                    <b x-text="$store.ui.lang==='en' ? 'Nothing here' : 'Tiada apa-apa di sini'"></b>
                </div>
            @endforelse
        </div>
    @endforeach
</div>

<script>
function officeRequests() {
    return {
        open: {{ $errors->any() ? 'true' : 'false' }},
        category: @js(old('category', 'pantry')),
        title: @js(old('title', '')),
        urgency: @js(old('urgency', 'normal')),
        similar: [],
        // QA F4: a refused action (422/403) shows the server's message instead of a silent reload.
        settle(r) {
            if (r.ok) { window.location.reload(); return; }
            r.json().then(d => alert(d.message || 'Something went wrong.')).catch(() => alert('Something went wrong.'));
        },
        checkSimilar() {
            if (this.title.trim().length < 3) { this.similar = []; return; }
            fetch('{{ route('office-requests.similar') }}?title=' + encodeURIComponent(this.title))
                .then(r => r.json()).then(d => { this.similar = d; });
        },
        upvote(id) {
            fetch(`/app/office-requests/${id}/upvote`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'Accept': 'application/json' },
            }).then(r => this.settle(r));
        },
        promptNote(id) {
            const note = prompt(this.$store.ui.lang === 'en' ? 'Note for the requester:' : 'Nota untuk pemohon:');
            if (!note) return;
            fetch(`/app/office-requests/${id}/admin-note`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'Accept': 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify({ note }),
            }).then(r => this.settle(r));
        },
        promptDone(id) {
            const note = prompt(this.$store.ui.lang === 'en' ? 'Closing note:' : 'Nota penutup:');
            if (!note) return;
            fetch(`/app/office-requests/${id}/done`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'Accept': 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify({ note }),
            }).then(r => this.settle(r));
        },
        reopen(id) {
            fetch(`/app/office-requests/${id}/reopen`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'Accept': 'application/json' },
            }).then(r => this.settle(r));
        },
    };
}
</script>

@endsection
