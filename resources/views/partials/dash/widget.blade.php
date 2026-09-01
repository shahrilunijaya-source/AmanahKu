@php
    /**
     * One dashboard widget card — the shell only: border, hover lift, drag
     * handle, and the state the header and body share. Everything inside is
     * partials/dash/widget-inner.blade.php, which is also what the period
     * arrows fetch on their own, so a card can be rebuilt without the page.
     *
     * Expects:
     *   $id       widget id from App\Support\DashboardWidgets
     *   $widgets  the full payload map from the controller
     *
     * `scope` lives out here rather than in either partial because the control
     * that sets it is in the header and the thing it switches is in the body.
     * Only the month summary reads it today, and it survives a period swap
     * because the swap replaces what is inside this section, not the section.
     */
@endphp
<section class="uj-dw" data-widget="{{ $id }}" x-data="{ scope: 'me' }">
    @include('partials.dash.widget-inner', ['id' => $id, 'w' => $widgets[$id] ?? []])
</section>
