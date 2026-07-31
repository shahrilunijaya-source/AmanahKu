{{-- Global toast host. Rendered once per layout; driven entirely by the `toast` Alpine
     store (resources/js/toast.js). Any screen calls $store.toast.success('…') /
     .error('…') / .info('…') — no per-screen markup or state needed. --}}
{{-- Always mounted: an empty, fixed, pointer-events:none flex container costs nothing and
     avoids depending on x-show reactivity for the container itself — x-for below handles
     showing/removing each toast. --}}
<div class="uj-toast-host" x-data aria-live="polite" aria-atomic="false">
    <template x-for="t in $store.toast.items" :key="t.id">
        <div class="uj-toast" :class="{ 'uj-toast--leaving': t.leaving }" :data-type="t.type"
             :role="t.type === 'error' ? 'alert' : 'status'"
             @mouseenter="$store.toast.pause(t.id)" @mouseleave="$store.toast.resume(t.id)">
            <span class="uj-toast-icon" aria-hidden="true">
                <svg x-show="t.type === 'success'" viewBox="0 0 20 20" fill="none"><path d="M4.7 10.5l3.2 3.2 7.4-7.4" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <svg x-show="t.type === 'error'" viewBox="0 0 20 20" fill="none"><path d="M10 5.4v5.3" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/><circle cx="10" cy="14.4" r="1.15" fill="currentColor"/></svg>
                <svg x-show="t.type === 'info'" viewBox="0 0 20 20" fill="none"><path d="M10 9.3v5.3" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/><circle cx="10" cy="5.7" r="1.15" fill="currentColor"/></svg>
            </span>
            <span class="uj-toast-msg" x-text="t.message"></span>
            <button type="button" class="uj-toast-close" @click="$store.toast.dismiss(t.id)"
                    :aria-label="$store.ui.lang === 'en' ? 'Dismiss' : 'Tutup'">
                <svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M5.6 5.6l8.8 8.8M14.4 5.6l-8.8 8.8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
            </button>
            <span class="uj-toast-bar" x-show="t.timeout > 0" :style="`animation-duration:${t.timeout}ms`"></span>
        </div>
    </template>
</div>
