@php
    /**
     * The inside of a widget card: header, then the body at
     * partials/dash/widgets/{id}.blade.php. Rendered on its own by
     * AppController::dashboardWidgetPartial when a period arrow is clicked, so
     * everything here has to stand up without the page around it.
     *
     * The HEADER is the drag handle, the way the Worksy dashboard drags a widget
     * by its title: no grip button had to be added to eleven sections, and the
     * grip dots are a pseudo element so the handle costs no layout. Controls
     * inside the header keep working — dashboard-widgets.js's dragstart bails
     * out when the mousedown landed on a button, link, input or select.
     *
     * Expects: $id, $w (that widget's payload).
     */
    $meta = \App\Support\DashboardWidgets::ALL[$id];
@endphp
<div class="uj-dw-hd">
    <h2 x-data="{ en: @js($meta['title']), ms: @js($meta['title_ms']) }"
        x-text="$store.ui.lang==='en' ? en : ms">{{ $meta['title'] }}</h2>
    @includeIf('partials.dash.widgets.'.$id.'-head', ['w' => $w])
    @includeWhen(! empty($w['pnav']), 'partials.dash.pnav', ['p' => $w['pnav'] ?? []])
</div>
@include('partials.dash.widgets.'.$id, ['w' => $w])
