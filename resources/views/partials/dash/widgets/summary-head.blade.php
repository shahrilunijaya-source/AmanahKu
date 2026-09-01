{{-- Me / My staff. Only here: the mock puts a scope toggle on this one card, and
     it only exists when the viewer has somebody reporting to them. --}}
@if (! empty($w['staffTiles']))
    <div class="uj-dw-spacer"></div>
    <div class="uj-seg">
        <button type="button" @click="scope = 'me'" :data-on="scope === 'me'"
                x-text="$store.ui.lang==='en' ? 'Me' : 'Saya'">Me</button>
        <button type="button" @click="scope = 'staff'" :data-on="scope === 'staff'"
                x-text="$store.ui.lang==='en' ? 'My staff' : 'Staf saya'">My staff</button>
    </div>
@endif
