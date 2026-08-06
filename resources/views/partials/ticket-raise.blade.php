{{--
    Ticket-raise modal — report a bug, idea, or support request.
    Included on every screen from layouts/app.blade.php, so it reads the category and
    priority lists from HelpdeskController's public constants rather than from screen data.
    Opened by the sidebar's pinned "Raise a ticket" button ($dispatch('ticket-raise-open',
    { category: 'Bug' })) and by the Helpdesk screen's "+ New ticket" button (no detail,
    so it defaults to IT).
    Reopens itself after a failed submit — but only for ITS OWN failed submit, guarded
    by the hidden _ticket_raise marker, not by any overlapping field name in $errors.
    Renders nothing (and so cannot be opened) when module.helpdesk is off for the
    tenant — $helpdeskEnabled is shared by the same view composer as partials.sidebar.
--}}
@php
    // Only reopen for OUR OWN failed submit — never because some other screen's form
    // happens to validate a field with an overlapping name (category, subject, ...).
    // The hidden _ticket_raise marker below round-trips through old() only when this
    // very form was the one that failed and redirected back.
    $ticketHasError = old('_ticket_raise') && $errors->any();
    $categories = \App\Http\Controllers\HelpdeskController::CATEGORIES;
    $priorities = \App\Http\Controllers\HelpdeskController::PRIORITIES;
@endphp
@if ($helpdeskEnabled ?? true)
<div x-data="{ show: {{ $ticketHasError ? 'true' : 'false' }}, category: '{{ old('category', 'IT') }}' }"
     x-show="show" x-cloak
     @ticket-raise-open.window="show = true; category = $event.detail?.category || 'IT'; $nextTick(() => { document.getElementById('tr-page-url').value = window.location.href; $refs.subject?.focus(); })"
     @keydown.escape.window="show = false"
     class="uj-dialog-overlay"
     style="position:fixed;inset:0;z-index:200;padding:20px;background:rgba(31,30,26,.55);backdrop-filter:blur(2px);">

    <div @click.outside="show = false" class="uj-slide"
         style="width:100%;max-width:480px;margin:auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 24px 70px rgba(31,30,26,.30);display:flex;flex-direction:column;max-height:88vh;">

        {{-- Header --}}
        <div style="padding:20px 26px;border-bottom:1px solid var(--hairline);flex-shrink:0;display:flex;align-items:flex-start;justify-content:space-between;gap:12px;">
            <h2 style="font-size:18px;font-weight:600;color:var(--ink);margin:0;letter-spacing:-0.3px;"
                x-text="$store.ui.lang==='en' ? 'Raise a ticket' : 'Buka ticket'"></h2>
            <button type="button" @click="show = false" aria-label="Close"
                    style="width:30px;height:30px;border-radius:8px;flex-shrink:0;color:var(--muted);background:var(--canvas);font-size:17px;line-height:1;">×</button>
        </div>

        <form action="{{ route('helpdesk.store') }}" method="post" enctype="multipart/form-data" x-data="ticketAttach()" style="display:flex;flex-direction:column;min-height:0;">
            @csrf
            <input type="hidden" name="_ticket_raise" value="1">
            <input type="hidden" id="tr-page-url" name="page_url" value="{{ old('page_url') }}">

            <div style="padding:20px 26px;display:flex;flex-direction:column;gap:16px;overflow-y:auto;max-height:calc(88vh - 180px);">
                <p style="font-size:13px;color:var(--muted);margin:0;line-height:1.5;"
                   x-show="category === 'Bug' || category === 'Idea'"
                   x-text="$store.ui.lang==='en' ? 'Spotted a bug or have an idea? Tell us — it goes straight to the team.' : 'Jumpa pepijat atau ada idea? Beritahu kami — terus sampai kepada pasukan.'"></p>

                <div>
                    <label style="display:block;font-size:12.5px;font-weight:600;color:var(--ink);margin-bottom:7px;"
                           x-text="$store.ui.lang==='en' ? 'Category' : 'Kategori'">Category</label>
                    <select name="category" x-model="category" required class="uj-lv-in">
                        @foreach ($categories as $c)
                            <option value="{{ $c }}">{{ $c }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Priority is a support-desk triage field. Bug/Idea always land as medium
                     server-side (HelpdeskController::store), so the submitter never sees it. --}}
                <div x-show="category !== 'Bug' && category !== 'Idea'">
                    <label style="display:block;font-size:12.5px;font-weight:600;color:var(--ink);margin-bottom:7px;"
                           x-text="$store.ui.lang==='en' ? 'Priority' : 'Keutamaan'">Priority</label>
                    <select name="priority" :required="category !== 'Bug' && category !== 'Idea'" class="uj-lv-in">
                        @foreach ($priorities as $p)
                            <option value="{{ $p }}" @selected(old('priority', 'medium') === $p)>{{ ucfirst($p) }}</option>
                        @endforeach
                    </select>
                    @include('partials.hint', ['en' => 'Urgent = work is fully blocked right now. High = serious but you can still work. Use Low/Medium for everyday requests.', 'ms' => 'Urgent = kerja terhenti sepenuhnya sekarang. High = serius tetapi anda masih boleh bekerja. Guna Low/Medium untuk permintaan harian.'])
                </div>

                <div>
                    <label style="display:block;font-size:12.5px;font-weight:600;color:var(--ink);margin-bottom:7px;"
                           x-text="$store.ui.lang==='en' ? 'Subject' : 'Subjek'">Subject</label>
                    <input x-ref="subject" name="subject" value="{{ old('subject') }}" required maxlength="150" class="uj-lv-in">
                    @error('subject')<p style="font-size:12px;color:var(--error);margin:7px 0 0;">{{ $message }}</p>@enderror
                </div>

                {{-- Description + attachments share one Alpine scope (the form's ticketAttach())
                     so a paste inside a textarea can hand image blobs to the attachment manager.
                     Bug/Idea trade the single free-text box for named fields (GitHub-issue-style),
                     joined server-side into one JSON description — see HelpdeskController::store(). --}}
                <div x-show="category !== 'Bug' && category !== 'Idea'">
                    <label style="display:block;font-size:12.5px;font-weight:600;color:var(--ink);margin-bottom:7px;"
                           x-text="$store.ui.lang==='en' ? 'Description' : 'Penerangan'">Description</label>
                    <textarea name="description" :required="category !== 'Bug' && category !== 'Idea'" @paste="onPaste($event)" maxlength="2000" rows="4" class="uj-lv-in">{{ old('description') }}</textarea>
                    @error('description')<p style="font-size:12px;color:var(--error);margin:7px 0 0;">{{ $message }}</p>@enderror
                </div>

                <div x-show="category === 'Bug'" x-cloak style="display:flex;flex-direction:column;gap:16px;">
                    @foreach (\App\Models\Ticket::BUG_DESCRIPTION_FIELDS as $key => $label)
                        <div>
                            <label style="display:block;font-size:12.5px;font-weight:600;color:var(--ink);margin-bottom:7px;"
                                   x-text="($store.ui.lang==='en' ? @js($label['en']) : @js($label['ms'])) + '{{ $key === 'additional_context' ? '' : ' *' }}'">{{ $label['en'] }}</label>
                            <textarea name="{{ $key }}" :required="category === 'Bug' && {{ $key === 'additional_context' ? 'false' : 'true' }}" @paste="onPaste($event)" maxlength="2000" rows="3"
                                      class="uj-lv-in">{{ old($key) }}</textarea>
                            @error($key)<p style="font-size:12px;color:var(--error);margin:7px 0 0;">{{ $message }}</p>@enderror
                        </div>
                    @endforeach
                </div>

                <div x-show="category === 'Idea'" x-cloak style="display:flex;flex-direction:column;gap:16px;">
                    @foreach (\App\Models\Ticket::IDEA_DESCRIPTION_FIELDS as $key => $label)
                        <div>
                            <label style="display:block;font-size:12.5px;font-weight:600;color:var(--ink);margin-bottom:7px;"
                                   x-text="($store.ui.lang==='en' ? @js($label['en']) : @js($label['ms'])) + '{{ $key === 'additional_context' ? '' : ' *' }}'">{{ $label['en'] }}</label>
                            <textarea name="{{ $key }}" :required="category === 'Idea' && {{ $key === 'additional_context' ? 'false' : 'true' }}" @paste="onPaste($event)" maxlength="2000" rows="3"
                                      class="uj-lv-in">{{ old($key) }}</textarea>
                            @error($key)<p style="font-size:12px;color:var(--error);margin:7px 0 0;">{{ $message }}</p>@enderror
                        </div>
                    @endforeach
                </div>

                <div x-show="category === 'Bug' || category === 'Idea'" x-cloak>
                    <input type="file" name="attachments[]" x-ref="input" multiple
                           accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.txt,.csv"
                           @change="addFiles($event.target.files)" style="display:none;">
                    <button type="button" @click="$refs.input.click()"
                            style="height:38px;padding:0 14px;border-radius:9px;font-size:12.5px;font-weight:500;color:var(--body);background:#fff;border:1px solid var(--hairline);"
                            x-text="$store.ui.lang==='en' ? 'Attach screenshot / file' : 'Lampirkan tangkapan skrin / fail'">Attach screenshot / file</button>
                    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:10px;">
                        <template x-for="(f, i) in files" :key="i">
                            <div style="position:relative;width:56px;height:56px;border-radius:8px;overflow:hidden;border:1px solid var(--hairline-soft);">
                                <img x-show="f.isImage" :src="f.url" style="width:100%;height:100%;object-fit:cover;">
                                <div x-show="!f.isImage" style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:var(--muted);" x-text="ext(f.file.name)"></div>
                                <button type="button" @click="remove(i)" style="position:absolute;top:2px;right:2px;background:rgba(0,0,0,.55);color:#fff;border:none;border-radius:50%;width:16px;height:16px;font-size:11px;line-height:1;cursor:pointer;">&times;</button>
                            </div>
                        </template>
                    </div>
                    <p x-show="error === 'type'" x-cloak style="font-size:12px;color:var(--error);margin:7px 0 0;" x-text="$store.ui.lang==='en' ? 'That file type is not accepted.' : 'Jenis fail itu tidak diterima.'"></p>
                    <p x-show="error === 'size'" x-cloak style="font-size:12px;color:var(--error);margin:7px 0 0;" x-text="$store.ui.lang==='en' ? 'Each file must be 8 MB or smaller.' : 'Setiap fail mesti 8 MB atau lebih kecil.'"></p>
                    <p x-show="error === 'max'" x-cloak style="font-size:12px;color:var(--error);margin:7px 0 0;" x-text="$store.ui.lang==='en' ? 'You can attach up to 6 files.' : 'Anda boleh lampirkan sehingga 6 fail.'"></p>
                    @error('attachments')<p style="font-size:12px;color:var(--error);margin:7px 0 0;">{{ $message }}</p>@enderror
                    @foreach ($errors->get('attachments.*') as $messages)@foreach ($messages as $message)<p style="font-size:12px;color:var(--error);margin:7px 0 0;">{{ $message }}</p>@endforeach @endforeach
                </div>
            </div>

            <div style="padding:15px 26px 20px;border-top:1px solid var(--hairline);display:flex;align-items:center;justify-content:flex-end;gap:12px;flex-shrink:0;">
                <button type="button" @click="show = false"
                        style="height:42px;padding:0 16px;border-radius:9px;font-size:13.5px;font-weight:500;color:var(--body);background:#fff;border:1px solid var(--hairline);"
                        x-text="$store.ui.lang==='en' ? 'Cancel' : 'Batal'"></button>
                <button type="submit" class="uj-btn-primary" style="height:42px;padding:0 22px;font-size:13.5px;"
                        x-text="$store.ui.lang==='en' ? 'Submit ticket' : 'Hantar ticket'"></button>
            </div>
        </form>
    </div>
</div>
@endif
