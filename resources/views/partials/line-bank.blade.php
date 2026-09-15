@php
    /**
     * Shared "line bank" card on Company Settings: the dashboard greeting bank (CR-33) and the
     * easter-egg bank (CR-31). Rows stay server-rendered forms; Alpine only filters them (bucket
     * tabs, category chips, search) inside a capped scroll area, so a long bank never stretches
     * the page.
     *
     * Params: $title_en/$title_ms, $hint_en/$hint_ms, $empty_en/$empty_ms, $field ('trigger'|'kind'),
     * $routes ('admin.greetings'|'admin.eggs'), $lines (models), $categories [key => [label_en, label_ms, bucket?]],
     * $buckets [key => [en, ms]] (omit for no tabs), $defaultBucket, $canManage, $pending (models, optional).
     */
    $pending = $pending ?? collect();
    $buckets = $buckets ?? [];
    $bucketed = $buckets !== [];
    $catLabel = fn (string $key, string $lang): string => $categories[$key]['label_'.$lang] ?? $key;
    // Rows follow the category order the chips use, so "All" reads in the same sequence.
    $catOrder = array_flip(array_keys($categories));
    $lines = $lines->sortBy(fn ($l) => $catOrder[$l->{$field}] ?? PHP_INT_MAX)->values();
    $rows = $lines->map(fn ($l) => [
        'k' => $l->{$field},
        'b' => $bucketed ? ($categories[$l->{$field}]['bucket'] ?? $l->bucket) : '',
        'q' => mb_strtolower($l->text_en.' '.$l->text_ms.' '.$catLabel($l->{$field}, 'en').' '.$catLabel($l->{$field}, 'ms')),
    ]);
    $cats = collect($categories)->map(fn ($c, $key) => ['key' => $key, 'en' => $c['label_en'], 'ms' => $c['label_ms'], 'b' => $bucketed ? $c['bucket'] : ''])->values();
@endphp
<div class="uj-card uj-lb" x-data="{
        q: '', bucket: @js($defaultBucket ?? ''), cat: null, editId: null, adding: false, newCat: null, pendingOpen: false,
        rows: @js($rows), cats: @js($cats), bucketed: @js($bucketed),
        get term() { return this.q.trim().toLowerCase() },
        show(i) { const r = this.rows[i]; if (this.term) return r.q.includes(this.term); if (this.cat) return r.k === this.cat; return ! this.bucketed || r.b === this.bucket },
        get shown() { return this.rows.filter((r, i) => this.show(i)).length },
        count(k) { return this.rows.filter(r => r.k === k).length },
        countBucket(b) { return this.rows.filter(r => r.b === b).length },
        catsIn() { return this.cats.filter(c => ! this.bucketed || c.b === this.bucket) },
        pick(k) { this.cat = k; this.editId = null },
        add() { this.adding = ! this.adding; this.editId = null; this.newCat = this.cat || (this.catsIn()[0] || this.cats[0]).key },
    }">
    <div class="uj-lb-head">
        <h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? @js($title_en) : @js($title_ms)">{{ $title_en }}</h3>
        @if ($canManage)
            <button type="button" class="uj-btn-primary uj-lb-btn" @click="add()">
                <span x-text="adding ? ($store.ui.lang==='en' ? 'Cancel' : 'Batal') : ($store.ui.lang==='en' ? '+ Add line' : '+ Tambah baris')">+ Add line</span>
            </button>
        @endif
    </div>
    @include('partials.hint', ['en' => $hint_en, 'ms' => $hint_ms])

    @if ($canManage && $pending->isNotEmpty())
        <div class="uj-lb-pending">
            <div class="uj-lb-pending-bar">
                <span><span class="uj-lb-dot" aria-hidden="true"></span><b>{{ $pending->count() }}</b>
                    <span x-text="$store.ui.lang==='en' ? @js(\Illuminate\Support\Str::plural('suggestion', $pending->count()).' from staff waiting') : 'cadangan staf menunggu'">{{ \Illuminate\Support\Str::plural('suggestion', $pending->count()) }} from staff waiting</span></span>
                <button type="button" class="uj-btn-ghost uj-lb-btn" @click="pendingOpen = ! pendingOpen"
                        x-text="pendingOpen ? ($store.ui.lang==='en' ? 'Hide' : 'Sorok') : ($store.ui.lang==='en' ? 'Review' : 'Semak')">Review</button>
            </div>
            <div class="uj-lb-pending-list" x-show="pendingOpen" x-cloak>
                @foreach ($pending as $p)
                    <div class="uj-lb-row">
                        <div class="uj-lb-txt">
                            <div class="uj-lb-en">{{ $p->text_en }}</div>
                            <div class="uj-lb-ms">{{ $p->text_ms }}</div>
                            <div class="uj-lb-meta">{{ $catLabel($p->{$field}, 'en') }} · {{ $p->suggestedBy?->display_name ?? 'Unknown' }}</div>
                        </div>
                        <div class="uj-lb-acts">
                            <form method="post" action="{{ route($routes.'.update', $p) }}">@csrf<input type="hidden" name="approve" value="1"><button type="submit" class="uj-btn-ghost uj-lb-btn" x-text="$store.ui.lang==='en' ? 'Approve' : 'Luluskan'">Approve</button></form>
                            <form method="post" action="{{ route($routes.'.delete', $p) }}" onsubmit="return confirm('Delete this suggestion?')">@csrf
                                <button type="submit" class="uj-lb-icon uj-lb-del" title="Delete" aria-label="Delete"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14"/></svg></button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <div class="uj-lb-bar">
        <input type="search" class="uj-lb-search" x-model="q" placeholder="Search lines" :placeholder="$store.ui.lang==='en' ? 'Search lines' : 'Cari baris'" aria-label="Search lines">
        @if ($bucketed)
            <div class="uj-seg" role="group">
                @foreach ($buckets as $bucketKey => [$bucketEn, $bucketMs])
                    <button type="button" @if ($bucketKey === ($defaultBucket ?? null)) data-on @endif :data-on="bucket === @js($bucketKey) && ! term ? '' : null" @click="bucket = @js($bucketKey); q = ''; pick(null)">
                        <span x-text="$store.ui.lang==='en' ? @js($bucketEn) : @js($bucketMs)">{{ $bucketEn }}</span><span class="uj-lb-n" x-text="countBucket(@js($bucketKey))"></span>
                    </button>
                @endforeach
            </div>
        @endif
    </div>

    <div class="uj-lb-chips" x-show="! term">
        <button type="button" class="uj-lb-chip" :aria-pressed="cat === null" @click="pick(null)"><span x-text="$store.ui.lang==='en' ? 'All' : 'Semua'">All</span><b x-text="bucketed ? countBucket(bucket) : rows.length"></b></button>
        <template x-for="c in catsIn()" :key="c.key">
            <button type="button" class="uj-lb-chip" :aria-pressed="cat === c.key" @click="pick(c.key)"><span x-text="$store.ui.lang==='en' ? c.en : c.ms"></span><b x-text="count(c.key)"></b></button>
        </template>
    </div>

    <div class="uj-lb-list">
        @if ($canManage)
            <form x-show="adding" x-cloak method="post" action="{{ route($routes.'.store') }}" class="uj-lb-edit">
                @csrf
                <select name="{{ $field }}" required class="uj-lb-in" x-model="newCat">
                    @foreach ($categories as $key => $c)
                        <option value="{{ $key }}">{{ $c['label_en'] }} / {{ $c['label_ms'] }}</option>
                    @endforeach
                </select>
                <input name="text_en" required maxlength="200" placeholder="English line" class="uj-lb-in" />
                <input name="text_ms" required maxlength="200" placeholder="Baris Bahasa Melayu" class="uj-lb-in" />
                <div class="uj-lb-edit-acts">
                    <button type="submit" class="uj-btn-primary uj-lb-btn"><span x-text="$store.ui.lang==='en' ? 'Add line' : 'Tambah baris'">Add line</span></button>
                    <button type="button" class="uj-lb-cancel" @click="adding = false" x-text="$store.ui.lang==='en' ? 'Cancel' : 'Batal'">Cancel</button>
                </div>
            </form>
        @endif

        @foreach ($lines as $l)
            <div x-show="show({{ $loop->index }})" data-lb-row="{{ $l->{$field} }}">
                <div class="uj-lb-row" @if ($canManage) x-show="editId !== {{ $l->id }}" @endif>
                    <div class="uj-lb-txt">
                        <div class="uj-lb-en" title="{{ $l->text_en }}">{{ $l->text_en }}</div>
                        <div class="uj-lb-ms" title="{{ $l->text_ms }}">{{ $l->text_ms }}</div>
                    </div>
                    <span class="uj-lb-tag" x-show="term || cat === null" x-text="$store.ui.lang==='en' ? @js($catLabel($l->{$field}, 'en')) : @js($catLabel($l->{$field}, 'ms'))">{{ $catLabel($l->{$field}, 'en') }}</span>
                    @if ($canManage)
                        <div class="uj-lb-acts">
                            <button type="button" class="uj-lb-icon" title="Edit" aria-label="Edit" @click="editId = {{ $l->id }}; adding = false"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg></button>
                            <form method="post" action="{{ route($routes.'.delete', $l) }}" onsubmit="return confirm('Delete this line?')">@csrf
                                <button type="submit" class="uj-lb-icon uj-lb-del" title="Delete" aria-label="Delete"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14"/></svg></button>
                            </form>
                        </div>
                    @endif
                </div>
                @if ($canManage)
                    <form x-show="editId === {{ $l->id }}" x-cloak method="post" action="{{ route($routes.'.update', $l) }}" class="uj-lb-edit">
                        @csrf
                        <select name="{{ $field }}" required class="uj-lb-in">
                            @foreach ($categories as $key => $c)
                                <option value="{{ $key }}" @selected($l->{$field} === $key)>{{ $c['label_en'] }} / {{ $c['label_ms'] }}</option>
                            @endforeach
                        </select>
                        <input name="text_en" value="{{ $l->text_en }}" required maxlength="200" class="uj-lb-in" />
                        <input name="text_ms" value="{{ $l->text_ms }}" required maxlength="200" class="uj-lb-in" />
                        <div class="uj-lb-edit-acts">
                            <button type="submit" class="uj-btn-primary uj-lb-btn"><span x-text="$store.ui.lang==='en' ? 'Save' : 'Simpan'">Save</span></button>
                            <button type="button" class="uj-lb-cancel" @click="editId = null" x-text="$store.ui.lang==='en' ? 'Cancel' : 'Batal'">Cancel</button>
                        </div>
                    </form>
                @endif
            </div>
        @endforeach

        <div class="uj-lb-empty" x-show="shown === 0" @if ($lines->isNotEmpty()) x-cloak @endif>
            <span x-show="term" x-cloak x-text="($store.ui.lang==='en' ? 'No lines match “' : 'Tiada baris sepadan “') + q + '”'"></span>
            <span x-show="! term" x-text="$store.ui.lang==='en' ? @js($empty_en) : @js($empty_ms)">{{ $empty_en }}</span>
        </div>
    </div>
    <div class="uj-lb-foot" x-show="rows.length" x-text="($store.ui.lang==='en' ? 'Showing ' + shown + ' of ' : 'Memapar ' + shown + ' daripada ') + rows.length"></div>
</div>
