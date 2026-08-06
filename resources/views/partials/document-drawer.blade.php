{{-- Document detail as a side drawer — same .wd-* grammar as helpdesk/knowledge-bank
     drawers (do not add new .wd- rules). Teleported so it is never clipped by the row
     and only one paints at a time. Expects an ancestor with x-data="{ drawerOpen: false }". --}}
<template x-teleport="body">
    <div x-show="drawerOpen" x-cloak>
        <div class="wd-scrim" :data-open="drawerOpen ? '' : null" @click="drawerOpen = false"></div>
        <aside class="wd" :data-open="drawerOpen ? '' : null" role="dialog" aria-modal="true"
               @keydown.escape.window="drawerOpen = false">

            <div class="wd-head">
                <span class="uj-stamp" @if ($catTone[$doc->category] ?? null) data-tone="{{ $catTone[$doc->category] }}" @endif>{{ $doc->category }}</span>
                <button type="button" class="wd-ico" style="margin-left:auto;" @click="drawerOpen = false"
                        :aria-label="$store.ui.lang==='en' ? 'Close' : 'Tutup'">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="wd-body">
                <h2 class="wd-title">{{ $doc->title }}</h2>
                <p class="wd-sub">
                    @if ($privileged){{ $doc->employee?->name ?? '—' }} · @endif
                    {{ $fmtSize($doc->size) }} · {{ $doc->created_at?->format('d M Y') }}
                </p>

                {{-- Same attachment-chip markup as partials/ticket-drawer.blade.php:50-54 —
                     extension badge + filename, pointing at the auth-gated download route,
                     never a public URL. --}}
                <a href="{{ route('documents.download', $doc) }}"
                   style="display:inline-flex;align-items:center;gap:7px;height:34px;padding:0 12px;border-radius:8px;border:1px solid var(--hairline-soft);font-size:12.5px;color:var(--body);text-decoration:none;">
                    <span style="font-weight:700;font-size:10.5px;color:var(--muted);">{{ strtoupper(pathinfo($doc->original_name, PATHINFO_EXTENSION)) }}</span>
                    <span>{{ $doc->original_name }}</span>
                </a>

                <hr class="wd-rule">

                <div style="display:flex;gap:10px;">
                    <a href="{{ route('documents.download', $doc) }}" class="uj-btn-primary" style="height:38px;padding:0 18px;font-size:13px;text-decoration:none;display:inline-flex;align-items:center;" x-text="$store.ui.lang==='en' ? 'Download' : 'Muat turun'">Download</a>
                    <form method="post" action="{{ route('documents.destroy', $doc) }}" onsubmit="return confirm('Delete this document?');">
                        @csrf
                        <button type="submit" class="uj-btn-ghost" style="height:38px;padding:0 16px;font-size:13px;color:var(--red);" x-text="$store.ui.lang==='en' ? 'Delete' : 'Padam'">Delete</button>
                    </form>
                </div>
            </div>
        </aside>
    </div>
</template>
