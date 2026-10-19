{{-- Confirm-before-submit dialog, driven by the `ask` Alpine store (resources/js/toast.js).
     Stands in for the browser's confirm()/prompt(), which embedded browsers refuse. --}}
<div id="uj-ask" x-data x-show="$store.ask.open" x-cloak class="uj-dialog-overlay"
     role="dialog" aria-modal="true" aria-labelledby="uj-ask-title"
     @keydown.escape.window="$store.ask.close()"
     style="position:fixed;inset:0;z-index:250;padding:20px;background:rgba(31,30,26,.55);backdrop-filter:blur(2px);">
    <form @submit.prevent="$store.ask.confirm()" @click.outside="$store.ask.close()"
          x-effect="if ($store.ask.open && $store.ask.seq) { requestAnimationFrame(() => requestAnimationFrame(() => ($store.ask.showInput ? $refs.input : $refs.cancel).focus())) }"
          style="width:100%;max-width:420px;margin:auto;padding:24px;background:#fff;border-radius:16px;box-shadow:0 24px 70px rgba(31,30,26,.30);display:flex;flex-direction:column;gap:12px;">
        <h2 id="uj-ask-title" x-text="$store.ask.title" style="font-size:16px;font-weight:600;color:var(--ink);margin:0;"></h2>
        <p x-show="$store.ask.body" x-text="$store.ask.body" style="font-size:13.5px;color:var(--muted);margin:0;line-height:1.5;"></p>
        <input x-ref="input" x-show="$store.ask.showInput" x-model="$store.ask.text" :type="$store.ask.input === 'password' ? 'password' : 'text'" autocomplete="off"
               :placeholder="$store.ask.placeholder || ($store.ask.match ?? '')" :aria-label="$store.ask.placeholder || $store.ask.title"
               style="height:38px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;background:#fff;color:var(--ink);width:100%;" />
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:4px;">
            <button type="button" x-ref="cancel" @click="$store.ask.close()" class="uj-btn-ghost" style="height:38px;padding:0 16px;font-size:13px;"
                    x-text="$store.ui?.lang === 'ms' ? 'Batal' : 'Cancel'">Cancel</button>
            <button type="submit" :disabled="! $store.ask.ready" class="uj-btn-primary" :style="{ opacity: $store.ask.ready ? 1 : .5 }" style="height:38px;padding:0 16px;font-size:13px;"
                    x-text="$store.ask.ok">OK</button>
        </div>
    </form>
</div>
