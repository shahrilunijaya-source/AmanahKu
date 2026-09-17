{{-- Google Calendar control for the task board. State and copy live in resources/js/calendar-sync.js;
     every action is JSON so the board never reloads. Mockup: docs/build/design/calendar-sync. --}}
<style>
    .cs{position:relative;margin-left:auto}
    .cs-pill{display:inline-flex;align-items:center;gap:8px;height:32px;padding:0 12px 0 10px;border:1px solid var(--hairline);border-radius:9999px;background:#fff;color:var(--ink);font-size:12.5px;font-weight:600;cursor:pointer;transition:border-color .14s var(--ease)}
    .cs-pill:hover{border-color:#d4d2cb}
    .cs-pill.is-warn{border-color:#f0c9d3;background:#fdf3f6;color:var(--error-ink)}
    .cs-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}
    .cs-sub{font-weight:500;color:var(--muted);font-size:11.5px}
    .cs-pill.is-warn .cs-sub{color:var(--error-ink)}
    .cs-badge{min-width:18px;height:18px;padding:0 5px;border-radius:9999px;background:var(--error);color:#fff;font-size:10.5px;font-family:var(--font-mono);display:grid;place-items:center}
    .cs-panel{position:absolute;right:0;top:40px;width:340px;max-width:calc(100vw - 32px);background:#fff;border:1px solid var(--hairline);border-radius:14px;box-shadow:var(--shadow-menu);z-index:40;overflow:hidden;text-align:left}
    .cs-head{padding:14px 16px 12px;display:flex;gap:11px;align-items:flex-start;border-bottom:1px solid var(--hairline-soft)}
    .cs-head svg{flex-shrink:0;margin-top:2px}
    .cs-title{font-weight:600;font-size:13.5px;color:var(--ink)}
    .cs-text{font-size:12px;color:var(--muted);margin-top:2px;line-height:1.45}
    .cs-body{padding:12px 16px 14px}
    .cs-btn{width:100%;height:34px;border-radius:9px;font-size:12.5px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:8px;border:1px solid transparent;background:var(--red);color:#fff;text-decoration:none}
    .cs-btn:hover{background:var(--red-active)}
    .cs-btn[disabled]{background:#eceae4;color:var(--muted-soft);cursor:not-allowed}
    .cs-btn.is-busy[disabled]{background:var(--red);color:#fff;opacity:.85}
    .cs-btn-google{background:#fff;color:#3c4043;border-color:#dadce0;height:38px}
    .cs-btn-google:hover{background:#f8f9fa}
    .cs-note{font-size:11.5px;color:var(--muted);margin-top:8px;text-align:center;line-height:1.45}
    .cs-spin{width:14px;height:14px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:cs-spin .8s linear infinite}
    .cs-pill .cs-spin{border-color:rgba(214,35,43,.25);border-top-color:var(--red)}
    @keyframes cs-spin{to{transform:rotate(360deg)}}
    .cs-bar{height:4px;border-radius:4px;background:var(--hairline-soft);overflow:hidden;margin-top:10px}
    .cs-bar i{display:block;height:100%;background:var(--red);border-radius:4px;transition:width .3s var(--ease)}
    .cs-result{display:flex;gap:9px;background:#eef7f3;border:1px solid #cfe7dc;color:var(--success-ink);border-radius:10px;padding:9px 11px;font-size:12px;margin-bottom:10px;line-height:1.45}
    .cs-result.is-bad{background:#fdf3f6;border-color:#f0c9d3;color:var(--error-ink)}
    .cs-issues{margin-top:12px;border-top:1px solid var(--hairline-soft);padding-top:10px}
    .cs-issues h4{margin:0 0 6px;font-size:10.5px;letter-spacing:.6px;text-transform:uppercase;color:var(--error)}
    .cs-issue{display:flex;gap:8px;align-items:center;padding:6px 0;font-size:12px}
    .cs-issue-name{flex:1;min-width:0}
    .cs-issue-name a{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--ink);font-weight:500;text-decoration:none}
    .cs-issue-name span{display:block;color:var(--muted);font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .cs-retry{height:26px;padding:0 9px;font-size:11px;border-radius:7px;background:#fff;color:var(--body);border:1px solid var(--hairline);cursor:pointer}
    .cs-retry[disabled]{color:var(--muted-soft);cursor:not-allowed}
    .cs-foot{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:10px 16px;background:#fbfbf9;border-top:1px solid var(--hairline-soft);font-size:11.5px;color:var(--muted)}
    .cs-link{background:none;border:none;color:var(--muted);font-size:12px;text-decoration:underline;cursor:pointer;padding:0}
    @media (prefers-reduced-motion: reduce){.cs-spin{animation:none}.cs-bar i{transition:none}}
</style>

@php
    $gIcon = '<svg width="16" height="16" viewBox="0 0 18 18" aria-hidden="true"><path fill="#4285F4" d="M17.64 9.2c0-.64-.06-1.25-.16-1.84H9v3.48h4.84a4.14 4.14 0 0 1-1.8 2.72v2.26h2.92c1.7-1.57 2.68-3.88 2.68-6.62z"/><path fill="#34A853" d="M9 18c2.43 0 4.47-.8 5.96-2.18l-2.92-2.26c-.8.54-1.84.86-3.04.86-2.34 0-4.32-1.58-5.03-3.7H.96v2.33A9 9 0 0 0 9 18z"/><path fill="#FBBC05" d="M3.97 10.72A5.4 5.4 0 0 1 3.68 9c0-.6.1-1.18.29-1.72V4.95H.96A9 9 0 0 0 0 9c0 1.45.35 2.83.96 4.05l3.01-2.33z"/><path fill="#EA4335" d="M9 3.58c1.32 0 2.51.45 3.44 1.35l2.59-2.59C13.46.89 11.43 0 9 0A9 9 0 0 0 .96 4.95l3.01 2.33C4.68 5.16 6.66 3.58 9 3.58z"/></svg>';
@endphp

<div class="cs" data-testid="calendar-sync"
     x-data="calendarSync(@js($calendarSync), @js([
        'status' => route('calendar-sync.status'),
        'sync' => route('calendar-sync.sync'),
        'retry' => route('calendar-sync.retry', ['workItem' => '__ID__']),
        'card' => route('app.screen', 'board').'?card=__ID__',
     ], JSON_UNESCAPED_SLASHES))"
     @keydown.escape.window="open = false" @click.outside="open = false">

    <button type="button" class="cs-pill" :class="{ 'is-warn': tone === 'warn' }" @click="open = !open" :aria-expanded="open" aria-haspopup="dialog">
        <span x-show="running" class="cs-spin" aria-hidden="true"></span>
        <span x-show="!running" class="cs-dot" :style="{ background: dotColor }" aria-hidden="true"></span>
        <span>Google Calendar</span>
        <span x-show="issueCount > 0 && s.state === 'connected' && !running" class="cs-badge" x-text="issueCount"></span>
        <span x-show="!(issueCount > 0 && s.state === 'connected' && !running)" class="cs-sub" x-text="pillSub"></span>
    </button>

    <div class="cs-panel" x-show="open" x-cloak x-transition.opacity.duration.120ms role="dialog" :aria-label="t('Google Calendar', 'Kalendar Google')">
        <div class="cs-head">
            {!! $gIcon !!}
            <div>
                <div class="cs-title" x-text="headTitle"></div>
                <div class="cs-text" x-text="headText"></div>
            </div>
        </div>

        {{-- Not connected / expired: the only Connect button in the app. --}}
        <template x-if="s.state !== 'connected'">
            <div class="cs-body">
                <a href="{{ route('google-calendar.redirect') }}" class="cs-btn cs-btn-google">{!! $gIcon !!}
                    <span x-text="s.state === 'expired' ? t('Reconnect Google Calendar', 'Sambung semula Kalendar Google') : t('Connect Google Calendar', 'Sambung Kalendar Google')"></span>
                </a>
                <div class="cs-note" x-text="s.state === 'expired'
                    ? t('This happens if access was removed in your Google account, or Google cancelled it.', 'Ini berlaku jika akses dibuang dalam akaun Google anda, atau Google membatalkannya.')
                    : t('Your cards are sent automatically right after you connect.', 'Kad anda dihantar secara automatik sebaik sahaja anda bersambung.')"></div>
            </div>
        </template>

        <template x-if="s.state === 'connected'">
            <div>
                <div class="cs-body">
                    <div x-show="result" class="cs-result" :class="{ 'is-bad': result && result.failed > 0 }" role="status">
                        <span x-text="result && result.failed > 0 ? '!' : '✓'" aria-hidden="true"></span>
                        <span x-text="resultText"></span>
                    </div>

                    <button type="button" class="cs-btn" :class="{ 'is-busy': running }" :disabled="running || cooldown > 0" @click="syncNow()">
                        <span x-show="running" class="cs-spin" aria-hidden="true"></span>
                        <span x-text="buttonText"></span>
                    </button>
                    <div x-show="running && progress.total > 0" class="cs-bar" aria-hidden="true"><i :style="{ width: percent + '%' }"></i></div>
                    <div class="cs-note" x-text="noteText"></div>

                    <div x-show="issueCount > 0" class="cs-issues">
                        <h4 x-text="t(issueCount + (issueCount === 1 ? ' card' : ' cards') + ' could not be sent', issueCount + ' kad tidak dapat dihantar')"></h4>
                        <template x-for="issue in s.issues" :key="issue.id">
                            <div class="cs-issue">
                                <div class="cs-issue-name">
                                    <a :href="urls.card.replace('__ID__', issue.id)" x-text="issue.title"></a>
                                    <span x-text="friendly(issue.message)" :title="issue.message"></span>
                                </div>
                                <button type="button" class="cs-retry" :disabled="running || cooldown > 0" @click="retry(issue.id)" x-text="t('Retry', 'Cuba lagi')"></button>
                            </div>
                        </template>
                    </div>
                </div>
                <div class="cs-foot">
                    <span x-text="t('Only your “Amanahku” calendar is used.', 'Hanya kalendar “Amanahku” anda digunakan.')"></span>
                    <form method="post" action="{{ route('google-calendar.disconnect') }}">
                        @csrf
                        <button type="submit" class="cs-link" x-text="t('Disconnect', 'Putuskan')"></button>
                    </form>
                </div>
            </div>
        </template>
    </div>
</div>
