{{--
    Hidden-cards tray, shown only in Customise mode. Only $railCards ever
    appear here — $queue/$secondary are pinned and cannot be hidden.

    Expects: $railCards (array of ['id','title', ...]) in scope.
--}}
<div class="uj-dq-tray" x-show="edit" x-cloak>
    <h3><span x-text="$store.ui.lang==='en' ? 'Hidden cards' : 'Kad tersembunyi'">Hidden cards</span></h3>
    <p><span x-text="$store.ui.lang==='en'
        ? 'Click one to bring it back. Cards marked “Always shown” cannot be hidden — they are the ones that tell you what is waiting on you.'
        : 'Klik untuk kembalikan. Kad bertanda “Sentiasa dipapar” tidak boleh disembunyikan — ia yang memberitahu apa sedang menunggu anda.'">Click one to bring it back.</span></p>
    <div class="uj-dq-tray-items">
        @foreach ($railCards as $c)
            {{-- Same transition as the card leaving the rail, so the card going and
                 its chip arriving read as one movement rather than two pops. --}}
            <button type="button" class="uj-dq-tray-chip" x-show="isHidden('{{ $c['id'] }}')" x-cloak
                    {{-- Base form, not the per-stage `x-transition:leave...` variant:
                         the per-stage modifiers silently no-op on leave in Alpine
                         3.15, so a chip vanished instead of fading. --}}
                    x-transition.opacity.duration.200ms
                    @click="show('{{ $c['id'] }}')">
                <span aria-hidden="true">+</span> {{ $c['title'] }}
            </button>
        @endforeach
        <span class="uj-dq-tray-empty" x-show="!hidden.length" x-cloak x-text="$store.ui.lang==='en' ? 'Nothing is hidden.' : 'Tiada yang disembunyikan.'">Nothing is hidden.</span>
    </div>
</div>
