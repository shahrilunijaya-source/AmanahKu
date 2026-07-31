{{-- Slide-over detail panel for one Knowledge Bank entry — the full lesson, an
     emoji React action, a Helpful toggle (the star), and the discussion. Lives
     inside the entry's kbCard x-data; teleported to <body> so it is never clipped
     and only one paints at a time. Reuses the T.A.A./T.O.T. .wd-* + .tot-* styles. --}}
<template x-teleport="body">
    <div x-show="drawerOpen" x-cloak>
        <div class="wd-scrim" :data-open="drawerOpen ? '' : null" @click="drawerOpen = false"></div>
        <aside class="wd" :data-open="drawerOpen ? '' : null" role="dialog" aria-modal="true"
               @keydown.escape.window="flyout ? (flyout = null) : (drawerOpen = false)">

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

                <h2 style="font-size:20px;font-weight:600;line-height:1.3;color:var(--ink);margin:0 0 14px;">{{ $e->title }}</h2>

                <div style="display:flex;align-items:center;gap:9px;margin-bottom:18px;">
                    <span style="width:30px;height:30px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;color:#fff;font-size:11px;font-weight:600;background:{{ $e->employee?->avatar_color ?? '#3a6ea5' }};">{{ $e->employee?->initials ?? '–' }}</span>
                    <div style="min-width:0;">
                        <div style="font-size:13px;font-weight:600;color:var(--ink);">{{ $e->employee?->name ?? 'Unknown' }}</div>
                        @if ($e->employee?->position)<div style="font-size:11px;color:var(--muted);">{{ $e->employee->position }}</div>@endif
                    </div>
                </div>

                <div style="font-size:14.5px;line-height:1.7;color:#3f3e38;text-wrap:pretty;">{!! \App\Support\Amanahku::linkify($e->body) !!}</div>

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
                            <span style="width:28px;height:28px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;color:#fff;font-size:10.5px;font-weight:600;" :style="`background:${c.color};`" x-text="c.initials"></span>
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
    </div>
</template>
