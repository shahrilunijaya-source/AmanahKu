@extends('layouts.app')

@php
    $u = auth()->user();
    $enabled = ! is_null($u->two_factor_secret);
    $confirmed = ! is_null($u->two_factor_confirmed_at);
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
</div>

<script>
function passkeyManager() {
    return {
        name: '', busy: false, msg: '', ok: false,
        async add() {
            const en = (Alpine.store('ui')?.lang ?? 'en') === 'en';
            this.msg = '';
            if (!window.Passkey || !window.Passkey.supported()) { this.ok = false; this.msg = en ? 'This browser does not support passkeys.' : 'Pelayar ini tidak menyokong passkey.'; return; }
            if (!this.name.trim()) { this.ok = false; this.msg = en ? 'Give the passkey a name first.' : 'Beri passkey ini nama dahulu.'; return; }
            this.busy = true;
            try {
                await window.Passkey.register(this.name.trim(), '{{ csrf_token() }}');
                this.ok = true; this.msg = en ? 'Passkey added.' : 'Passkey ditambah.';
                setTimeout(() => window.location.reload(), 700);
            } catch (e) {
                this.ok = false; this.msg = e.message || (en ? 'Could not add the passkey.' : 'Tidak dapat menambah passkey.');
            } finally {
                this.busy = false;
            }
        },
    };
}
</script>
@endsection
