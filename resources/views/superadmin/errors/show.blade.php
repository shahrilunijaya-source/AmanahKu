<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $event->reference }} · Amanahku Admin</title>
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
            <a href="{{ route('superadmin.errors.index') }}" style="margin-left:auto;font-size:13px;font-weight:600;color:var(--ink);text-decoration:none;border:1px solid var(--hairline);padding:8px 14px;border-radius:8px;">All errors</a>
        </div>

        <div style="margin-bottom:24px;">
            <div style="font-family:'JetBrains Mono',monospace;font-size:22px;font-weight:600;color:var(--red);letter-spacing:1.5px;margin-bottom:8px;">{{ $event->reference }}</div>
            <h1 style="font-weight:400;font-size:24px;letter-spacing:-0.4px;color:var(--ink);margin:0 0 8px;">{{ $event->exception_class }}</h1>
            <p style="font-size:15px;color:var(--ink);line-height:1.6;margin:0;">{{ $event->message }}</p>
        </div>

        @if ($repeats > 1)
            <div style="background:#fdf3e3;border:1px solid #f0d9a8;color:#7a4f10;border-radius:10px;padding:14px 18px;margin-bottom:20px;font-size:14px;line-height:1.6;">
                This same fault was captured <strong>{{ $repeats }} times</strong>. It is not a one-off.
            </div>
        @endif

        <div style="background:var(--surface,#fff);border:1px solid var(--hairline,#e6e6ec);border-radius:14px;overflow:hidden;margin-bottom:20px;">
            <table style="width:100%;border-collapse:collapse;font-size:14px;">
                <tbody>
                @foreach ([
                    'Where' => $event->file.':'.$event->line,
                    'Request' => $event->method.' '.$event->url,
                    'Company' => $event->tenant?->name ?? 'None resolved',
                    'User' => $event->user ? $event->user->name.' ('.$event->user->email.')' : 'Not signed in',
                    'IP' => $event->ip ?? '—',
                    'Device' => $event->user_agent ?? '—',
                    'When' => $event->created_at?->format('D, j M Y H:i:s'),
                ] as $label => $value)
                    <tr style="border-top:1px solid var(--hairline,#e6e6ec);">
                        <th style="text-align:left;padding:12px 18px;width:150px;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:600;vertical-align:top;">{{ $label }}</th>
                        <td style="padding:12px 18px;color:var(--ink);word-break:break-all;">{{ $value }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        {{-- Frames only. Call arguments are never stored, so a password submitted to a
             sign-in route cannot surface here. See ErrorEvent::traceFrames(). --}}
        <div style="background:var(--surface,#fff);border:1px solid var(--hairline,#e6e6ec);border-radius:14px;padding:18px;">
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:600;margin-bottom:12px;">Stack</div>
            <pre style="font-family:'JetBrains Mono',monospace;font-size:12.5px;line-height:1.7;color:var(--ink);margin:0;overflow-x:auto;white-space:pre;">{{ $event->trace }}</pre>
        </div>
    </div>
</div>
</body>
</html>
