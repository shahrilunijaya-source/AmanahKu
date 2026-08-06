{{-- Direct-messages slide-over (toggled by `msg` on the shell root). Mounted at app root
     alongside the AI + Knowledge panels. It exists so you can answer someone without
     losing the screen you are on — mid-way through a leave form, say.

     The thread half is NOT drawn here. It is the same `partials.messages-thread`
     fragment the full screen uses, fetched from `messages.pane`, so the two can never
     drift again: this panel used to carry its own bubbles, its own composer and its own
     attachment tiles, and had already fallen behind on run grouping, day dividers and
     read receipts. The fragment sizes itself with container queries, so the 464px panel
     needs no compact variant. --}}
<template x-if="msg">
    <div>
        <div @click="msg = false" style="position:fixed;inset:0;background:rgba(31,30,26,.18);z-index:40;"></div>
        <aside class="uj-slide" x-data="messagesPanel()"
            style="position:fixed;top:0;right:0;width:464px;max-width:94vw;height:100vh;background:#fff;border-left:1px solid var(--hairline);z-index:50;display:flex;flex-direction:column;box-shadow:-12px 0 40px rgba(31,30,26,.10);">

            {{-- ── LIST ─────────────────────────────────────────────────────── --}}
            <div class="uj-msg-slide-view" x-show="view === 'list'">
                <div style="height:60px;flex-shrink:0;display:flex;align-items:center;gap:11px;padding:0 16px;border-bottom:1px solid var(--hairline);">
                    <span style="width:30px;height:30px;border-radius:8px;background:var(--red-tint);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--red)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H8l-4 4V6a2 2 0 0 1 2-2z"></path></svg>
                    </span>
                    <div style="flex:1;min-width:0;">
                        <div style="font-size:14px;font-weight:600;color:var(--ink);" x-text="$store.ui.lang==='en' ? 'Messages' : 'Mesej'">Messages</div>
                        <div style="font-size:11px;color:var(--muted);"><span x-text="threads.length"></span> <span x-text="$store.ui.lang==='en' ? 'conversations' : 'perbualan'">conversations</span></div>
                    </div>
                    <a href="{{ route('app.screen', 'messages') }}" style="font-size:12px;color:var(--red);text-decoration:none;font-weight:500;" x-text="$store.ui.lang==='en' ? 'View all →' : 'Lihat semua →'">View all →</a>
                    <button @click="msg = false" style="width:30px;height:30px;border-radius:7px;color:var(--muted);font-size:18px;flex-shrink:0;">×</button>
                </div>

                <div class="uj-msg-list" style="flex:1;padding:6px 10px;">
                    <template x-for="g in [
                            { key: 'today',   en: 'Today',     ms: 'Hari ini' },
                            { key: 'week',    en: 'This week', ms: 'Minggu ini' },
                            { key: 'earlier', en: 'Earlier',   ms: 'Lebih awal' },
                        ]" :key="g.key">
                    <div x-show="threads.some(t => t.bucket === g.key)">
                    <div class="uj-msg-grp" x-text="$store.ui.lang==='en' ? g.en : g.ms"></div>
                    <template x-for="t in threads.filter(t => t.bucket === g.key)" :key="t.id">
                        <button class="uj-msg-row" @click="open(t)" :data-unread="t.unread > 0 ? '' : null">
                            <span class="uj-msg-av" :style="'background:' + t.other.color" x-text="t.other.initials"></span>
                            <span class="uj-msg-rmid">
                                <span class="uj-msg-rtop">
                                    <span class="uj-msg-name" x-text="t.other.name"></span>
                                    <span class="uj-msg-at" x-text="$store.ui.lang==='en' ? t.atShort : t.atShortMs"></span>
                                </span>
                                <span class="uj-msg-pos" x-text="t.other.position"></span>
                                <span class="uj-msg-snip">
                                    <span x-show="t.lastMine" x-text="$store.ui.lang==='en' ? 'You: ' : 'Anda: '"></span><span
                                        x-text="t.snippet || ($store.ui.lang==='en' ? 'No messages yet' : 'Belum ada mesej')"></span>
                                </span>
                                <span class="uj-msg-badge" x-show="t.unread > 0">
                                    <span x-text="t.unread"></span>
                                    <span x-text="$store.ui.lang==='en' ? 'unread' : 'belum dibaca'"></span>
                                </span>
                            </span>
                        </button>
                    </template>
                    </div>
                    </template>
                    <template x-if="threads.length === 0">
                        <div style="padding:40px 24px;text-align:center;">
                            <div style="font-size:13px;color:var(--muted);line-height:1.5;" x-text="$store.ui.lang==='en' ? 'No conversations yet. Open a colleague\'s profile and hit Message to start one.' : 'Belum ada perbualan. Buka profil rakan sekerja dan tekan Mesej untuk mula.'">No conversations yet.</div>
                        </div>
                    </template>
                </div>

                <div style="flex-shrink:0;padding:12px 16px;border-top:1px solid var(--hairline);">
                    <a href="{{ route('app.screen', 'messages') }}" class="uj-btn-primary" style="display:flex;align-items:center;justify-content:center;height:42px;font-size:13.5px;text-decoration:none;"><span x-text="$store.ui.lang==='en' ? '＋ New message' : '＋ Mesej baharu'">＋ New message</span></a>
                </div>
            </div>

            {{-- ── THREAD ───────────────────────────────────────────────────────
                 One close button belongs to the panel; everything below it is the
                 shared fragment, which brings its own header, back button and dock. --}}
            <div class="uj-msg-slide-view" x-show="view === 'thread'" x-cloak>
                <div style="flex-shrink:0;display:flex;justify-content:flex-end;padding:8px 10px 0;">
                    <button @click="msg = false" style="width:30px;height:30px;border-radius:7px;color:var(--muted);font-size:18px;" aria-label="Close messages">×</button>
                </div>
                <div class="uj-msg-thread uj-msg-pane" x-ref="pane" :data-loading="loading ? '' : null"></div>
            </div>
        </aside>
    </div>
</template>
