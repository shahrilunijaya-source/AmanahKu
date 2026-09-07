{{--
    Wishes under a birthday band (CR-13). Composer for a colleague, "Say thanks"
    for the celebrant, wishes list newest-first with the thank-you (if any)
    pinned above the rest. Every action posts over fetch and swaps this whole
    block's outerHTML with what the controller re-renders — no full reload,
    same pattern the work board uses for a card repaint.

    $employee        Employee (the celebrant)
    $wishes          Collection<BirthdayWish> newest first, thanks pinned first
    $viewerId        int|null  signed-in employee id
    $isCelebrant     bool
    $celebratedToday bool
--}}
@php
    $thanksWish = $wishes->firstWhere('is_thanks', true);
    $rest = $wishes->reject(fn ($w) => $w->is_thanks);
@endphp
<div class="uj-db-wishes" id="uj-bday-wishes-{{ $employee->id }}"
     x-data="{
        body: '',
        thanksBody: '',
        thanking: false,
        busy: false,
        emoji: @js(\App\Models\TotSession::EMOJI),
        async post(url, body) {
            if (this.busy || !body.trim()) return;
            const root = this.$root;
            this.busy = true;
            try {
                const res = await fetch(url, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, Accept: 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify({ body }),
                });
                if (!res.ok) throw new Error(res.status);
                const data = await res.json();
                root.outerHTML = data.html;
            } catch (e) {
                $store.toast.error($store.ui.lang==='en' ? 'That did not save. Try again.' : 'Tidak berjaya disimpan. Cuba lagi.');
            } finally { this.busy = false; }
        },
        async react(wishId, emoji) {
            if (this.busy) return;
            const root = this.$root;
            this.busy = true;
            try {
                const res = await fetch(@js(url('/app/birthday/wish')) + '/' + wishId + '/react', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, Accept: 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify({ emoji }),
                });
                if (!res.ok) throw new Error(res.status);
                const data = await res.json();
                root.outerHTML = data.html;
            } catch (e) {
                // silent — a failed toggle just leaves the chip as it was
            } finally { this.busy = false; }
        }
     }">
    @if ($celebratedToday && $isCelebrant)
        <div class="uj-db-wish-composer">
            <template x-if="!thanking">
                <button type="button" class="uj-db-thanks-open" @click="thanking = true"
                        x-text="$store.ui.lang==='en' ? 'Say thanks' : 'Ucap terima kasih'">Say thanks</button>
            </template>
            <template x-if="thanking">
                <span style="display:flex;gap:8px;flex:1;min-width:0;">
                    <input type="text" maxlength="280" class="uj-db-wish-input" x-model="thanksBody"
                           :placeholder="$store.ui.lang==='en' ? 'Say thanks…' : 'Ucap terima kasih…'"
                           @keydown.enter.prevent="post(@js(route('birthday.thanks', $employee)), thanksBody); thanksBody = ''; thanking = false">
                    <button type="button" class="uj-db-wish-send" :disabled="busy || !thanksBody.trim()"
                            @click="post(@js(route('birthday.thanks', $employee)), thanksBody); thanksBody = ''; thanking = false"
                            x-text="$store.ui.lang==='en' ? 'Send' : 'Hantar'">Send</button>
                </span>
            </template>
        </div>
    @elseif ($celebratedToday)
        <div class="uj-db-wish-composer">
            <input type="text" maxlength="280" class="uj-db-wish-input" x-model="body"
                   :placeholder="$store.ui.lang==='en' ? 'Write a wish…' : 'Tulis ucapan…'"
                   @keydown.enter.prevent="post(@js(route('birthday.wish', $employee)), body); body = ''">
            <span class="uj-db-wish-emoji">
                <template x-for="e in emoji" :key="e">
                    <button type="button" @click="body += e" x-text="e"></button>
                </template>
            </span>
            <button type="button" class="uj-db-wish-send" :disabled="busy || !body.trim()"
                    @click="post(@js(route('birthday.wish', $employee)), body); body = ''"
                    x-text="$store.ui.lang==='en' ? 'Send' : 'Hantar'">Send</button>
        </div>
    @endif

    @if ($thanksWish || $rest->isNotEmpty())
        <div class="uj-db-wish-list">
            @if ($thanksWish)
                @include('partials.dash.birthday-wish-row', ['w' => $thanksWish, 'viewerId' => $viewerId])
            @endif
            @foreach ($rest as $w)
                @include('partials.dash.birthday-wish-row', ['w' => $w, 'viewerId' => $viewerId])
            @endforeach
        </div>
    @endif
</div>
