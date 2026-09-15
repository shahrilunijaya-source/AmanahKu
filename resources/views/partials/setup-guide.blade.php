{{--
    Live setup guide dock — bottom-right of every app screen while the company's
    setup is unfinished (SetupGuide::forRequest returned data; the layout only
    includes this partial then). Reads $store.guide, registered in layouts/app.

    - Progress and the step list are Launch Center's. Nothing here writes state.
    - "Skip for now" only advances the dock (localStorage amanahku-guide-skip). It
      never ticks a step, the launch lock still holds staff out.
    - Collapse to a pill: localStorage amanahku-guide-collapsed.
    - "Done, next: …": the key that was current on the previous page load is kept
      in amanahku-guide-last; if that key is done on this load, flash it for a few
      seconds before showing the next step.
    - Sits above the phone dock (--uj-dock-h) and below toasts. See .uj-guide in app.css.
--}}
<div class="uj-guide"
     data-guide-dock
     x-data="{
        collapsed: localStorage.getItem('amanahku-guide-collapsed') === '1',
        flash: null,
        get g() { return $store.guide; },
        get s() { return this.g.step; },
        get en() { return $store.ui.lang === 'en'; },
        t(step, field) { return step ? (this.en ? step[field] : (step[field + '_ms'] || step[field])) : ''; },
        get doneNames() { return this.g.steps.filter(s => s.done).slice(-2).map(s => this.t(s, 'label')).join(', '); },
        get nextStep() { const i = this.g.steps.indexOf(this.s); return i >= 0 ? (this.g.steps.slice(i + 1).find(s => ! s.done && ! this.g.skipped.includes(s.key)) ?? null) : null; },
        toggle() {
            this.collapsed = ! this.collapsed;
            try { localStorage.setItem('amanahku-guide-collapsed', this.collapsed ? '1' : '0'); } catch (e) {}
        },
        init() {
            // Step-done feedback: the step that was current last time is done now.
            let last = null;
            try { last = localStorage.getItem('amanahku-guide-last'); } catch (e) {}
            const done = last ? this.g.steps.find(s => s.key === last && s.done) : null;
            if (done) {
                this.flash = done;
                setTimeout(() => { this.flash = null; }, 4000);
            }
            this.$watch('g.current', (k) => { try { k ? localStorage.setItem('amanahku-guide-last', k) : localStorage.removeItem('amanahku-guide-last'); } catch (e) {} });
            try { this.g.current ? localStorage.setItem('amanahku-guide-last', this.g.current) : localStorage.removeItem('amanahku-guide-last'); } catch (e) {}
        },
     }"
     :data-guide-step="g.current"
     x-cloak>

    {{-- Collapsed pill --}}
    <button type="button" class="uj-guide-pill" x-show="collapsed" @click="toggle()"
            :aria-label="en ? 'Open setup guide' : 'Buka panduan persediaan'">
        <span class="uj-guide-pill-dot" aria-hidden="true"></span>
        <span x-text="(en ? 'Setup' : 'Persediaan') + ' · ' + g.doneCount + '/' + g.total">Setup</span>
    </button>

    {{-- Open card --}}
    <div class="uj-guide-card" x-show="! collapsed" role="complementary"
         :aria-label="en ? 'Setup guide' : 'Panduan persediaan'">
        <div class="uj-guide-head">
            <span class="uj-guide-eyebrow"
                  x-text="s ? ((en ? 'Setting up · step ' : 'Persediaan · langkah ') + g.index + (en ? ' of ' : ' daripada ') + g.total) : (en ? 'Setting up' : 'Persediaan')">Setting up</span>
            <button type="button" class="uj-guide-x" @click="toggle()" :title="en ? 'Collapse' : 'Kecilkan'" :aria-label="en ? 'Collapse setup guide' : 'Kecilkan panduan persediaan'">&ndash;</button>
        </div>
        <div class="uj-guide-bar" aria-hidden="true"><div class="uj-guide-fill" :style="'width:' + Math.round(g.doneCount / Math.max(g.total, 1) * 100) + '%'"></div></div>

        {{-- Done, next: … --}}
        <div class="uj-guide-flash" x-show="flash" x-transition.opacity>
            <span x-text="(en ? 'Done: ' : 'Selesai: ') + t(flash, 'label') + (s ? (en ? '. Next: ' : '. Seterusnya: ') + t(s, 'label') : '')"></span>
        </div>

        <template x-if="s">
            <div>
                <div class="uj-guide-title" x-text="t(s, 'label')"></div>
                <p class="uj-guide-body" x-text="t(s, 'guide')"></p>
                <div class="uj-guide-actions">
                    <a :href="s.url" class="uj-btn-primary uj-guide-go" data-guide-go x-text="en ? 'Take me there' : 'Bawa saya ke sana'">Take me there</a>
                    <button type="button" class="uj-btn-ghost uj-guide-skip" data-guide-skip @click="g.skip()" x-text="en ? 'Skip for now' : 'Langkau dulu'">Skip for now</button>
                </div>
                <div class="uj-guide-foot" x-show="doneNames || nextStep">
                    <span x-show="doneNames" x-text="(en ? 'Done so far: ' : 'Selesai setakat ini: ') + doneNames + '.'"></span>
                    <span x-show="nextStep" x-text="' ' + (en ? 'Next: ' : 'Seterusnya: ') + t(nextStep, 'label') + '.'"></span>
                </div>
            </div>
        </template>

        {{-- Every remaining step was skipped in this browser: point at Launch Center,
             where skipped steps are still listed as outstanding. --}}
        <template x-if="! s">
            <div>
                <div class="uj-guide-title" x-text="en ? 'Nothing left to point at' : 'Tiada lagi yang perlu ditunjuk'"></div>
                <p class="uj-guide-body" x-text="en ? 'The steps you skipped are still open in Company Setup. Finish them there, then click Complete setup.' : 'Langkah yang anda langkau masih terbuka di Persediaan Syarikat. Selesaikan di sana, kemudian klik Selesai persediaan.'"></p>
                <div class="uj-guide-actions">
                    <a href="{{ route('app.screen', ['screen' => 'setup']) }}" class="uj-btn-primary uj-guide-go" data-guide-go x-text="en ? 'Open Company Setup' : 'Buka Persediaan Syarikat'">Open Company Setup</a>
                </div>
            </div>
        </template>
    </div>
</div>
