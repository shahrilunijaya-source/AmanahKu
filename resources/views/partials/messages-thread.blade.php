{{-- The thread column: header, runs, composer. Rendered TWICE by two paths that must
     never drift — the full screen render, and MessageController::pane(), which returns
     just this fragment so opening a conversation or sending a message swaps one column
     instead of reloading the whole shell.

     Expects: $a (the active-thread payload, or null), $msgCanSend, $draft (string).

     Everything inside is inert markup plus Alpine. It carries no <script>, so it is safe
     to drop into the page with innerHTML — Alpine initialises inserted nodes itself. --}}
@if ($a)
    <div class="uj-msg-thd">
        {{-- Below 820px the index and the thread share one pane, and the URL decides
             which you see. On desktop this button is hidden by CSS. --}}
        <button type="button" class="uj-msg-back" @click="back()" aria-label="Back to conversations">
            <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"></path></svg>
        </button>
        <span class="uj-msg-av" style="background:{{ $a['other']['color'] }};">{{ $a['other']['initials'] }}</span>
        <div class="uj-msg-thwho">
            <div class="uj-msg-thn">{{ $a['other']['name'] }}</div>
            <div class="uj-msg-thp">{{ $a['other']['position'] }}</div>
        </div>
        @if ($a['other']['id'])
            {{-- A real navigation: the profile is a different screen, not part of this one. --}}
            <a class="uj-msg-thb" href="{{ route('app.screen', ['screen' => 'profile', 'emp' => $a['other']['id']]) }}">
                <span x-text="$store.ui.lang==='en' ? 'View profile' : 'Lihat profil'">View profile</span>
            </a>
        @endif
    </div>

    <div class="uj-msg-scroll" x-ref="msgs" x-data="{
            init() {
                this.$nextTick(() => { this.$refs.msgs && (this.$refs.msgs.scrollTop = this.$refs.msgs.scrollHeight); });
                @if ($a['conversationId'])
                fetch('{{ route('messages.read', $a['conversationId']) }}', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' },
                }).then(r => r.ok ? r.json() : Promise.reject()).then(d => {
                    if (this.$store.msgbadge) { this.$store.msgbadge.unread = d.unread; }
                    // Clear the row's own badge too, without refetching the index.
                    this.$dispatch('msg-read', { id: {{ $a['conversationId'] }} });
                }).catch(() => {});
                @endif
            }
        }">
        @forelse ($a['runs'] as $run)
            @if (isset($run['day']))
                <div class="uj-msg-day">
                    <i></i>
                    <span x-data="{ en: @js($run['day']), ms: @js($run['dayMs']) }"
                          x-text="$store.ui.lang==='en' ? en : ms">{{ $run['day'] }}</span>
                    <i></i>
                </div>
            @else
                <div class="uj-msg-run" @if ($run['mine']) data-mine @endif>
                    @foreach ($run['bubbles'] as $body)
                        <div class="uj-msg-b">{{ $body }}</div>
                    @endforeach
                    @foreach ($run['attachments'] as $att)
                        @if ($att['isImage'])
                            <a class="uj-msg-att" href="{{ $att['url'] }}" target="_blank" rel="noopener">
                                <img src="{{ $att['url'] }}" alt="{{ $att['name'] }}" />
                            </a>
                        @else
                            <a class="uj-msg-file" href="{{ $att['url'] }}" target="_blank" rel="noopener">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                                <span>{{ $att['name'] }}</span>
                            </a>
                        @endif
                    @endforeach
                    <div class="uj-msg-meta">
                        {{ $run['time'] }}@if ($run['mine'] && $run['read']) · <b x-text="$store.ui.lang==='en' ? 'Read' : 'Dibaca'">Read</b>@endif
                    </div>
                </div>
            @endif
        @empty
            <div class="uj-msg-none" x-text="$store.ui.lang==='en' ? 'No messages yet — say hello.' : 'Belum ada mesej — sapa dahulu.'">No messages yet — say hello.</div>
        @endforelse
    </div>

    @if ($msgCanSend ?? false)
        {{-- `files` mirrors the real file input. Removing a chip rebuilds that input
             through DataTransfer, so the batch that posts is always the batch on screen.

             @submit.prevent hands the send to the root component, which posts it and
             swaps this column back in. The action and enctype stay correct anyway, so
             the form still works if the JS never runs. --}}
        <form method="post" action="{{ route('messages.send') }}" enctype="multipart/form-data" class="uj-msg-dock"
              @submit.prevent="send($el)"
              x-data="{
                body: @js($draft ?? ''),
                files: [],
                commit(list) { const dt = new DataTransfer(); list.forEach(f => dt.items.add(f)); this.$refs.file.files = dt.files; this.files = Array.from(dt.files); },
                sync() { this.files = Array.from(this.$refs.file.files); },
                add(e) { this.commit([...this.files, ...Array.from(e.target.files)]); e.target.value = ''; },
                drop(i) { this.commit(this.files.filter((_, j) => j !== i)); },
                get empty() { return this.body.trim() === '' && this.files.length === 0; },
              }">
            @csrf
            @if ($a['conversationId'])
                <input type="hidden" name="conversation_id" value="{{ $a['conversationId'] }}">
            @else
                <input type="hidden" name="to" value="{{ $a['to'] }}">
            @endif

            <div class="uj-msg-chips" x-show="files.length" x-cloak>
                <template x-for="(f, i) in files" :key="i">
                    <span class="uj-msg-chip">
                        <span x-text="f.name"></span>
                        <button type="button" @click="drop(i)" :aria-label="($store.ui.lang==='en' ? 'Remove ' : 'Buang ') + f.name">&times;</button>
                    </span>
                </template>
            </div>

            {{-- `file` holds the batch; the camera appends into it. --}}
            <input x-ref="file" type="file" name="attachments[]" multiple
                   accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.txt,.csv"
                   @change="sync" style="display:none;" />
            <input x-ref="cam" type="file" name="attachments[]" accept="image/*" capture="environment"
                   @change="add" style="display:none;" />

            <div class="uj-msg-drow">
                <button type="button" class="uj-msg-db" @click="$refs.file.click()"
                        :title="$store.ui.lang==='en' ? 'Attach a file' : 'Lampirkan fail'">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path></svg>
                </button>
                {{-- Camera trigger — mobile/touch only. --}}
                <button type="button" class="uj-msg-db uj-cam-only" @click="$refs.cam.click()" style="display:none;"
                        :title="$store.ui.lang==='en' ? 'Camera' : 'Kamera'">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path><circle cx="12" cy="13" r="4"></circle></svg>
                </button>
                {{-- Shift+Enter must fall through to a real newline: the old handler was
                     `@keydown.enter.prevent`, which blocked EVERY Enter and left a
                     5000-character pre-wrap field unable to hold two lines. The shift test
                     is inline because Alpine has no `.exact` modifier — it would read
                     `exact` as a key name and never fire at all. --}}
                <textarea name="body" maxlength="5000" rows="1" x-model="body"
                          @keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); if (! empty) { $el.form.requestSubmit(); } }"
                          :placeholder="$store.ui.lang==='en' ? 'Write a message…' : 'Tulis mesej…'"></textarea>
                <button type="submit" class="uj-msg-send" :disabled="empty || sending">
                    <span x-show="! sending" x-text="$store.ui.lang==='en' ? 'Send' : 'Hantar'">Send</span>
                    <span x-show="sending" x-cloak x-text="$store.ui.lang==='en' ? 'Sending…' : 'Menghantar…'"></span>
                </button>
            </div>

            <div class="uj-msg-hint">
                <b class="uj-msg-kbd">&crarr;</b>
                <span x-text="$store.ui.lang==='en' ? 'send' : 'hantar'">send</span>
                <span>·</span>
                <b class="uj-msg-kbd">&#8679;&crarr;</b>
                <span x-text="$store.ui.lang==='en' ? 'new line · up to 6 files' : 'baris baharu · sehingga 6 fail'">new line</span>
            </div>

            <div class="uj-msg-err" x-show="error" x-cloak x-text="error"></div>
        </form>
    @endif
@else
    <div class="uj-msg-empty">
        <h3 x-text="$store.ui.lang==='en' ? 'No conversation open' : 'Tiada perbualan dibuka'">No conversation open</h3>
        <p x-text="$store.ui.lang==='en'
            ? 'Pick someone on the left to read the thread, or search their name to start a new one.'
            : 'Pilih seseorang di sebelah kiri untuk baca perbualan, atau cari nama mereka untuk mula yang baharu.'">Pick someone on the left.</p>
    </div>
@endif
