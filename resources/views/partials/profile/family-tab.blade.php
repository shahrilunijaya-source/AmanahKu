{{-- Family tab: Parents, Spouse, Children, Other Dependents. Expects $p, $familyMembers, $canEditPersonal, $fs. --}}
@php
    $L = fn ($en, $ms) => '<span x-text="'.e("\$store.ui.lang==='en' ? ".json_encode($en).' : '.json_encode($ms)).'">'.e($en).'</span>';
    $lbl = 'display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;';
    $groups = [
        ['Parents', 'Ibu Bapa', ['father', 'mother']],
        ['Spouse', 'Pasangan', ['spouse']],
        ['Children', 'Anak-anak', ['child']],
        ['Other Dependents', 'Tanggungan Lain', ['dependent']],
    ];
    $relL = ['father' => ['Father', 'Bapa'], 'mother' => ['Mother', 'Ibu'], 'spouse' => ['Spouse', 'Pasangan'], 'child' => ['Child', 'Anak'], 'dependent' => ['Dependent', 'Tanggungan']];
    $canEdit = $canEditPersonal ?? false;
    $familyErr = $errors->any() && session('form') === 'family';
    $addOpen = $familyErr && ! old('_member');
@endphp

@foreach ($groups as [$gen, $gms, $relations])
    @php
        $rows = $familyMembers->whereIn('relation', $relations);
        // Parents: one father and one mother, so the Add form only offers what is still missing.
        $addable = array_values(array_diff($relations, count($relations) > 1 ? $rows->pluck('relation')->all() : []));
    @endphp
    <div style="display:flex;flex-direction:column;gap:10px;">
        <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.6px;">{!! $L($gen, $gms) !!}</div>

        @forelse ($rows as $m)
            <div class="uj-card" x-data="{ open: {{ ($familyErr && old('_member') == $m->id) ? 'true' : 'false' }} }" style="padding:12px 14px;">
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    <span style="font-size:11px;font-weight:600;padding:2px 8px;border-radius:999px;background:var(--surface-2,#f1f1f4);color:var(--muted);">{!! $L(...$relL[$m->relation]) !!}</span>
                    <span style="font-size:13px;font-weight:600;color:var(--ink);">{{ $m->name }}</span>
                    @if ($m->deceased)<span style="font-size:11px;color:var(--muted);">({!! $L('deceased', 'meninggal dunia') !!})</span>@endif
                    <span style="font-size:12px;color:var(--muted);">{{ $m->date_of_birth?->format('d/m/Y') }}{{ $m->phone ? ' · '.$m->phone : '' }}{{ $m->occupation ? ' · '.ucfirst($m->occupation) : '' }}{{ $m->education ? ' · '.$m->education : '' }}{{ $m->employer_name ? ' · '.$m->employer_name : '' }}</span>
                    @if ($canEdit)<button type="button" @click="open = !open" class="uj-btn-ghost" style="margin-left:auto;height:28px;padding:0 10px;font-size:12px;">{!! $L('Edit', 'Sunting') !!}</button>@endif
                </div>
                @if ($canEdit)
                    <div x-show="open" x-cloak style="margin-top:12px;">
                        @include('partials.profile.family-form', ['action' => route('employees.family.update', $m), 'm' => $m, 'relations' => $relations])
                        <form method="post" action="{{ route('employees.family.destroy', $m) }}" onsubmit="return confirm('Remove this family member?')" style="margin-top:6px;">@csrf<button type="submit" class="uj-btn-ghost" style="height:32px;padding:0 12px;font-size:12px;color:var(--red);">{!! $L('Remove', 'Buang') !!}</button></form>
                    </div>
                @endif
            </div>
        @empty
            <p style="font-size:12.5px;color:var(--muted);margin:0;">{!! $L('No record found', 'Tiada rekod') !!}</p>
        @endforelse

        @if ($canEdit && $addable !== [])
            <div x-data="{ add: {{ ($addOpen && in_array(old('relation'), $relations, true)) ? 'true' : 'false' }} }">
                <button type="button" @click="add = !add" class="uj-btn-ghost" style="height:32px;padding:0 12px;font-size:12px;">+ {!! $L('Add', 'Tambah') !!}</button>
                <div x-show="add" x-cloak class="uj-card" style="margin-top:8px;padding:14px;">
                    @include('partials.profile.family-form', ['action' => route('employees.family.store', $p), 'm' => null, 'relations' => $addable])
                </div>
            </div>
        @endif
    </div>
@endforeach
