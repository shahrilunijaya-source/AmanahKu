{{-- Slide-over detail panel for one Knowledge Bank entry — the full lesson, an
     emoji React action, a Helpful toggle (the star), and the discussion. Lives
     inside the entry's kbCard x-data; teleported to <body> so it is never clipped
     and only one paints at a time. Reuses the T.A.A./T.O.T. .wd-* + .tot-* styles. --}}
<template x-teleport="body">
    <div x-show="drawerOpen" x-cloak>
        <div class="wd-scrim" :data-open="drawerOpen ? '' : null" @click="drawerOpen = false"></div>
        <aside class="wd" :data-open="drawerOpen ? '' : null" role="dialog" aria-modal="true"
               @keydown.escape.window="lightboxOpen ? null : (flyout ? (flyout = null) : (drawerOpen = false))">

            <div class="wd-head">
                <span style="font:600 13.5px var(--font-sans);color:var(--ink);">{{ $e->created_at?->format('j M Y') }}</span>
                <button type="button" class="wd-ico" style="margin-left:auto;" @click="drawerOpen = false"
                        :aria-label="$store.ui.lang==='en' ? 'Close' : 'Tutup'">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="wd-body">
                <div style="display:flex;align-items:center;gap:9px;flex-wrap:wrap;margin-bottom:8px;">
                    <span style="font-size:11.5px;color:var(--muted);">{{ $e->segment?->label }}</span>
                    @if ($e->subSegment)
                        <span style="width:3px;height:3px;border-radius:50%;background:#c9c6bc;"></span>
                        <span style="font-size:11.5px;color:var(--muted);">{{ $e->subSegment->label }}</span>
                    @endif
                </div>

                <template x-if="!editing">
                    <div style="display:flex;align-items:flex-start;gap:8px;margin:0 0 14px;">
                        <h2 style="font-size:20px;font-weight:600;line-height:1.3;color:var(--ink);margin:0;flex:1;" x-text="title"></h2>
                        <button type="button" x-show="canEdit" class="wd-ico" style="flex-shrink:0;" @click="startEdit()"
                                :aria-label="$store.ui.lang==='en' ? 'Edit lesson' : 'Sunting pengajaran'">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5z"/></svg>
                        </button>
                    </div>
                </template>

                <template x-if="editing">
                    <div style="margin:0 0 14px;">
                        <input x-model="editTitle" maxlength="200" style="width:100%;height:40px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:15px;font-weight:600;margin-bottom:8px;outline:none;" />
                        <textarea x-model="editBody" maxlength="5000" rows="5" style="width:100%;padding:10px 12px;border:1px solid var(--hairline);border-radius:8px;font-size:13.5px;resize:vertical;outline:none;font-family:inherit;line-height:1.6;margin-bottom:8px;"></textarea>

                        <div x-show="editError" x-cloak style="font-size:11.5px;color:var(--red);margin-bottom:6px;">
                            <span x-show="editError==='type'" x-text="$store.ui.lang==='en' ? 'Only JPG, PNG, GIF, or WebP pictures.' : 'Hanya gambar JPG, PNG, GIF, atau WebP.'"></span>
                            <span x-show="editError==='size'" x-text="$store.ui.lang==='en' ? 'Each picture must be 8 MB or smaller.' : 'Setiap gambar mesti 8 MB atau lebih kecil.'"></span>
                            <span x-show="editError==='max'" x-text="$store.ui.lang==='en' ? 'You can attach up to 10 pictures.' : 'Anda boleh lampirkan sehingga 10 gambar.'"></span>
                        </div>
                        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;">
                            <template x-for="e in editExisting.filter(x => !x.removed)" :key="e.id">
                                <div style="position:relative;width:64px;">
                                    <img :src="e.url" style="width:64px;height:64px;object-fit:cover;border-radius:8px;border:1px solid var(--hairline);" />
                                    <button type="button" @click="toggleRemoveExisting(e.id)" style="position:absolute;top:-6px;right:-6px;width:18px;height:18px;border-radius:50%;background:var(--ink);color:#fff;font-size:11px;line-height:1;">&times;</button>
                                    <input x-model="e.caption" maxlength="200" :placeholder="$store.ui.lang==='en' ? 'Caption' : 'Kapsyen'" style="width:64px;font-size:9.5px;margin-top:3px;border:1px solid var(--hairline);border-radius:4px;padding:2px 3px;" />
                                </div>
                            </template>
                            <template x-for="(f, i) in editFiles" :key="f.url">
                                <div style="position:relative;width:64px;">
                                    <img :src="f.url" style="width:64px;height:64px;object-fit:cover;border-radius:8px;border:1px solid var(--hairline);" />
                                    <button type="button" @click="removeEditFile(i)" style="position:absolute;top:-6px;right:-6px;width:18px;height:18px;border-radius:50%;background:var(--ink);color:#fff;font-size:11px;line-height:1;">&times;</button>
                                    <input x-model="f.caption" maxlength="200" :placeholder="$store.ui.lang==='en' ? 'Caption' : 'Kapsyen'" style="width:64px;font-size:9.5px;margin-top:3px;border:1px solid var(--hairline);border-radius:4px;padding:2px 3px;" />
                                </div>
                            </template>
                            <button type="button" @click="$refs.editFileInput.click()" style="width:64px;height:64px;border:1px dashed var(--hairline);border-radius:8px;color:var(--muted);font-size:20px;background:#fff;">+</button>
                            <input x-ref="editFileInput" type="file" multiple accept="image/*" style="display:none;" @change="addEditFiles($event.target.files); $event.target.value = ''" />
                        </div>

                        <div style="display:flex;gap:8px;">
                            <button type="button" class="uj-btn-primary" style="height:36px;padding:0 16px;font-size:12.5px;" :disabled="busy" @click="saveEdit()"
                                    x-text="$store.ui.lang==='en' ? 'Save' : 'Simpan'">Save</button>
                            <button type="button" style="height:36px;padding:0 16px;border:1px solid var(--hairline);border-radius:8px;background:#fff;color:var(--body);font-size:12.5px;font-weight:500;cursor:pointer;" @click="editing = false"
                                    x-text="$store.ui.lang==='en' ? 'Cancel' : 'Batal'">Cancel</button>
                        </div>
                    </div>
                </template>

                <div style="display:flex;align-items:center;gap:9px;margin-bottom:18px;">
                    <span style="width:30px;height:30px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;color:#fff;font-size:11px;font-weight:600;background:{{ $e->employee?->avatar_color ?? '#3a6ea5' }};">{{ $e->employee?->initials ?? '–' }}</span>
                    <div style="min-width:0;">
                        <div style="font-size:13px;font-weight:600;color:var(--ink);">{{ $e->employee?->name ?? 'Unknown' }}</div>
                        @if ($e->employee?->position)<div style="font-size:11px;color:var(--muted);">{{ $e->employee->position }}</div>@endif
                    </div>
                </div>

                <div x-show="!editing" x-html="bodyHtml" style="font-size:14.5px;line-height:1.7;color:#3f3e38;text-wrap:pretty;white-space:pre-wrap;">{!! \App\Support\Amanahku::linkify($e->body) !!}</div>

                <div x-show="!editing && attachments.length" x-cloak class="kb-attach-strip" style="gap:10px;margin-top:14px;overflow-x:auto;-webkit-overflow-scrolling:touch;scrollbar-width:none;">
                    <template x-for="(a, i) in attachments" :key="a.id">
                        <button type="button" @click="openLightbox(i)" style="position:relative;padding:0;border:none;border-radius:10px;overflow:hidden;width:96px;height:96px;flex-shrink:0;cursor:pointer;">
                            <img :src="a.url" :alt="a.caption || title" style="width:100%;height:100%;object-fit:cover;display:block;" loading="lazy" />
                        </button>
                    </template>
                </div>

                @if (! empty($e->tags))
                    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:16px;">
                        @foreach ($e->tags as $tag)
                            <span style="font-size:11px;color:var(--muted-soft);border:1px solid var(--hairline);padding:3px 10px;border-radius:999px;">#{{ $tag }}</span>
                        @endforeach
                    </div>
                @endif

                {{-- React + Helpful --}}
                <div class="tot-actions">
                    <span class="tot-fw">
                        @if ($canSubmit)
                            <span class="tot-fly" x-show="flyout === 'react'" x-cloak
                                  @mouseleave="flyout = null" @keydown.escape.window="flyout = null">
                                @foreach (\App\Models\KnowledgeEntry::EMOJI as $i => $em)
                                    <button type="button" class="tot-fly-e" style="--d:{{ $i * 30 }}ms"
                                            @click="react(@js($em)); flyout = null"
                                            :data-mine="mine.includes(@js($em)) ? '1' : null"
                                            aria-label="React {{ $em }}">{{ $em }}</button>
                                @endforeach
                            </span>
                            <button type="button" class="tot-act" :data-on="mine.length ? '1' : null"
                                    @click="heartPress()" @mouseenter="flyout = 'react'"
                                    :aria-label="mine.length
                                        ? ($store.ui.lang==='en' ? 'Remove your reaction' : 'Buang reaksi anda')
                                        : ($store.ui.lang==='en' ? 'React to this lesson' : 'Beri reaksi')">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1.1 1L12 21l7.7-7.6 1.1-1a5.5 5.5 0 0 0 0-7.8z"/></svg>
                                <span x-text="reactionTotal || ''"></span>
                            </button>
                        @else
                            <span class="tot-act">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1.1 1L12 21l7.7-7.6 1.1-1a5.5 5.5 0 0 0 0-7.8z"/></svg>
                                <span x-text="reactionTotal || ''"></span>
                            </span>
                        @endif
                    </span>

                    @if ($canSubmit)
                        <button type="button" class="tot-act" :data-on="starred ? '1' : null" @click="helpful()"
                                :aria-label="starred
                                    ? ($store.ui.lang==='en' ? 'Remove helpful' : 'Buang membantu')
                                    : ($store.ui.lang==='en' ? 'Mark as helpful' : 'Tanda membantu')">
                            <svg width="18" height="18" viewBox="0 0 24 24" :fill="starred ? 'currentColor' : 'none'" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l3.1 6.3 6.9 1-5 4.9 1.2 6.9L12 17.8 5.8 21l1.2-6.9-5-4.9 6.9-1z"/></svg>
                            <span x-text="$store.ui.lang==='en' ? 'Helpful' : 'Membantu'">Helpful</span>
                            <span x-text="stars || ''"></span>
                        </button>
                    @else
                        <span class="tot-act">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l3.1 6.3 6.9 1-5 4.9 1.2 6.9L12 17.8 5.8 21l1.2-6.9-5-4.9 6.9-1z"/></svg>
                            <span x-text="$store.ui.lang==='en' ? 'Helpful' : 'Membantu'">Helpful</span>
                            <span x-text="stars || ''"></span>
                        </span>
                    @endif
                </div>

                <hr class="wd-rule">

                <h3 class="wd-sech" x-text="commentsCount ? ($store.ui.lang==='en' ? `Discussion · ${commentsCount}` : `Perbincangan · ${commentsCount}`) : ($store.ui.lang==='en' ? 'Discussion' : 'Perbincangan')">Discussion</h3>

                <template x-if="thread === null">
                    <div style="font-size:12.5px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Loading…' : 'Memuatkan…'">Loading…</div>
                </template>
                <template x-if="thread !== null && thread.length === 0">
                    <div style="font-size:12.5px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'No comments yet — start the discussion.' : 'Tiada komen lagi — mulakan perbincangan.'">No comments yet.</div>
                </template>

                <div class="wd-cmts">
                    <template x-for="c in (thread || [])" :key="c.id">
                        <div class="wd-cmt">
                            <span style="width:28px;height:28px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;color:#fff;font-size:10.5px;font-weight:600;" :style="{ background: c.color }" x-text="c.initials"></span>
                            <div style="min-width:0;flex:1;">
                                <div class="wd-cmt-who">
                                    <span class="wd-cmt-name" x-text="c.name"></span>
                                    <span class="wd-cmt-at" x-text="c.at"></span>
                                    <button type="button" x-show="c.canDelete" class="wd-ico" style="margin-left:auto;width:22px;height:22px;"
                                            @click="removeComment(c.id)"
                                            :aria-label="$store.ui.lang==='en' ? 'Remove comment' : 'Buang komen'">&times;</button>
                                </div>
                                <div class="wd-cmt-body" x-text="c.body"></div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            @if ($canSubmit)
                <div class="wd-foot">
                    <textarea rows="1" x-ref="kbComposer" maxlength="2000"
                              :placeholder="$store.ui.lang==='en' ? 'Add a comment…' : 'Tambah komen…'"
                              @keydown.enter.prevent="postComment($event.target.value); $event.target.value = ''"></textarea>
                    <button type="button" class="uj-btn-primary wd-post" :disabled="busy"
                            @click="postComment($refs.kbComposer.value); $refs.kbComposer.value = ''"
                            x-text="$store.ui.lang==='en' ? 'Post' : 'Hantar'">Post</button>
                </div>
            @endif
        </aside>

        {{-- Sibling of .wd, not a descendant: .wd has `transform` for its slide-in
             animation, which creates a new containing block for `position:fixed`
             descendants — a fixed-position child of .wd would be positioned relative to
             the drawer, not the viewport. Kept inside the same teleported-to-body wrapper
             so it still only exists while the drawer is mounted. --}}
        <div x-show="lightboxOpen" x-cloak
             x-transition:enter="uj-overlay-enter" x-transition:enter-start="uj-overlay-from" x-transition:enter-end="uj-overlay-to"
             x-transition:leave="uj-overlay-leave" x-transition:leave-start="uj-overlay-to" x-transition:leave-end="uj-overlay-from"
             @keydown.escape.window="closeLightbox()" @touchstart="swipeStart($event)" @touchmove="swipeMove($event)" @touchend="swipeEnd($event)" class="kb-lightbox">
            <button type="button" @click="closeLightbox()" style="position:absolute;top:20px;right:20px;color:#fff;font-size:28px;background:none;">&times;</button>
            <button type="button" x-show="lightboxIndex > 0" @click="prevImage()" style="position:absolute;left:20px;color:#fff;font-size:32px;background:none;">&larr;</button>
            <template x-if="lightboxOpen">
                <div style="max-width:90vw;max-height:85vh;text-align:center;">
                    <img data-kb-lightbox-img :src="attachments[lightboxIndex]?.url" :alt="attachments[lightboxIndex]?.caption || title"
                         :class="{ 'kb-lightbox-img--settle': !dragging }"
                         :style="'max-width:90vw;max-height:78vh;object-fit:contain;border-radius:12px;transform:translateX(' + dragX + 'px);'" />
                    <div x-show="attachments[lightboxIndex]?.caption" style="color:#fff;font-size:13px;margin-top:10px;" x-text="attachments[lightboxIndex]?.caption"></div>
                </div>
            </template>
            <button type="button" x-show="lightboxIndex < attachments.length - 1" @click="nextImage()" style="position:absolute;right:20px;color:#fff;font-size:32px;background:none;">&rarr;</button>
        </div>
    </div>
</template>
