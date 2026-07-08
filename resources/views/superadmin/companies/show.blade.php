<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $company->name }} · Amanahku Admin</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        .fld label{display:block;font-size:13px;font-weight:600;color:var(--ink);margin-bottom:6px;}
        .fld input,.fld select{width:100%;padding:11px 13px;border:1px solid var(--hairline,#e6e6ec);border-radius:10px;font-size:14px;background:#fff;color:var(--ink);font-family:inherit;}
        .fld input:focus,.fld select:focus{outline:none;border-color:var(--red);box-shadow:0 0 0 3px rgba(214,35,43,.12);}
        .err{color:var(--red);font-size:12.5px;margin-top:5px;}
    </style>
</head>
<body>
<div style="min-height:100vh;background:var(--canvas);padding:48px 24px;">
    <div style="max-width:760px;margin:0 auto;">
        <a href="{{ route('superadmin.companies.index') }}" style="font-size:13px;color:var(--muted);text-decoration:none;">← All companies</a>

        <div style="display:flex;align-items:center;gap:14px;margin:18px 0 28px;">
            <div style="width:48px;height:48px;border-radius:11px;background:{{ $company->color }};color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:18px;">{{ $company->initials }}</div>
            <div style="flex:1;">
                <h1 style="font-weight:500;font-size:24px;letter-spacing:-0.4px;color:var(--ink);margin:0;display:flex;align-items:center;gap:10px;">
                    {{ $company->name }}
                    @if ($company->isActive())
                        <span style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#0f5132;background:#eaf6f1;border:1px solid #bfe3d3;padding:3px 9px;border-radius:9999px;">Active</span>
                    @else
                        <span style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#a81820;background:#fbeaeb;border:1px solid #f3c6c8;padding:3px 9px;border-radius:9999px;">Suspended</span>
                    @endif
                </h1>
                <div style="font-size:13px;color:var(--muted);">{{ $company->companyCategory?->name ?? 'No category' }} · {{ $company->plan }} · {{ $company->slug }}</div>
            </div>
            <a href="{{ route('superadmin.companies.edit', $company) }}" class="uj-btn" style="text-decoration:none;padding:10px 16px;border:1px solid var(--hairline,#e6e6ec);border-radius:10px;font-size:13.5px;font-weight:600;background:#fff;color:var(--ink);">Edit profile</a>
            <a href="{{ route('superadmin.companies.features', $company) }}" class="uj-btn" style="text-decoration:none;padding:10px 16px;border:1px solid var(--hairline,#e6e6ec);border-radius:10px;font-size:13.5px;font-weight:600;background:#fff;color:var(--ink);">Feature matrix →</a>
        </div>

        @if (session('ok'))
            <div style="background:#eaf6f1;border:1px solid #bfe3d3;color:#0f5132;border-radius:10px;padding:13px 16px;margin-bottom:18px;font-size:14px;">{{ session('ok') }}</div>
        @endif
        @if ($errors->any())
            <div style="background:#fbeaeb;border:1px solid #f3c6c8;color:#a81820;border-radius:10px;padding:13px 16px;margin-bottom:18px;font-size:14px;">{{ $errors->first() }}</div>
        @endif

        {{-- Category package + lifecycle — super-admin only. A company admin can never reach these. --}}
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:24px;">
            <div style="background:var(--surface,#fff);border:1px solid var(--hairline,#e6e6ec);border-radius:14px;padding:18px;">
                <div style="font-weight:600;font-size:14px;color:var(--ink);margin-bottom:4px;">Category &amp; package</div>
                <p style="font-size:12.5px;color:var(--muted);margin:0 0 12px;">Re-applies the default module package for the chosen stage.</p>
                <form method="POST" action="{{ route('superadmin.companies.category', $company) }}" style="display:flex;gap:10px;">
                    @csrf
                    <select name="company_category_id" class="fld" style="flex:1;padding:10px 12px;border:1px solid var(--hairline,#e6e6ec);border-radius:9px;font-size:13.5px;">
                        @foreach ($categories as $cat)
                            <option value="{{ $cat->id }}" @selected($company->company_category_id === $cat->id)>{{ $cat->name }}</option>
                        @endforeach
                    </select>
                    <button type="submit" style="padding:10px 16px;border:none;border-radius:9px;font-size:13.5px;font-weight:600;background:var(--red);color:#fff;cursor:pointer;">Apply</button>
                </form>
            </div>
            <div style="background:var(--surface,#fff);border:1px solid var(--hairline,#e6e6ec);border-radius:14px;padding:18px;">
                <div style="font-weight:600;font-size:14px;color:var(--ink);margin-bottom:4px;">Lifecycle</div>
                <p style="font-size:12.5px;color:var(--muted);margin:0 0 12px;">Suspending blocks every member from the workspace.</p>
                <form method="POST" action="{{ route('superadmin.companies.status', $company) }}">
                    @csrf
                    @if ($company->isActive())
                        <input type="hidden" name="status" value="suspended">
                        <button type="submit" style="padding:10px 16px;border:1px solid #f3c6c8;border-radius:9px;font-size:13.5px;font-weight:600;background:#fbeaeb;color:#a81820;cursor:pointer;">Suspend company</button>
                    @else
                        <input type="hidden" name="status" value="active">
                        <button type="submit" style="padding:10px 16px;border:1px solid #bfe3d3;border-radius:9px;font-size:13.5px;font-weight:600;background:#eaf6f1;color:#0f5132;cursor:pointer;">Reactivate company</button>
                    @endif
                </form>
            </div>
        </div>

        <div style="background:var(--surface,#fff);border:1px solid var(--hairline,#e6e6ec);border-radius:14px;overflow:hidden;margin-bottom:24px;">
            <div style="padding:14px 18px;border-bottom:1px solid var(--hairline,#e6e6ec);font-weight:600;font-size:14px;color:var(--ink);">Members ({{ $members->count() }})</div>
            <table style="width:100%;border-collapse:collapse;font-size:14px;">
                <tbody>
                    @forelse ($members as $m)
                        <tr style="border-top:1px solid var(--hairline,#e6e6ec);">
                            <td style="padding:12px 18px;color:var(--ink);font-weight:500;">{{ $m->name }}</td>
                            <td style="padding:12px 18px;color:var(--muted);">{{ $m->email }}</td>
                            <td style="padding:12px 18px;text-align:right;"><span style="font-size:12px;font-weight:600;color:var(--ink);background:var(--hairline-soft);padding:3px 10px;border-radius:9999px;">{{ ucfirst($m->pivot->role) }}</span></td>
                        </tr>
                    @empty
                        <tr><td style="padding:20px 18px;color:var(--muted);text-align:center;">No members yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div style="background:var(--surface,#fff);border:1px solid var(--hairline,#e6e6ec);border-radius:14px;padding:22px;">
            <div style="font-weight:600;font-size:14px;color:var(--ink);margin-bottom:4px;">Assign an existing user</div>
            <p style="font-size:13px;color:var(--muted);margin:0 0 16px;">Attach an account that already exists on the platform to this company.</p>
            <form method="POST" action="{{ route('superadmin.companies.members.assign', $company) }}">
                @csrf
                <div style="display:grid;grid-template-columns:2fr 1fr auto;gap:12px;align-items:end;">
                    <div class="fld">
                        <label>User email</label>
                        <input type="email" name="email" value="{{ old('email') }}" placeholder="person@example.com" required>
                    </div>
                    <div class="fld">
                        <label>Role</label>
                        <select name="role">
                            @foreach (['employee','manager','management','hr'] as $r)
                                <option value="{{ $r }}" @selected(old('role')===$r)>{{ ucfirst($r) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="uj-btn" style="padding:11px 18px;border:none;border-radius:10px;font-size:14px;font-weight:600;background:var(--red);color:#fff;cursor:pointer;height:42px;">Assign</button>
                </div>
            </form>
        </div>
    </div>
</div>
</body>
</html>
