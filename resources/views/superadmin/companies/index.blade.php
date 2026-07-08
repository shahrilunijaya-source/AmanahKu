<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Companies · Amanahku Admin</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<div style="min-height:100vh;background:var(--canvas);padding:48px 24px;">
    <div style="max-width:880px;margin:0 auto;">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:32px;">
            <div style="width:30px;height:30px;border-radius:7px;background:var(--red);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:16px;">A</div>
            <span style="font-weight:600;font-size:18px;color:var(--ink);letter-spacing:-0.2px;">Amanah<span style="color:var(--red);">ku</span></span>
            <span style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.6px;color:var(--muted);background:var(--hairline-soft);padding:4px 9px;border-radius:9999px;margin-left:4px;">Super Admin</span>
            <form action="/logout" method="post" style="margin-left:auto;">
                @csrf
                <button type="submit" style="font-size:13px;font-weight:600;color:var(--red);background:none;border:1px solid var(--hairline);cursor:pointer;padding:8px 14px;border-radius:8px;">Sign out</button>
            </form>
        </div>

        <div style="display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin-bottom:24px;">
            <div>
                <h1 style="font-weight:400;font-size:28px;letter-spacing:-0.5px;color:var(--ink);margin:0 0 6px;">Companies</h1>
                <p style="font-size:14px;color:var(--muted);margin:0;">{{ $companies->count() }} {{ Str::plural('workspace', $companies->count()) }} provisioned.</p>
            </div>
            <a href="{{ route('superadmin.companies.create') }}" class="uj-btn" style="text-decoration:none;padding:11px 18px;border-radius:10px;font-size:14px;font-weight:600;background:var(--red);color:#fff;">+ New company</a>
        </div>

        @if (session('ok'))
            <div style="background:#eaf6f1;border:1px solid #bfe3d3;color:#0f5132;border-radius:10px;padding:14px 18px;margin-bottom:20px;font-size:14px;line-height:1.6;">{{ session('ok') }}</div>
        @endif

        <div style="background:var(--surface,#fff);border:1px solid var(--hairline,#e6e6ec);border-radius:14px;overflow:hidden;">
            <table style="width:100%;border-collapse:collapse;font-size:14px;">
                <thead>
                    <tr style="background:var(--hairline-soft);">
                        <th style="text-align:left;padding:12px 18px;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:600;">Company</th>
                        <th style="text-align:left;padding:12px 18px;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:600;">Category</th>
                        <th style="text-align:left;padding:12px 18px;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:600;">Plan</th>
                        <th style="text-align:right;padding:12px 18px;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:600;">Logins</th>
                        <th style="text-align:right;padding:12px 18px;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:600;">Employees</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($companies as $c)
                        <tr style="border-top:1px solid var(--hairline,#e6e6ec);">
                            <td style="padding:14px 18px;">
                                <a href="{{ route('superadmin.companies.show', $c) }}" style="display:flex;align-items:center;gap:12px;text-decoration:none;">
                                    <div style="width:38px;height:38px;border-radius:9px;background:{{ $c->color }};color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:15px;flex-shrink:0;">{{ $c->initials }}</div>
                                    <div>
                                        <div style="font-weight:600;color:var(--ink);">{{ $c->name }}</div>
                                        <div style="font-size:12px;color:var(--muted);">{{ $c->slug }}</div>
                                    </div>
                                </a>
                            </td>
                            <td style="padding:14px 18px;color:var(--muted);">
                                {{ $c->companyCategory?->name ?? '—' }}
                                @unless ($c->isActive())
                                    <span style="font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:.4px;color:#a81820;background:#fbeaeb;border:1px solid #f3c6c8;padding:2px 7px;border-radius:9999px;margin-left:6px;">Suspended</span>
                                @endunless
                            </td>
                            <td style="padding:14px 18px;color:var(--muted);">{{ $c->plan }}</td>
                            <td style="padding:14px 18px;text-align:right;color:var(--ink);">{{ $c->users_count }}</td>
                            <td style="padding:14px 18px;text-align:right;color:var(--ink);">{{ $c->employees_count }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" style="padding:28px;text-align:center;color:var(--muted);">No companies yet. Create the first one.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div style="margin-top:24px;">
            <a href="{{ route('tenant.select') }}" style="font-size:13px;color:var(--muted);text-decoration:none;">← Back to workspaces</a>
        </div>
    </div>
</div>
</body>
</html>
