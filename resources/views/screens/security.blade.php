@extends('layouts.app')

@php
    $u = auth()->user();
    $enabled = ! is_null($u->two_factor_secret);
    $confirmed = ! is_null($u->two_factor_confirmed_at);
    $currentTenant = app(\App\Tenancy\CurrentTenant::class);
    $aiKey = $currentTenant->id()
        ? $u->tokens()
            ->where('tenant_id', $currentTenant->id())
            ->where('name', \App\Http\Controllers\SecurityController::AI_KEY_NAME)
            ->first()
        : null;
    $aiCanWrite = $aiKey && in_array('board:write', $aiKey->abilities ?? [], true);

    /*
     * One section is shown at a time. ?section= is the source of truth because every
     * form on this screen posts and comes back via back(), which keeps the query string
     * (a #hash never reaches the server). A flash from one of those posts outranks it,
     * and a workspace that requires 2FA opens on Security so the person can enrol.
     */
    $sections = ['account', 'security', 'appearance', 'ai'];
    $tfaRequired = $currentTenant->get()
        && app(\App\Services\FeatureManager::class)->value($currentTenant->get(), 'security.2fa') === 'required';
    $section = match (true) {
        session()->has('aiKeyPlaintext') => 'ai',
        $errors->updatePassword->isNotEmpty() || session('status') === 'password-updated' => 'account',
        $errors->confirmTwoFactorAuthentication->isNotEmpty() => 'security',
        in_array(request('section'), $sections, true) => request('section'),
        $tfaRequired && ! $confirmed => 'security',
        default => 'account',
    };

    /** Bilingual inline label, same x-text pattern as the rest of the app. */
    $bi = fn (string $en, string $ms) => new \Illuminate\Support\HtmlString(
        '<span x-text="$store.ui.lang===\'en\' ? '.\Illuminate\Support\Js::from($en).' : '.\Illuminate\Support\Js::from($ms).'">'.e($en).'</span>'
    );

    $ap = $u->appearance ?? [];
    $apChoice = $ap['wallpaper'] ?? 'none';
    $apPath = $ap['wallpaper_path'] ?? null;
    $apUrl = $apPath ? \Illuminate\Support\Facades\Storage::disk('public')->url($apPath) : null;
@endphp

@section('screen')
@include('partials.guide', [
    'key' => 'security',
    'en'  => [
        'title' => 'Your settings',
        'body'  => 'Everything here is about your own account: your password, sign-in protection, the background behind your workspace, and the key that lets an AI assistant read your work. Nobody else sees these settings.',
        'who'   => 'Just you · settings for your own account',
        'steps' => [
            'Under Security, click "Turn on two-factor", then scan the QR code with an authenticator app (Google Authenticator, 1Password, Authy).',
            'Enter the 6-digit code the app shows to confirm and switch it on.',
            'Save the recovery codes somewhere safe. Each one lets you in once if you lose your phone.',
        ],
    ],
    'ms'  => [
        'title' => 'Tetapan anda',
        'body'  => 'Semua di sini tentang akaun anda sendiri: password, perlindungan log masuk, latar belakang ruang kerja anda, dan kunci yang membenarkan pembantu AI membaca kerja anda. Tiada orang lain melihat tetapan ini.',
        'who'   => 'Anda sahaja · tetapan untuk akaun anda sendiri',
        'steps' => [
            'Di bawah Keselamatan, klik "Hidupkan two-factor", kemudian imbas kod QR dengan authenticator app (Google Authenticator, 1Password, Authy).',
            'Masukkan kod 6 digit yang dipaparkan app untuk sahkan dan hidupkannya.',
            'Simpan recovery codes di tempat yang selamat. Setiap satu membenarkan anda masuk sekali jika telefon anda hilang.',
        ],
    ],
])

<div class="uj-set"
     x-data="{
        section: @js($section),
        open(s) {
            this.section = s;
            const url = new URL(window.location.href);
            url.searchParams.set('section', s);
            url.hash = '';
            {{-- replaceState, not pushState: a section switch is not a new screen, and
                 partial-nav's popstate handler only owns entries it tagged itself. --}}
            history.replaceState({ partialNav: true }, '', url);
        },
        init() {
            {{-- Old bookmarks and links pointed at #appearance. --}}
            if (window.location.hash === '#appearance') { this.open('appearance'); }
            {{-- On phones the rail is a sideways strip; bring the open tab into view. --}}
            this.$nextTick(() => {
                const rail = this.$el.querySelector('.uj-set-rail');
                const tab = rail.querySelector('[aria-current=page]');
                if (tab && rail.scrollWidth > rail.clientWidth) { rail.scrollLeft += tab.getBoundingClientRect().left - rail.getBoundingClientRect().left - 16; }
            });
        },
     }">
    <nav class="uj-set-rail" aria-label="Settings sections">
        <a href="?section=account" class="uj-set-tab" :aria-current="section === 'account' ? 'page' : null" @click.prevent="open('account')" @if ($section === 'account') aria-current="page" @endif>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z"/></svg>
            {{ $bi('Account', 'Akaun') }}
        </a>
        <a href="?section=security" class="uj-set-tab" :aria-current="section === 'security' ? 'page' : null" @click.prevent="open('security')" @if ($section === 'security') aria-current="page" @endif>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            {{ $bi('Security', 'Keselamatan') }}
            @unless ($confirmed)
                <span class="uj-stamp" data-tone="amber">{{ $bi('2FA OFF', '2FA MATI') }}</span>
            @endunless
        </a>
        <a href="?section=appearance" class="uj-set-tab" :aria-current="section === 'appearance' ? 'page' : null" @click.prevent="open('appearance')" @if ($section === 'appearance') aria-current="page" @endif>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
            {{ $bi('Background', 'Latar belakang') }}
        </a>
        <a href="?section=ai" class="uj-set-tab" :aria-current="section === 'ai' ? 'page' : null" @click.prevent="open('ai')" @if ($section === 'ai') aria-current="page" @endif>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="7.5" cy="15.5" r="5.5"/><path d="M11.4 11.6L21 2M15.5 7.5l3 3M18 5l2 2"/></svg>
            {{ $bi('AI access', 'Akses AI') }}
            @if ($aiKey)
                <span class="uj-stamp">{{ $bi('ON', 'HIDUP') }}</span>
            @endif
        </a>
    </nav>

    {{-- ═══ Account ═══ --}}
    <section class="uj-set-panel" x-show="section === 'account'" @if ($section !== 'account') style="display:none;" @endif>
        <div class="uj-set-intro">
            <h2>{{ $bi('Account', 'Akaun') }}</h2>
            <p>{{ $bi('Who you are signed in as, and your password.', 'Siapa anda semasa log masuk, dan password anda.') }}</p>
        </div>

        <div class="uj-set-card">
            <div class="uj-set-card-head"><div>
                <h3>{{ $bi('Profile', 'Profil') }}</h3>
                <p>{{ $bi('Your name and job details are kept by HR.', 'Nama dan butiran kerja anda diurus oleh HR.') }}</p>
            </div></div>
            <div class="uj-set-body">
                <dl class="uj-set-kv">
                    <dt>{{ $bi('Name', 'Nama') }}</dt><dd>{{ $employee?->display_name ?: $u->name }}</dd>
                    <dt>{{ $bi('Email', 'Emel') }}</dt><dd>{{ $u->email }}</dd>
                    <dt>{{ $bi('Role', 'Peranan') }}</dt><dd>{{ collect([$roleLabel ?? null, $employee?->position])->filter()->implode(' · ') ?: '—' }}</dd>
                    <dt>{{ $bi('Workspace', 'Ruang kerja') }}</dt><dd>{{ $tenant['name'] ?? '—' }}</dd>
                </dl>
            </div>
            <div class="uj-set-foot">
                <span>{{ $bi('Something wrong here? Ask HR to update it.', 'Ada yang salah? Minta HR kemas kini.') }}</span>
                @if ($employee)
                    <a href="{{ route('app.screen', ['screen' => 'profile', 'emp' => $employee->id]) }}" class="uj-btn-ghost uj-set-btn">{{ $bi('Open my profile', 'Buka profil saya') }}</a>
                @endif
            </div>
        </div>

        @if ($employee)
            <form method="post" action="{{ route('security.birthday-privacy') }}" class="uj-set-card">
                @csrf
                <div class="uj-set-card-head"><div>
                    <h3>{{ $bi('Birthday privacy', 'Privasi hari lahir') }}</h3>
                    <p>{{ $bi('Hides your birthday from the calendar, the dashboard and birthday wishes.', 'Sembunyikan hari lahir anda daripada kalendar, papan pemuka dan ucapan hari lahir.') }}</p>
                </div></div>
                <div class="uj-set-body">
                    <input type="hidden" name="birthday_private" value="0" />
                    <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--ink);">
                        <input type="checkbox" name="birthday_private" value="1" @checked($employee->birthday_private) style="width:16px;height:16px;" />
                        <span x-text="$store.ui.lang==='en' ? 'Keep my birthday private' : 'Rahsiakan hari lahir saya'">Keep my birthday private</span>
                    </label>
                </div>
                <div class="uj-set-foot">
                    <button type="submit" class="uj-btn-primary uj-set-btn">{{ $bi('Save', 'Simpan') }}</button>
                </div>
            </form>
        @endif

        <form method="post" action="{{ route('user-password.update') }}" class="uj-set-card">
            @csrf @method('PUT')
            <div class="uj-set-card-head"><div>
                <h3>{{ $bi('Password', 'Password') }}</h3>
                <p>{{ $bi('Change the password you sign in with. You stay signed in on this device.', 'Tukar password yang anda guna untuk log masuk. Anda kekal log masuk pada peranti ini.') }}</p>
            </div></div>
            <div class="uj-set-body uj-set-fields">
                <div>
                    <label class="uj-set-label" for="pw-current">{{ $bi('Current password', 'Password semasa') }}</label>
                    <input id="pw-current" type="password" name="current_password" required autocomplete="current-password" class="uj-set-input uj-field">
                    @if ($errors->updatePassword->has('current_password'))<p class="uj-set-err">{{ $errors->updatePassword->first('current_password') }}</p>@endif
                </div>
                <div>
                    <label class="uj-set-label" for="pw-new">{{ $bi('New password', 'Password baharu') }}</label>
                    <input id="pw-new" type="password" name="password" required autocomplete="new-password" class="uj-set-input uj-field">
                    @if ($errors->updatePassword->has('password'))<p class="uj-set-err">{{ $errors->updatePassword->first('password') }}</p>@endif
                </div>
                <div>
                    <label class="uj-set-label" for="pw-confirm">{{ $bi('Type it again', 'Taip sekali lagi') }}</label>
                    <input id="pw-confirm" type="password" name="password_confirmation" required autocomplete="new-password" class="uj-set-input uj-field">
                </div>
            </div>
            <div class="uj-set-foot">
                @if (session('status') === 'password-updated')
                    <span class="uj-set-ok">{{ $bi('Password updated.', 'Password dikemas kini.') }}</span>
                @else
                    <span>{{ $bi('At least 10 characters, with upper and lower case and a number.', 'Sekurang-kurangnya 10 aksara, dengan huruf besar, huruf kecil dan nombor.') }}</span>
                @endif
                <button type="submit" class="uj-btn-primary uj-set-btn">{{ $bi('Update password', 'Kemas kini password') }}</button>
            </div>
        </form>
    </section>

    {{-- ═══ Security ═══ --}}
    <section class="uj-set-panel" x-show="section === 'security'" @if ($section !== 'security') style="display:none;" @endif>
        <div class="uj-set-intro">
            <h2>{{ $bi('Security', 'Keselamatan') }}</h2>
            <p>{{ $bi('Extra protection so a stolen password alone can\'t get into your account.', 'Perlindungan tambahan supaya password yang dicuri sahaja tidak boleh masuk ke akaun anda.') }}</p>
        </div>

        <div class="uj-set-card">
            <div class="uj-set-card-head">
                <div>
                    <h3>{{ $bi('Two-factor authentication', 'Pengesahan dua faktor') }}</h3>
                    <p>{{ $bi('Sign-in also asks for a 6-digit code from an app on your phone (Google Authenticator, 1Password, Authy).', 'Log masuk juga meminta kod 6 digit dari app pada telefon anda (Google Authenticator, 1Password, Authy).') }}</p>
                </div>
                @if ($confirmed)
                    <span class="uj-stamp" data-tone="success">{{ $bi('ON', 'HIDUP') }}</span>
                @elseif ($enabled)
                    <span class="uj-stamp" data-tone="amber">{{ $bi('SETTING UP', 'SEDANG DISEDIAKAN') }}</span>
                @else
                    <span class="uj-stamp" data-tone="amber">{{ $bi('OFF', 'MATI') }}</span>
                @endif
            </div>

            @if (! $enabled)
                <div class="uj-set-gap"></div>
                <form method="post" action="{{ route('two-factor.enable') }}" class="uj-set-foot">@csrf
                    <span>{{ $tfaRequired ? $bi('Your workspace requires this before you can carry on.', 'Ruang kerja anda mewajibkan ini sebelum anda boleh teruskan.') : $bi('Takes about a minute.', 'Mengambil masa kira-kira seminit.') }}</span>
                    <button type="submit" class="uj-btn-primary uj-set-btn">{{ $bi('Turn on two-factor', 'Hidupkan two-factor') }}</button>
                </form>

            @elseif (! $confirmed)
                <div x-data="{ qr: '', secret: '', copied: false, async init() {
                        this.qr = (await (await fetch('{{ route('two-factor.qr-code') }}', { headers: { Accept: 'application/json' } })).json()).svg;
                        this.secret = (await (await fetch('{{ route('two-factor.secret-key') }}', { headers: { Accept: 'application/json' } })).json()).secretKey;
                    } }">
                    <form method="post" action="{{ route('two-factor.confirm') }}" id="tfa-confirm">@csrf</form>
                    <div class="uj-set-body">
                        <div class="uj-set-enrol">
                            <div class="uj-set-qr" x-html="qr" aria-label="QR code"></div>
                            <div class="uj-set-steps">
                                <p>{{ $bi('1. Scan the code with your authenticator app, or type the setup key.', '1. Imbas kod dengan authenticator app anda, atau taip kunci persediaan.') }}</p>
                                <div class="uj-set-secret">
                                    <code x-text="secret"></code>
                                    <button type="button" class="uj-btn-ghost uj-set-copy" @click="navigator.clipboard.writeText(secret); copied = true; setTimeout(() => copied = false, 1600)"
                                            x-text="copied ? ($store.ui.lang==='en' ? 'Copied' : 'Disalin') : ($store.ui.lang==='en' ? 'Copy' : 'Salin')">Copy</button>
                                </div>
                                <label class="uj-set-label" for="tfa-code" style="margin:4px 0 0;font-weight:400;font-size:var(--t-base);">{{ $bi('2. Enter the 6-digit code the app shows now.', '2. Masukkan kod 6 digit yang dipaparkan app sekarang.') }}</label>
                                <input id="tfa-code" form="tfa-confirm" name="code" inputmode="numeric" autocomplete="one-time-code" placeholder="000000" required class="uj-set-input uj-field uj-set-otp">
                                @if ($errors->confirmTwoFactorAuthentication->has('code'))
                                    <p class="uj-set-err">{{ $errors->confirmTwoFactorAuthentication->first('code') }}</p>
                                @endif
                                <p class="uj-set-note">{{ $bi('The code changes every 30 seconds. If it fails, wait for the next one and try again.', 'Kod bertukar setiap 30 saat. Jika gagal, tunggu kod seterusnya dan cuba lagi.') }}</p>
                            </div>
                        </div>
                    </div>
                    <div class="uj-set-foot">
                        <form method="post" action="{{ route('two-factor.disable') }}">@csrf @method('DELETE')
                            <button type="submit" class="uj-btn-ghost uj-set-btn">{{ $bi('Cancel setup', 'Batal persediaan') }}</button>
                        </form>
                        <button type="submit" form="tfa-confirm" class="uj-btn-primary uj-set-btn">{{ $bi('Confirm and turn on', 'Sahkan dan hidupkan') }}</button>
                    </div>
                </div>

            @else
                <div class="uj-set-body" x-data="{ codes: [], copied: false, async init() { this.codes = await (await fetch('{{ route('two-factor.recovery-codes') }}', { headers: { Accept: 'application/json' } })).json(); } }">
                    <div class="uj-set-subhead">
                        <span class="uj-set-label" style="margin:0;">{{ $bi('Recovery codes', 'Kod pemulihan') }}</span>
                        <span>{{ $bi('Each one lets you in once if you lose your phone.', 'Setiap satu membenarkan anda masuk sekali jika telefon anda hilang.') }}</span>
                    </div>
                    <div class="uj-set-codes"><template x-for="code in codes" :key="code"><span x-text="code"></span></template></div>
                    <div class="uj-set-actions">
                        <button type="button" class="uj-btn-ghost uj-set-copy" @click="navigator.clipboard.writeText(codes.join('\n')); copied = true; setTimeout(() => copied = false, 1600)"
                                x-text="copied ? ($store.ui.lang==='en' ? 'Copied' : 'Disalin') : ($store.ui.lang==='en' ? 'Copy codes' : 'Salin kod')">Copy codes</button>
                        <form method="post" action="{{ route('two-factor.regenerate-recovery-codes') }}">@csrf
                            <button type="submit" class="uj-btn-ghost uj-set-copy">{{ $bi('Make new codes', 'Jana kod baharu') }}</button>
                        </form>
                    </div>
                </div>
                <div x-data="{ off: @js($errors->has('password') && $section === 'security') }">
                    <div class="uj-set-foot" x-show="! off">
                        <span>{{ $bi('Switching phones? Turn it off first, then set it up again.', 'Tukar telefon? Matikan dahulu, kemudian sediakan semula.') }}</span>
                        <button type="button" class="uj-btn-ghost uj-set-btn uj-set-danger" @click="off = true; $nextTick(() => $refs.pw.focus())">{{ $bi('Turn off…', 'Matikan…') }}</button>
                    </div>
                    <form method="post" action="{{ route('security.2fa.disable') }}" class="uj-set-foot" x-show="off" x-cloak>@csrf
                        <div class="uj-set-inline">
                            <label class="uj-set-label" for="tfa-off-pw">{{ $bi('Confirm your password to turn it off', 'Sahkan password anda untuk matikan') }}</label>
                            <input id="tfa-off-pw" x-ref="pw" type="password" name="password" required autocomplete="current-password" class="uj-set-input uj-field">
                            @error('password')<p class="uj-set-err">{{ $message }}</p>@enderror
                        </div>
                        <div class="uj-set-actions">
                            <button type="button" class="uj-btn-ghost uj-set-btn" @click="off = false">{{ $bi('Keep it on', 'Kekalkan') }}</button>
                            <button type="submit" class="uj-btn-ghost uj-set-btn uj-set-danger">{{ $bi('Turn off two-factor', 'Matikan two-factor') }}</button>
                        </div>
                    </form>
                </div>
            @endif
        </div>

        @if ($passkeyEnabled ?? true)
            @php $passkeys = $u->passkeys()->latest()->get(); @endphp
            <div class="uj-set-card" x-data="passkeyManager()">
                <div class="uj-set-card-head"><div>
                    <h3>{{ $bi('Passkeys', 'Passkey') }}</h3>
                    <p>{{ $bi('Sign in without a password using Face ID, Windows Hello or a security key.', 'Log masuk tanpa password dengan Face ID, Windows Hello atau security key.') }}</p>
                </div></div>
                <div class="uj-set-body">
                    @forelse ($passkeys as $pk)
                        <div class="uj-set-row">
                            <span class="uj-set-ico" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="7.5" cy="15.5" r="5.5"/><path d="M11.4 11.6L21 2M15.5 7.5l3 3M18 5l2 2"/></svg></span>
                            <div class="uj-set-row-main">
                                <div class="uj-set-row-title">{{ $pk->name }}</div>
                                <div class="uj-set-row-sub">{{ $bi('Added', 'Ditambah') }} {{ $pk->created_at?->diffForHumans() }}@if ($pk->last_used_at) · {{ $bi('last used', 'guna terakhir') }} {{ $pk->last_used_at->diffForHumans() }}@endif</div>
                            </div>
                            <form method="post" action="{{ url('/user/passkeys/'.$pk->id) }}" @submit="if (! confirm($store.ui.lang==='en' ? 'Remove this passkey?' : 'Buang passkey ini?')) $event.preventDefault()">
                                @csrf @method('DELETE')
                                <button type="submit" class="uj-btn-ghost uj-set-copy uj-set-danger">{{ $bi('Remove', 'Buang') }}</button>
                            </form>
                        </div>
                    @empty
                        <p class="uj-set-empty">{{ $bi('No passkeys yet. Add one below to sign in with your face, fingerprint or device PIN.', 'Belum ada passkey. Tambah satu di bawah untuk log masuk dengan wajah, cap jari atau PIN peranti anda.') }}</p>
                    @endforelse
                </div>
                <div class="uj-set-foot">
                    <input x-model="name" @keydown.enter.prevent="add()" aria-label="Passkey name" :placeholder="$store.ui.lang==='en' ? 'Name it, e.g. My laptop' : 'Beri nama, cth. Laptop saya'" class="uj-set-input uj-field uj-set-foot-input">
                    <span x-show="msg" x-text="msg" :class="ok ? 'uj-set-ok' : 'uj-set-err'" style="margin:0;" x-cloak></span>
                    <button type="button" @click="add()" :disabled="busy" class="uj-btn-ghost uj-set-btn"
                            x-text="busy ? ($store.ui.lang==='en' ? 'Waiting…' : 'Menunggu…') : ($store.ui.lang==='en' ? 'Add passkey' : 'Tambah passkey')">Add passkey</button>
                </div>
            </div>
        @endif
    </section>

    {{-- ═══ Background ═══ --}}
    {{-- Personal workspace wallpaper. Picking a tile saves at once and swaps the
         wallpaper behind this page in place; there is no Save button to scroll to. --}}
    <section class="uj-set-panel" id="appearance" x-show="section === 'appearance'" @if ($section !== 'appearance') style="display:none;" @endif>
        <div class="uj-set-intro">
            <h2>{{ $bi('Background', 'Latar belakang') }}</h2>
            <p>{{ $bi('A backdrop behind your workspace. Picking one saves straight away. Only you see it.', 'Latar di belakang ruang kerja anda. Pilihan terus disimpan. Hanya anda yang melihatnya.') }}</p>
        </div>
        <div class="uj-set-card"
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
            <div class="uj-set-body uj-set-body--top">
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
            </div>
            <div class="uj-set-foot">
                <div class="uj-set-actions" style="margin:0;">
                    <span>{{ $bi('Dim', 'Malap') }}</span>
                    <div class="uj-seg">
                        <button type="button" :data-on="dim === 'none'" @click="setDim('none')" x-text="$store.ui.lang==='en' ? 'None' : 'Tiada'">None</button>
                        <button type="button" :data-on="dim === 'soft'" @click="setDim('soft')" x-text="$store.ui.lang==='en' ? 'Soft' : 'Lembut'">Soft</button>
                        <button type="button" :data-on="dim === 'strong'" @click="setDim('strong')" x-text="$store.ui.lang==='en' ? 'Strong' : 'Kuat'">Strong</button>
                    </div>
                </div>
                <span x-show="!photoUrl">{{ $bi('JPEG, PNG or WebP, up to 10 MB', 'JPEG, PNG atau WebP, sehingga 10 MB') }}</span>
                <button type="button" x-show="photoUrl" x-cloak class="uj-btn-ghost uj-set-btn" @click="removePhoto()">{{ $bi('Remove photo', 'Buang foto') }}</button>
                <p x-show="error" x-cloak x-text="error" class="uj-set-err" style="flex-basis:100%;margin:0;"></p>
            </div>
        </div>
    </section>

    {{-- ═══ AI access ═══ --}}
    <section class="uj-set-panel" x-show="section === 'ai'" @if ($section !== 'ai') style="display:none;" @endif>
        <div class="uj-set-intro">
            <h2>{{ $bi('AI access', 'Akses AI') }}</h2>
            <p>
                {{ $bi('Let Claude Code on your own computer read your timesheets, board cards and TOT sessions.', 'Benarkan Claude Code pada komputer anda sendiri membaca timesheet, kad board dan sesi TOT anda.') }}
                <a href="{{ route('docs.mcp') }}" class="uj-set-link">{{ $bi('Read the setup guide', 'Baca panduan persediaan') }}</a>
            </p>
        </div>

        @if (session('aiKeyPlaintext'))
            <div class="uj-set-notice" role="status">
                <div class="uj-set-notice-head">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0zM12 9v4M12 17h.01"/></svg>
                    <div>
                        <strong>{{ $bi('Copy this key now. It will not be shown again.', 'Salin kunci ini sekarang. Ia tidak akan dipaparkan lagi.') }}</strong>
                        <small>{{ $bi('Treat it like a password. Never paste it into code or share it.', 'Layan seperti password. Jangan tampal ke dalam kod atau kongsi.') }}</small>
                    </div>
                </div>
                <div x-data="{ copied: false }">
                    <span class="uj-set-label">{{ $bi('Key', 'Kunci') }}</span>
                    <div class="uj-set-secret">
                        <code x-ref="v" id="ai-key-plaintext">{{ session('aiKeyPlaintext') }}</code>
                        <button type="button" class="uj-btn-ghost uj-set-copy" @click="navigator.clipboard.writeText($refs.v.textContent.trim()); copied = true; setTimeout(() => copied = false, 1600)"
                                x-text="copied ? ($store.ui.lang==='en' ? 'Copied' : 'Disalin') : ($store.ui.lang==='en' ? 'Copy key' : 'Salin kunci')">Copy key</button>
                    </div>
                </div>
                <div x-data="{ copied: false }">
                    <span class="uj-set-label">{{ $bi('Or paste this into your terminal', 'Atau tampal ini ke terminal anda') }}</span>
                    <div class="uj-set-secret">
                        <code x-ref="v" id="ai-key-command">{{ session('aiKeyCommand') }}</code>
                        <button type="button" class="uj-btn-ghost uj-set-copy" @click="navigator.clipboard.writeText($refs.v.textContent.trim()); copied = true; setTimeout(() => copied = false, 1600)"
                                x-text="copied ? ($store.ui.lang==='en' ? 'Copied' : 'Disalin') : ($store.ui.lang==='en' ? 'Copy command' : 'Salin arahan')">Copy command</button>
                    </div>
                </div>
            </div>
        @endif

        <div class="uj-set-card" x-data="{ replace: @js(! $aiKey || ($errors->has('password') && $section === 'ai')) }">
            <div class="uj-set-card-head">
                <div>
                    <h3>{{ $bi('AI access key', 'Kunci akses AI') }}</h3>
                    <p>{{ $bi('One key per person. Making a new one switches the old one off straight away.', 'Satu kunci setiap orang. Menjana kunci baharu terus mematikan kunci lama.') }}</p>
                </div>
                @if ($aiKey)
                    <span class="uj-stamp" data-tone="success">{{ $bi('ACTIVE', 'AKTIF') }}</span>
                @else
                    <span class="uj-stamp">{{ $bi('NO KEY', 'TIADA KUNCI') }}</span>
                @endif
            </div>

            @if ($aiKey)
                <div class="uj-set-body">
                    <dl class="uj-set-kv">
                        <dt>{{ $bi('Can', 'Boleh') }}</dt>
                        <dd>{{ $aiCanWrite ? $bi('Read and make changes', 'Baca dan buat perubahan') : $bi('Read only', 'Baca sahaja') }}</dd>
                        <dt>{{ $bi('Created', 'Dijana') }}</dt>
                        <dd>{{ $aiKey->created_at?->diffForHumans() }}</dd>
                        <dt>{{ $bi('Last used', 'Guna terakhir') }}</dt>
                        <dd>{{ $aiKey->last_used_at ? $aiKey->last_used_at->diffForHumans() : $bi('Never', 'Tidak pernah') }}</dd>
                    </dl>
                </div>
                <div class="uj-set-foot" x-show="! replace">
                    <form method="post" action="{{ route('security.ai-key.revoke') }}" @submit="if (! confirm($store.ui.lang==='en' ? 'Revoke your AI access key?' : 'Batalkan kunci akses AI anda?')) $event.preventDefault()">
                        @csrf
                        <button type="submit" class="uj-btn-ghost uj-set-btn uj-set-danger">{{ $bi('Revoke key', 'Batalkan kunci') }}</button>
                    </form>
                    <button type="button" class="uj-btn-ghost uj-set-btn" @click="replace = true">{{ $bi('Replace key…', 'Gantikan kunci…') }}</button>
                </div>
            @endif

            <form method="post" action="{{ route('security.ai-key.generate') }}" x-show="replace" @if ($aiKey && ! ($errors->has('password') && $section === 'ai')) style="display:none;" @endif>
                @csrf
                <div class="uj-set-body {{ $aiKey ? 'uj-set-body--rule' : '' }} uj-set-fields uj-set-fields--wide">
                    <fieldset class="uj-set-fieldset">
                        <legend class="uj-set-label">{{ $bi('What can it do?', 'Apa yang ia boleh buat?') }}</legend>
                        <div class="uj-set-choice">
                            <label class="uj-set-opt">
                                <input type="radio" name="allow_writes" value="0" @checked(! old('allow_writes'))>
                                <span><b>{{ $bi('Read only', 'Baca sahaja') }}</b><small>{{ $bi('Looks things up. Changes nothing. Recommended.', 'Mencari maklumat. Tidak mengubah apa-apa. Disyorkan.') }}</small></span>
                            </label>
                            <label class="uj-set-opt">
                                <input type="radio" name="allow_writes" value="1" @checked(old('allow_writes'))>
                                <span><b>{{ $bi('Read and make changes', 'Baca dan buat perubahan') }}</b><small>{{ $bi('Can also create and edit cards, assign tasks, save timesheet drafts and post external TOT events. It asks before every change.', 'Juga boleh cipta dan edit kad, tugaskan tugasan, simpan draf timesheet dan siarkan acara TOT luaran. Ia bertanya sebelum setiap perubahan.') }}</small></span>
                            </label>
                        </div>
                    </fieldset>
                    <div class="uj-set-narrow">
                        <label class="uj-set-label" for="ai-pw">{{ $bi('Your password', 'Password anda') }}</label>
                        <input id="ai-pw" type="password" name="password" required autocomplete="current-password" class="uj-set-input uj-field">
                        @error('password')<p class="uj-set-err">{{ $message }}</p>@enderror
                    </div>
                </div>
                <div class="uj-set-foot">
                    @if ($aiKey)
                        <button type="button" class="uj-btn-ghost uj-set-btn" @click="replace = false">{{ $bi('Cancel', 'Batal') }}</button>
                    @else
                        <span>{{ $bi('We ask for your password so nobody else at your desk can do this.', 'Kami minta password anda supaya orang lain di meja anda tidak boleh buat ini.') }}</span>
                    @endif
                    <button type="submit" class="uj-btn-primary uj-set-btn">{{ $aiKey ? $bi('Make new key', 'Jana kunci baharu') : $bi('Create key', 'Jana kunci') }}</button>
                </div>
            </form>
        </div>
    </section>
</div>
@endsection
