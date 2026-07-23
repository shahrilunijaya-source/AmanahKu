@extends('layouts.app')

@section('screen')
@include('partials.guide', [
    'key' => 'messages',
    'en'  => [
        'title' => 'Messages',
        'body'  => 'Private one-to-one messages with anyone in the company. Pick a conversation on the left, or start a new one with "+ New". You can also message someone straight from their profile using the Message button.',
    ],
    'ms'  => [
        'title' => 'Mesej',
        'body'  => 'Mesej peribadi satu-dengan-satu dengan sesiapa dalam syarikat. Pilih perbualan di sebelah kiri, atau mula yang baharu dengan "+ Baharu". Anda juga boleh mesej seseorang terus dari profil mereka guna butang Mesej.',
    ],
])

@php $a = $msgActive ?? null; @endphp

<style>@media (hover: none) and (pointer: coarse) { .uj-cam-only { display:inline-flex !important; } }</style>

<div style="display:flex;gap:16px;align-items:stretch;height:calc(100vh - 240px);min-height:460px;">

    {{-- ── Conversation list ─────────────────────────────────────────────── --}}
    <div class="uj-card" style="width:330px;flex-shrink:0;display:flex;flex-direction:column;overflow:hidden;" x-data="{ compose: false, q: '' }">
        <div style="padding:14px 16px;border-bottom:1px solid var(--hairline);display:flex;align-items:center;gap:8px;flex-shrink:0;">
            <span style="flex:1;font-size:14px;font-weight:600;color:var(--ink);" x-text="$store.ui.lang==='en' ? 'Conversations' : 'Perbualan'">Conversations</span>
            @if ($msgCanSend ?? false)
                <button @click="compose = ! compose; q = ''" class="uj-btn-ghost" style="height:32px;padding:0 12px;font-size:12.5px;"><span x-text="compose ? ($store.ui.lang==='en' ? 'Cancel' : 'Batal') : ($store.ui.lang==='en' ? '+ New' : '+ Baharu')">+ New</span></button>
            @endif
        </div>

        {{-- New-message recipient picker --}}
        @if ($msgCanSend ?? false)
            <div x-show="compose" x-cloak style="padding:12px 14px;border-bottom:1px solid var(--hairline);flex-shrink:0;">
                <input x-model="q" :placeholder="$store.ui.lang==='en' ? 'Search colleagues…' : 'Cari rakan sekerja…'" style="width:100%;height:36px;padding:0 12px;background:var(--canvas);border:1px solid var(--hairline);border-radius:8px;font-size:13px;outline:none;color:var(--ink);" />
                <div style="max-height:240px;overflow-y:auto;margin-top:8px;">
                    @forelse ($msgRecipients as $r)
                        <a href="{{ route('app.screen', 'messages') }}?to={{ $r['id'] }}"
                           x-show="q === '' || @js(strtolower($r['name'])).includes(q.toLowerCase())"
                           style="display:flex;align-items:center;gap:10px;padding:8px 6px;border-radius:8px;text-decoration:none;">
                            <span style="width:30px;height:30px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;color:#fff;font-size:11px;font-weight:600;background:{{ $r['color'] }};">{{ $r['initials'] }}</span>
                            <span style="min-width:0;">
                                <span style="display:block;font-size:13px;color:var(--ink);font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $r['name'] }}</span>
                                <span style="display:block;font-size:11px;color:var(--muted-soft);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $r['position'] }}</span>
                            </span>
                        </a>
                    @empty
                        <div style="padding:14px;text-align:center;font-size:12px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'No colleagues to message.' : 'Tiada rakan sekerja untuk dimesej.'">No colleagues.</div>
                    @endforelse
                </div>
            </div>
        @endif

        <div style="flex:1;overflow-y:auto;">
            @forelse ($msgConversations as $c)
                @php $on = $a && $a['conversationId'] === $c['id']; @endphp
                <a href="{{ route('app.screen', 'messages') }}?c={{ $c['id'] }}"
                   style="display:flex;align-items:center;gap:11px;padding:13px 16px;border-bottom:1px solid var(--hairline-soft);text-decoration:none;background:{{ $on ? 'var(--canvas)' : '#fff' }};">
                    <span style="width:38px;height:38px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;color:#fff;font-size:12px;font-weight:600;background:{{ $c['other']['color'] }};">{{ $c['other']['initials'] }}</span>
                    <span style="flex:1;min-width:0;">
                        <span style="display:flex;align-items:center;gap:7px;">
                            <span style="flex:1;min-width:0;font-size:13.5px;font-weight:600;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $c['other']['name'] }}</span>
                            <span style="flex-shrink:0;font-size:10.5px;font-family:var(--font-mono);color:var(--muted-soft);">{{ $c['at'] }}</span>
                        </span>
                        <span style="display:flex;align-items:center;gap:7px;margin-top:2px;">
                            <span style="flex:1;min-width:0;font-size:12px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">@if ($c['lastMine'])<span style="color:var(--muted-soft);" x-text="$store.ui.lang==='en' ? 'You: ' : 'Anda: '">You: </span>@endif{{ $c['snippet'] ?? '' }}</span>
                            @if ($c['unread'] > 0)
                                <span style="flex-shrink:0;min-width:18px;height:18px;padding:0 5px;background:var(--red);color:#fff;border-radius:9999px;font-family:var(--font-mono);font-weight:600;font-size:10.5px;display:flex;align-items:center;justify-content:center;">{{ $c['unread'] }}</span>
                            @endif
                        </span>
                    </span>
                </a>
            @empty
                <div style="padding:40px 24px;text-align:center;font-size:12.5px;color:var(--muted);line-height:1.5;" x-text="$store.ui.lang==='en' ? 'No conversations yet.' : 'Belum ada perbualan.'">No conversations yet.</div>
            @endforelse
        </div>
    </div>

    {{-- ── Active thread ─────────────────────────────────────────────────── --}}
    <div class="uj-card" style="flex:1;display:flex;flex-direction:column;overflow:hidden;min-width:0;">
        @if ($a)
            <div style="padding:14px 18px;border-bottom:1px solid var(--hairline);display:flex;align-items:center;gap:11px;flex-shrink:0;">
                <span style="width:40px;height:40px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;color:#fff;font-size:13px;font-weight:600;background:{{ $a['other']['color'] }};">{{ $a['other']['initials'] }}</span>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:15px;font-weight:600;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $a['other']['name'] }}</div>
                    <div style="font-size:12px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $a['other']['position'] }}</div>
                </div>
                @if ($a['other']['id'])
                    <a href="{{ route('app.screen', ['screen' => 'profile', 'emp' => $a['other']['id']]) }}" class="uj-btn-ghost" style="height:34px;padding:0 14px;font-size:12.5px;text-decoration:none;display:inline-flex;align-items:center;"><span x-text="$store.ui.lang==='en' ? 'View profile' : 'Lihat profil'">View profile</span></a>
                @endif
            </div>

            <div x-ref="msgs" x-data="{
                    init() {
                        this.$nextTick(() => { this.$refs.msgs && (this.$refs.msgs.scrollTop = this.$refs.msgs.scrollHeight); });
                        @if ($a['conversationId'])
                        fetch('{{ url('/app/messages') }}/{{ $a['conversationId'] }}/read', {
                            method: 'POST',
                            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' },
                        }).then(r => r.json()).then(d => { if (this.$store.msgbadge) this.$store.msgbadge.unread = d.unread; }).catch(() => {});
                        @endif
                    }
                }"
                style="flex:1;overflow-y:auto;padding:18px;display:flex;flex-direction:column;gap:8px;background:var(--canvas);">
                @forelse ($a['messages'] as $m)
                    <div style="max-width:70%;{{ $m['mine'] ? 'align-self:flex-end;' : 'align-self:flex-start;' }}">
                        @if ($m['body'] !== '')
                            <div style="padding:10px 13px;border-radius:14px;font-size:13.5px;line-height:1.55;white-space:pre-wrap;word-break:break-word;{{ $m['mine'] ? 'background:var(--red);color:#fff;border-bottom-right-radius:4px;' : 'background:#fff;color:var(--ink);border:1px solid var(--hairline);border-bottom-left-radius:4px;' }}">{{ $m['body'] }}</div>
                        @endif
                        @foreach ($m['attachments'] as $att)
                            <div style="margin-top:{{ $m['body'] !== '' || ! $loop->first ? '6px' : '0' }};">
                                @if ($att['isImage'])
                                    <a href="{{ $att['url'] }}" target="_blank" rel="noopener">
                                        <img src="{{ $att['url'] }}" alt="{{ $att['name'] }}" style="max-width:220px;max-height:220px;border-radius:12px;display:block;border:1px solid var(--hairline);" />
                                    </a>
                                @else
                                    <a href="{{ $att['url'] }}" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:8px;padding:9px 12px;border-radius:12px;text-decoration:none;background:#fff;border:1px solid var(--hairline);color:var(--ink);max-width:240px;">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                                        <span style="font-size:12.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $att['name'] }}</span>
                                    </a>
                                @endif
                            </div>
                        @endforeach
                        <div style="font-size:10px;font-family:var(--font-mono);color:var(--muted-soft);margin-top:3px;{{ $m['mine'] ? 'text-align:right;' : '' }}">{{ $m['at'] }}</div>
                    </div>
                @empty
                    <div style="margin:auto;text-align:center;font-size:13px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'No messages yet — say hello.' : 'Belum ada mesej — sapa dahulu.'">No messages yet — say hello.</div>
                @endforelse
            </div>

            @if ($msgCanSend ?? false)
                <form method="post" action="{{ route('messages.send') }}" enctype="multipart/form-data"
                      x-data="{ files: [], sync(e){ this.files = Array.from(this.$refs.file.files); },
                                add(e){ const dt = new DataTransfer(); this.files.forEach(f => dt.items.add(f));
                                        Array.from(e.target.files).forEach(f => dt.items.add(f));
                                        this.$refs.file.files = dt.files; this.files = Array.from(dt.files); e.target.value=''; } }"
                      style="padding:12px 16px;border-top:1px solid var(--hairline);display:flex;flex-direction:column;gap:8px;flex-shrink:0;">
                    @csrf
                    {{-- Selected-files preview --}}
                    <div x-show="files.length" x-cloak style="display:flex;flex-wrap:wrap;gap:6px;">
                        <template x-for="(f, i) in files" :key="i">
                            <span style="display:inline-flex;align-items:center;gap:6px;padding:5px 9px;background:var(--canvas);border:1px solid var(--hairline);border-radius:8px;font-size:11.5px;color:var(--ink);max-width:180px;">
                                <span x-text="f.name" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"></span>
                            </span>
                        </template>
                    </div>
                    <div style="display:flex;align-items:flex-end;gap:10px;">
                    @if ($a['conversationId'])
                        <input type="hidden" name="conversation_id" value="{{ $a['conversationId'] }}">
                    @else
                        <input type="hidden" name="to" value="{{ $a['to'] }}">
                    @endif
                    {{-- Deep-link draft (?draft=…) seeds a blank new composer only — e.g. the
                         "🎂 Wish" button from the dashboard. Blade-escaped; server still caps at 5000. --}}
                        {{-- Real inputs. `file` holds the batch; camera appends into it. --}}
                        <input x-ref="file" type="file" name="attachments[]" multiple
                               accept="{{ '.jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.txt,.csv' }}"
                               @change="sync" style="display:none;" />
                        <input x-ref="cam" type="file" name="attachments[]" accept="image/*" capture="environment"
                               @change="add" style="display:none;" />
                        <button type="button" @click="$refs.file.click()" class="uj-btn-ghost" title="Attach"
                                style="height:44px;width:44px;flex-shrink:0;display:flex;align-items:center;justify-content:center;padding:0;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path></svg>
                        </button>
                        {{-- Camera trigger — mobile/touch only. --}}
                        <button type="button" @click="$refs.cam.click()" class="uj-btn-ghost uj-cam-only" title="Camera"
                                style="height:44px;width:44px;flex-shrink:0;display:none;align-items:center;justify-content:center;padding:0;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path><circle cx="12" cy="13" r="4"></circle></svg>
                        </button>
                    <textarea name="body" maxlength="5000" rows="1"
                              x-data @keydown.enter.prevent="$el.form.requestSubmit()"
                              :placeholder="$store.ui.lang==='en' ? 'Write a message…' : 'Tulis mesej…'"
                              style="flex:1;min-height:44px;max-height:140px;padding:11px 13px;border:1px solid var(--hairline);border-radius:10px;font-size:13.5px;resize:none;outline:none;font-family:inherit;line-height:1.5;">{{ (empty($a['conversationId']) && empty($a['messages']) && request()->filled('draft')) ? \Illuminate\Support\Str::limit(request('draft'), 5000, '') : '' }}</textarea>
                    <button type="submit" class="uj-btn-primary" style="height:44px;padding:0 18px;font-size:13.5px;flex-shrink:0;"><span x-text="$store.ui.lang==='en' ? 'Send' : 'Hantar'">Send</span></button>
                    </div>
                </form>
            @endif
        @else
            <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;padding:40px;text-align:center;">
                <span style="width:56px;height:56px;border-radius:50%;background:var(--canvas);display:flex;align-items:center;justify-content:center;">
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="var(--muted-soft)" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H8l-4 4V6a2 2 0 0 1 2-2z"></path></svg>
                </span>
                <div style="font-size:14px;color:var(--muted);max-width:280px;line-height:1.5;" x-text="$store.ui.lang==='en' ? 'Select a conversation on the left, or start a new one.' : 'Pilih perbualan di sebelah kiri, atau mula yang baharu.'">Select a conversation.</div>
            </div>
        @endif
    </div>
</div>
@endsection
