{{-- Worksy's staff form page: staff list on the left (All / My Staff, search), the chosen
     person's form on the right with year arrows and Edit. $form is one of
     StaffFormData::FORMS. Selection lives in the URL: ?tab=&year=&employee=. --}}
@php
    $company = app(\App\Tenancy\CurrentTenant::class)->get();
    $forms = app(\App\Services\Payroll\StaffFormData::class);
    $title = \App\Services\Payroll\StaffFormData::TITLES[$form];
    $mine = request('tab') === $form;
    $year = $mine && preg_match('/^\d{4}$/', (string) request('year')) === 1 ? (int) request('year') : (int) now()->year;
    $staff = $forms->staff($company, $form, $year);
    $picked = ($mine ? $staff->firstWhere('id', (int) request('employee')) : null) ?? $staff->first();
    $me = auth()->user()?->employeeFor($company);
    $url = fn (array $q) => route('app.screen', ['screen' => 'payroll-form', 'tab' => $form, 'year' => $year] + $q);
    $role = (string) request()->attributes->get('tenantRole');
    $canEdit = in_array($role, ['management', 'hr'], true) || \App\Support\Permissions::effectiveRole($role) === 'management';
@endphp
<div class="uj-card" data-testid="staff-form-{{ $form }}" style="padding:0;display:flex;min-height:520px;">
    <aside x-data="{ q: '', only: 'all' }" style="width:280px;flex-shrink:0;border-right:1px solid var(--hairline);display:flex;flex-direction:column;">
        <div style="padding:12px;border-bottom:1px solid var(--hairline);">
            <div style="display:flex;gap:6px;margin-bottom:8px;">
                <button type="button" @click="only = 'all'" :class="only === 'all' ? 'uj-btn-primary' : 'uj-btn-ghost'" style="height:28px;padding:0 12px;font-size:12px;" x-text="$store.ui.lang==='en' ? 'All' : 'Semua'">All</button>
                <button type="button" @click="only = 'mine'" :class="only === 'mine' ? 'uj-btn-primary' : 'uj-btn-ghost'" style="height:28px;padding:0 12px;font-size:12px;" x-text="$store.ui.lang==='en' ? 'My Staff' : 'Staf Saya'">My Staff</button>
            </div>
            <input type="search" x-model="q" placeholder="Search" style="border:1px solid var(--hairline);border-radius:8px;padding:0 8px;background:#fff;width:100%;height:32px;font-size:12.5px;">
        </div>
        <div style="overflow-y:auto;flex:1;">
            @forelse ($staff as $e)
                <div x-show="(only === 'all' || @js($me !== null && $e->reports_to_id === $me->id)) && @js(mb_strtolower($e->name.' '.$e->staff_id)).includes(q.toLowerCase())">
                <a href="{{ $url(['employee' => $e->id]) }}" style="display:flex;align-items:center;gap:10px;padding:10px 12px;text-decoration:none;color:var(--ink);border-bottom:1px solid var(--hairline-soft);{{ $picked?->id === $e->id ? 'background:var(--accent-tint, #eef4ff);' : '' }}">
                    <span style="width:30px;height:30px;border-radius:50%;background:{{ $e->avatar_color ?? '#3a6ea5' }};color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:600;flex-shrink:0;">{{ $e->initials }}</span>
                    <span style="min-width:0;">
                        <span style="display:block;font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $e->name }}</span>
                        <span style="display:block;font-size:11.5px;color:var(--muted);">{{ $e->staff_id }}</span>
                    </span>
                </a>
                </div>
            @empty
                <div style="padding:20px 12px;font-size:12.5px;color:var(--muted);">No staff for {{ $year }}.</div>
            @endforelse
        </div>
    </aside>

    <section style="flex:1;min-width:0;padding:16px 20px;" x-data="{ editing: false }">
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:14px;">
            <h3 class="uj-card-title" style="margin:0;">{{ $title }}</h3>
            <div style="margin-left:auto;display:flex;align-items:center;gap:6px;">
                <a href="{{ $url(['year' => $year - 1]) }}" class="uj-btn-ghost" aria-label="Previous year" style="height:32px;width:32px;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;">‹</a>
                <span style="font-size:13px;min-width:40px;text-align:center;">{{ $year }}</span>
                <a href="{{ $url(['year' => $year + 1]) }}" class="uj-btn-ghost" aria-label="Next year" style="height:32px;width:32px;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;">›</a>
                @if ($canEdit && $staff->isNotEmpty())
                    <a href="{{ route('payroll.staff-forms.pdf', [$form, $year]) }}" class="uj-btn-ghost" style="height:32px;padding:0 12px;font-size:12.5px;display:inline-flex;align-items:center;text-decoration:none;" x-text="$store.ui.lang==='en' ? 'Batch Export PDF' : 'Eksport PDF Pukal'">Batch Export PDF</a>
                @endif
                @if (in_array($form, ['cp22', 'cp22a'], true))
                    <button type="button" disabled class="uj-btn-ghost" title="LHDN's e-CP22 / e-CP22A text layout isn't built yet" style="height:32px;padding:0 12px;font-size:12.5px;opacity:.45;cursor:not-allowed;" x-text="$store.ui.lang==='en' ? 'Batch Export Text Files' : 'Eksport Fail Teks Pukal'">Batch Export Text Files</button>
                @endif
            </div>
        </div>

        @if ($picked === null)
            <div style="padding:40px 0;text-align:center;font-size:13px;color:var(--muted);">There is no record for {{ $year }}.</div>
        @else
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">
                <span style="width:42px;height:42px;border-radius:50%;background:{{ $picked->avatar_color ?? '#3a6ea5' }};color:#fff;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:600;">{{ $picked->initials }}</span>
                <div style="min-width:0;">
                    <div style="font-size:14px;font-weight:600;color:var(--ink);">{{ $picked->name }}</div>
                    <div style="font-size:12px;color:var(--muted);">{{ collect([$picked->position, $picked->phone, $picked->archived_at ? 'Archived' : 'Active', $picked->reportsTo ? 'Reports to '.$picked->reportsTo->name : null])->filter()->implode(' · ') }}</div>
                </div>
                @if ($form === 'cp22a' && \App\Services\Payroll\LifecycleNotices::onPcb($picked, $year))
                    <span class="uj-pill" data-testid="cp22a-on-pcb" style="font-size:11px;" x-text="$store.ui.lang==='en' ? 'Not required, on PCB' : 'Tidak diperlukan, dalam PCB'">Not required, on PCB</span>
                @endif
                @if ($canEdit)
                    <div style="margin-left:auto;display:flex;gap:6px;">
                        <button type="button" x-show="!editing" @click="editing = true" class="uj-btn-ghost" style="height:32px;padding:0 14px;font-size:12.5px;" x-text="$store.ui.lang==='en' ? 'Edit' : 'Sunting'">Edit</button>
                        <button type="button" x-show="editing" x-cloak @click="editing = false" class="uj-btn-ghost" style="height:32px;padding:0 14px;font-size:12.5px;" x-text="$store.ui.lang==='en' ? 'Cancel' : 'Batal'">Cancel</button>
                        <button type="submit" form="staff-form-{{ $form }}-edit" x-show="editing" x-cloak class="uj-btn-primary" style="height:32px;padding:0 14px;font-size:12.5px;" x-text="$store.ui.lang==='en' ? 'Save' : 'Simpan'">Save</button>
                    </div>
                @endif
            </div>
            <form id="staff-form-{{ $form }}-edit" method="post" action="{{ route('payroll.staff-forms.update', [$picked, $form, $year]) }}">
                @csrf
                @include('partials.payroll.form.lhdn-form', ['data' => $forms->build($company, $picked, $form, $year), 'title' => $title, 'year' => $year, 'editable' => $canEdit])
            </form>
        @endif
    </section>
</div>
