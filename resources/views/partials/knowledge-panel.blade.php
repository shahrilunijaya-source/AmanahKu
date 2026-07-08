{{-- Knowledge Bank slide-over (toggled by `kb` on the shell root; `kbView` = feed|add|newseg).
     Mounted at app root alongside the AI panel. --}}
<template x-if="kb">
    <div>
        <div @click="kb = false" style="position:fixed;inset:0;background:rgba(31,30,26,.18);z-index:40;"></div>
        <aside class="uj-slide" x-data="{
                segChip: 'all',
                entries: @js($kbEntries),
                segs: @js($kbSegmentsNav),
                form: @js($kbSegmentsForm),
                pickSeg: @js(old('seg_id')),
                pickSub: @js(old('subseg_id')),
                get filtered() { return this.segChip === 'all' ? this.entries : this.entries.filter(e => e.seg_id === this.segChip); },
                get pickedChildren() { const s = this.form.find(f => f.id === this.pickSeg); return s ? s.children : []; },
            }"
            style="position:fixed;top:0;right:0;width:464px;max-width:94vw;height:100vh;background:#fff;border-left:1px solid var(--hairline);z-index:50;display:flex;flex-direction:column;box-shadow:-12px 0 40px rgba(31,30,26,.10);">

            {{-- ── FEED ─────────────────────────────────────────────────────── --}}
            <div x-show="kbView === 'feed'" x-cloak style="display:flex;flex-direction:column;height:100%;">
                <div style="height:60px;flex-shrink:0;display:flex;align-items:center;gap:11px;padding:0 16px;border-bottom:1px solid var(--hairline);">
                    <span style="width:30px;height:30px;border-radius:8px;background:var(--red-tint);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--red)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21h6M12 3a6 6 0 0 0-6 6c0 2.22 1.21 4.16 3 5.2V17a1 1 0 0 0 1 1h4a1 1 0 0 0 1-1v-2.8c1.79-1.04 3-2.98 3-5.2a6 6 0 0 0-6-6z"></path></svg>
                    </span>
                    <div style="flex:1;min-width:0;">
                        <div style="font-size:14px;font-weight:600;color:var(--ink);" x-text="$store.ui.lang==='en' ? 'Knowledge Bank' : 'Bank Pengetahuan'">Knowledge Bank</div>
                        <div style="font-size:11px;color:var(--muted);"><span>{{ $kbTotal }}</span> <span x-text="$store.ui.lang==='en' ? 'entries' : 'entri'">entries</span></div>
                    </div>
                    <a href="{{ route('app.screen', 'knowledge-bank') }}" style="font-size:12px;color:var(--red);text-decoration:none;font-weight:500;" x-text="$store.ui.lang==='en' ? 'View all →' : 'Lihat semua →'">View all →</a>
                    <button @click="kb = false" style="width:30px;height:30px;border-radius:7px;color:var(--muted);font-size:18px;flex-shrink:0;">×</button>
                </div>

                <div style="flex:1;overflow-y:auto;padding:16px;">
                    {{-- Monthly banner --}}
                    @if ($kbOwes)
                        <div style="background:var(--red-tint);border:1px solid #f1cdcf;border-radius:12px;padding:14px;margin-bottom:14px;">
                            <div style="display:flex;align-items:flex-start;gap:10px;">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--red)" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:1px;"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><path d="M12 9v4M12 17h.01"></path></svg>
                                <div style="flex:1;min-width:0;">
                                    <div style="font-size:13.5px;font-weight:600;color:var(--ink);" x-text="$store.ui.lang==='en' ? @js('Share your '.$kbMonthEn.' lesson') : @js('Kongsi pengajaran '.$kbMonthMs.' anda')">Share your lesson</div>
                                    <div style="font-size:12px;color:var(--body);line-height:1.45;margin-top:2px;" x-text="$store.ui.lang==='en' ? 'You haven\'t shared a lesson this month yet. It only takes a minute.' : 'Anda belum kongsi pengajaran bulan ini. Hanya ambil seminit.'">You haven't shared a lesson this month yet.</div>
                                </div>
                            </div>
                            <button @click="kbView = 'add'" class="uj-btn-primary" style="width:100%;height:38px;font-size:13px;margin-top:11px;"><span x-text="$store.ui.lang==='en' ? 'Share a lesson' : 'Kongsi pengajaran'">Share a lesson</span></button>
                        </div>
                    @else
                        <div style="background:#e9f5ef;border:1px solid #cce6da;border-radius:12px;padding:14px;margin-bottom:14px;display:flex;align-items:center;gap:11px;">
                            <span style="width:30px;height:30px;border-radius:50%;background:var(--success);display:flex;align-items:center;justify-content:center;flex-shrink:0;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"></path></svg></span>
                            <div style="flex:1;min-width:0;">
                                <div style="font-size:13px;font-weight:600;color:#176e51;" x-text="$store.ui.lang==='en' ? 'You\'ve shared a lesson this month' : 'Anda sudah kongsi pengajaran bulan ini'">You've shared a lesson this month</div>
                                <div style="font-size:11.5px;color:#3d7a63;margin-top:1px;" x-text="$store.ui.lang==='en' ? @js('Team streak: '.$kbTeamStreak.' months running') : @js('Streak pasukan: '.$kbTeamStreak.' bulan berturut')">Team streak</div>
                            </div>
                        </div>
                    @endif

                    {{-- Culture metrics --}}
                    <div style="display:flex;border:1px solid var(--hairline);border-radius:12px;overflow:hidden;margin-bottom:14px;">
                        <div style="flex:1;padding:12px;text-align:center;">
                            <div style="font-family:var(--font-mono);font-size:18px;font-weight:600;color:var(--ink);">{{ $kbTotal }}</div>
                            <div style="font-size:10.5px;color:var(--muted);margin-top:2px;" x-text="$store.ui.lang==='en' ? 'Lessons' : 'Pengajaran'">Lessons</div>
                        </div>
                        <div style="width:1px;background:var(--hairline);"></div>
                        <div style="flex:1;padding:12px;text-align:center;">
                            <div style="font-family:var(--font-mono);font-size:18px;font-weight:600;color:var(--ink);">{{ $kbSegCount }}</div>
                            <div style="font-size:10.5px;color:var(--muted);margin-top:2px;" x-text="$store.ui.lang==='en' ? 'Segments' : 'Segmen'">Segments</div>
                        </div>
                        <div style="width:1px;background:var(--hairline);"></div>
                        <div style="flex:1;padding:12px;text-align:center;">
                            <div style="font-family:var(--font-mono);font-size:18px;font-weight:600;color:var(--success);">{{ $kbContribPct }}%</div>
                            <div style="font-size:10.5px;color:var(--muted);margin-top:2px;" x-text="$store.ui.lang==='en' ? 'Contributed' : 'Menyumbang'">Contributed</div>
                        </div>
                    </div>

                    {{-- Segment chips --}}
                    <div style="display:flex;gap:7px;overflow-x:auto;padding-bottom:6px;margin-bottom:12px;scrollbar-width:none;">
                        <button @click="segChip = 'all'" :style="'flex-shrink:0;font-size:12px;font-weight:500;padding:6px 12px;border-radius:9999px;cursor:pointer;border:1px solid '+(segChip==='all'?'var(--red)':'var(--hairline)')+';background:'+(segChip==='all'?'var(--red)':'#fff')+';color:'+(segChip==='all'?'#fff':'var(--body)')" x-text="$store.ui.lang==='en' ? 'All' : 'Semua'">All</button>
                        <template x-for="s in segs" :key="s.id">
                            <button @click="segChip = s.id" :style="'flex-shrink:0;display:flex;align-items:center;gap:6px;font-size:12px;font-weight:500;padding:6px 12px;border-radius:9999px;cursor:pointer;white-space:nowrap;border:1px solid '+(segChip===s.id?'var(--red)':'var(--hairline)')+';background:'+(segChip===s.id?'var(--red)':'#fff')+';color:'+(segChip===s.id?'#fff':'var(--body)')">
                                <span x-text="s.label"></span>
                                <template x-if="s.unread > 0 && segChip !== s.id"><span style="font-family:var(--font-mono);font-size:9.5px;font-weight:700;color:#fff;background:var(--red);border-radius:9999px;padding:1px 5px;" x-text="s.unread"></span></template>
                            </button>
                        </template>
                        @if ($kbCanSubmit)
                            <button @click="kbView = 'newseg'" style="flex-shrink:0;font-size:12px;font-weight:500;padding:6px 12px;border-radius:9999px;cursor:pointer;border:1px dashed var(--hairline);background:#fff;color:var(--muted);white-space:nowrap;" x-text="$store.ui.lang==='en' ? '+ New segment' : '+ Segmen baharu'">+ New segment</button>
                        @endif
                    </div>

                    {{-- Entries --}}
                    <div style="display:flex;flex-direction:column;gap:11px;">
                        <template x-for="e in filtered" :key="e.id">
                            <div style="border:1px solid var(--hairline);border-radius:12px;padding:14px 15px;">
                                <div style="display:flex;align-items:center;gap:7px;margin-bottom:6px;">
                                    <span style="font-size:10.5px;color:var(--body);background:var(--canvas);border:1px solid var(--hairline);padding:2px 8px;border-radius:9999px;" x-text="e.seg"></span>
                                    <template x-if="e.isNew"><span style="width:7px;height:7px;border-radius:50%;background:var(--red);"></span></template>
                                    <span style="margin-left:auto;font-size:10.5px;font-family:var(--font-mono);color:var(--muted-soft);" x-text="e.date"></span>
                                </div>
                                <div style="font-size:14.5px;font-weight:600;color:var(--ink);line-height:1.35;margin-bottom:5px;" x-text="e.title"></div>
                                <div style="font-size:12.5px;color:var(--body);line-height:1.55;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;" x-text="e.body"></div>
                                <div style="display:flex;align-items:center;gap:9px;margin-top:11px;">
                                    <span :style="'width:30px;height:30px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;color:#fff;font-size:10.5px;font-weight:600;background:'+e.color" x-text="e.initials"></span>
                                    <div style="flex:1;min-width:0;">
                                        <div style="font-size:12px;font-weight:600;color:var(--ink);" x-text="e.author"></div>
                                        <div style="font-size:10px;color:var(--muted);" x-text="e.dept"></div>
                                    </div>
                                    <span style="display:flex;align-items:center;gap:10px;font-size:11px;font-family:var(--font-mono);color:var(--muted);">
                                        <span style="display:inline-flex;align-items:center;gap:4px;"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="var(--amber)" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg><span x-text="e.stars"></span></span>
                                        <span style="display:inline-flex;align-items:center;gap:4px;"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg><span x-text="e.comments"></span></span>
                                    </span>
                                </div>
                            </div>
                        </template>
                        <template x-if="filtered.length === 0">
                            <div style="padding:28px 16px;text-align:center;font-size:12.5px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'No lessons in this segment yet.' : 'Belum ada pengajaran dalam segmen ini.'">No lessons yet.</div>
                        </template>
                    </div>
                </div>

                @if ($kbCanSubmit)
                    <div style="flex-shrink:0;padding:12px 16px;border-top:1px solid var(--hairline);">
                        <button @click="kbView = 'add'" class="uj-btn-primary" style="width:100%;height:42px;font-size:13.5px;"><span x-text="$store.ui.lang==='en' ? '＋ Share a lesson' : '＋ Kongsi pengajaran'">＋ Share a lesson</span></button>
                    </div>
                @endif
            </div>

            {{-- ── ADD LESSON ───────────────────────────────────────────────── --}}
            <div x-show="kbView === 'add'" x-cloak style="display:flex;flex-direction:column;height:100%;">
                <div style="flex-shrink:0;padding:16px 16px 0;">
                    <button @click="kbView = 'feed'" style="font-size:12.5px;color:var(--muted);background:none;cursor:pointer;" x-text="$store.ui.lang==='en' ? '← Back to feed' : '← Kembali ke suapan'">← Back to feed</button>
                </div>
                <div style="flex:1;overflow-y:auto;padding:14px 18px 18px;">
                    <h2 style="font-size:21px;font-weight:400;color:var(--ink);letter-spacing:-0.3px;margin:0 0 4px;" x-text="$store.ui.lang==='en' ? 'Share a lesson learned' : 'Kongsi pengajaran'">Share a lesson learned</h2>
                    <p style="font-size:12.5px;color:var(--muted);margin:0 0 18px;" x-text="$store.ui.lang==='en' ? 'A fix, a pitfall, a shortcut — anything the next person would thank you for.' : 'Penyelesaian, jebakan, jalan pintas — apa sahaja yang orang seterusnya akan hargai.'">A fix, a pitfall, a shortcut.</p>

                    @if ($errors->any() && old('kbform') === 'add')
                        <div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:9px 12px;margin-bottom:14px;">{{ $errors->first() }}</div>
                    @endif

                    <form method="post" action="{{ route('knowledge.store') }}">
                        @csrf
                        <input type="hidden" name="kbform" value="add">
                        <input type="hidden" name="seg_id" :value="pickSeg">
                        <input type="hidden" name="subseg_id" :value="pickSub">

                        <label style="display:block;font-size:12.5px;font-weight:500;color:var(--ink);margin-bottom:7px;" x-text="$store.ui.lang==='en' ? 'Segment' : 'Segmen'">Segment</label>
                        <div style="display:flex;gap:7px;flex-wrap:wrap;margin-bottom:14px;">
                            <template x-for="s in form" :key="s.id">
                                <button type="button" @click="pickSeg = s.id; pickSub = null" :style="'font-size:12px;font-weight:500;padding:6px 12px;border-radius:9999px;cursor:pointer;border:1px solid '+(pickSeg===s.id?'var(--red)':'var(--hairline)')+';background:'+(pickSeg===s.id?'var(--red)':'#fff')+';color:'+(pickSeg===s.id?'#fff':'var(--body)')" x-text="s.label"></button>
                            </template>
                        </div>

                        <div x-show="pickedChildren.length > 0" x-cloak>
                            <label style="display:block;font-size:12.5px;font-weight:500;color:var(--ink);margin-bottom:7px;" x-text="$store.ui.lang==='en' ? 'Sub-segment' : 'Sub-segmen'">Sub-segment</label>
                            <div style="display:flex;gap:7px;flex-wrap:wrap;margin-bottom:14px;">
                                <template x-for="c in pickedChildren" :key="c.id">
                                    <button type="button" @click="pickSub = (pickSub === c.id ? null : c.id)" :style="'font-size:12px;font-weight:500;padding:6px 12px;border-radius:9999px;cursor:pointer;border:1px solid '+(pickSub===c.id?'var(--ink)':'var(--hairline)')+';background:'+(pickSub===c.id?'var(--ink)':'#fff')+';color:'+(pickSub===c.id?'#fff':'var(--body)')" x-text="c.label"></button>
                                </template>
                            </div>
                        </div>

                        <label style="display:block;font-size:12.5px;font-weight:500;color:var(--ink);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Title' : 'Tajuk'">Title</label>
                        <input name="title" value="{{ old('title') }}" required maxlength="200" :placeholder="$store.ui.lang==='en' ? 'e.g. Always re-run migrations on the seeded DB first' : 'cth. Sentiasa jalankan semula migrasi pada DB seed dahulu'" style="width:100%;height:42px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:13.5px;margin-bottom:13px;outline:none;" />

                        <label style="display:block;font-size:12.5px;font-weight:500;color:var(--ink);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'What happened' : 'Apa yang berlaku'">What happened</label>
                        <textarea name="body" required maxlength="5000" rows="5" :placeholder="$store.ui.lang==='en' ? 'What did you learn, and how can someone apply it?' : 'Apa yang anda pelajari, dan bagaimana orang lain boleh gunakannya?'" style="width:100%;padding:10px 12px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;resize:vertical;outline:none;margin-bottom:13px;font-family:inherit;line-height:1.55;">{{ old('body') }}</textarea>

                        <label style="display:block;font-size:12.5px;font-weight:500;color:var(--ink);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Tags' : 'Tag'">Tags</span> <span style="color:var(--muted-soft);font-weight:400;" x-text="$store.ui.lang==='en' ? '(optional, comma-separated)' : '(pilihan, pisah dengan koma)'">(optional)</span></label>
                        <input name="tags" value="{{ old('tags') }}" maxlength="200" placeholder="laravel, deploy, gotcha" style="width:100%;height:42px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:13.5px;margin-bottom:18px;outline:none;" />

                        <div style="display:flex;gap:10px;">
                            <button type="submit" class="uj-btn-primary" style="flex:1;height:44px;font-size:13.5px;"><span x-text="$store.ui.lang==='en' ? 'Share with Unijaya' : 'Kongsi dengan Unijaya'">Share with Unijaya</span></button>
                            <button type="button" @click="kbView = 'feed'" style="height:44px;padding:0 18px;border:1px solid var(--hairline);border-radius:8px;background:#fff;color:var(--body);font-size:13.5px;font-weight:500;cursor:pointer;" x-text="$store.ui.lang==='en' ? 'Cancel' : 'Batal'">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>

            {{-- ── NEW SEGMENT ──────────────────────────────────────────────── --}}
            <div x-show="kbView === 'newseg'" x-cloak style="display:flex;flex-direction:column;height:100%;">
                <div style="flex-shrink:0;padding:16px 16px 0;">
                    <button @click="kbView = 'feed'" style="font-size:12.5px;color:var(--muted);background:none;cursor:pointer;" x-text="$store.ui.lang==='en' ? '← Back to feed' : '← Kembali ke suapan'">← Back to feed</button>
                </div>
                <div style="flex:1;overflow-y:auto;padding:14px 18px 18px;">
                    <h2 style="font-size:21px;font-weight:400;color:var(--ink);letter-spacing:-0.3px;margin:0 0 4px;" x-text="$store.ui.lang==='en' ? 'Create a segment' : 'Cipta segmen'">Create a segment</h2>
                    <p style="font-size:12.5px;color:var(--muted);margin:0 0 18px;" x-text="$store.ui.lang==='en' ? 'Group related lessons under a new topic everyone can file into.' : 'Kumpulkan pengajaran berkaitan di bawah topik baharu yang boleh digunakan semua.'">Group related lessons under a new topic.</p>

                    @if ($errors->any() && old('kbform') === 'newseg')
                        <div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:9px 12px;margin-bottom:14px;">{{ $errors->first() }}</div>
                    @endif

                    <form method="post" action="{{ route('knowledge.segments') }}">
                        @csrf
                        <input type="hidden" name="kbform" value="newseg">
                        <label style="display:block;font-size:12.5px;font-weight:500;color:var(--ink);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Segment name' : 'Nama segmen'">Segment name</label>
                        <input name="label" value="{{ old('label') }}" required maxlength="80" :placeholder="$store.ui.lang==='en' ? 'e.g. Vendor Management' : 'cth. Pengurusan Vendor'" style="width:100%;height:42px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:13.5px;margin-bottom:18px;outline:none;" />
                        <div style="display:flex;gap:10px;">
                            <button type="submit" class="uj-btn-primary" style="flex:1;height:44px;font-size:13.5px;"><span x-text="$store.ui.lang==='en' ? 'Create segment' : 'Cipta segmen'">Create segment</span></button>
                            <button type="button" @click="kbView = 'feed'" style="height:44px;padding:0 18px;border:1px solid var(--hairline);border-radius:8px;background:#fff;color:var(--body);font-size:13.5px;font-weight:500;cursor:pointer;" x-text="$store.ui.lang==='en' ? 'Cancel' : 'Batal'">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
        </aside>
    </div>
</template>
