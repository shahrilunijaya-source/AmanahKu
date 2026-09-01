@php
    /**
     * One dashboard widget card. The shell (border, hover lift, drag handle,
     * title) lives here; the body is partials/dash/widgets/{id}.blade.php.
     *
     * Expects:
     *   $id       widget id from App\Support\DashboardWidgets
     *   $widgets  the full payload map from the controller
     *
     * The HEADER is the drag handle, the way the Worksy dashboard drags a widget
     * by its title: no grip button had to be added to eleven sections, and the
     * grip dots are a pseudo element so the handle costs no layout. Controls
     * inside the header keep working — dash.blade.php's dragstart bails out when
     * the mousedown landed on a button, link, input or select.
     */
    $meta = \App\Support\DashboardWidgets::ALL[$id];
    $w = $widgets[$id] ?? [];
@endphp
<section class="uj-dw" data-widget="{{ $id }}">
    <div class="uj-dw-hd">
        <h2 x-data="{ en: @js($meta['title']), ms: @js($meta['title_ms']) }"
            x-text="$store.ui.lang==='en' ? en : ms">{{ $meta['title'] }}</h2>
        @includeIf('partials.dash.widgets.'.$id.'-head', ['w' => $w])
    </div>
    @include('partials.dash.widgets.'.$id, ['w' => $w])
</section>
