{{-- Direct-messages slide-over (toggled by `msg` on the shell root). Mounted at app root
     alongside the AI + Knowledge panels. List of conversations → tap one to open an inline
     thread (fetched) with a composer that posts without leaving the page. --}}
<template x-if="msg">
    <div>
        <div @click="msg = false" style="position:fixed;inset:0;background:rgba(31,30,26,.18);z-index:40;"></div>
        <aside class="uj-slide" x-data="messagesPanel(@js($msgThreads))"
            style="position:fixed;top:0;right:0;width:464px;max-width:94vw;height:100vh;background:#fff;border-left:1px solid var(--hairline);z-index:50;display:flex;flex-direction:column;box-shadow:-12px 0 40px rgba(31,30,26,.10);">

            {{-- ── LIST ─────────────────────────────────────────────────────── --}}
            <div x-show="view === 'list'" style="display:flex;flex-direction:column;height:100%;">
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

                <div style="flex:1;overflow-y:auto;">
                    <template x-for="t in threads" :key="t.id">
                        <button @click="open(t)" style="width:100%;display:flex;align-items:center;gap:11px;padding:13px 16px;border-bottom:1px solid var(--hairline-soft);background:none;cursor:pointer;text-align:left;">
                            <span :style="'width:38px;height:38px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;color:#fff;font-size:12px;font-weight:600;background:'+t.other.color" x-text="t.other.initials"></span>
                            <span style="flex:1;min-width:0;">
                                <span style="display:flex;align-items:center;gap:7px;">
                                    <span style="flex:1;min-width:0;font-size:13.5px;font-weight:600;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" x-text="t.other.name"></span>
                                    <span style="flex-shrink:0;font-size:10.5px;font-family:var(--font-mono);color:var(--muted-soft);" x-text="t.at"></span>
                                </span>
                                <span style="display:flex;align-items:center;gap:7px;margin-top:2px;">
                                    <span style="flex:1;min-width:0;font-size:12px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                        <span x-show="t.lastMine" style="color:var(--muted-soft);" x-text="($store.ui.lang==='en' ? 'You: ' : 'Anda: ')"></span><span x-text="t.snippet || ($store.ui.lang==='en' ? 'No messages yet' : 'Belum ada mesej')"></span>
                                    </span>
                                    <template x-if="t.unread > 0">
                                        <span style="flex-shrink:0;min-width:18px;height:18px;padding:0 5px;background:var(--red);color:#fff;border-radius:9999px;font-family:var(--font-mono);font-weight:600;font-size:10.5px;display:flex;align-items:center;justify-content:center;" x-text="t.unread"></span>
                                    </template>
                                </span>
                            </span>
                        </button>
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

            {{-- ── THREAD ───────────────────────────────────────────────────── --}}
            <div x-show="view === 'thread'" x-cloak style="display:flex;flex-direction:column;height:100%;">
                <div style="height:60px;flex-shrink:0;display:flex;align-items:center;gap:11px;padding:0 16px;border-bottom:1px solid var(--hairline);">
                    <button @click="back()" style="width:30px;height:30px;border-radius:7px;color:var(--muted);flex-shrink:0;background:none;font-size:18px;">←</button>
                    <template x-if="active">
                        <div style="display:flex;align-items:center;gap:10px;flex:1;min-width:0;">
                            <span :style="'width:34px;height:34px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;color:#fff;font-size:11.5px;font-weight:600;background:'+active.other.color" x-text="active.other.initials"></span>
                            <div style="flex:1;min-width:0;">
                                <div style="font-size:13.5px;font-weight:600;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" x-text="active.other.name"></div>
                                <div style="font-size:11px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" x-text="active.other.position"></div>
                            </div>
                        </div>
                    </template>
                    <button @click="msg = false" style="width:30px;height:30px;border-radius:7px;color:var(--muted);font-size:18px;flex-shrink:0;">×</button>
                </div>

                <div x-ref="scroll" style="flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:8px;background:var(--canvas);">
                    <template x-if="loading">
                        <div style="padding:24px;text-align:center;font-size:12.5px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Loading…' : 'Memuatkan…'">Loading…</div>
                    </template>
                    <template x-for="m in (active ? active.messages : [])" :key="m.id">
                        <div :style="'max-width:78%;'+(m.mine ? 'align-self:flex-end;' : 'align-self:flex-start;')">
                            <div :style="'padding:9px 12px;border-radius:14px;font-size:13px;line-height:1.5;white-space:pre-wrap;word-break:break-word;'+(m.mine ? 'background:var(--red);color:#fff;border-bottom-right-radius:4px;' : 'background:#fff;color:var(--ink);border:1px solid var(--hairline);border-bottom-left-radius:4px;')" x-text="m.body"></div>
                            <div :style="'font-size:10px;font-family:var(--font-mono);color:var(--muted-soft);margin-top:3px;'+(m.mine ? 'text-align:right;' : '')" x-text="m.at"></div>
                        </div>
                    </template>
                    <template x-if="active && active.messages.length === 0 && !loading">
                        <div style="padding:24px;text-align:center;font-size:12.5px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'No messages yet — say hello.' : 'Belum ada mesej — sapa dahulu.'">No messages yet.</div>
                    </template>
                </div>

                <form @submit.prevent="send()" style="flex-shrink:0;padding:12px 16px;border-top:1px solid var(--hairline);display:flex;align-items:flex-end;gap:9px;">
                    <textarea x-model="body" @keydown.enter.prevent="send()" rows="1" maxlength="5000"
                              :placeholder="$store.ui.lang==='en' ? 'Write a message…' : 'Tulis mesej…'"
                              style="flex:1;min-height:42px;max-height:120px;padding:10px 12px;border:1px solid var(--hairline);border-radius:10px;font-size:13px;resize:none;outline:none;font-family:inherit;line-height:1.5;"></textarea>
                    <button type="submit" :disabled="!body.trim() || sending" class="uj-btn-primary" style="height:42px;width:42px;flex-shrink:0;display:flex;align-items:center;justify-content:center;padding:0;" :style="(!body.trim() || sending) ? 'opacity:.5;cursor:not-allowed;' : ''">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4z"></path></svg>
                    </button>
                </form>
            </div>
        </aside>
    </div>
</template>
