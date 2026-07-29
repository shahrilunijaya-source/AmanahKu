<div class="tot-actions">
    <span class="tot-fw">
        <span class="tot-fly" x-show="flyout === 'react'" x-cloak
              @mouseleave="flyout = null" @keydown.escape.window="flyout = null">
            @foreach (\App\Models\TotSession::EMOJI as $i => $emoji)
                <button type="button" class="tot-fly-e" style="--d:{{ $i * 30 }}ms"
                        @click="react(@js($emoji)); flyout = null"
                        :data-mine="mine.includes(@js($emoji)) ? '1' : null"
                        aria-label="React {{ $emoji }}">{{ $emoji }}</button>
            @endforeach
        </span>
        <button type="button" class="tot-act" :data-on="mine.length ? '1' : null"
                @click="flyout = flyout === 'react' ? null : 'react'"
                @mouseenter="flyout = 'react'"
                :aria-label="$store.ui.lang==='en' ? 'React to this session' : 'Beri reaksi'">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1.1 1L12 21l7.7-7.6 1.1-1a5.5 5.5 0 0 0 0-7.8z"/></svg>
            <span x-text="reactionTotal || ''"></span>
        </button>
    </span>

    <button type="button" class="tot-act" @click="openThread()"
            :aria-label="$store.ui.lang==='en' ? 'Open comments' : 'Buka komen'">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 8.9 8.9 0 0 1-4-.9L3 21l1.9-4.9A8.4 8.4 0 0 1 12 3.1a8.4 8.4 0 0 1 9 8.4z"/></svg>
        <span x-text="comments || ''"></span>
    </button>

    <button type="button" class="tot-act" :data-on="iWatched ? '1' : null"
            @click="toggleWatched()" x-show="canParticipate"
            :aria-label="$store.ui.lang==='en' ? 'Mark as watched' : 'Tanda sudah tonton'">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        <span x-text="watched || ''"></span>
    </button>

    <span class="tot-fw" x-show="canParticipate">
        {{-- noting resets whenever the flyout closes. x-show only hides,
             it does not tear the component down, so without this a rater
             who scores and then moves the mouse away reopens on the note
             box instead of the scores, with no way back to change the
             number until the box has been focused and blurred. --}}
        <span class="tot-fly tot-fly-rate" x-show="flyout === 'rate'" x-cloak
              @mouseleave="flyout = null; noting = false"
              @keydown.escape.window="flyout = null; noting = false"
              x-data="{ noting: false }">
            {{-- Two rows: the five scores, then the reassurance under them. Side by
                 side the sentence had to compete with the circles for width, which
                 either overflowed the pill or squeezed the text into a narrow column. --}}
            <template x-if="!noting">
                <span style="display:flex;flex-direction:column;align-items:flex-start;gap:8px;">
                    <span style="display:flex;align-items:center;gap:6px;">
                        @foreach ([1, 2, 3, 4, 5] as $n)
                            <button type="button" class="tot-sc" :data-mine="myScore === {{ $n }} ? '1' : null"
                                    @click="rate({{ $n }}); noting = true">{{ $n }}</button>
                        @endforeach
                    </span>
                    {{-- white-space:normal because .tot-fly is nowrap, which the
                         sentence would otherwise inherit and run past the pill. --}}
                    <span class="tot-note" style="font-size:11.5px;max-width:300px;white-space:normal;line-height:1.4;"
                          x-text="$store.ui.lang==='en' ? @js('Only '.($session->presenter?->name ?? $session->presenter_name ?? 'the presenter').' and management see scores, and never with your name.') : @js('Hanya '.($session->presenter?->name ?? $session->presenter_name ?? 'pembentang').' dan pengurusan nampak skor, dan tidak sekali dengan nama anda.')">Only {{ $session->presenter?->name ?? $session->presenter_name ?? 'the presenter' }} and management see scores, and never with your name.</span>
                </span>
            </template>
            <template x-if="noting">
                <span style="display:flex;align-items:center;gap:6px;">
                    <input type="text" maxlength="1000" class="tot-field" style="height:30px;width:210px;"
                           :value="myNote"
                           :placeholder="$store.ui.lang==='en' ? 'Add a note, optional' : 'Tambah nota, pilihan'"
                           @keydown.enter.prevent="saveNote($event.target.value); flyout = null; noting = false"
                           @blur="saveNote($event.target.value); noting = false">
                </span>
            </template>
        </span>
        <button type="button" class="tot-act" :data-on="myScore ? '1' : null"
                @click="flyout = flyout === 'rate' ? null : 'rate'"
                @mouseenter="flyout = 'rate'"
                :aria-label="$store.ui.lang==='en' ? 'Rate this session' : 'Nilai sesi ini'">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l3.1 6.3 6.9 1-5 4.9 1.2 6.9L12 17.8 5.8 21l1.2-6.9-5-4.9 6.9-1z"/></svg>
            <span x-text="score ? `${score.average} (${score.count})` : ''"></span>
        </button>
    </span>
</div>
