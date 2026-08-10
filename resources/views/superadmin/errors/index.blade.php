<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Errors · Amanahku Admin</title>
    {{-- Self-hosted Poppins + JetBrains Mono. Vite emits the @font-face rules as a
         non-entry chunk, so @vite never links them: without this line every page
         silently falls back to the system UI font. See the `fonts` block in
         vite.config.js and public/build/fonts-manifest.json. --}}
    {{ Vite::fonts() }}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
@include('partials.pwa-head')
</head>
<body>
<div style="min-height:100vh;background:var(--canvas);padding:48px 24px;">
    <div style="max-width:1040px;margin:0 auto;">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:32px;">
            <div style="width:30px;height:30px;border-radius:7px;background:var(--red);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:16px;">A</div>
            <span style="font-weight:600;font-size:18px;color:var(--ink);letter-spacing:-0.2px;">Amanah<span style="color:var(--red);">ku</span></span>
            <span style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.6px;color:var(--muted);background:var(--hairline-soft);padding:4px 9px;border-radius:9999px;margin-left:4px;">Super Admin</span>
            {{-- A refused punch never reaches this table, so the link out matters: half the
                 answer to "why can nobody clock in" lives on the other screen. --}}
            <a href="{{ route('superadmin.attendance-attempts.index') }}" style="margin-left:auto;font-size:13px;font-weight:600;color:var(--ink);text-decoration:none;border:1px solid var(--hairline);padding:8px 14px;border-radius:8px;">Clock attempts</a>
            <a href="{{ route('superadmin.companies.index') }}" style="font-size:13px;font-weight:600;color:var(--ink);text-decoration:none;border:1px solid var(--hairline);padding:8px 14px;border-radius:8px;">Companies</a>
        </div>

        <div style="display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin-bottom:24px;">
            <div>
                <h1 style="font-weight:400;font-size:28px;letter-spacing:-0.5px;color:var(--ink);margin:0 0 6px;">Errors</h1>
                <p style="font-size:14px;color:var(--muted);margin:0;">Unhandled faults from the last 30 days. Older entries are pruned nightly.</p>
            </div>
            <div style="display:flex;gap:8px;align-items:center;">
            {{-- Proves the capture chain on whichever environment this is. Safe to click:
                 it throws on purpose and touches no data. --}}
            <a href="{{ route('superadmin.errors.self-test') }}" style="text-decoration:none;padding:10px 16px;border:1px solid var(--hairline,#e6e6ec);border-radius:10px;font-size:14px;font-weight:600;color:var(--ink);background:#fff;white-space:nowrap;">Run self-test</a>
            <form method="get" style="display:flex;gap:8px;">
                <input type="search" name="q" value="{{ $search }}" placeholder="Error reference" style="padding:10px 14px;border:1px solid var(--hairline,#e6e6ec);border-radius:10px;font-size:14px;font-family:'JetBrains Mono',monospace;letter-spacing:1px;width:190px;">
                <button type="submit" class="uj-btn" style="padding:10px 16px;border-radius:10px;font-size:14px;font-weight:600;background:var(--red);color:#fff;border:none;cursor:pointer;">Find</button>
            </form>
            </div>
        </div>

        @if ($search !== '' && $events->isEmpty())
            <div style="background:#fdf3e3;border:1px solid #f0d9a8;color:#7a4f10;border-radius:10px;padding:14px 18px;margin-bottom:20px;font-size:14px;line-height:1.6;">
                No fault carries the reference <strong>{{ strtoupper($search) }}</strong>. It may be older than 30 days, or the code was misread.
                <a href="{{ route('superadmin.errors.index') }}" style="color:#7a4f10;font-weight:600;">Show all</a>
            </div>
        @endif

        <div style="background:var(--surface,#fff);border:1px solid var(--hairline,#e6e6ec);border-radius:14px;overflow:hidden;">
            <table style="width:100%;border-collapse:collapse;font-size:14px;">
                <thead>
                    <tr style="background:var(--hairline-soft);">
                        <th style="text-align:left;padding:12px 18px;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:600;">Reference</th>
                        <th style="text-align:left;padding:12px 18px;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:600;">Fault</th>
                        <th style="text-align:left;padding:12px 18px;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:600;">Company</th>
                        <th style="text-align:left;padding:12px 18px;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:600;">User</th>
                        <th style="text-align:right;padding:12px 18px;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:600;">When</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($events as $event)
                    <tr style="border-top:1px solid var(--hairline,#e6e6ec);">
                        <td style="padding:14px 18px;">
                            <a href="{{ route('superadmin.errors.show', $event) }}" style="font-family:'JetBrains Mono',monospace;font-weight:600;color:var(--red);text-decoration:none;letter-spacing:1px;">{{ $event->reference }}</a>
                        </td>
                        <td style="padding:14px 18px;max-width:380px;">
                            <div style="color:var(--ink);font-weight:500;">{{ class_basename($event->exception_class) }}</div>
                            <div style="color:var(--muted);font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $event->message }}</div>
                        </td>
                        <td style="padding:14px 18px;color:var(--muted);">{{ $event->tenant?->name ?? '—' }}</td>
                        <td style="padding:14px 18px;color:var(--muted);">{{ $event->user?->name ?? 'Guest' }}</td>
                        <td style="padding:14px 18px;text-align:right;color:var(--muted);white-space:nowrap;">{{ $event->created_at?->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" style="padding:38px 18px;text-align:center;color:var(--muted);font-size:14px;">No faults captured. That is the result you want.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        @if ($events->hasPages())
            <div style="padding:18px 2px;">{{ $events->onEachSide(1)->links() }}</div>
        @endif
    </div>
</div>
</body>
</html>
