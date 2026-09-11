{{--
    "Give a flower" button + inline composer (CR-23), for the profile header
    actions row (next to Message / Org chart) on someone ELSE's profile. On
    success it swaps the Wall card (partials.wall, id uj-wall-{recipient}) by
    outerHTML rather than reloading — same pattern the dashboard birthday band
    uses for its wishes region.

    $employee              Employee (the profile subject / flower recipient)
    $flowersLeft           int  flowers the viewer may still give this month
    $alreadyGaveThisMonth  bool  viewer already gave THIS recipient a flower this month
--}}
<span x-data="{
        giving: false,
        note: '',
        busy: false,
        async give() {
            if (this.busy || !this.note.trim()) return;
            this.busy = true;
            let data = null;
            try {
                const res = await fetch(@js(route('flowers.store', $employee)), {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, Accept: 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify({ note: this.note }),
                });
                data = await res.json().catch(() => null);
                if (!res.ok) throw new Error((data && data.message) || res.status);
                const wall = document.getElementById(@js('uj-wall-'.$employee->id));
                if (wall) { wall.outerHTML = data.html; }
                this.note = '';
                this.giving = false;
                this.left = data.left;
                this.given = true;
            } catch (e) {
                $store.toast.error((data && data.message) || ($store.ui.lang==='en' ? 'That did not save. Try again.' : 'Tidak berjaya disimpan. Cuba lagi.'));
            } finally { this.busy = false; }
        },
        left: {{ (int) ($flowersLeft ?? 0) }},
        given: {{ ($alreadyGaveThisMonth ?? false) ? 'true' : 'false' }},
     }" style="display:inline-flex;align-items:center;">
    <template x-if="!giving">
        <button type="button" class="uj-btn-ghost" style="height:38px;padding:0 14px;font-size:13px;"
                @click="giving = true" :disabled="left === 0 || given">
            <span x-text="$store.ui.lang==='en' ? 'Give a flower \u{1F338}' : 'Beri bunga \u{1F338}'">Give a flower 🌸</span>
            <span style="color:var(--muted-soft);margin-left:6px;font-size:11px;">
                <template x-if="given"><span x-text="$store.ui.lang==='en' ? 'given this month' : 'sudah diberi bulan ini'"></span></template>
                <template x-if="!given"><span><span x-text="left"></span> <span x-text="$store.ui.lang==='en' ? 'left this month' : 'baki bulan ini'"></span></span></template>
            </span>
        </button>
    </template>
    <template x-if="giving">
        <span style="display:flex;gap:8px;align-items:center;">
            <input type="text" maxlength="200" x-model="note"
                   :placeholder="$store.ui.lang==='en' ? 'What did they do well?' : 'Apa yang mereka lakukan dengan baik?'"
                   style="width:220px;height:38px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;background:#fff;color:var(--ink);outline:none;"
                   @keydown.enter.prevent="give()">
            <button type="button" class="uj-btn-primary" style="height:38px;padding:0 14px;font-size:13px;" :disabled="busy || !note.trim()" @click="give()">
                <span x-text="$store.ui.lang==='en' ? 'Send' : 'Hantar'">Send</span>
            </button>
            <button type="button" class="uj-btn-ghost" style="height:38px;padding:0 12px;font-size:13px;" @click="giving = false; note = ''">
                <span x-text="$store.ui.lang==='en' ? 'Cancel' : 'Batal'">Cancel</span>
            </button>
        </span>
    </template>
</span>
