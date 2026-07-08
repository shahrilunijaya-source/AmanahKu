@extends('layouts.app')

@php
    // Per-category presentation: icon path, accent colour, EN/MS label.
    $catMeta = [
        'email'   => ['icon' => 'M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zM22 7l-10 6L2 7', 'color' => 'var(--info)',    'en' => 'Email',          'ms' => 'E-mel'],
        'design'  => ['icon' => 'M12 19l7-7 3 3-7 7-3-3zM18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5zM2 2l7.586 7.586M11 13a2 2 0 1 0 0-4 2 2 0 0 0 0 4z', 'color' => 'var(--amber)', 'en' => 'Design',  'ms' => 'Reka Bentuk'],
        'comms'   => ['icon' => 'M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z', 'color' => 'var(--success)', 'en' => 'Communication', 'ms' => 'Komunikasi'],
        'system'  => ['icon' => 'M2 3h20v14H2zM8 21h8M12 17v4', 'color' => 'var(--red)',     'en' => 'System',         'ms' => 'Sistem'],
        'storage' => ['icon' => 'M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z', 'color' => 'var(--info)', 'en' => 'Storage', 'ms' => 'Storan'],
        'other'   => ['icon' => 'M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71', 'color' => 'var(--muted)', 'en' => 'Other', 'ms' => 'Lain-lain'],
    ];
    $catLabel = fn ($c) => $catMeta[$c] ?? $catMeta['other'];
    $fs = 'height:38px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;background:#fff;color:var(--ink);outline:none;width:100%;';
    $ta = 'width:100%;padding:10px 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;background:#fff;color:var(--ink);outline:none;resize:vertical;';
@endphp

@section('screen')

@include('partials.guide', [
    'key' => 'shared-resources',
    'en'  => [
        'title' => 'Shared resources',
        'body'  => 'The company accounts and tools everyone shares — the Gmail account, Canva, the WhatsApp number, the inhouse system and more. Find the login link and credentials for each one here, all in one place.',
        'who'   => 'Everyone can view · HR & managers maintain the list',
        'steps' => [
            'Find the resource you need and click "Open" to go to its login page.',
            'Use the copy buttons to grab the username or password without retyping.',
            'Read the notes for any extra access instructions (for example, where the 2FA code goes).',
        ],
    ],
    'ms'  => [
        'title' => 'Sumber bersama',
        'body'  => 'Akaun dan alat syarikat yang dikongsi semua — akaun Gmail, Canva, nombor WhatsApp, sistem dalaman dan lain-lain. Cari pautan log masuk dan butiran akaun untuk setiap satu di sini, dalam satu tempat.',
        'who'   => 'Semua boleh lihat · HR & pengurus menyelenggara senarai',
        'steps' => [
            'Cari sumber yang anda perlukan dan klik "Buka" untuk ke halaman log masuknya.',
            'Guna butang salin untuk ambil nama pengguna atau kata laluan tanpa menaip semula.',
            'Baca nota untuk arahan akses tambahan (contohnya, di mana kod 2FA dihantar).',
        ],
    ],
])

<div x-data="{
        show: {{ $errors->any() ? 'true' : 'false' }},
        mode: @js(old('_mode', 'add')),
        editId: {{ old('_edit_id') ?: 'null' }},
        f: {
            name: @js(old('name', '')),
            category: @js(old('category', 'other')),
            url: @js(old('url', '')),
            username: @js(old('username', '')),
            password: @js(old('password', '')),
            notes: @js(old('notes', '')),
            sort_order: {{ (int) old('sort_order', 0) }},
        },
        copied: null,
        openAdd() { this.mode = 'add'; this.editId = null; this.f = { name: '', category: 'other', url: '', username: '', password: '', notes: '', sort_order: 0 }; this.show = true; },
        openEdit(r) { this.mode = 'edit'; this.editId = r.id; this.f = { name: r.name || '', category: r.category || 'other', url: r.url || '', username: r.username || '', password: r.password || '', notes: r.notes || '', sort_order: r.sort_order || 0 }; this.show = true; },
        copy(t) { if (! t) return; navigator.clipboard.writeText(t); this.copied = t; setTimeout(() => { if (this.copied === t) this.copied = null; }, 1200); }
    }">

    @if ($canManage)
        <div style="display:flex;justify-content:flex-end;margin-bottom:14px;">
            <button @click="openAdd()" class="uj-btn-primary" style="height:38px;padding:0 16px;font-size:13px;"
                    x-text="$store.ui.lang==='en' ? '+ Add resource' : '+ Tambah sumber'">+ Add resource</button>
        </div>
    @endif

    @if ($resources->isEmpty())
        {{-- ───────────────────────── Empty state ───────────────────────── --}}
        <div class="uj-card" style="padding:40px 24px;text-align:center;">
            <div style="font-size:15px;font-weight:600;color:var(--ink);margin-bottom:4px;">
                <span x-text="$store.ui.lang==='en' ? 'No shared resources yet' : 'Belum ada sumber bersama'"></span>
            </div>
            <div style="font-size:13px;color:var(--muted);line-height:1.5;max-width:420px;margin:0 auto;">
                @if ($canManage)
                    <span x-text="$store.ui.lang==='en' ? 'Add the company accounts and tools your team shares — the Gmail account, Canva, WhatsApp, the inhouse system. Use \'+ Add resource\' above.' : 'Tambah akaun dan alat syarikat yang dikongsi pasukan anda — akaun Gmail, Canva, WhatsApp, sistem dalaman. Guna \'+ Tambah sumber\' di atas.'"></span>
                @else
                    <span x-text="$store.ui.lang==='en' ? 'Ask HR to add the shared company accounts here.' : 'Minta HR menambah akaun syarikat bersama di sini.'"></span>
                @endif
            </div>
        </div>
    @else
        {{-- ───────────────────────── Grouped resource cards ───────────────────────── --}}
        @foreach ($grouped as $category => $items)
            @php $m = $catLabel($category); @endphp
            <div style="margin-bottom:22px;">
                <div style="display:flex;align-items:center;gap:9px;margin:0 2px 11px;">
                    <span style="width:26px;height:26px;border-radius:7px;display:flex;align-items:center;justify-content:center;background:color-mix(in srgb, {{ $m['color'] }} 14%, #fff);flex-shrink:0;">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="{{ $m['color'] }}" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $m['icon'] }}"></path></svg>
                    </span>
                    <span style="font-size:11px;font-weight:700;letter-spacing:.6px;text-transform:uppercase;color:var(--muted);"
                          x-text="$store.ui.lang==='en' ? @js($m['en']) : @js($m['ms'])">{{ $m['en'] }}</span>
                    <span style="font-size:11px;font-weight:600;color:var(--muted-soft);">· {{ $items->count() }}</span>
                </div>

                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(290px,1fr));gap:14px;">
                    @foreach ($items as $r)
                        <div class="uj-card" style="padding:16px;display:flex;flex-direction:column;gap:11px;">
                            {{-- Header: name + open link --}}
                            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px;">
                                <div style="font-size:14.5px;font-weight:600;color:var(--ink);line-height:1.25;min-width:0;word-break:break-word;">{{ $r->name }}</div>
                                @if ($r->url)
                                    <a href="{{ $r->url }}" target="_blank" rel="noopener noreferrer"
                                       style="flex-shrink:0;display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:600;color:{{ $m['color'] }};text-decoration:none;padding:4px 9px;border-radius:7px;background:color-mix(in srgb, {{ $m['color'] }} 10%, #fff);">
                                        <span x-text="$store.ui.lang==='en' ? 'Open' : 'Buka'">Open</span>
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6M15 3h6v6M10 14L21 3"></path></svg>
                                    </a>
                                @endif
                            </div>

                            {{-- Credential rows --}}
                            @if ($r->username || $r->password)
                                <div style="display:flex;flex-direction:column;gap:8px;">
                                    @if ($r->username)
                                        <div style="display:flex;align-items:center;gap:8px;background:var(--canvas);border-radius:8px;padding:7px 9px;">
                                            <div style="min-width:0;flex:1;">
                                                <div style="font-size:9.5px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:var(--muted-soft);margin-bottom:1px;" x-text="$store.ui.lang==='en' ? 'Username' : 'Nama pengguna'">Username</div>
                                                <div style="font-size:12.5px;color:var(--ink);font-family:var(--font-mono);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $r->username }}</div>
                                            </div>
                                            <button type="button" @click="copy(@js($r->username))" title="Copy" style="flex-shrink:0;display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;color:var(--muted);background:#fff;border:1px solid var(--hairline);border-radius:6px;padding:4px 8px;cursor:pointer;">
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                                                <span x-text="copied === @js($r->username) ? ($store.ui.lang==='en' ? 'Copied' : 'Disalin') : ($store.ui.lang==='en' ? 'Copy' : 'Salin')">Copy</span>
                                            </button>
                                        </div>
                                    @endif
                                    @if ($r->password)
                                        <div style="display:flex;align-items:center;gap:8px;background:var(--canvas);border-radius:8px;padding:7px 9px;">
                                            <div style="min-width:0;flex:1;">
                                                <div style="font-size:9.5px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:var(--muted-soft);margin-bottom:1px;" x-text="$store.ui.lang==='en' ? 'Password' : 'Kata laluan'">Password</div>
                                                <div style="font-size:12.5px;color:var(--ink);font-family:var(--font-mono);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $r->password }}</div>
                                            </div>
                                            <button type="button" @click="copy(@js($r->password))" title="Copy" style="flex-shrink:0;display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;color:var(--muted);background:#fff;border:1px solid var(--hairline);border-radius:6px;padding:4px 8px;cursor:pointer;">
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                                                <span x-text="copied === @js($r->password) ? ($store.ui.lang==='en' ? 'Copied' : 'Disalin') : ($store.ui.lang==='en' ? 'Copy' : 'Salin')">Copy</span>
                                            </button>
                                        </div>
                                    @endif
                                </div>
                            @endif

                            {{-- Notes --}}
                            @if ($r->notes)
                                <div style="font-size:12px;color:var(--body);line-height:1.5;white-space:pre-line;">{{ $r->notes }}</div>
                            @endif

                            {{-- Privileged: edit --}}
                            @if ($canManage)
                                <div style="margin-top:auto;padding-top:4px;display:flex;justify-content:flex-end;">
                                    <button @click="openEdit({ id: {{ $r->id }}, name: @js($r->name), category: @js($r->category), url: @js($r->url), username: @js($r->username), password: @js($r->password), notes: @js($r->notes), sort_order: {{ (int) $r->sort_order }} })"
                                            class="uj-btn-ghost" style="height:30px;padding:0 12px;font-size:12px;"
                                            x-text="$store.ui.lang==='en' ? 'Edit' : 'Sunting'">Edit</button>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    @endif

    {{-- ───────────────────────── Add / Edit modal (privileged) — teleported to body so it centres ───────────────────────── --}}
    @if ($canManage)
        <template x-teleport="body">
            <div x-show="show" x-cloak @keydown.escape.window="show = false"
                 style="position:fixed;inset:0;z-index:1000;display:flex;align-items:center;justify-content:center;padding:20px;">
                <div @click="show = false" style="position:absolute;inset:0;background:rgba(0,0,0,.45);"></div>
                <div @click.stop class="uj-card" style="position:relative;width:100%;max-width:520px;margin:auto;max-height:90vh;overflow-y:auto;padding:22px;box-shadow:0 20px 60px rgba(0,0,0,.3);">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
                        <h3 class="uj-card-title" x-text="mode === 'add' ? ($store.ui.lang==='en' ? 'Add shared resource' : 'Tambah sumber bersama') : ($store.ui.lang==='en' ? 'Edit shared resource' : 'Sunting sumber bersama')">Add shared resource</h3>
                        <button @click="show = false" type="button" style="background:none;border:none;cursor:pointer;color:var(--muted);font-size:20px;line-height:1;">&times;</button>
                    </div>

                    @if ($errors->any())
                        <div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:9px 12px;margin-bottom:14px;">{{ $errors->first() }}</div>
                    @endif

                    <form method="post" :action="mode === 'add' ? '{{ route('shared-resources.store') }}' : ('{{ url('/app/shared-resources') }}/' + editId)">
                        @csrf
                        <input type="hidden" name="_mode" :value="mode" />
                        <input type="hidden" name="_edit_id" :value="editId" />

                        <div style="display:flex;flex-direction:column;gap:12px;">
                            <div>
                                <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Name *' : 'Nama *'">Name *</label>
                                <input name="name" x-model="f.name" required maxlength="120" placeholder="e.g. Company Gmail" :placeholder="$store.ui.lang==='en' ? 'e.g. Company Gmail' : 'cth. Gmail Syarikat'" style="{{ $fs }}" />
                            </div>
                            <div>
                                <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Category *' : 'Kategori *'">Category *</label>
                                <select name="category" x-model="f.category" required style="{{ $fs }}">
                                    @foreach ($categories as $c)
                                        @php $cm = $catLabel($c); @endphp
                                        <option value="{{ $c }}" x-text="$store.ui.lang==='en' ? @js($cm['en']) : @js($cm['ms'])">{{ $cm['en'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Login link (URL)' : 'Pautan log masuk (URL)'">Login link (URL)</label>
                                <input name="url" x-model="f.url" type="url" maxlength="255" placeholder="https://…" style="{{ $fs }}" />
                            </div>
                            <div>
                                <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Username / email' : 'Nama pengguna / e-mel'">Username / email</label>
                                <input name="username" x-model="f.username" maxlength="160" style="{{ $fs }}" />
                            </div>
                            <div>
                                <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Password' : 'Kata laluan'">Password</label>
                                <input name="password" x-model="f.password" maxlength="255" style="{{ $fs }}font-family:var(--font-mono);" />
                                @include('partials.hint', ['en' => 'Stored encrypted, but shown in full to every staff member on this page. Only put credentials here that the whole team is meant to share.', 'ms' => 'Disimpan secara terenkripsi, tetapi dipaparkan penuh kepada setiap staf di halaman ini. Letak hanya butiran yang memang untuk dikongsi seluruh pasukan.'])
                            </div>
                            <div>
                                <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Notes' : 'Nota'">Notes</label>
                                <textarea name="notes" x-model="f.notes" maxlength="2000" rows="3" placeholder="e.g. 2FA code goes to Yati's phone" :placeholder="$store.ui.lang==='en' ? 'e.g. 2FA code goes to Yati\'s phone' : 'cth. kod 2FA dihantar ke telefon Yati'" style="{{ $ta }}"></textarea>
                            </div>
                            <div>
                                <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Sort order' : 'Susunan'">Sort order</label>
                                <input name="sort_order" x-model="f.sort_order" type="number" min="0" max="9999" style="{{ $fs }}max-width:120px;" />
                            </div>
                        </div>

                        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:20px;">
                            <div>
                                <button x-show="mode === 'edit'" @click="if (confirm($store.ui.lang==='en' ? 'Delete this shared resource? This cannot be undone.' : 'Padam sumber bersama ini? Tindakan ini tidak boleh dibatalkan.')) $refs.delForm.submit()" type="button"
                                        style="height:40px;padding:0 14px;font-size:13px;font-weight:600;color:var(--red);background:none;border:1px solid var(--red);border-radius:9px;cursor:pointer;"
                                        x-text="$store.ui.lang==='en' ? 'Delete' : 'Padam'">Delete</button>
                            </div>
                            <div style="display:flex;gap:8px;">
                                <button @click="show = false" type="button" class="uj-btn-ghost" style="height:40px;padding:0 16px;font-size:13px;" x-text="$store.ui.lang==='en' ? 'Cancel' : 'Batal'">Cancel</button>
                                <button type="submit" class="uj-btn-primary" style="height:40px;padding:0 20px;font-size:13px;" x-text="$store.ui.lang==='en' ? 'Save' : 'Simpan'">Save</button>
                            </div>
                        </div>
                    </form>

                    {{-- Separate delete form (no body) — submitted by the Delete button above. --}}
                    <form x-ref="delForm" method="post" :action="'{{ url('/app/shared-resources') }}/' + editId + '/delete'" style="display:none;">
                        @csrf
                    </form>
                </div>
            </div>
        </template>
    @endif
</div>

@endsection
