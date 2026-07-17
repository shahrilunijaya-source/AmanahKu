{{-- "Who signs off your request" — the two-step approval chain shown up front so an
     applicant knows who verifies and who approves before they submit. Always visible,
     even with zero requests. Shared by leave, claims and overtime. Embeds inside an
     existing card (it is an inset panel, not a full card). Expects $approvalChain =
     ['verifiers' => Collection<Employee>, 'approvers' => Collection<Employee>]. --}}
@php
    $verifiers = collect($approvalChain['verifiers'] ?? []);
    $approvers = collect($approvalChain['approvers'] ?? []);
    // "(any one)" only when more than one management member can give final approval.
    $manyApprovers = $approvers->count() > 1;
    $approveEn = 'Approved by management'.($manyApprovers ? ' (any one)' : '');
    $approveMs = 'Diluluskan oleh pengurusan'.($manyApprovers ? ' (mana-mana satu)' : '');
@endphp
<div style="border:1px solid var(--hairline-soft);border-radius:12px;padding:14px 15px;margin-bottom:14px;background:var(--canvas);">
    <h4 style="margin:0 0 3px;font-size:12.5px;font-weight:650;color:var(--ink);">
        <span x-text="$store.ui.lang==='en' ? 'Who signs off your request' : 'Siapa meluluskan permohonan anda'">Who signs off your request</span>
    </h4>
    <p style="margin:0 0 13px;font-size:11px;color:var(--muted);line-height:1.5;">
        <span x-text="$store.ui.lang==='en' ? 'Two steps: your manager verifies first, then management gives the final approval.' : 'Dua langkah: pengurus anda sahkan dahulu, kemudian pengurusan beri kelulusan akhir.'"></span>
    </p>

    <div style="display:flex;flex-direction:column;gap:13px;">
        {{-- Step 1 — verify (the requester's own superior/s) --}}
        <div style="display:flex;gap:11px;">
            <span style="width:22px;height:22px;border-radius:50%;background:var(--info-tint,#e8f0f8);color:var(--info);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0;">1</span>
            <div style="min-width:0;flex:1;">
                <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.03em;margin-bottom:6px;">
                    <span x-text="$store.ui.lang==='en' ? 'Verified by your manager' : 'Disahkan oleh pengurus anda'">Verified by your manager</span>
                </div>
                <div style="display:flex;flex-wrap:wrap;gap:7px;">
                    @forelse ($verifiers as $p)
                        @include('partials.person-chip', ['p' => $p])
                    @empty
                        <span style="font-size:11.5px;color:var(--amber);line-height:1.5;" x-text="$store.ui.lang==='en' ? 'No manager assigned yet — ask HR to set your superior in the org chart.' : 'Belum ada pengurus ditetapkan — minta HR tetapkan penyelia anda dalam carta organisasi.'">No manager assigned yet.</span>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- Step 2 — approve (any one of the management tier) --}}
        <div style="display:flex;gap:11px;">
            <span style="width:22px;height:22px;border-radius:50%;background:var(--success-tint,#e7f4ec);color:var(--success);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0;">2</span>
            <div style="min-width:0;flex:1;">
                <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.03em;margin-bottom:6px;">
                    <span x-text="$store.ui.lang==='en' ? '{{ $approveEn }}' : '{{ $approveMs }}'">Approved by management</span>
                </div>
                <div style="display:flex;flex-wrap:wrap;gap:7px;">
                    @forelse ($approvers as $p)
                        @include('partials.person-chip', ['p' => $p])
                    @empty
                        <span style="font-size:11.5px;color:var(--muted);line-height:1.5;" x-text="$store.ui.lang==='en' ? 'The management team.' : 'Pasukan pengurusan.'">The management team.</span>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>
