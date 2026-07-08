<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $company->name }} · Feature matrix</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        .ft-row{display:grid;grid-template-columns:1.6fr 1.1fr 90px 1.1fr auto;gap:12px;align-items:end;padding:14px 18px;border-top:1px solid var(--hairline,#e6e6ec);}
        .ft-head{display:grid;grid-template-columns:1.6fr 1.1fr 90px 1.1fr auto;gap:12px;padding:10px 18px;background:var(--hairline-soft);}
        .ft-head span{font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:600;}
        .ft-row select{width:100%;padding:8px 10px;border:1px solid var(--hairline,#e6e6ec);border-radius:9px;font-size:13px;background:#fff;color:var(--ink);font-family:inherit;}
        .ft-row select:focus{outline:none;border-color:var(--red);box-shadow:0 0 0 3px rgba(214,35,43,.12);}
        .ft-key{font-weight:600;color:var(--ink);font-size:13.5px;}
        .ft-keycode{font-size:11px;color:var(--muted);font-family:var(--font-mono);margin-top:2px;}
        .ft-resolved{font-size:12px;color:var(--muted);margin-top:3px;}
        .ft-lock{display:inline-flex;align-items:center;gap:5px;font-size:12px;color:var(--ink);}
        .ft-btn{padding:8px 14px;border:none;border-radius:9px;font-size:13px;font-weight:600;background:var(--red);color:#fff;cursor:pointer;}
        .ft-badge{font-size:11px;font-weight:600;color:#a81820;background:#fbeaeb;border:1px solid #f3c6c8;padding:2px 8px;border-radius:9999px;}
    </style>
</head>
<body>
<div style="min-height:100vh;background:var(--canvas);padding:48px 24px;">
    <div style="max-width:1000px;margin:0 auto;">
        <a href="{{ route('superadmin.companies.show', $company) }}" style="font-size:13px;color:var(--muted);text-decoration:none;">← {{ $company->name }}</a>

        <div style="display:flex;align-items:center;gap:14px;margin:18px 0 8px;">
            <div style="width:48px;height:48px;border-radius:11px;background:{{ $company->color }};color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:18px;">{{ $company->initials }}</div>
            <div>
                <h1 style="font-weight:500;font-size:24px;letter-spacing:-0.4px;color:var(--ink);margin:0;">Feature matrix</h1>
                <div style="font-size:13px;color:var(--muted);">Platform defaults + locks · resolved values shown for {{ $company->name }}</div>
            </div>
        </div>
        <p style="font-size:13px;color:var(--muted);max-width:680px;margin:0 0 24px;">Set the platform default and lock for each feature. Locking forces every company to the platform value. Each save also lets you seed a company-specific override (ignored when locked).</p>

        @if (session('ok'))
            <div style="background:#eaf6f1;border:1px solid #bfe3d3;color:#0f5132;border-radius:10px;padding:13px 16px;margin-bottom:18px;font-size:14px;">{{ session('ok') }}</div>
        @endif
        @if ($errors->any())
            <div style="background:#fbeaeb;border:1px solid #f3c6c8;color:#a81820;border-radius:10px;padding:13px 16px;margin-bottom:18px;font-size:14px;">{{ $errors->first() }}</div>
        @endif

        @php
            $section = function (string $title, string $hint, array $rows) use ($company) {
                return compact('title', 'hint', 'rows');
            };
        @endphp

        @foreach ([
            ['title' => 'Modules', 'hint' => 'On/off surfaces. Disabling hides the nav entry and 403s the screens.', 'rows' => $modules],
            ['title' => 'Tenant settings', 'hint' => 'Per-company behavioural flags.', 'rows' => $tenantSettings],
            ['title' => 'Platform settings', 'hint' => 'Global, platform-scope only — no per-company override.', 'rows' => $platformSettings],
        ] as $group)
            @if (count($group['rows']))
                <div style="background:var(--surface,#fff);border:1px solid var(--hairline,#e6e6ec);border-radius:14px;overflow:hidden;margin-bottom:24px;">
                    <div style="padding:14px 18px;font-weight:600;font-size:14px;color:var(--ink);">{{ $group['title'] }}<span style="font-weight:400;color:var(--muted);font-size:12.5px;margin-left:8px;">{{ $group['hint'] }}</span></div>
                    <div class="ft-head"><span>Feature</span><span>Platform default</span><span>Lock</span><span>{{ $company->initials }} override</span><span></span></div>
                    @foreach ($group['rows'] as $row)
                        <form method="POST" action="{{ route('superadmin.companies.features.update', $company) }}" class="ft-row">
                            @csrf
                            <input type="hidden" name="key" value="{{ $row['key'] }}">
                            <div>
                                <div class="ft-key">{{ $row['label'] }}</div>
                                <div class="ft-keycode">{{ $row['key'] }}</div>
                                <div class="ft-resolved">Resolved: <strong>{{ \App\Support\Features::asBool($row['resolved']) || $row['type'] !== 'bool' ? ($row['type'] === 'bool' ? (\App\Support\Features::asBool($row['resolved']) ? 'On' : 'Off') : $row['resolved']) : 'Off' }}</strong></div>
                            </div>
                            <div>
                                @if ($row['type'] === 'enum')
                                    <select name="platform_value">
                                        @foreach ($row['options'] as $val => $optLabel)
                                            <option value="{{ $val }}" @selected((string) $row['platformValue'] === (string) $val)>{{ $optLabel }}</option>
                                        @endforeach
                                    </select>
                                @elseif ($row['type'] === 'number')
                                    <input type="number" name="platform_value" value="{{ $row['platformValue'] }}" step="1"
                                        style="width:100%;padding:8px 10px;border:1px solid var(--hairline,#e6e6ec);border-radius:9px;font-size:13px;">
                                @else
                                    <select name="platform_value">
                                        <option value="1" @selected(\App\Support\Features::asBool($row['platformValue']))>On</option>
                                        <option value="0" @selected(! \App\Support\Features::asBool($row['platformValue']))>Off</option>
                                    </select>
                                @endif
                            </div>
                            <div>
                                <label class="ft-lock"><input type="checkbox" name="locked" value="1" @checked($row['locked'])> Lock</label>
                            </div>
                            <div>
                                @if (($group['title']) === 'Platform settings')
                                    <span style="font-size:12px;color:var(--muted);">n/a</span>
                                @else
                                    <label style="display:flex;align-items:center;gap:6px;">
                                        <input type="checkbox" name="set_tenant" value="1">
                                        @if ($row['type'] === 'enum')
                                            <select name="tenant_value">
                                                @foreach ($row['options'] as $val => $optLabel)
                                                    <option value="{{ $val }}">{{ $optLabel }}</option>
                                                @endforeach
                                            </select>
                                        @elseif ($row['type'] === 'number')
                                            <input type="number" name="tenant_value" value="{{ $row['resolved'] }}" step="1"
                                                style="width:100px;padding:8px 10px;border:1px solid var(--hairline,#e6e6ec);border-radius:9px;font-size:13px;">
                                        @else
                                            <select name="tenant_value">
                                                <option value="1">On</option>
                                                <option value="0">Off</option>
                                            </select>
                                        @endif
                                    </label>
                                @endif
                            </div>
                            <div style="display:flex;align-items:center;gap:8px;">
                                @if ($row['locked'])<span class="ft-badge">Locked</span>@endif
                                <button type="submit" class="ft-btn">Save</button>
                            </div>
                        </form>
                    @endforeach
                </div>
            @endif
        @endforeach
    </div>
</div>
</body>
</html>
