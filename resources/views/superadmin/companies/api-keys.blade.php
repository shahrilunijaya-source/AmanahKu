<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $company->name }} · API keys</title>
    {{ Vite::fonts() }}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        .ak-card{background:#fff;border:1px solid var(--hairline,#e6e6ec);border-radius:12px;padding:18px;margin-bottom:14px;}
        .ak-name{font-weight:600;color:var(--ink);font-size:14.5px;}
        .ak-meta{font-size:12px;color:var(--muted);margin-top:3px;}
        .ak-scope{display:inline-block;font-size:11px;font-family:var(--font-mono);background:var(--hairline-soft);border:1px solid var(--hairline,#e6e6ec);color:var(--ink);padding:2px 8px;border-radius:9999px;margin:4px 4px 0 0;}
        .ak-btn{padding:8px 14px;border:none;border-radius:9px;font-size:13px;font-weight:600;background:var(--red);color:#fff;cursor:pointer;}
        .ak-revoke{padding:6px 12px;border:1px solid #f3c6c8;border-radius:9px;font-size:12px;font-weight:600;background:#fff;color:#a81820;cursor:pointer;}
        .ak-label{display:block;font-size:12px;font-weight:600;color:var(--ink);margin-bottom:5px;}
        .ak-input{width:100%;padding:9px 11px;border:1px solid var(--hairline,#e6e6ec);border-radius:9px;font-size:13.5px;font-family:inherit;color:var(--ink);}
        .ak-key{font-family:var(--font-mono);font-size:13px;word-break:break-all;background:#fff;border:1px solid #f3c6c8;border-radius:9px;padding:12px;margin-top:10px;}
    </style>
@include('partials.pwa-head')
</head>
<body>
<div style="min-height:100vh;background:var(--canvas);padding:48px 24px;">
    <div style="max-width:860px;margin:0 auto;">
        <a href="{{ route('superadmin.companies.show', $company) }}" style="font-size:13px;color:var(--muted);text-decoration:none;">← {{ $company->name }}</a>

        <div style="display:flex;align-items:center;gap:14px;margin:18px 0 8px;">
            <div style="width:48px;height:48px;border-radius:11px;background:{{ $company->color }};color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:18px;">{{ $company->initials }}</div>
            <div>
                <h1 style="font-weight:500;font-size:24px;letter-spacing:-0.4px;color:var(--ink);margin:0;">API keys</h1>
                <div style="font-size:13px;color:var(--muted);">One key per application, reading only what it is ticked for</div>
            </div>
        </div>
        <p style="font-size:13px;color:var(--muted);max-width:680px;margin:0 0 24px;">A key belongs to an application, not to a person, so nobody leaving breaks an integration. The key text is shown once when you create it and cannot be recovered afterwards — if it is lost, revoke it and create another.</p>

        @if (session('ok'))
            <div style="background:#eaf6f1;border:1px solid #bfe3d3;color:#0f5132;border-radius:10px;padding:13px 16px;margin-bottom:18px;font-size:14px;">{{ session('ok') }}</div>
        @endif
        @if ($errors->any())
            <div style="background:#fbeaeb;border:1px solid #f3c6c8;color:#a81820;border-radius:10px;padding:13px 16px;margin-bottom:18px;font-size:14px;">{{ $errors->first() }}</div>
        @endif

        @if (session('newKey'))
            <div style="background:#fbeaeb;border:1px solid #f3c6c8;border-radius:12px;padding:16px 18px;margin-bottom:22px;">
                <div style="font-weight:600;color:#a81820;font-size:14px;">Copy {{ session('newKeyName') }}'s key now</div>
                <div style="font-size:12.5px;color:#a81820;margin-top:3px;">This is the only time it will ever be shown. Leaving this page loses it for good.</div>
                <div class="ak-key" id="ak-new-key">{{ session('newKey') }}</div>
                <button class="ak-btn" style="margin-top:10px;" onclick="navigator.clipboard.writeText(document.getElementById('ak-new-key').textContent.trim());this.textContent='Copied';">Copy key</button>
            </div>
        @endif

        <div class="ak-card">
            <div class="ak-name" style="margin-bottom:14px;">Issue a key</div>
            <form method="POST" action="{{ route('superadmin.companies.api-keys.store', $company) }}">
                @csrf
                <label class="ak-label" for="ak-app-name">Application</label>
                <input class="ak-input" id="ak-app-name" name="name" required maxlength="80" placeholder="Track" value="{{ old('name') }}">

                <div class="ak-label" style="margin-top:16px;">May read</div>
                @foreach ($scopes as $key => $label)
                    <label style="display:flex;gap:9px;align-items:flex-start;padding:7px 0;font-size:13.5px;color:var(--ink);">
                        <input type="checkbox" name="scopes[]" value="{{ $key }}" style="margin-top:3px;">
                        <span>
                            <span style="font-family:var(--font-mono);font-size:12px;color:var(--muted);">{{ $key }}</span><br>
                            {{ $label }}
                        </span>
                    </label>
                @endforeach

                <button class="ak-btn" style="margin-top:16px;">Create key</button>
            </form>
        </div>

        @forelse ($clients as $client)
            @foreach ($client->tokens as $token)
                <div class="ak-card" style="display:flex;justify-content:space-between;gap:18px;align-items:flex-start;">
                    <div>
                        <div class="ak-name">{{ $client->name }}</div>
                        <div class="ak-meta">
                            Issued by {{ $client->creator?->name ?? 'unknown' }} on {{ $token->created_at?->format('j M Y') }}
                            · {{ $token->last_used_at ? 'last used '.$token->last_used_at->diffForHumans() : 'never used' }}
                        </div>
                        <div>
                            @foreach ((array) $token->abilities as $ability)
                                <span class="ak-scope">{{ $ability }}</span>
                            @endforeach
                        </div>
                    </div>
                    <form method="POST" action="{{ route('superadmin.companies.api-keys.revoke', [$company, $token]) }}"
                          onsubmit="return confirm('Revoke {{ $client->name }}\'s key? It stops working immediately.');">
                        @csrf
                        <button class="ak-revoke">Revoke</button>
                    </form>
                </div>
            @endforeach
        @empty
            <div class="ak-card" style="color:var(--muted);font-size:13.5px;">No keys issued for {{ $company->name }} yet.</div>
        @endforelse
    </div>
</div>
</body>
</html>
