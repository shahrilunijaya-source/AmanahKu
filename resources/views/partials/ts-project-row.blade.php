{{-- One project row on the Projects register. Shared by the initial render and the
     AJAX append on add. Expects $project (with categories + versions.createdBy +
     variations.decidedBy loaded), $categories (full list, for the edit form), $canEdit,
     $employees (for the PM/PE pickers), $editableFields (the viewer's writable master
     fields), $canReopen, $canRaiseVariation (finance: hr + management tier) and
     $canDecideVariation (management tier only). --}}
@php
    $canEdit = $canEdit ?? false;
    $employees = $employees ?? collect();
    $editableFields = $editableFields ?? [];
    $canReopen = $canReopen ?? false;
    $canRaiseVariation = $canRaiseVariation ?? false;
    $canDecideVariation = $canDecideVariation ?? false;
    $canRaiseBigDeal = $canRaiseBigDeal ?? false;
    $hay = mb_strtolower(trim($project->name.' '.$project->code.' '.$project->project_code.' '.$project->client));
    $catIds = $project->categories->pluck('id')->all();
    $statusColours = ['planning' => 'var(--muted)', 'active' => 'var(--info)', 'closed' => 'var(--error)'];
    // History shows people by name, not employee id.
    $peopleNames = collect($employees)->pluck('display_name', 'id')->all();
    $pendingVariations = $project->pendingVariationsCount();
@endphp
<div class="uj-card" style="padding:15px 18px;margin-bottom:10px;{{ $project->is_active ? '' : 'background:var(--canvas);' }}"
     x-data="{ edit: false, history: false, variations: false, bigDeal: false }"
     {{-- Registers this row in the parent's `items` index (search/empty-state banner)
          on both the initial render and an AJAX-appended row (Alpine.initTree runs
          x-init same as first paint) — no separate server-built index to fall stale. --}}
     x-init="items.push({ hay: @js($hay), active: @js($project->is_active), cats: @js($catIds) })"
     x-show="(showOff || @js($project->is_active)) && @js($hay).includes(q.toLowerCase())
             && (! cats.length || @js($catIds).some(c => cats.includes(c)))">
    <div style="display:flex;gap:13px;align-items:center;flex-wrap:wrap;">
        @if ($project->code)
            <span style="width:36px;height:36px;border-radius:9px;background:var(--canvas);border:1px solid var(--hairline);color:var(--muted);font-size:11px;font-weight:600;font-family:var(--font-mono);display:flex;align-items:center;justify-content:center;flex-shrink:0;">{{ $project->code }}</span>
        @endif
        <div style="flex:1;min-width:0;">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <span style="font-size:14px;color:{{ $project->is_active ? 'var(--ink)' : 'var(--muted)' }};font-weight:500;">{{ $project->name }}</span>
                @if ($project->status)
                    <span class="uj-stamp" style="color:{{ $statusColours[$project->status] ?? 'var(--muted)' }};border-color:{{ $statusColours[$project->status] ?? 'var(--hairline)' }};">{{ ucfirst($project->status) }}</span>
                @endif
                @if ($pendingVariations > 0)
                    <span class="uj-stamp" data-tone="amber"><span x-text="$store.ui.lang==='en' ? 'Awaiting approval' : 'Menunggu kelulusan'">Awaiting approval</span></span>
                @endif
            </div>
            <div style="display:flex;flex-wrap:wrap;align-items:center;gap:7px;margin-top:5px;font-size:11.5px;color:var(--muted);">
                @if ($project->client)
                    <span>{{ $project->client }}</span>
                @endif
                @if ($project->contract_value !== null)
                    <span>·</span>
                    <span style="font-family:var(--font-mono);">RM {{ number_format((float) $project->contract_value, 2) }}</span>
                @endif
                @if ($project->pm || $project->pe)
                    <span>·</span>
                    <span>PM: {{ $project->pm?->display_name ?? '—' }} / PE: {{ $project->pe?->display_name ?? '—' }}</span>
                @endif
                @unless ($project->is_active)
                    <span class="uj-stamp"><span x-text="$store.ui.lang==='en' ? 'Archived' : 'Diarkibkan'">Archived</span></span>
                @endunless
                @foreach ($project->categories as $cat)
                    <span class="uj-pill" style="background:color-mix(in srgb, {{ $cat->colour() }} 13%, var(--card));color:{{ $cat->colour() }};">{{ $cat->name }}</span>
                @endforeach
            </div>
        </div>
        @if ($project->versions->isNotEmpty())
            <button @click="history = ! history" type="button" class="uj-btn-ghost" style="height:32px;font-size:12px;padding:0 13px;"><span x-text="history ? ($store.ui.lang==='en' ? 'Hide history' : 'Sembunyi sejarah') : ($store.ui.lang==='en' ? 'History' : 'Sejarah')">History</span></button>
        @endif
        @if ($project->variations->isNotEmpty() || $canRaiseVariation)
            <button @click="variations = ! variations" type="button" class="uj-btn-ghost" style="height:32px;font-size:12px;padding:0 13px;"><span x-text="variations ? ($store.ui.lang==='en' ? 'Hide variations' : 'Sembunyi variasi') : ($store.ui.lang==='en' ? 'Variations' : 'Variasi')">Variations</span></button>
        @endif
        @if ($canRaiseBigDeal)
            <button @click="bigDeal = ! bigDeal" type="button" class="uj-btn-ghost" style="height:32px;font-size:12px;padding:0 13px;"><span x-text="bigDeal ? ($store.ui.lang==='en' ? 'Close' : 'Tutup') : ($store.ui.lang==='en' ? 'Mark as Big Deal' : 'Tanda sebagai Big Deal')">Mark as Big Deal</span></button>
        @endif
        @if ($canEdit)
            <button @click="edit = ! edit" type="button" class="uj-btn-ghost" style="height:32px;font-size:12px;padding:0 13px;"><span x-text="edit ? ($store.ui.lang==='en' ? 'Close' : 'Tutup') : ($store.ui.lang==='en' ? 'Edit' : 'Sunting')">Edit</span></button>
            <form method="post" action="{{ route('projects.archive', $project) }}">
                @csrf
                <button type="submit" class="uj-btn-ghost" style="height:32px;font-size:12px;padding:0 13px;">
                    @if ($project->is_active)
                        <span x-text="$store.ui.lang==='en' ? 'Archive' : 'Arkibkan'">Archive</span>
                    @else
                        <span x-text="$store.ui.lang==='en' ? 'Restore' : 'Pulihkan'">Restore</span>
                    @endif
                </button>
            </form>
            <form method="post" action="{{ route('projects.delete', $project) }}" onsubmit="return confirm('Delete or deactivate this project?')">
                @csrf
                <button type="submit" class="uj-btn-ghost" style="height:32px;font-size:12px;padding:0 13px;color:var(--error);"><span x-text="$store.ui.lang==='en' ? 'Delete' : 'Padam'">Delete</span></button>
            </form>
        @endif
    </div>

    @if ($project->isClosed() && $canReopen)
        <form method="post" action="{{ route('projects.reopen', $project) }}" style="margin-top:12px;padding-top:12px;border-top:1px solid var(--hairline-soft);display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
            @csrf
            <div style="flex:1;min-width:200px;">
                <label style="display:block;font-size:12px;font-weight:500;color:var(--ink);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Reopen reason (required)' : 'Sebab buka semula (diperlukan)'">Reopen reason (required)</span></label>
                <input name="reason" required maxlength="500" placeholder="Extension signed" style="width:100%;height:36px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;outline:none;" />
            </div>
            <button type="submit" class="uj-btn-primary" style="height:36px;padding:0 14px;font-size:12.5px;"><span x-text="$store.ui.lang==='en' ? 'Reopen' : 'Buka semula'">Reopen</span></button>
        </form>
    @endif

    @if ($project->versions->isNotEmpty())
        <div x-show="history" x-cloak style="margin-top:12px;padding-top:12px;border-top:1px solid var(--hairline-soft);display:flex;flex-direction:column;gap:8px;">
            @foreach ($project->versions->sortByDesc('version_no') as $version)
                <div style="font-size:12px;color:var(--body);">
                    <span style="font-weight:600;">v{{ $version->version_no }}</span>
                    <span style="color:var(--muted);">— {{ $version->effective_date->format('Y-m-d') }} — {{ $version->createdBy?->name ?? 'System' }}</span>
                    @if ($version->changes)
                        <div style="margin-top:2px;color:var(--muted);">
                            @foreach ($version->changes as $field => $delta)
                                @php $show = fn ($v) => $v === null || $v === '' ? '—' : (in_array($field, ['pm_id', 'pe_id'], true) ? ($peopleNames[$v] ?? $v) : $v); @endphp
                                <span>{{ \App\Projects\ProjectMaster::label($field) }}: {{ $show($delta['old'] ?? null) }} → {{ $show($delta['new'] ?? null) }}</span>@if (! $loop->last), @endif
                            @endforeach
                        </div>
                    @endif
                    @if ($version->reason)
                        <div style="margin-top:2px;font-style:italic;color:var(--muted);">{{ $version->reason }}</div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    @if ($project->variations->isNotEmpty() || $canRaiseVariation)
        <div x-show="variations" x-cloak style="margin-top:12px;padding-top:12px;border-top:1px solid var(--hairline-soft);display:flex;flex-direction:column;gap:12px;">
            @php $statusLabels = ['pending' => ['Awaiting approval', 'Menunggu kelulusan'], 'approved' => ['Approved', 'Diluluskan'], 'rejected' => ['Rejected', 'Ditolak']]; @endphp
            @forelse ($project->variations as $variation)
                <div style="font-size:12px;color:var(--body);padding:10px 12px;border:1px solid var(--hairline-soft);border-radius:8px;">
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                        <span style="font-weight:600;font-family:var(--font-mono);">{{ $variation->vo_no }}</span>
                        <span style="color:var(--muted);">— {{ $variation->variation_date->format('Y-m-d') }}</span>
                        @if ($variation->delta !== null)
                            <span style="font-family:var(--font-mono);color:{{ (float) $variation->delta >= 0 ? 'var(--info)' : 'var(--error)' }};">{{ (float) $variation->delta >= 0 ? '+' : '' }}{{ number_format((float) $variation->delta, 2) }}</span>
                        @endif
                        @php $statusLabel = $statusLabels[$variation->status] ?? [ucfirst($variation->status), ucfirst($variation->status)]; @endphp
                        <span class="uj-stamp" @if($variation->status === 'pending') data-tone="amber" @endif>
                            <span x-text="$store.ui.lang==='en' ? @js($statusLabel[0]) : @js($statusLabel[1])">{{ $statusLabel[0] }}</span>
                        </span>
                        @if ($variation->attachment_path)
                            <a href="{{ route('projects.variations.attachment', [$project, $variation]) }}" style="font-size:11px;color:var(--info);"><span x-text="$store.ui.lang==='en' ? 'Attachment' : 'Lampiran'">Attachment</span></a>
                        @endif
                    </div>
                    <div style="margin-top:4px;color:var(--muted);">{{ $variation->reason }}</div>
                    <div style="margin-top:4px;color:var(--muted);">
                        @foreach ($variation->changes as $field => $delta)
                            <span>{{ \App\Projects\ProjectMaster::label($field) }}: {{ $delta['old'] ?? '—' }} → {{ $delta['new'] ?? '—' }}</span>@if (! $loop->last), @endif
                        @endforeach
                    </div>
                    @if ($variation->decision_note)
                        <div style="margin-top:4px;font-style:italic;color:var(--muted);">{{ $variation->decision_note }}</div>
                    @endif
                    @if ($variation->status === 'pending' && $canDecideVariation)
                        <div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;margin-top:8px;">
                            <form method="post" action="{{ route('projects.variations.approve', [$project, $variation]) }}">
                                @csrf
                                <button type="submit" class="uj-btn-primary" style="height:32px;padding:0 12px;font-size:12px;"><span x-text="$store.ui.lang==='en' ? 'Approve' : 'Luluskan'">Approve</span></button>
                            </form>
                            <form method="post" action="{{ route('projects.variations.reject', [$project, $variation]) }}" style="display:flex;gap:6px;align-items:flex-end;flex-wrap:wrap;">
                                @csrf
                                <input name="note" maxlength="500" placeholder="Reason (optional)" style="height:32px;padding:0 10px;border:1px solid var(--hairline);border-radius:7px;font-size:12px;outline:none;" />
                                <button type="submit" class="uj-btn-ghost" style="height:32px;padding:0 12px;font-size:12px;color:var(--error);"><span x-text="$store.ui.lang==='en' ? 'Reject' : 'Tolak'">Reject</span></button>
                            </form>
                        </div>
                    @endif
                </div>
            @empty
                <p style="font-size:12px;color:var(--muted);margin:0;"><span x-text="$store.ui.lang==='en' ? 'No variations yet.' : 'Tiada variasi lagi.'">No variations yet.</span></p>
            @endforelse

            @if ($canRaiseVariation && ! $project->isClosed())
                <form method="post" action="{{ route('projects.variations.store', $project) }}" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:10px;padding-top:8px;border-top:1px solid var(--hairline-soft);">
                    @csrf
                    <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;"><span x-text="$store.ui.lang==='en' ? 'Raise a variation' : 'Ajukan variasi'">Raise a variation</span></div>
                    <div style="display:flex;gap:10px;flex-wrap:wrap;">
                        <div style="width:130px;">
                            <label style="{{ $lbl ?? 'display:block;font-size:12px;font-weight:500;color:var(--ink);margin-bottom:5px;' }}"><span x-text="$store.ui.lang==='en' ? 'VO number' : 'No. VO'">VO number</span></label>
                            <input name="vo_no" required maxlength="40" placeholder="VO-01" style="width:100%;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:12.5px;outline:none;" />
                        </div>
                        <div style="width:150px;">
                            <label style="display:block;font-size:12px;font-weight:500;color:var(--ink);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Date' : 'Tarikh'">Date</span></label>
                            <input type="date" name="variation_date" required style="width:100%;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:12.5px;outline:none;" />
                        </div>
                        <div style="flex:1;min-width:200px;">
                            <label style="display:block;font-size:12px;font-weight:500;color:var(--ink);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Reason' : 'Sebab'">Reason</span></label>
                            <input name="reason" required maxlength="500" style="width:100%;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:12.5px;outline:none;" />
                        </div>
                    </div>
                    <div style="display:flex;gap:10px;flex-wrap:wrap;">
                        <div style="width:150px;">
                            <label style="display:block;font-size:12px;font-weight:500;color:var(--ink);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'New contract value' : 'Nilai kontrak baharu'">New contract value</span></label>
                            <input type="number" step="0.01" min="0" name="contract_value" style="width:100%;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:12.5px;outline:none;font-family:var(--font-mono);" />
                        </div>
                        <div style="width:150px;">
                            <label style="display:block;font-size:12px;font-weight:500;color:var(--ink);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'New contract start' : 'Mula kontrak baharu'">New contract start</span></label>
                            <input type="date" name="contract_start" style="width:100%;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:12.5px;outline:none;" />
                        </div>
                        <div style="width:150px;">
                            <label style="display:block;font-size:12px;font-weight:500;color:var(--ink);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'New contract end' : 'Tamat kontrak baharu'">New contract end</span></label>
                            <input type="date" name="contract_end" style="width:100%;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:12.5px;outline:none;" />
                        </div>
                        <div style="flex:1;min-width:160px;">
                            <label style="display:block;font-size:12px;font-weight:500;color:var(--ink);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'New client' : 'Pelanggan baharu'">New client</span></label>
                            <input name="client" maxlength="160" style="width:100%;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:12.5px;outline:none;" />
                        </div>
                        <div style="width:200px;">
                            <label style="display:block;font-size:12px;font-weight:500;color:var(--ink);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Attachment (optional)' : 'Lampiran (pilihan)'">Attachment (optional)</span></label>
                            <input type="file" name="attachment" accept=".pdf,.jpg,.jpeg,.png" style="width:100%;font-size:12px;" />
                        </div>
                    </div>
                    <div>
                        <button type="submit" class="uj-btn-primary" style="height:36px;padding:0 14px;font-size:12.5px;"><span x-text="$store.ui.lang==='en' ? 'Raise variation' : 'Ajukan variasi'">Raise variation</span></button>
                    </div>
                </form>
            @endif
        </div>
    @endif

    @if ($canRaiseBigDeal)
        <div x-show="bigDeal" x-cloak x-data="{ type: 'go_live' }" style="margin-top:12px;padding-top:12px;border-top:1px solid var(--hairline-soft);">
            <form method="post" action="{{ route('big-deals.store') }}" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:10px;">
                @csrf
                <input type="hidden" name="project_id" value="{{ $project->id }}" />
                <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;"><span x-text="$store.ui.lang==='en' ? 'Mark as Big Deal' : 'Tanda sebagai Big Deal'">Mark as Big Deal</span></div>
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <div style="width:190px;">
                        <label style="display:block;font-size:12px;font-weight:500;color:var(--ink);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Type' : 'Jenis'">Type</span></label>
                        <select name="type" x-model="type" style="width:100%;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:12.5px;outline:none;">
                            <option value="go_live">Project go-live</option>
                            <option value="tender_won">Tender won</option>
                            <option value="claim_received">Claim received</option>
                            <option value="uat_completed">UAT completed</option>
                            <option value="milestone">Major milestone</option>
                            <option value="client_compliment">Client compliment</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div style="width:180px;">
                        <label style="display:block;font-size:12px;font-weight:500;color:var(--ink);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Track ref (optional)' : 'Ruj. Track (pilihan)'">Track ref (optional)</span></label>
                        <input name="track_ref" maxlength="100" placeholder="MS-42" style="width:100%;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:12.5px;outline:none;" />
                    </div>
                    <div style="flex:1;min-width:220px;">
                        <label style="display:block;font-size:12px;font-weight:500;color:var(--ink);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Headline' : 'Tajuk'">Headline</span></label>
                        <input name="title" required maxlength="255" placeholder="iLPF just completed UAT" style="width:100%;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:12.5px;outline:none;" />
                    </div>
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:500;color:var(--ink);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'What it took' : 'Apa yang diperlukan'">What it took</span></label>
                    <textarea name="story" rows="3" maxlength="4000" placeholder="Everyone involved may now breathe again." style="width:100%;border:1px solid var(--hairline);border-radius:8px;padding:8px 10px;font-size:12.5px;outline:none;"></textarea>
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:500;color:var(--ink);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Team' : 'Pasukan'">Team</span></label>
                    <select name="team[]" multiple style="width:100%;min-height:76px;padding:6px 10px;border:1px solid var(--hairline);border-radius:8px;font-size:12.5px;outline:none;">
                        @foreach ($employees as $emp)
                            <option value="{{ $emp['id'] }}">{{ $emp['display_name'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
                    <div>
                        <label style="display:block;font-size:12px;font-weight:500;color:var(--ink);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Photos (up to 3)' : 'Foto (sehingga 3)'">Photos (up to 3)</span></label>
                        <input type="file" name="photos[]" accept="image/*" multiple style="font-size:12px;" />
                    </div>
                    <div x-show="type === 'client_compliment'">
                        <label style="display:block;font-size:12px;font-weight:500;color:var(--ink);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Source (email/letter)' : 'Sumber (emel/surat)'">Source (email/letter)</span></label>
                        <input type="file" name="source" accept=".pdf,.jpg,.jpeg,.png,.eml,.msg" style="font-size:12px;" />
                    </div>
                    <div x-show="type === 'client_compliment'" style="width:180px;">
                        <label style="display:block;font-size:12px;font-weight:500;color:var(--ink);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Client contact' : 'Kenalan klien'">Client contact</span></label>
                        <input name="client_contact" maxlength="255" placeholder="Puan Rahimah" style="width:100%;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:12.5px;outline:none;" />
                    </div>
                    <label x-show="type === 'client_compliment'" style="display:flex;align-items:center;gap:6px;font-size:12px;color:var(--ink);height:36px;">
                        <input type="checkbox" name="names_approved" value="1" />
                        <span x-text="$store.ui.lang==='en' ? 'Client name approved to show' : 'Nama klien diluluskan untuk dipapar'">Client name approved to show</span>
                    </label>
                </div>
                <p style="font-size:11.5px;color:var(--muted);margin:0;"><span x-text="$store.ui.lang==='en' ? 'Shows on every dashboard for 3 days, then moves to the Wins page.' : 'Dipaparkan di setiap papan pemuka selama 3 hari, kemudian berpindah ke halaman Kejayaan.'">Shows on every dashboard for 3 days, then moves to the Wins page.</span></p>
                <div>
                    <button type="submit" class="uj-btn-primary" style="height:36px;padding:0 14px;font-size:12.5px;"><span x-text="$store.ui.lang==='en' ? 'Raise Big Deal' : 'Ajukan Big Deal'">Raise Big Deal</span></button>
                </div>
            </form>
        </div>
    @endif

    @if ($canEdit)
        <div x-show="edit" x-cloak style="margin-top:14px;padding-top:14px;border-top:1px solid var(--hairline-soft);">
            @include('partials.ts-project-form', ['project' => $project, 'action' => route('projects.update', $project), 'categories' => $categories, 'employees' => $employees, 'editableFields' => $editableFields])
        </div>
    @endif
</div>
