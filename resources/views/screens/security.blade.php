@extends('layouts.app')

@php
    $u = auth()->user();
    $enabled = ! is_null($u->two_factor_secret);
    $confirmed = ! is_null($u->two_factor_confirmed_at);
    $aiKey = app(\App\Tenancy\CurrentTenant::class)->id()
        ? $u->tokens()
            ->where('tenant_id', app(\App\Tenancy\CurrentTenant::class)->id())
            ->where('name', \App\Http\Controllers\SecurityController::AI_KEY_NAME)
            ->first()
        : null;
@endphp

@section('screen')
@include('partials.guide', [
    'key' => 'security',
    'en'  => [
        'title' => 'Sign-in security',
        'body'  => 'Protect your own account. Turn on two-factor authentication so a stolen password alone can\'t get into your account — sign-in will also ask for a one-time code from an app on your phone.',
        'who'   => 'Just you · settings for your own account',
        'steps' => [
            'Click "Enable two-factor", then scan the QR code with an authenticator app (Google Authenticator, 1Password, Authy).',
            'Enter the 6-digit code the app shows to confirm and switch it on.',
            'Save the recovery codes somewhere safe — each one lets you in once if you lose your phone.',
        ],
    ],
    'ms'  => [
        'title' => 'Keselamatan log masuk',
        'body'  => 'Lindungi akaun anda sendiri. Hidupkan two-factor authentication supaya password yang dicuri sahaja tidak boleh masuk ke akaun anda — log masuk juga akan minta kod sekali guna dari app pada telefon anda.',
        'who'   => 'Anda sahaja · tetapan untuk akaun anda sendiri',
        'steps' => [
            'Klik "Enable two-factor", kemudian imbas kod QR dengan authenticator app (Google Authenticator, 1Password, Authy).',
            'Masukkan kod 6 digit yang dipaparkan app untuk sahkan dan hidupkannya.',
            'Simpan recovery codes di tempat yang selamat — setiap satu membenarkan anda masuk sekali jika telefon anda hilang.',
        ],
    ],
])
<div style="max-width:640px;display:flex;flex-direction:column;gap:16px;">
    <div class="uj-card" style="padding:24px;">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;">
            <div>
                <h3 class="uj-card-title" style="margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Two-factor authentication' : 'Pengesahan dua faktor'">Two-factor authentication</h3>
                <p style="font-size:13px;color:var(--muted);margin:0;max-width:420px;line-height:1.5;" x-text="$store.ui.lang==='en' ? 'Require a one-time code from an authenticator app (Google Authenticator, 1Password, Authy) at sign-in.' : 'Wajibkan kod sekali guna dari authenticator app (Google Authenticator, 1Password, Authy) semasa log masuk.'">Require a one-time code from an authenticator app (Google Authenticator, 1Password, Authy) at sign-in.</p>
            </div>
            @php [$pc, $pbg, $pl, $plMs] = $confirmed ? ['var(--success)', '#e7f4ee', 'On', 'Hidup'] : ($enabled ? ['var(--amber)', '#fbf3e6', 'Pending', 'Menunggu'] : ['var(--muted)', 'var(--hairline-soft)', 'Off', 'Mati']); @endphp
            <span class="uj-pill" style="background:{{ $pbg }};color:{{ $pc }};white-space:nowrap;" x-text="$store.ui.lang==='en' ? @js($pl) : @js($plMs)">{{ $pl }}</span>
        </div>

        @if (! $enabled)
            <form method="post" action="{{ route('two-factor.enable') }}" style="margin-top:20px;">@csrf
                <button type="submit" class="uj-btn-primary" style="height:42px;padding:0 20px;font-size:13.5px;"><span x-text="$store.ui.lang==='en' ? 'Enable two-factor' : 'Hidupkan two-factor'">Enable two-factor</span></button>
            </form>

        @elseif (! $confirmed)
            <div x-data="{ qr: 'Loading…', secret: '', async init() {
                    this.qr = (await (await fetch('{{ route('two-factor.qr-code') }}', { headers: { Accept: 'application/json' } })).json()).svg;
                    this.secret = (await (await fetch('{{ route('two-factor.secret-key') }}', { headers: { Accept: 'application/json' } })).json()).secretKey;
                } }" style="margin-top:20px;">
                <p style="font-size:13px;color:var(--ink);margin:0 0 14px;" x-text="$store.ui.lang==='en' ? 'Scan this QR code, then enter the 6-digit code to finish enabling.' : 'Imbas kod QR ini, kemudian masukkan kod 6 digit untuk selesai menghidupkannya.'">Scan this QR code, then enter the 6-digit code to finish enabling.</p>
                <div style="display:flex;gap:20px;flex-wrap:wrap;align-items:flex-start;">
                    <div x-html="qr" style="width:160px;height:160px;background:#fff;border:1px solid var(--hairline);border-radius:10px;padding:8px;flex-shrink:0;"></div>
                    <div style="flex:1;min-width:220px;">
                        <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Setup key' : 'Kunci persediaan'">Setup key</div>
                        <div x-text="secret" style="font-size:13px;color:var(--ink);font-family:var(--font-mono);word-break:break-all;background:var(--canvas);border:1px solid var(--hairline);border-radius:7px;padding:9px 11px;margin-bottom:16px;"></div>
                        <form method="post" action="{{ route('two-factor.confirm') }}" style="display:flex;gap:9px;align-items:flex-end;">
                            @csrf
                            <div><label style="display:block;font-size:11px;color:var(--muted);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? '6-digit code' : 'Kod 6 digit'">6-digit code</label><input name="code" inputmode="numeric" autocomplete="one-time-code" placeholder="000000" required style="width:120px;height:40px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;font-family:var(--font-mono);letter-spacing:2px;text-align:center;outline:none;" /></div>
                            <button type="submit" class="uj-btn-primary" style="height:40px;padding:0 16px;font-size:13px;"><span x-text="$store.ui.lang==='en' ? 'Confirm' : 'Sahkan'">Confirm</span></button>
                        </form>
                        <div style="margin-top:8px;">@include('partials.hint', ['en' => 'The code changes every 30 seconds — type the one showing now. If it fails, wait for the next code and try again.', 'ms' => 'Kod bertukar setiap 30 saat — taip yang dipaparkan sekarang. Jika gagal, tunggu kod seterusnya dan cuba lagi.'])</div>
                    </div>
                </div>
                @error('code')<div style="color:var(--red);font-size:12.5px;margin-top:10px;">{{ $message }}</div>@enderror
                <form method="post" action="{{ route('two-factor.disable') }}" style="margin-top:16px;">@csrf @method('DELETE')<button type="submit" class="uj-btn-ghost" style="height:36px;padding:0 14px;font-size:12.5px;"><span x-text="$store.ui.lang==='en' ? 'Cancel setup' : 'Batal persediaan'">Cancel setup</span></button></form>
            </div>

        @else
            <div x-data="{ codes: [], async init() { this.codes = await (await fetch('{{ route('two-factor.recovery-codes') }}', { headers: { Accept: 'application/json' } })).json(); } }" style="margin-top:20px;">
                <p style="font-size:13px;color:var(--ink);margin:0 0 12px;" x-text="$store.ui.lang==='en' ? 'Store these recovery codes somewhere safe. Each works once if you lose your authenticator.' : 'Simpan recovery codes ini di tempat yang selamat. Setiap satu berfungsi sekali jika anda kehilangan authenticator.'">Store these recovery codes somewhere safe. Each works once if you lose your authenticator.</p>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:8px;background:var(--canvas);border:1px solid var(--hairline);border-radius:9px;padding:14px;margin-bottom:14px;">
                    <template x-for="code in codes" :key="code"><span x-text="code" style="font-size:12.5px;font-family:var(--font-mono);color:var(--ink);"></span></template>
                </div>
                <form method="post" action="{{ route('two-factor.regenerate-recovery-codes') }}">@csrf<button type="submit" class="uj-btn-ghost" style="height:36px;padding:0 14px;font-size:12.5px;"><span x-text="$store.ui.lang==='en' ? 'Regenerate codes' : 'Jana semula kod'">Regenerate codes</span></button></form>
            </div>
            <div style="margin-top:18px;padding-top:16px;border-top:1px solid var(--hairline-soft);">@include('partials.hint', ['en' => 'Turning off two-factor makes your account easier to break into. Only do this if you must — for example before switching to a new phone.', 'ms' => 'Mematikan two-factor menjadikan akaun anda lebih mudah dicerobohi. Buat ini hanya jika terpaksa — contohnya sebelum bertukar ke telefon baharu.', 'tone' => 'warn'])</div>
            <form method="post" action="{{ route('security.2fa.disable') }}" style="display:flex;gap:9px;align-items:flex-end;flex-wrap:wrap;">@csrf
                <div><label style="display:block;font-size:11px;color:var(--muted);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Confirm your password to turn off' : 'Sahkan password anda untuk matikan'">Confirm your password to turn off</label><input type="password" name="password" required autocomplete="current-password" style="height:38px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;width:200px;outline:none;" /></div>
                <button type="submit" style="height:38px;padding:0 16px;font-size:13px;font-weight:500;color:var(--red);background:var(--red-tint);border:1px solid var(--red);border-radius:8px;"><span x-text="$store.ui.lang==='en' ? 'Turn off two-factor' : 'Matikan two-factor'">Turn off two-factor</span></button>
                @error('password')<div style="flex-basis:100%;color:var(--red);font-size:12.5px;">{{ $message }}</div>@enderror
            </form>
        @endif
    </div>

    @if ($passkeyEnabled ?? true)
    <div class="uj-card" style="padding:24px;" x-data="passkeyManager()">
        <h3 class="uj-card-title" style="margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Passkeys' : 'Passkey'">Passkeys</h3>
        <p style="font-size:13px;color:var(--muted);margin:0 0 16px;line-height:1.5;" x-text="$store.ui.lang==='en' ? 'Passwordless sign-in with FaceID, Windows Hello or a security key. Requires the app to be served over HTTPS (or localhost) from its configured origin.' : 'Log masuk tanpa password dengan FaceID, Windows Hello atau security key. Memerlukan aplikasi disajikan melalui HTTPS (atau localhost) dari origin yang ditetapkan.'">Passwordless sign-in with FaceID, Windows Hello or a security key. Requires the app to be served over HTTPS (or localhost) from its configured origin.</p>

        @forelse (auth()->user()->passkeys()->latest()->get() as $pk)
            <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:11px 0;border-top:1px solid var(--hairline-soft);">
                <div>
                    <div style="font-size:13.5px;color:var(--ink);font-weight:500;">{{ $pk->name }}</div>
                    <div style="font-size:11.5px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'Added' : 'Ditambah'">Added</span> {{ $pk->created_at?->diffForHumans() }}@if ($pk->last_used_at) · <span x-text="$store.ui.lang==='en' ? 'last used' : 'guna terakhir'">last used</span> {{ $pk->last_used_at->diffForHumans() }}@endif</div>
                </div>
                <form method="post" action="{{ url('/user/passkeys/'.$pk->id) }}" @submit="if (! confirm($store.ui.lang==='en' ? 'Remove this passkey?' : 'Buang passkey ini?')) $event.preventDefault()">
                    @csrf @method('DELETE')
                    <button type="submit" class="uj-btn-ghost" style="height:32px;padding:0 11px;font-size:12px;color:var(--error);"><span x-text="$store.ui.lang==='en' ? 'Remove' : 'Buang'">Remove</span></button>
                </form>
            </div>
        @empty
            <div style="font-size:12.5px;color:var(--muted);padding:6px 0 12px;"><span x-text="$store.ui.lang==='en' ? 'No passkeys registered yet.' : 'Belum ada passkey didaftarkan.'">No passkeys registered yet.</span></div>
        @endforelse

        <div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--hairline);">
            <div style="display:flex;gap:9px;align-items:flex-end;flex-wrap:wrap;">
                <div>
                    <label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Passkey name' : 'Nama passkey'">Passkey name</label>
                    <input x-model="name" :placeholder="$store.ui.lang==='en' ? 'e.g. My laptop' : 'cth. Laptop saya'" style="height:38px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;background:#fff;color:var(--ink);outline:none;" />
                </div>
                <button type="button" @click="add()" :disabled="busy" class="uj-btn-primary" style="height:38px;padding:0 16px;font-size:13px;" x-text="busy ? ($store.ui.lang==='en' ? 'Waiting…' : 'Menunggu…') : ($store.ui.lang==='en' ? 'Add passkey' : 'Tambah passkey')"></button>
            </div>
            <div x-show="msg" x-text="msg" :style="ok ? { color:'var(--success)' } : { color:'var(--error)' }" style="font-size:12px;margin-top:10px;" x-cloak></div>
        </div>
    </div>
    @endif

    @php
        $ap = auth()->user()->appearance ?? [];
        $apChoice = $ap['wallpaper'] ?? 'none';
        $apPath = $ap['wallpaper_path'] ?? null;
        $apUrl = $apPath ? \Illuminate\Support\Facades\Storage::disk('public')->url($apPath) : null;
    @endphp
    {{-- Personal workspace wallpaper. Picking a tile saves at once and swaps the
         wallpaper behind this page in place; there is no Save button to scroll to. --}}
    <div class="uj-card" id="appearance" style="padding:24px;"
         x-data="appearanceCard({
            url: @js(route('account.appearance')),
            deleteUrl: @js(route('account.appearance.photo.destroy')),
            choice: @js($apChoice),
            dim: @js($ap['dim'] ?? 'soft'),
            photoUrl: @js($apUrl),
            photoLum: @js($ap['wallpaper_lum'] ?? null),
            presets: @js(config('amanahku.wallpaper_presets')),
            lums: @js(array_map(fn (string $css) => \App\Support\Tone::ofCss($css), config('amanahku.wallpaper_presets'))),
            dims: @js(config('amanahku.wallpaper_dims')),
            canvasLum: @js(\App\Support\Tone::CANVAS),
            darkBelow: @js(\App\Support\Tone::DARK_BELOW),
         })">
        <h3 class="uj-card-title" style="margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Appearance' : 'Penampilan'">Appearance</h3>
        <p style="font-size:13px;color:var(--muted);margin:0 0 16px;line-height:1.5;" x-text="$store.ui.lang==='en' ? 'A background for your workspace. Only you see it.' : 'Latar belakang untuk ruang kerja anda. Hanya anda yang melihatnya.'">A background for your workspace. Only you see it.</p>

        <div class="uj-wp-grid">
            <button type="button" class="uj-wp-tile uj-wp-tile--none" data-wallpaper="none" :data-on="choice === 'none'" @click="pick('none')">
                <span class="uj-wp-name" x-text="$store.ui.lang==='en' ? 'None' : 'Tiada'">None</span>
            </button>
            {{-- Always rendered (not gated on an existing photo) so x-if stays
                 reactive to a first-ever upload with no reload. data-wallpaper
                 is Alpine-bound rather than a static attribute so the server
                 HTML never literally contains data-wallpaper="upload" before
                 an upload happens; it's a client-rendered marker only. --}}
            <template x-if="photoUrl">
                <button type="button" class="uj-wp-tile" :data-wallpaper="'upload'" :data-on="choice === 'upload'" :style="'background-image:url(' + photoUrl + ')'" @click="pick('upload')">
                    <span class="uj-wp-name" x-text="$store.ui.lang==='en' ? 'Your photo' : 'Foto anda'">Your photo</span>
                </button>
            </template>
            @foreach (config('amanahku.wallpaper_presets') as $key => $css)
                @php
                    $presetNames = [
                        'dawn' => ['Dawn', 'Subuh'],
                        'dusk' => ['Dusk', 'Senja'],
                        'paper' => ['Paper', 'Kertas'],
                        'moss' => ['Moss', 'Lumut'],
                        'slate' => ['Slate', 'Batu'],
                        'sand' => ['Sand', 'Pasir'],
                    ][$key] ?? [ucfirst($key), ucfirst($key)];
                @endphp
                <button type="button" class="uj-wp-tile" data-wallpaper="preset:{{ $key }}" :data-on="choice === 'preset:{{ $key }}'" style="background:{{ $css }};" @click="pick('preset:{{ $key }}')">
                    <span class="uj-wp-name" x-text="$store.ui.lang==='en' ? '{{ $presetNames[0] }}' : '{{ $presetNames[1] }}'">{{ $presetNames[0] }}</span>
                </button>
            @endforeach
            <label class="uj-wp-tile uj-wp-tile--upload">
                <input type="file" accept="image/jpeg,image/png,image/webp" style="display:none;" @change="upload($event)">
                <b>+</b>
                <span x-text="busy ? ($store.ui.lang==='en' ? 'Uploading…' : 'Memuat naik…') : ($store.ui.lang==='en' ? 'Upload photo' : 'Muat naik foto')">Upload photo</span>
            </label>
        </div>

        <div style="display:flex;align-items:center;gap:14px;margin-top:18px;padding-top:16px;border-top:1px solid var(--hairline-soft);font-size:12.5px;flex-wrap:wrap;">
            <span style="color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Dim' : 'Malap'">Dim</span>
            <div class="uj-seg">
                <button type="button" :data-on="dim === 'none'" @click="setDim('none')" x-text="$store.ui.lang==='en' ? 'None' : 'Tiada'">None</button>
                <button type="button" :data-on="dim === 'soft'" @click="setDim('soft')" x-text="$store.ui.lang==='en' ? 'Soft' : 'Lembut'">Soft</button>
                <button type="button" :data-on="dim === 'strong'" @click="setDim('strong')" x-text="$store.ui.lang==='en' ? 'Strong' : 'Kuat'">Strong</button>
            </div>
            <span style="margin-left:auto;font-size:11.5px;color:var(--muted-soft);" x-show="!photoUrl" x-text="$store.ui.lang==='en' ? 'JPEG, PNG, WebP · 5 MB' : 'JPEG, PNG, WebP · 5 MB'">JPEG, PNG, WebP · 5 MB</span>
            <button type="button" x-show="photoUrl" x-cloak class="uj-btn-ghost" style="margin-left:auto;height:32px;font-size:12px;padding:0 12px;border:0;color:var(--muted);" @click="removePhoto()">
                <span x-text="$store.ui.lang==='en' ? 'Remove photo' : 'Buang foto'">Remove photo</span>
            </button>
            <p x-show="error" x-cloak x-text="error" style="flex-basis:100%;margin:0;color:var(--error);font-size:12px;"></p>
        </div>
    </div>

    <div class="uj-card" style="padding:24px;">
        <h3 class="uj-card-title" style="margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'AI access key' : 'Kunci akses AI'">AI access key</h3>
        <p style="font-size:13px;color:var(--muted);margin:0 0 16px;line-height:1.5;" x-text="$store.ui.lang==='en'
                ? 'Let a Claude Code assistant on your own computer read your timesheets, board cards and TOT sessions. Do not commit this key to code or share it with anyone; treat it like a password. Generating a new key immediately switches off the old one.'
                : 'Benarkan Claude Code pada komputer anda sendiri membaca timesheet, kad board dan sesi TOT anda. Jangan commit kunci ini ke dalam kod atau kongsi dengan sesiapa; layan seperti password. Menjana kunci baharu terus mematikan kunci lama.'">
            Let a Claude Code assistant on your own computer read your timesheets, board cards and TOT sessions. Do not commit this key to code or share it with anyone; treat it like a password. Generating a new key immediately switches off the old one.
        </p>
        <a href="{{ route('docs.mcp') }}" style="display:inline-block;font-size:12.5px;font-weight:600;margin:-8px 0 16px;" x-text="$store.ui.lang==='en' ? 'New to this? Read the guide →' : 'Baru dengan ini? Baca panduan →'">New to this? Read the guide →</a>

        @if (session('aiKeyPlaintext'))
            <div style="background:#fbeaeb;border:1px solid #f3c6c8;border-radius:10px;padding:14px 16px;margin-bottom:18px;">
                <div style="font-weight:600;color:#a81820;font-size:13.5px;" x-text="$store.ui.lang==='en' ? 'Copy this key now — it will not be shown again' : 'Salin kunci ini sekarang — ia tidak akan dipaparkan lagi'">Copy this key now — it will not be shown again</div>
                <div id="ai-key-plaintext" style="font-family:var(--font-mono);font-size:12.5px;word-break:break-all;background:#fff;border:1px solid #f3c6c8;border-radius:8px;padding:11px;margin-top:9px;">{{ session('aiKeyPlaintext') }}</div>
                <button type="button" class="uj-btn-ghost" style="height:32px;padding:0 12px;font-size:12px;margin-top:8px;" onclick="navigator.clipboard.writeText(document.getElementById('ai-key-plaintext').textContent.trim());this.querySelector('span').textContent='Copied';">
                    <span x-text="$store.ui.lang==='en' ? 'Copy key' : 'Salin kunci'">Copy key</span>
                </button>

                <div style="font-size:11.5px;color:#a81820;margin:14px 0 5px;" x-text="$store.ui.lang==='en' ? 'Ready-to-paste command for Claude Code:' : 'Arahan sedia-tampal untuk Claude Code:'">Ready-to-paste command for Claude Code:</div>
                <div id="ai-key-command" style="font-family:var(--font-mono);font-size:11.5px;word-break:break-all;background:#fff;border:1px solid #f3c6c8;border-radius:8px;padding:11px;">{{ session('aiKeyCommand') }}</div>
                <button type="button" class="uj-btn-ghost" style="height:32px;padding:0 12px;font-size:12px;margin-top:8px;" onclick="navigator.clipboard.writeText(document.getElementById('ai-key-command').textContent.trim());this.querySelector('span').textContent='Copied';">
                    <span x-text="$store.ui.lang==='en' ? 'Copy command' : 'Salin arahan'">Copy command</span>
                </button>
            </div>
        @endif

        @if ($aiKey)
            <div style="font-size:12.5px;color:var(--muted);margin-bottom:14px;">
                <span x-text="$store.ui.lang==='en' ? 'Created' : 'Dijana'">Created</span> {{ $aiKey->created_at?->diffForHumans() }}
                · <span x-text="$store.ui.lang==='en' ? 'last used' : 'guna terakhir'">last used</span>
                @if ($aiKey->last_used_at)
                    {{ $aiKey->last_used_at->diffForHumans() }}
                @else
                    <span x-text="$store.ui.lang==='en' ? 'never' : 'tidak pernah'">never</span>
                @endif
            </div>
            <div style="display:flex;gap:9px;flex-wrap:wrap;">
                <form method="post" action="{{ route('security.ai-key.revoke') }}" @submit="if (! confirm($store.ui.lang==='en' ? 'Revoke your AI access key?' : 'Batalkan kunci akses AI anda?')) $event.preventDefault()">
                    @csrf
                    <button type="submit" class="uj-btn-ghost" style="height:36px;padding:0 14px;font-size:12.5px;color:var(--error);"><span x-text="$store.ui.lang==='en' ? 'Revoke key' : 'Batalkan kunci'">Revoke key</span></button>
                </form>
            </div>
            <div style="margin-top:16px;padding-top:14px;border-top:1px solid var(--hairline-soft);">
                <form method="post" action="{{ route('security.ai-key.generate') }}" style="display:flex;flex-direction:column;gap:11px;">
                    @csrf
                    <label style="display:flex;align-items:flex-start;gap:8px;font-size:12.5px;color:var(--ink);cursor:pointer;">
                        <input type="checkbox" name="allow_writes" value="1" style="margin-top:2px;" />
                        <span x-text="$store.ui.lang==='en'
                                ? 'Also let it make changes (create and edit cards, assign tasks, save timesheet drafts, post external TOT events). It always asks before each change.'
                                : 'Juga benarkan ia membuat perubahan (cipta dan edit kad, tugaskan tugasan, simpan draf timesheet, siarkan acara TOT luaran). Ia sentiasa bertanya sebelum setiap perubahan.'">
                            Also let it make changes (create and edit cards, assign tasks, save timesheet drafts, post external TOT events). It always asks before each change.
                        </span>
                    </label>
                    <div style="display:flex;gap:9px;align-items:flex-end;flex-wrap:wrap;">
                        <div><label style="display:block;font-size:11px;color:var(--muted);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Confirm your password to replace it' : 'Sahkan password anda untuk gantikannya'">Confirm your password to replace it</label><input type="password" name="password" required autocomplete="current-password" style="height:38px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;width:200px;outline:none;" /></div>
                        <button type="submit" class="uj-btn-primary" style="height:38px;padding:0 16px;font-size:13px;"><span x-text="$store.ui.lang==='en' ? 'Generate new key' : 'Jana kunci baharu'">Generate new key</span></button>
                        @error('password')<div style="flex-basis:100%;color:var(--red);font-size:12.5px;">{{ $message }}</div>@enderror
                    </div>
                </form>
            </div>
        @else
            <form method="post" action="{{ route('security.ai-key.generate') }}" style="display:flex;flex-direction:column;gap:11px;">
                @csrf
                <label style="display:flex;align-items:flex-start;gap:8px;font-size:12.5px;color:var(--ink);cursor:pointer;">
                    <input type="checkbox" name="allow_writes" value="1" style="margin-top:2px;" />
                    <span x-text="$store.ui.lang==='en'
                            ? 'Also let it make changes (create and edit cards, assign tasks, save timesheet drafts, post external TOT events). It always asks before each change.'
                            : 'Juga benarkan ia membuat perubahan (cipta dan edit kad, tugaskan tugasan, simpan draf timesheet, siarkan acara TOT luaran). Ia sentiasa bertanya sebelum setiap perubahan.'">
                        Also let it make changes (create and edit cards, assign tasks, save timesheet drafts, post external TOT events). It always asks before each change.
                    </span>
                </label>
                <div style="display:flex;gap:9px;align-items:flex-end;flex-wrap:wrap;">
                    <div><label style="display:block;font-size:11px;color:var(--muted);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Confirm your password to generate' : 'Sahkan password anda untuk jana'">Confirm your password to generate</label><input type="password" name="password" required autocomplete="current-password" style="height:38px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;width:200px;outline:none;" /></div>
                    <button type="submit" class="uj-btn-primary" style="height:38px;padding:0 16px;font-size:13px;"><span x-text="$store.ui.lang==='en' ? 'Generate key' : 'Jana kunci'">Generate key</span></button>
                    @error('password')<div style="flex-basis:100%;color:var(--red);font-size:12.5px;">{{ $message }}</div>@enderror
                </div>
            </form>
        @endif
    </div>
</div>
@endsection
