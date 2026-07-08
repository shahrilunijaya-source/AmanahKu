{{--
    Feedback hub — two tabs: Report (bug/idea) and What's New (changelog).
    Pinned button lives at the bottom of the sidebar; it dispatches `feedback-open`.
    Mirrors the welcome modal's pattern (Alpine + $store.ui.lang bilingual).
    What's New is fed by config/changelog.php via a view composer ($releases / $latestVersion);
    opening that tab marks the latest version seen, which clears the "New" badge everywhere.
    Reopens itself after a failed submit so validation errors stay visible.
--}}
@php
    $feedbackHasError = $errors->hasAny(['type', 'title', 'description', 'page_url']);
    $releases = $releases ?? [];
    $noteMeta = [
        'new' => ['en' => 'New', 'ms' => 'Baharu', 'dot' => 'var(--success)'],
        'improved' => ['en' => 'Improved', 'ms' => 'Diperbaik', 'dot' => 'var(--info)'],
        'fixed' => ['en' => 'Fixed', 'ms' => 'Dibaiki', 'dot' => 'var(--amber)'],
    ];
@endphp
<div x-data="{ show: {{ $feedbackHasError ? 'true' : 'false' }}, tab: 'report', type: '{{ old('type', 'bug') }}' }"
     x-show="show" x-cloak
     @feedback-open.window="show = true; tab = 'report'; $nextTick(() => { document.getElementById('fb-page-url').value = window.location.href; $refs.title?.focus(); })"
     @keydown.escape.window="show = false"
     style="position:fixed;inset:0;z-index:200;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(31,30,26,.55);backdrop-filter:blur(2px);">

    <div @click.outside="show = false" class="uj-slide"
         style="width:100%;max-width:480px;margin:auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 24px 70px rgba(31,30,26,.30);display:flex;flex-direction:column;max-height:88vh;">

        {{-- Header + tabs --}}
        <div style="padding:20px 26px 0;border-bottom:1px solid var(--hairline);flex-shrink:0;">
            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;">
                <h2 style="font-size:18px;font-weight:600;color:var(--ink);margin:0;letter-spacing:-0.3px;"
                    x-text="$store.ui.lang==='en' ? 'Feedback &amp; updates' : 'Maklum balas &amp; kemas kini'"></h2>
                <button type="button" @click="show = false" aria-label="Close"
                        style="width:30px;height:30px;border-radius:8px;flex-shrink:0;color:var(--muted);background:var(--canvas);font-size:17px;line-height:1;">×</button>
            </div>
            <div style="display:flex;gap:20px;margin-top:14px;">
                <button type="button" @click="tab = 'report'"
                        :style="'padding-bottom:11px;font-size:13.5px;font-weight:600;border-bottom:2px solid '+(tab==='report'?'var(--red)':'transparent')+';color:'+(tab==='report'?'var(--ink)':'var(--muted)')"
                        x-text="$store.ui.lang==='en' ? 'Report' : 'Lapor'"></button>
                <button type="button" @click="tab = 'whatsnew'; $store.changelog.markSeen()"
                        :style="'display:flex;align-items:center;gap:7px;padding-bottom:11px;font-size:13.5px;font-weight:600;border-bottom:2px solid '+(tab==='whatsnew'?'var(--red)':'transparent')+';color:'+(tab==='whatsnew'?'var(--ink)':'var(--muted)')">
                    <span x-text="$store.ui.lang==='en' ? 'What\'s new' : 'Apa baharu'"></span>
                    <span x-show="$store.changelog.unseen" x-cloak style="width:7px;height:7px;border-radius:50%;background:var(--red);"></span>
                </button>
            </div>
        </div>

        {{-- ── Report tab ── --}}
        <form x-show="tab === 'report'" action="{{ route('feedback.store') }}" method="post" style="display:flex;flex-direction:column;min-height:0;">
            @csrf
            <input type="hidden" id="fb-page-url" name="page_url" value="{{ old('page_url') }}">
            <input type="hidden" name="type" :value="type">

            <div style="padding:20px 26px;display:flex;flex-direction:column;gap:16px;overflow-y:auto;max-height:calc(88vh - 180px);">
                <p style="font-size:13px;color:var(--muted);margin:0;line-height:1.5;"
                   x-text="$store.ui.lang==='en' ? 'Spotted a bug or have an idea? Tell us — it goes straight to the team.' : 'Jumpa pepijat atau ada idea? Beritahu kami — terus sampai kepada pasukan.'"></p>

                {{-- Type: segmented Bug / Idea --}}
                <div>
                    <label style="display:block;font-size:12.5px;font-weight:600;color:var(--ink);margin-bottom:7px;"
                           x-text="$store.ui.lang==='en' ? 'What is this about?' : 'Mengenai apa?'"></label>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                        <button type="button" @click="type = 'bug'"
                                :style="'display:flex;align-items:center;gap:9px;padding:11px 13px;border-radius:10px;text-align:left;transition:all .15s;border:1.5px solid '+(type==='bug'?'var(--red)':'var(--hairline)')+';background:'+(type==='bug'?'var(--red-tint)':'#fff')">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" :stroke="type==='bug'?'var(--red)':'var(--muted)'" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="M8 2l1.5 2M16 2l-1.5 2M9 7h6a3 3 0 0 1 3 3v3a6 6 0 0 1-12 0v-3a3 3 0 0 1 3-3zM3 13h3M18 13h3M4 8l2 1M20 8l-2 1M4 18l2-1M20 18l-2-1"></path></svg>
                            <span style="min-width:0;">
                                <span :style="'display:block;font-size:13.5px;font-weight:600;color:'+(type==='bug'?'var(--red)':'var(--ink)')" x-text="$store.ui.lang==='en' ? 'Report a bug' : 'Lapor pepijat'"></span>
                                <span style="display:block;font-size:11.5px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Something is broken' : 'Ada yang rosak'"></span>
                            </span>
                        </button>
                        <button type="button" @click="type = 'idea'"
                                :style="'display:flex;align-items:center;gap:9px;padding:11px 13px;border-radius:10px;text-align:left;transition:all .15s;border:1.5px solid '+(type==='idea'?'var(--red)':'var(--hairline)')+';background:'+(type==='idea'?'var(--red-tint)':'#fff')">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" :stroke="type==='idea'?'var(--red)':'var(--muted)'" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="M9 18h6M10 22h4M12 2a7 7 0 0 0-4 12.7c.6.5 1 1.3 1 2.1h6c0-.8.4-1.6 1-2.1A7 7 0 0 0 12 2z"></path></svg>
                            <span style="min-width:0;">
                                <span :style="'display:block;font-size:13.5px;font-weight:600;color:'+(type==='idea'?'var(--red)':'var(--ink)')" x-text="$store.ui.lang==='en' ? 'Suggest an idea' : 'Cadang idea'"></span>
                                <span style="display:block;font-size:11.5px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Something to improve' : 'Sesuatu nak baiki'"></span>
                            </span>
                        </button>
                    </div>
                </div>

                {{-- Title --}}
                <div>
                    <label for="fb-title" style="display:block;font-size:12.5px;font-weight:600;color:var(--ink);margin-bottom:6px;"
                           x-text="$store.ui.lang==='en' ? 'Summary' : 'Ringkasan'"></label>
                    <input id="fb-title" x-ref="title" name="title" type="text" value="{{ old('title') }}" maxlength="160" required
                           class="uj-fb-input"
                           :placeholder="$store.ui.lang==='en' ? 'A short, clear title' : 'Tajuk pendek dan jelas'"
                           style="width:100%;height:44px;padding:0 14px;border:1px solid var(--hairline);border-radius:9px;font-size:14px;color:var(--ink);background:#fff;outline:none;transition:border .15s,box-shadow .15s;" />
                    @error('title')<p style="font-size:12px;color:var(--error);margin:6px 0 0;">{{ $message }}</p>@enderror
                </div>

                {{-- Description --}}
                <div>
                    <label for="fb-desc" style="display:block;font-size:12.5px;font-weight:600;color:var(--ink);margin-bottom:6px;">
                        <span x-text="$store.ui.lang==='en' ? 'Details' : 'Butiran'"></span>
                        <span style="font-weight:400;color:var(--muted-soft);" x-text="$store.ui.lang==='en' ? '(optional)' : '(pilihan)'"></span>
                    </label>
                    <textarea id="fb-desc" name="description" rows="4" maxlength="2000"
                              class="uj-fb-input"
                              :placeholder="type==='bug' ? ($store.ui.lang==='en' ? 'What happened? What did you expect instead? Steps to repeat it help a lot.' : 'Apa yang berlaku? Apa yang anda jangka? Langkah ulangan amat membantu.') : ($store.ui.lang==='en' ? 'What would you like, and what problem does it solve?' : 'Apa yang anda mahu, dan masalah apa ia selesaikan?')"
                              style="width:100%;padding:11px 14px;border:1px solid var(--hairline);border-radius:9px;font-size:14px;color:var(--ink);background:#fff;outline:none;resize:vertical;line-height:1.55;font-family:inherit;transition:border .15s,box-shadow .15s;">{{ old('description') }}</textarea>
                    @error('description')<p style="font-size:12px;color:var(--error);margin:6px 0 0;">{{ $message }}</p>@enderror
                </div>

                <p style="display:flex;align-items:center;gap:7px;font-size:11.5px;color:var(--muted-soft);margin:0;">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><circle cx="12" cy="12" r="10"></circle><path d="M12 16v-4M12 8h.01"></path></svg>
                    <span x-text="$store.ui.lang==='en' ? 'We attach the page you are on so we can find it faster.' : 'Kami lampirkan halaman semasa anda supaya lebih mudah dicari.'"></span>
                </p>
            </div>

            <div style="padding:15px 26px 20px;border-top:1px solid var(--hairline);display:flex;align-items:center;justify-content:flex-end;gap:10px;flex-shrink:0;">
                <button type="button" @click="show = false"
                        style="height:42px;padding:0 16px;border-radius:9px;font-size:13.5px;font-weight:500;color:var(--body);background:#fff;border:1px solid var(--hairline);"
                        x-text="$store.ui.lang==='en' ? 'Cancel' : 'Batal'"></button>
                <button type="submit" class="uj-btn-primary" style="height:42px;padding:0 22px;font-size:13.5px;"
                        x-text="$store.ui.lang==='en' ? 'Send feedback' : 'Hantar'"></button>
            </div>
        </form>

        {{-- ── What's New tab ── --}}
        <div x-show="tab === 'whatsnew'" x-cloak style="display:flex;flex-direction:column;min-height:0;">
            {{-- max-height (not flex) drives the scroll: x-show wipes inline display:flex on the
                 tab wrapper, so a flex-shrink chain never engages. A hard cap always does. --}}
            <div style="padding:18px 26px 22px;overflow-y:auto;max-height:calc(88vh - 180px);display:flex;flex-direction:column;gap:22px;">
                @forelse ($releases as $rel)
                    <div>
                        <div style="display:flex;align-items:baseline;justify-content:space-between;gap:10px;margin-bottom:11px;">
                            <h3 style="font-size:14.5px;font-weight:600;color:var(--ink);margin:0;">{{ $rel['title'] }}</h3>
                            <span style="font-size:11.5px;color:var(--muted-soft);font-family:var(--font-mono);white-space:nowrap;flex-shrink:0;">{{ $rel['date'] }}</span>
                        </div>
                        @foreach ($noteMeta as $key => $meta)
                            @php $lines = $rel['notes'][$key] ?? []; @endphp
                            @if (!empty($lines))
                                <div style="margin-bottom:12px;">
                                    <div style="display:flex;align-items:center;gap:7px;margin-bottom:7px;">
                                        <span style="width:6px;height:6px;border-radius:50%;background:{{ $meta['dot'] }};"></span>
                                        <span style="font-size:10.5px;font-weight:700;letter-spacing:.6px;text-transform:uppercase;color:var(--muted);"
                                              x-text="$store.ui.lang==='en' ? @js($meta['en']) : @js($meta['ms'])">{{ $meta['en'] }}</span>
                                    </div>
                                    <ul style="margin:0;padding:0 0 0 19px;display:flex;flex-direction:column;gap:5px;">
                                        @foreach ($lines as $line)
                                            <li style="font-size:13px;color:var(--body);line-height:1.5;">{{ $line }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        @endforeach
                    </div>
                @empty
                    <div style="text-align:center;padding:34px 0;font-size:13px;color:var(--muted-soft);"
                         x-text="$store.ui.lang==='en' ? 'No updates yet.' : 'Tiada kemas kini lagi.'"></div>
                @endforelse
            </div>
            <div style="padding:15px 26px 20px;border-top:1px solid var(--hairline);display:flex;align-items:center;justify-content:space-between;gap:12px;flex-shrink:0;">
                <a href="{{ route('app.screen', ['screen' => 'updates']) }}" class="uj-link" style="font-size:12.5px;color:var(--red);text-decoration:none;font-weight:500;"
                   x-text="$store.ui.lang==='en' ? 'View all updates →' : 'Lihat semua kemas kini →'">View all updates →</a>
                <button type="button" @click="show = false" class="uj-btn-primary" style="height:42px;padding:0 22px;font-size:13.5px;"
                        x-text="$store.ui.lang==='en' ? 'Got it' : 'Faham'"></button>
            </div>
        </div>
    </div>
</div>
