{{-- Directors band. Redesigned away from the gold gradient card it used to be: gold
     appeared nowhere else in Amanahku, so it read as a sticker on top of the chart rather
     than the top of it. The meaning is unchanged — a flat authority row with no subtree
     under any single director, because a lone director beside one with a full team only
     invited "why is nobody under X" arguments.

     No container: the circles sit side by side and the caption below says what they are.
     Directors are not clickable seats either; the band is a fact about who approves, not
     a level to navigate into. --}}
<div>
    <template x-if="directors.length">
        <div class="oc-band">
            <div class="oc-band-row">
                <template x-for="d in directors" :key="d.id">
                    <div class="oc-col">
                        {{-- Not a button, so the hover name is unreachable by keyboard —
                             role/label is what carries the director's name to a reader. --}}
                        <div class="oc-circle" style="cursor:default;" :data-name="d.name"
                             :title="[d.role, d.fullName].filter(Boolean).join(' · ')"
                             role="img" :aria-label="d.name">
                            @include('partials.org-face', ['p' => 'd'])
                        </div>
                        <div class="oc-role" x-text="d.name"></div>
                    </div>
                </template>
            </div>
            <div class="oc-caption"
                 x-text="directors.length + ' ' + ($store.ui.lang==='en'
                    ? (directors.length > 1 ? 'directors · final approval' : 'director · final approval')
                    : 'pengarah · kelulusan akhir')"></div>
        </div>
    </template>
    <template x-if="! directors.length">
        <div class="oc-caption" style="margin:0 0 4px;"
             x-text="$store.ui.lang==='en' ? 'top of the chart · no directors set' : 'atas carta · tiada pengarah ditetapkan'"></div>
    </template>
</div>
