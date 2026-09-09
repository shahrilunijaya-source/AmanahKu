{{--
    CR-27: the Mystery Award pick form — POST /app/awards/mystery. Used both as the first
    pick and, inside a <details> "Pick again", once a pick already exists. Never
    prefilled with the current pick (category/explanation stay sealed even here).

    $colleagues        Employee collection (screenData()'s existing list)
    $mysteryLastWinnerId  int|null — only set when last month's row is published
--}}
<form method="post" action="{{ url('/app/awards/mystery') }}" class="uj-ma-form">
    @csrf
    <div>
        <label>Colleague</label>
        <select name="employee_id" required>
            @foreach ($colleagues ?? [] as $c)
                <option value="{{ $c->id }}" @if (($mysteryLastWinnerId ?? null) === $c->id) disabled @endif>{{ $c->display_name }}@if (($mysteryLastWinnerId ?? null) === $c->id) (won last month)@endif</option>
            @endforeach
        </select>
    </div>
    <div>
        <label>Category (make one up)</label>
        {{-- OPEN.md S27/CR-27: no <datalist> of the spec's own example names here — every
             one of them is also a literal secret string in some CR27Test scenario, so a
             static suggestion list built from that set would leak on this very page. --}}
        <input type="text" name="category" maxlength="80" required placeholder="Make up a category, e.g. a funny one-off title" />
    </div>
    <div>
        <label>Why (funny, kind, one or two lines)</label>
        <textarea name="explanation" rows="3" maxlength="500" required></textarea>
    </div>
    <button type="submit" class="uj-btn-primary">Seal it</button>
    <p class="uj-ma-hint">Stays hidden from everyone, you included, until the 1st.</p>
</form>
