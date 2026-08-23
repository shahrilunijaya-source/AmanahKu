<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Clock attempts · Amanahku Admin</title>
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
    <div style="max-width:1240px;margin:0 auto;">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:32px;">
            <div style="width:30px;height:30px;border-radius:7px;background:var(--red);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:16px;">A</div>
            <span style="font-weight:600;font-size:18px;color:var(--ink);letter-spacing:-0.2px;">Amanah<span style="color:var(--red);">ku</span></span>
            <span style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.6px;color:var(--muted);background:var(--hairline-soft);padding:4px 9px;border-radius:9999px;margin-left:4px;">Super Admin</span>
            <a href="{{ route('superadmin.errors.index') }}" style="margin-left:auto;font-size:13px;font-weight:600;color:var(--ink);text-decoration:none;border:1px solid var(--hairline);padding:8px 14px;border-radius:8px;">Errors</a>
            <a href="{{ route('superadmin.companies.index') }}" style="font-size:13px;font-weight:600;color:var(--ink);text-decoration:none;border:1px solid var(--hairline);padding:8px 14px;border-radius:8px;">Companies</a>
        </div>

        <div style="display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin-bottom:20px;">
            <div>
                <h1 style="font-weight:400;font-size:28px;letter-spacing:-0.5px;color:var(--ink);margin:0 0 6px;">Clock attempts</h1>
                <p style="font-size:14px;color:var(--muted);margin:0;">Every punch the server answered, from the last 30 days. Older entries are pruned nightly.</p>
            </div>
            <div style="display:flex;gap:8px;">
                @foreach (['refused' => 'Refused only', 'all' => 'All attempts'] as $key => $label)
                    <a href="{{ route('superadmin.attendance-attempts.index', ['outcome' => $key]) }}"
                       style="text-decoration:none;padding:10px 16px;border:1px solid var(--hairline,#e6e6ec);border-radius:10px;font-size:14px;font-weight:600;white-space:nowrap;{{ $outcome === $key ? 'background:var(--red);color:#fff;border-color:var(--red);' : 'background:#fff;color:var(--ink);' }}">{{ $label }}</a>
                @endforeach
            </div>
        </div>

        {{-- Read this before reading the rows. The app's own cap is 4MB, but PHP refuses
             a larger body before Laravel ever validates it, and on production these two
             values belong to whoever owns the host. A phone camera selfie is 3-8MB, so a
             limit under 4MB here explains "works on desktop, fails on mobile" on its own
             — a desktop selfie goes through a canvas and is small. --}}
        <div style="background:var(--hairline-soft);border:1px solid var(--hairline,#e6e6ec);border-radius:10px;padding:14px 18px;margin-bottom:20px;font-size:13.5px;color:var(--ink);line-height:1.7;">
            This host allows <strong style="font-family:'JetBrains Mono',monospace;">upload_max_filesize={{ $uploadMax }}</strong>
            and <strong style="font-family:'JetBrains Mono',monospace;">post_max_size={{ $postMax }}</strong>.
            The selfie rule in the app is 4MB. Whichever of these two is smaller is the one that really applies, and the two
            fail in different places: a body over <code>post_max_size</code> is refused before Laravel runs at all and lands in
            <a href="{{ route('superadmin.errors.index') }}" style="color:var(--red);font-weight:600;">Errors</a>, while a file
            over <code>upload_max_filesize</code> reaches validation as a dead upload and lands here as
            <strong>invalid</strong>, saying the selfie was too large.
        </div>

        <div style="background:var(--surface,#fff);border:1px solid var(--hairline,#e6e6ec);border-radius:14px;overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:14px;">
                <thead>
                    <tr style="background:var(--hairline-soft);">
                        @foreach (['Outcome', 'Why', 'Device', 'Person', 'Company', 'Payload', 'When'] as $heading)
                            <th style="text-align:left;padding:12px 18px;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:600;white-space:nowrap;">{{ $heading }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                @forelse ($attempts as $attempt)
                    <tr style="border-top:1px solid var(--hairline,#e6e6ec);">
                        <td style="padding:14px 18px;white-space:nowrap;">
                            <span style="font-family:'JetBrains Mono',monospace;font-size:12.5px;font-weight:600;padding:3px 8px;border-radius:6px;{{ $attempt->outcome === 'ok' ? 'background:#e8f5ec;color:#1d6b39;' : 'background:#fdecec;color:#a11c1c;' }}">{{ $attempt->outcome }}</span>
                            <div style="color:var(--muted);font-size:12px;margin-top:4px;">clock {{ $attempt->action ?? '—' }}</div>
                        </td>
                        <td style="padding:14px 18px;max-width:340px;color:var(--ink);">{{ $attempt->message ?? '—' }}</td>
                        <td style="padding:14px 18px;">
                            <div style="color:var(--ink);font-weight:500;white-space:nowrap;">{{ $attempt->deviceLabel() }}</div>
                            {{-- Full string kept on the row: deviceLabel() is a guess off
                                 client-supplied text, and a guess is not evidence. --}}
                            <div style="color:var(--muted);font-size:11.5px;font-family:'JetBrains Mono',monospace;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{{ $attempt->user_agent }}">{{ $attempt->user_agent ?? '—' }}</div>
                        </td>
                        <td style="padding:14px 18px;color:var(--muted);white-space:nowrap;">{{ $attempt->user?->name ?? '—' }}</td>
                        <td style="padding:14px 18px;color:var(--muted);white-space:nowrap;">{{ $attempt->tenant?->name ?? '—' }}</td>
                        <td style="padding:14px 18px;color:var(--muted);font-size:12.5px;white-space:nowrap;">
                            <div>{{ $attempt->has_location ? 'GPS yes' : 'GPS no' }}</div>
                            <div>
                                @if ($attempt->has_photo)
                                    selfie {{ $attempt->photo_bytes === null ? 'size unknown' : App\Models\AttendanceAttempt::humanBytes($attempt->photo_bytes) }}
                                @else
                                    no selfie
                                @endif
                            </div>
                            <div>body {{ App\Models\AttendanceAttempt::humanBytes($attempt->content_length) }}</div>
                        </td>
                        <td style="padding:14px 18px;color:var(--muted);white-space:nowrap;">{{ $attempt->created_at?->format('j M H:i:s') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" style="padding:38px 18px;text-align:center;color:var(--muted);font-size:14px;">
                            {{ $outcome === 'refused' ? 'No punch was refused. That is the result you want.' : 'No clock attempt recorded yet.' }}
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        @if ($attempts->hasPages())
            <div style="padding:18px 2px;">{{ $attempts->onEachSide(1)->links() }}</div>
        @endif
    </div>
</div>
</body>
</html>
