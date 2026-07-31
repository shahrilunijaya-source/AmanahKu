{{--
    Shared Profile Test question form — used for both "add" and "edit" on the
    Profile Test Editor screen. Working-style questions carry 4 animal-tagged
    options; colour questions are open free-text (no options).

    Params: $question (ProfileTestQuestion|null), $action (url), $submitLabel (string)
--}}
@php
    $q          = $question ?? null;
    $isEdit     = $q && $q->exists;
    $sectionVal = old('section', $q->section ?? 'working_style');
    $promptVal  = old('prompt', $q->prompt_en ?? '');
    $optRows    = old('options', $isEdit
        ? $q->options->map(fn ($o) => ['label' => $o->label_en, 'animal' => $o->animal])->toArray()
        : []);
    while (count($optRows) < 4) {
        $optRows[] = ['label' => '', 'animal' => ''];
    }
    $animals     = ['rabbit', 'tortoise', 'fox', 'sloth'];
    $animalEmoji = ['rabbit' => '🐇', 'tortoise' => '🐢', 'fox' => '🦊', 'sloth' => '🦥'];
    $pf = 'width:100%;padding:9px 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;background:#fff;color:var(--ink);outline:none;font-family:inherit;';
@endphp
<form method="post" action="{{ $action }}" x-data="{ section: '{{ $sectionVal }}', emoji: {{ \Illuminate\Support\Js::from($animalEmoji) }} }">
    @csrf
    <div style="display:flex;flex-direction:column;gap:14px;">
        <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
            <div style="flex:1;min-width:200px;">
                <label style="display:block;font-size:11.5px;font-weight:600;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Section' : 'Bahagian'">Section</span></label>
                <select name="section" x-model="section" style="{{ $pf }}">
                    <option value="working_style">Working style — scored on animal</option>
                    <option value="colour">Colour — flavour only, unscored</option>
                </select>
            </div>
        </div>

        <div>
            <label style="display:block;font-size:11.5px;font-weight:600;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Question prompt' : 'Soalan'">Question prompt</span></label>
            <textarea name="prompt" rows="2" required style="{{ $pf }}resize:vertical;">{{ $promptVal }}</textarea>
        </div>

        <div x-show="section === 'working_style'" x-cloak>
            <label style="display:block;font-size:11.5px;font-weight:600;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Options — give each one an animal. Empty rows are ignored.' : 'Pilihan — beri setiap satu haiwan. Baris kosong diabaikan.'">Options — give each one an animal. Empty rows are ignored.</span></label>
            <div style="display:flex;flex-direction:column;gap:8px;">
                @foreach ($optRows as $i => $opt)
                    @php $animalVal = $opt['animal'] ?? ''; @endphp
                    <div x-data="{ animal: '{{ $animalVal }}' }" style="display:flex;gap:8px;align-items:center;">
                        <span style="width:34px;height:34px;border-radius:8px;background:var(--canvas);border:1px solid var(--hairline);display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0;" x-text="emoji[animal] || '•'">{{ $animalEmoji[$animalVal] ?? '•' }}</span>
                        <input type="text" name="options[{{ $i }}][label]" value="{{ $opt['label'] ?? '' }}" placeholder="Option {{ $i + 1 }}" style="{{ $pf }}flex:1;">
                        <select name="options[{{ $i }}][animal]" x-model="animal" style="{{ $pf }}width:140px;flex-shrink:0;">
                            <option value="">— none —</option>
                            @foreach ($animals as $a)
                                <option value="{{ $a }}" @selected($animalVal === $a)>{{ ucfirst($a) }}</option>
                            @endforeach
                        </select>
                    </div>
                @endforeach
            </div>
        </div>

        <div style="display:flex;gap:10px;">
            <button type="submit" class="uj-btn-primary" style="height:38px;padding:0 18px;font-size:13px;">{{ $submitLabel }}</button>
        </div>
    </div>
</form>
