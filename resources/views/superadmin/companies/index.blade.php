<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Companies · Amanahku Admin</title>
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
    <div style="max-width:880px;margin:0 auto;">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:32px;">
            <div style="width:30px;height:30px;border-radius:7px;background:var(--red);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:16px;">A</div>
            <span style="font-weight:600;font-size:18px;color:var(--ink);letter-spacing:-0.2px;">Amanah<span style="color:var(--red);">ku</span></span>
            <span style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.6px;color:var(--muted);background:var(--hairline-soft);padding:4px 9px;border-radius:9999px;margin-left:4px;">Super Admin</span>
            <a href="{{ route('superadmin.errors.index') }}" style="margin-left:auto;font-size:13px;font-weight:600;color:var(--ink);text-decoration:none;border:1px solid var(--hairline);padding:8px 14px;border-radius:8px;">Errors</a>
            <form action="/logout" method="post">
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

        {{-- Queued mail is the app's only outbound email path, and a failure there is
             otherwise silent: it cannot be emailed (mail is what broke) and the in-app
             bell is tenant-scoped. This banner is the whole alerting surface. --}}
        @if ($failedJobs['count'] > 0)
            <div style="background:#fdf3e3;border:1px solid #f0d9a8;color:#7a4f10;border-radius:10px;padding:14px 18px;margin-bottom:20px;font-size:14px;line-height:1.6;">
                <div style="font-weight:600;margin-bottom:2px;">Queued jobs are failing</div>
                <div>{{ $failedJobs['count'] }} queued {{ Str::plural('job', $failedJobs['count']) }} {{ $failedJobs['count'] === 1 ? 'has' : 'have' }} failed. Most recent: <strong>{{ $failedJobs['latest'] }}</strong> at {{ $failedJobs['failedAt'] }}.</div>
                <div style="margin-top:6px;">Invites, password resets and the weekly digest all send through the queue, so they are probably not being delivered. Fix the mail settings first, then run <code>php artisan queue:retry all</code> to resend.</div>
            </div>
        @endif

        {{-- A job nobody picks up never fails, so the banner above stays silent while a
             stopped worker quietly swallows every invite. This is the only warning. --}}
        @if ($stuckJobs > 0)
            <div style="background:#fdf3e3;border:1px solid #f0d9a8;color:#7a4f10;border-radius:10px;padding:14px 18px;margin-bottom:20px;font-size:14px;line-height:1.6;">
                <div style="font-weight:600;margin-bottom:2px;">Queued jobs are not being processed</div>
                <div>{{ $stuckJobs }} queued {{ Str::plural('job', $stuckJobs) }} {{ $stuckJobs === 1 ? 'has' : 'have' }} been waiting more than 10 minutes. Nothing has failed — the queue worker is most likely not running.</div>
                <div style="margin-top:6px;">Invites, password resets and the weekly digest all send through the queue, so none of them are going out. Ask the operations team to restart <code>queue:work</code>; the backlog then sends by itself.</div>
            </div>
        @endif

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

        {{-- Signup links: a super-admin mints one, sends it by WhatsApp or email, and the
             person in charge provisions the company themselves. One link, one company,
             seven days. The fresh link is shown once, right after generating. --}}
        <div style="background:var(--surface,#fff);border:1px solid var(--hairline,#e6e6ec);border-radius:14px;padding:24px;margin-top:24px;">
            <h2 style="font-weight:500;font-size:18px;letter-spacing:-0.3px;color:var(--ink);margin:0 0 4px;">Signup links</h2>
            <p style="font-size:13px;color:var(--muted);margin:0 0 18px;line-height:1.55;">Send a link to the person in charge of a new company. They create the company and their own HR admin login themselves. One link, one company, valid 7 days.</p>

            <form method="POST" action="{{ route('superadmin.invites.store') }}" style="display:grid;grid-template-columns:1fr 220px auto;gap:12px;align-items:end;padding:16px;background:var(--canvas);border-radius:10px;margin-bottom:20px;">
                @csrf
                <div>
                    <label style="display:block;font-size:12px;font-weight:600;color:var(--ink);margin-bottom:6px;">For (note to self)</label>
                    <input name="note" value="{{ old('note') }}" maxlength="160" placeholder="e.g. Encik Faizal, Maju Bina Sdn Bhd" style="width:100%;height:44px;padding:0 13px;border:1px solid var(--hairline,#e6e6ec);border-radius:10px;font-size:14px;background:#fff;color:var(--ink);font-family:inherit;">
                    @error('note')<div style="color:var(--red);font-size:12.5px;margin-top:5px;">{{ $message }}</div>@enderror
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:600;color:var(--ink);margin-bottom:6px;">Starting package</label>
                    <select name="company_category_id" required style="width:100%;height:44px;padding:0 13px;border:1px solid var(--hairline,#e6e6ec);border-radius:10px;font-size:14px;background:#fff;color:var(--ink);font-family:inherit;">
                        @foreach ($categories as $cat)
                            <option value="{{ $cat->id }}" @selected((int) old('company_category_id', $categories->first()?->id) === $cat->id)>{{ $cat->name }}</option>
                        @endforeach
                    </select>
                    @error('company_category_id')<div style="color:var(--red);font-size:12.5px;margin-top:5px;">{{ $message }}</div>@enderror
                </div>
                <button type="submit" class="uj-btn" style="height:44px;padding:0 18px;border:none;border-radius:10px;font-size:14px;font-weight:600;background:var(--red);color:#fff;cursor:pointer;">Generate link</button>
            </form>

            <script>
                // Copies the button's data-copy value. The Clipboard API only exists on
                // HTTPS or localhost, so a plain-HTTP host (a LAN IP) falls back to a
                // hidden textarea and execCommand.
                function ujCopy(btn) {
                    const text = btn.dataset.copy;
                    const done = () => { const was = btn.textContent; btn.textContent = 'Copied'; setTimeout(() => { btn.textContent = was; }, 1500); };
                    if (navigator.clipboard && window.isSecureContext) {
                        navigator.clipboard.writeText(text).then(done, () => window.prompt('Copy this link:', text));
                        return;
                    }
                    const ta = document.createElement('textarea');
                    ta.value = text;
                    ta.style.cssText = 'position:fixed;opacity:0;';
                    document.body.appendChild(ta);
                    ta.select();
                    const ok = document.execCommand('copy');
                    ta.remove();
                    ok ? done() : window.prompt('Copy this link:', text);
                }
            </script>
            @if (session('inviteUrl'))
                <div style="background:#eaf6f1;border:1px solid #bfe3d3;color:#0f5132;border-radius:10px;padding:12px 14px;margin-bottom:18px;font-size:13px;display:flex;align-items:center;gap:12px;">
                    <span style="flex:1;min-width:0;">Link ready @if (session('inviteNote'))for <strong>{{ session('inviteNote') }}</strong>@endif:<br><span style="font-family:var(--font-mono,monospace);color:var(--ink);word-break:break-all;">{{ session('inviteUrl') }}</span></span>
                    <button type="button" data-copy="{{ session('inviteUrl') }}" onclick="ujCopy(this)"  style="flex-shrink:0;font-size:12.5px;font-weight:600;color:var(--ink);background:#fff;border:1px solid var(--hairline);cursor:pointer;padding:7px 12px;border-radius:8px;">Copy link</button>
                </div>
            @endif

            <table style="width:100%;border-collapse:collapse;font-size:13.5px;">
                <thead>
                    <tr style="background:var(--hairline-soft);">
                        <th style="text-align:left;padding:10px 12px;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:600;">For</th>
                        <th style="text-align:left;padding:10px 12px;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:600;">Package</th>
                        <th style="text-align:left;padding:10px 12px;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:600;">Created</th>
                        <th style="text-align:left;padding:10px 12px;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:600;">Expires</th>
                        <th style="text-align:left;padding:10px 12px;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);font-weight:600;">Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($invites as $invite)
                        @php $status = $invite->status(); @endphp
                        <tr style="border-top:1px solid var(--hairline,#e6e6ec);">
                            <td style="padding:12px;color:var(--ink);">{{ $invite->note ?: '—' }}</td>
                            <td style="padding:12px;color:var(--muted);">Stage {{ $invite->category->level }}</td>
                            <td style="padding:12px;color:var(--muted);">{{ $invite->created_at->format('j M Y') }}</td>
                            <td style="padding:12px;color:var(--muted);">{{ $invite->expires_at->format('j M Y') }}</td>
                            <td style="padding:12px;">
                                @if ($status === 'pending')
                                    <span style="font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:.4px;color:#7a4f10;background:#fdf3e3;border:1px solid #f0d9a8;display:inline-block;padding:2px 8px;border-radius:9999px;">Pending</span>
                                @elseif ($status === 'used')
                                    <span style="font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:.4px;color:#0f5132;background:#eaf6f1;border:1px solid #bfe3d3;display:inline-block;padding:2px 8px;border-radius:9999px;">Used · {{ $invite->usedByTenant?->name ?? 'Company deleted' }}</span>
                                @else
                                    <span style="font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:.4px;color:var(--muted);background:var(--hairline-soft);border:1px solid var(--hairline);display:inline-block;padding:2px 8px;border-radius:9999px;">Expired</span>
                                @endif
                            </td>
                            <td style="padding:12px;text-align:right;white-space:nowrap;">
                                @if ($status === 'pending')
                                    <button type="button" data-copy="{{ $invite->url() }}" onclick="ujCopy(this)"  style="font-size:12.5px;font-weight:600;color:var(--ink);background:#fff;border:1px solid var(--hairline);cursor:pointer;padding:6px 10px;border-radius:8px;">Copy</button>
                                    <form method="POST" action="{{ route('superadmin.invites.destroy', $invite) }}" style="display:inline;" onsubmit="return confirm('Revoke this link? Anyone holding it will get a 404.')">
                                        @csrf
                                        <button type="submit" style="font-size:12.5px;font-weight:600;color:var(--red);background:#fff;border:1px solid var(--hairline);cursor:pointer;padding:6px 10px;border-radius:8px;">Revoke</button>
                                    </form>
                                @elseif ($status === 'used' && $invite->usedByTenant)
                                    <a href="{{ route('superadmin.companies.show', $invite->usedByTenant) }}" style="font-size:12.5px;color:var(--red);font-weight:500;text-decoration:none;">Open company</a>
                                @elseif ($status === 'expired' || $status === 'used')
                                    <form method="POST" action="{{ route('superadmin.invites.destroy', $invite) }}" style="display:inline;">
                                        @csrf
                                        <button type="submit" style="font-size:12.5px;font-weight:600;color:var(--red);background:#fff;border:1px solid var(--hairline);cursor:pointer;padding:6px 10px;border-radius:8px;">Remove</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" style="padding:22px;text-align:center;color:var(--muted);">No signup links yet.</td></tr>
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
