{{-- Flowers dashboard widget (CR-23): latest recognitions this month, tenant-wide. --}}
<div class="uj-dw-body">
    @forelse ($w['rows'] ?? [] as $r)
        <div class="uj-dw-notice">
            <span class="when">{{ $r['meta'] ?? '' }}</span>
            <span class="txt">
                <span class="t">@unless($w['plain'] ?? false)🌸 @endunless{{ $r['title'] ?? '' }}</span>
                <span class="s">{{ \Illuminate\Support\Str::limit((string) ($r['sub'] ?? ''), 120) }}</span>
            </span>
        </div>
    @empty
        {{-- Never actually shown: the widget doesn't get built when rows is empty
             (see BuildsDashboardWidgets::dashboardData), kept for template safety. --}}
        <p class="uj-dw-empty" x-text="$store.ui.lang==='en'
            ? 'No flowers yet this month.'
            : 'Tiada bunga lagi bulan ini.'">No flowers yet this month.</p>
    @endforelse
</div>
