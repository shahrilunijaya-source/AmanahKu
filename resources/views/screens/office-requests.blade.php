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
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
        <h1 style="font-size:19px;font-weight:600;color:var(--ink);margin:0;"
            x-text="$store.ui.lang==='en' ? 'Office Requests' : 'Permintaan Pejabat'">Office Requests</h1>
        <div style="display:flex;gap:10px;">
            @if ($orCanSeeInsights ?? false)
            <a href="{{ route('office-requests.insights') }}" class="uj-btn-ghost" style="height:34px;padding:0 13px;font-size:12.5px;display:inline-flex;align-items:center;"
               x-text="$store.ui.lang==='en' ? 'Insights' : 'Wawasan'">Insights</a>
            @endif
            <button type="button" @click="open = !open" class="uj-btn-primary" style="height:34px;padding:0 13px;font-size:12.5px;"
                    x-text="open ? ($store.ui.lang==='en' ? 'Cancel' : 'Batal') : ($store.ui.lang==='en' ? '+ New request' : '+ Permintaan baharu')">+ New request</button>
        </div>
    </div>

    {{-- ── Raise form ─────────────────────────────────────────────────── --}}
    <div class="uj-card" x-show="open" x-cloak style="padding:20px;margin-top:14px;">
        <form method="post" action="{{ route('office-requests.store') }}" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:14px;">
            @csrf
            <div>
                <label style="display:block;font-size:12.5px;font-weight:600;color:var(--ink);margin-bottom:7px;"
                       x-text="$store.ui.lang==='en' ? 'Category' : 'Kategori'">Category</label>
                <select name="category" x-model="category" required class="uj-lv-in">
                    @foreach ($orCategories as $c)
                        <option value="{{ $c }}" @selected(old('category') === $c)>{{ ucfirst($c) }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label style="display:block;font-size:12.5px;font-weight:600;color:var(--ink);margin-bottom:7px;"
                       x-text="$store.ui.lang==='en' ? 'Title' : 'Tajuk'">Title</label>
                <input name="title" x-model="title" @input.debounce.400ms="checkSimilar()" required maxlength="160" class="uj-lv-in" value="{{ old('title') }}">
                <template x-if="similar.length">
                    <div style="margin-top:8px;padding:10px 12px;border-radius:9px;background:var(--canvas);border:1px solid var(--hairline);">
                        <p style="font-size:12px;color:var(--muted);margin:0 0 6px;" x-text="$store.ui.lang==='en' ? 'Already raised — +1 instead?' : 'Sudah dimohon — +1 sahaja?'"></p>
                        <template x-for="s in similar" :key="s.id">
                            <div style="display:flex;align-items:center;justify-content:space-between;padding:4px 0;">
                                <span style="font-size:13px;" x-text="s.title + ' (' + s.votes + ')'"></span>
                                <button type="button" class="uj-btn-ghost" style="height:26px;padding:0 10px;font-size:11.5px;" @click="upvote(s.id)">+1</button>
                            </div>
                        </template>
                    </div>
                </template>
            </div>

            <div>
                <label style="display:block;font-size:12.5px;font-weight:600;color:var(--ink);margin-bottom:7px;"
                       x-text="$store.ui.lang==='en' ? 'Description' : 'Penerangan'">Description</label>
                <textarea name="description" required maxlength="2000" rows="3" class="uj-lv-in">{{ old('description') }}</textarea>
            </div>

            <div>
                <label style="display:block;font-size:12.5px;font-weight:600;color:var(--ink);margin-bottom:7px;"
                       x-text="$store.ui.lang==='en' ? 'Location' : 'Lokasi'">Location</label>
                <input name="location" required maxlength="160" class="uj-lv-in" value="{{ old('location') }}">
            </div>

            <div x-show="category === 'vehicle'" x-cloak style="display:flex;flex-direction:column;gap:14px;">
                <div>
                    <label style="display:block;font-size:12.5px;font-weight:600;color:var(--ink);margin-bottom:7px;"
                           x-text="$store.ui.lang==='en' ? 'Vehicle plate' : 'Plat kenderaan'">Vehicle plate</label>
                    <input name="vehicle_plate" maxlength="20" class="uj-lv-in" value="{{ old('vehicle_plate') }}">
                </div>
                <div>
                    <label style="display:block;font-size:12.5px;font-weight:600;color:var(--ink);margin-bottom:7px;"
                           x-text="$store.ui.lang==='en' ? 'Mileage' : 'Bacaan meter'">Mileage</label>
                    <input type="number" name="vehicle_mileage" min="0" class="uj-lv-in" value="{{ old('vehicle_mileage') }}">
                </div>
                <div>
                    <label style="display:block;font-size:12.5px;font-weight:600;color:var(--ink);margin-bottom:7px;"
                           x-text="$store.ui.lang==='en' ? 'Last service date' : 'Tarikh servis terakhir'">Last service date</label>
                    <input type="date" name="vehicle_last_service_at" class="uj-lv-in" value="{{ old('vehicle_last_service_at') }}">
                </div>
            </div>

            <div>
                <label style="display:block;font-size:12.5px;font-weight:600;color:var(--ink);margin-bottom:7px;"
                       x-text="$store.ui.lang==='en' ? 'Urgency' : 'Kesegeraan'">Urgency</label>
                <select name="urgency" x-model="urgency" required class="uj-lv-in">
                    @foreach ($orUrgencies as $u)
                        <option value="{{ $u }}" @selected(old('urgency', 'normal') === $u)>{{ ucfirst($u) }}</option>
                    @endforeach
                </select>
            </div>

            <div x-show="urgency === 'urgent'" x-cloak>
                <label style="display:block;font-size:12.5px;font-weight:600;color:var(--ink);margin-bottom:7px;"
                       x-text="$store.ui.lang==='en' ? 'Why is this urgent?' : 'Kenapa ini segera?'">Why is this urgent?</label>
                <textarea name="urgency_reason" :required="urgency === 'urgent'" maxlength="500" rows="2" class="uj-lv-in">{{ old('urgency_reason') }}</textarea>
                @error('urgency_reason')<p style="font-size:12px;color:var(--error);margin:7px 0 0;">{{ $message }}</p>@enderror
            </div>

            <div>
                <label style="display:block;font-size:12.5px;font-weight:600;color:var(--ink);margin-bottom:7px;"
                       x-text="$store.ui.lang==='en' ? 'Photo (optional)' : 'Gambar (pilihan)'">Photo</label>
                <input type="file" name="photo" accept="image/*" class="uj-lv-in">
            </div>

            <div style="display:flex;justify-content:flex-end;">
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
                                <span class="uj-stamp" style="font-size:10.5px;">{{ ucfirst($r->category) }}</span>
                                @if ($r->urgency === 'urgent')
                                    <span class="uj-stamp" data-tone="error">{{ $r->urgency === 'urgent' ? 'Urgent' : '' }}</span>
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
                            @if ($r->status === 'done' && $r->employee_id === ($employee->id ?? null))
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
        checkSimilar() {
            if (this.title.trim().length < 3) { this.similar = []; return; }
            fetch('{{ route('office-requests.similar') }}?title=' + encodeURIComponent(this.title))
                .then(r => r.json()).then(d => { this.similar = d; });
        },
        upvote(id) {
            fetch(`/app/office-requests/${id}/upvote`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'Accept': 'application/json' },
            }).then(() => window.location.reload());
        },
        promptNote(id) {
            const note = prompt(this.$store.ui.lang === 'en' ? 'Note for the requester:' : 'Nota untuk pemohon:');
            if (!note) return;
            fetch(`/app/office-requests/${id}/admin-note`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'Accept': 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify({ note }),
            }).then(() => window.location.reload());
        },
        promptDone(id) {
            const note = prompt(this.$store.ui.lang === 'en' ? 'Closing note:' : 'Nota penutup:');
            if (!note) return;
            fetch(`/app/office-requests/${id}/done`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'Accept': 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify({ note }),
            }).then(() => window.location.reload());
        },
        reopen(id) {
            fetch(`/app/office-requests/${id}/reopen`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'Accept': 'application/json' },
            }).then(() => window.location.reload());
        },
    };
}
</script>

@endsection
