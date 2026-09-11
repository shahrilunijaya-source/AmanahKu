{{--
    Profile Wall (CR-13 birthday wishes + CR-23 flowers): everything a person
    has received, newest first, grouped by year. Re-rendered wholesale by
    FlowerController on every give/hide (targeted by DOM id — the give-a-flower
    button lives in the profile header, in partials.flower-give, not here), same
    pattern the dashboard birthday band uses for its wishes region: no full
    reload. The wrapper always renders (with its stable id, `hidden` when
    empty) rather than being omitted, so the header button has something to
    swap even before this person's very first wish or flower.

    $employee        Employee (the profile subject / flower recipient)
    $wall            Collection<year, Collection<row>> from App\Support\ProfileWall
                      row: ['type' => 'wish'|'flower', 'date' => Carbon, 'model' => ...]
    $canHideFlowers  bool  viewer is HR
--}}
<div class="uj-card" id="uj-wall-{{ $employee->id }}" style="padding:20px;" @if ($wall->isEmpty()) hidden @endif x-data="{
            busy: false,
            async hide(flowerId) {
                if (this.busy) return;
                const root = this.$root;
                this.busy = true;
                try {
                    const res = await fetch(@js(url('/app/flowers')) + '/' + flowerId + '/hide', {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, Accept: 'application/json', 'Content-Type': 'application/json' },
                    });
                    if (!res.ok) throw new Error(res.status);
                    const data = await res.json();
                    root.outerHTML = data.html;
                } catch (e) {
                    $store.toast.error($store.ui.lang==='en' ? 'That did not save. Try again.' : 'Tidak berjaya disimpan. Cuba lagi.');
                } finally { this.busy = false; }
            },
         }">
        <div style="font-size:13px;font-weight:600;color:var(--ink);margin-bottom:14px;"><span x-text="$store.ui.lang==='en' ? 'Wall' : 'Dinding'">Wall</span></div>
        @foreach ($wall as $year => $rows)
            <div style="margin-bottom:16px;">
                <div style="font-size:11px;font-weight:600;color:var(--muted-soft);letter-spacing:.08em;text-transform:uppercase;margin-bottom:8px;">{{ $year }}</div>
                <div style="display:flex;flex-direction:column;gap:10px;">
                    @foreach ($rows as $row)
                        @if ($row['type'] === 'wish')
                            @php $w = $row['model']; @endphp
                            <div style="display:flex;gap:10px;">
                                <span style="flex:none;width:26px;height:26px;border-radius:50%;background:{{ $w->author->avatar_color ?? '#3a6ea5' }};color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:600;">{{ $w->author->initials }}</span>
                                <div style="min-width:0;flex:1 1 auto;">
                                    <div style="display:flex;align-items:baseline;gap:6px;">
                                        <span style="font-size:12.5px;font-weight:600;color:var(--ink);">{{ $w->author->display_name }}</span>
                                        @if ($w->is_thanks)<span style="font-size:12px;">🙏</span>@endif
                                        <span style="font-size:11px;color:var(--muted-soft);margin-left:auto;">{{ $w->celebrated_on->format('j M Y') }}</span>
                                    </div>
                                    <div style="font-size:12.5px;color:var(--body);word-break:break-word;">{{ $w->body }}</div>
                                </div>
                            </div>
                        @else
                            @php $f = $row['model']; @endphp
                            <div style="display:flex;gap:10px;">
                                <span style="flex:none;width:26px;height:26px;border-radius:50%;background:{{ $f->giver->avatar_color ?? '#3a6ea5' }};color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:600;">{{ $f->giver->initials }}</span>
                                <div style="min-width:0;flex:1 1 auto;">
                                    <div style="display:flex;align-items:baseline;gap:6px;">
                                        <span style="font-size:12.5px;font-weight:600;color:var(--ink);">{{ $f->giver->display_name }}</span>
                                        <span style="font-size:12px;">🌸</span>
                                        <span style="font-size:11px;color:var(--muted-soft);margin-left:auto;">{{ $f->created_at->format('j M Y') }}</span>
                                        @if ($canHideFlowers ?? false)
                                            <button type="button" style="font-size:10.5px;color:var(--muted);background:transparent;cursor:pointer;text-decoration:underline;" @click="hide({{ $f->id }})">
                                                <span x-text="$store.ui.lang==='en' ? 'Hide' : 'Sembunyi'">Hide</span>
                                            </button>
                                        @endif
                                    </div>
                                    <div style="font-size:12.5px;color:var(--body);word-break:break-word;">{{ $f->note }}</div>
                                </div>
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
