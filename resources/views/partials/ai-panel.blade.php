{{-- AI Workforce Assistant slide-over (toggled by `ai` on the shell root) --}}
<template x-if="ai">
    <div>
        <div @click="ai = false" style="position:fixed;inset:0;background:rgba(31,30,26,.18);z-index:40;"></div>
        <aside class="uj-slide" x-data="{
                messages: {{ Illuminate\Support\Js::from($aiMessages) }},
                prompts: {{ Illuminate\Support\Js::from($aiPrompts) }},
                input: '',
                sending: false,
                scrollDown() { this.$nextTick(() => { if (this.$refs.scroll) this.$refs.scroll.scrollTop = this.$refs.scroll.scrollHeight; }); },
                async send(text) {
                    const msg = (text || this.input).trim();
                    if (!msg || this.sending) return;
                    this.messages.push({ isUser: true, text: msg });
                    this.input = '';
                    this.sending = true;
                    this.scrollDown();
                    try {
                        const res = await fetch('{{ route('assistant.reply') }}', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                            },
                            body: JSON.stringify({ message: msg }),
                        });
                        const data = await res.json();
                        const fallback = Alpine.store('ui').lang==='en' ? 'Sorry, I could not respond.' : 'Maaf, saya tidak dapat membalas.';
                        this.messages.push({ isAi: true, text: data.reply ?? fallback, source: data.source });
                    } catch (e) {
                        const offline = Alpine.store('ui').lang==='en' ? 'The assistant is unavailable right now. Please try again.' : 'Pembantu tidak tersedia buat masa ini. Sila cuba lagi.';
                        this.messages.push({ isAi: true, text: offline });
                    } finally {
                        this.sending = false;
                        this.scrollDown();
                    }
                }
            }"
            style="position:fixed;top:0;right:0;width:400px;max-width:90vw;height:100vh;background:#fff;border-left:1px solid var(--hairline);z-index:50;display:flex;flex-direction:column;box-shadow:-12px 0 40px rgba(31,30,26,.10);">
            <div style="height:60px;flex-shrink:0;display:flex;align-items:center;gap:10px;padding:0 18px;border-bottom:1px solid var(--hairline);">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--red)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 4.6L18.5 9.5 13.9 11.4 12 16l-1.9-4.6L5.5 9.5l4.6-1.9z"></path></svg>
                <div style="flex:1;">
                    <div style="font-size:14px;font-weight:600;color:var(--ink);" x-text="$store.ui.lang==='en' ? 'AI Workforce Assistant' : 'Pembantu Tenaga Kerja AI'">AI Workforce Assistant</div>
                    <div style="font-size:11px;color:var(--muted);">{{ $roleLabel }} <span x-text="$store.ui.lang==='en' ? 'scope · tenant-isolated' : 'skop · terasing ikut penyewa'">scope · tenant-isolated</span></div>
                </div>
                <button @click="ai = false" style="width:30px;height:30px;border-radius:7px;color:var(--muted);font-size:18px;">×</button>
            </div>

            <div x-ref="scroll" style="flex:1;overflow-y:auto;padding:18px;display:flex;flex-direction:column;gap:14px;">
                <template x-for="(m, i) in messages" :key="i">
                    <div :style="'align-self:' + (m.isUser ? 'flex-end' : 'flex-start') + ';max-width:88%;'">
                        <div :style="(m.isUser
                                ? 'background:var(--red);color:#fff;border-top-right-radius:3px;'
                                : 'background:var(--canvas);border:1px solid var(--hairline);color:var(--body);border-top-left-radius:3px;')
                                + 'border-radius:12px;padding:12px 14px;font-size:13.5px;line-height:1.55;white-space:pre-wrap;'"
                            x-text="m.text"></div>
                        <template x-if="m.source">
                            <div style="display:flex;align-items:center;gap:6px;margin-top:7px;font-size:11px;color:var(--muted);">
                                <span style="background:var(--red-tint);color:var(--red);padding:2px 7px;border-radius:9999px;font-weight:500;" x-text="$store.ui.lang==='en' ? 'Source' : 'Sumber'">Source</span><span x-text="m.source"></span>
                            </div>
                        </template>
                    </div>
                </template>

                <div x-show="sending" style="align-self:flex-start;font-size:12.5px;color:var(--muted-soft);" x-text="$store.ui.lang==='en' ? 'Thinking…' : 'Sedang berfikir…'">Thinking…</div>

                <div style="display:flex;flex-wrap:wrap;gap:7px;margin-top:4px;">
                    <template x-for="prompt in prompts" :key="prompt">
                        <button @click="send(prompt)" :disabled="sending" x-text="prompt" style="font-size:12px;color:var(--ink);border:1px solid var(--hairline);border-radius:9999px;padding:6px 11px;background:#fff;text-align:left;cursor:pointer;"></button>
                    </template>
                </div>
            </div>

            <div style="flex-shrink:0;padding:14px 16px;border-top:1px solid var(--hairline);">
                <div style="display:flex;gap:8px;align-items:center;background:var(--canvas);border:1px solid var(--hairline);border-radius:10px;padding:6px 6px 6px 14px;">
                    <input x-model="input" @keydown.enter.prevent="send()" :disabled="sending" :placeholder="$store.ui.lang==='en' ? 'Ask about your team, workload, approvals…' : 'Tanya tentang pasukan, beban kerja, kelulusan…'" style="flex:1;border:none;background:none;font-size:13.5px;color:var(--ink);outline:none;" />
                    <button @click="send()" :disabled="sending" :aria-label="$store.ui.lang==='en' ? 'Send' : 'Hantar'" style="width:32px;height:32px;border-radius:8px;background:var(--red);color:#fff;display:flex;align-items:center;justify-content:center;flex-shrink:0;">↑</button>
                </div>
                <div style="font-size:10.5px;color:var(--muted-soft);margin-top:8px;text-align:center;" x-text="$store.ui.lang==='en' ? 'AI respects your permissions · answers use live tenant data only' : 'AI mematuhi kebenaran anda · jawapan menggunakan data penyewa secara langsung sahaja'">AI respects your permissions · answers use live tenant data only</div>
            </div>
        </aside>
    </div>
</template>
