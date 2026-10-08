@extends('layouts.app')

@section('screen')
@include('partials.guide', [
    'key' => 'settings',
    'en'  => [
        'title' => 'Workspace settings',
        'body'  => 'Admin settings for the whole company — the workspace name, subscription plan, and the list of branches and departments. Changes here affect every member, so update them carefully.',
    ],
    'ms'  => [
        'title' => 'Tetapan workspace',
        'body'  => 'Tetapan admin untuk seluruh syarikat — nama workspace, pelan langganan, serta senarai cawangan dan jabatan. Perubahan di sini memberi kesan kepada setiap ahli, jadi kemas kini dengan berhati-hati.',
    ],
])
@php
    $only = request('section');
    // A payroll run can't be created while any of these three is blank (same rule the
    // readiness bar inside the Statutory card applies live as you type).
    $statutoryReady = filled($company->employer_tin) && filled($company->epf_employer_no) && filled($company->socso_employer_code);
    // Section index: [anchor id, EN, BM], grouped. Admin-only cards drop out for everyone else.
    $manage = ! empty($canManageFeatures);
    $setIndex = array_values(array_filter([
        ['en' => 'Company', 'ms' => 'Syarikat', 'items' => [['profile', 'Workspace profile', 'Profil workspace'], ['statutory', 'Statutory & tax', 'Berkanun & cukai']]],
        ['en' => 'Working time', 'ms' => 'Masa bekerja', 'items' => array_values(array_filter([
            $manage ? ['work-week', 'Work week', 'Minggu bekerja'] : null,
            $manage ? ['approvals', 'Approval shortcut', 'Pintasan kelulusan'] : null,
        ]))],
        ['en' => 'Organisation', 'ms' => 'Organisasi', 'items' => [['branches', 'Branches', 'Cawangan'], ['departments', 'Departments', 'Jabatan'], ['staff-levels', 'Staff levels', 'Tahap staf'], ['employment-types', 'Employment types', 'Jenis pekerjaan']]],
        ['en' => 'Modules', 'ms' => 'Modul', 'items' => $manage ? [['features', 'Features', 'Ciri']] : []],
        ['en' => 'Culture', 'ms' => 'Budaya', 'items' => array_values(array_filter([
            ['greetings', 'Greetings', 'Ucapan'], ['eggs', 'Easter eggs', 'Telur Paskah'],
            $manage ? ['reactions', 'Reactions', 'Reaksi'] : null,
        ]))],
    ], fn ($g) => $g['items'] !== []));
    $editIcon = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20h4L19 9l-4-4L4 16v4Z"/><path d="m13.5 6.5 4 4"/></svg>';
    $deleteIcon = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/></svg>';
    $lockIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>';
@endphp
<div class="uj-settings {{ $only ? '' : 'uj-cs' }}">
    @unless ($only)
    {{-- Section index: sticky beside the cards, a scrolling chip bar on narrower panes.
         Highlights the card in view; the amber dot means payroll is blocked. --}}
    <nav class="uj-cs-idx" :aria-label="$store.ui.lang==='en' ? 'Settings sections' : 'Bahagian tetapan'"
         x-data="{
            active: 'profile', io: null, root: null, atEnd: null,
            init() {
                this.root = document.querySelector('main.uj-main');
                this.io = new IntersectionObserver((es) => { es.forEach((e) => { if (e.isIntersecting) this.active = e.target.id }); this.atEnd() },
                    { root: this.root, rootMargin: '-15% 0px -70% 0px' });
                this.$nextTick(() => document.querySelectorAll('.uj-cs-sec[id]').forEach((s) => this.io.observe(s)));
                // The last cards are too short to reach the trigger line, so the bottom of the page picks the last link.
                this.atEnd = () => { const r = this.root; if (r.scrollTop + r.clientHeight >= r.scrollHeight - 4) { this.active = [...this.$el.querySelectorAll('a')].at(-1).hash.slice(1) } };
                this.root?.addEventListener('scroll', this.atEnd, { passive: true });
                // Chip-bar mode: keep the current chip in view as the page scrolls.
                this.$watch('active', (id) => {
                    const a = this.$el.querySelector('a[href=\'#' + id + '\']');
                    if (a && this.$el.scrollWidth > this.$el.clientWidth) { this.$el.scrollTo({ left: a.offsetLeft - 28, behavior: 'smooth' }) }
                });
            },
            destroy() { this.io?.disconnect(); this.root?.removeEventListener('scroll', this.atEnd) },
         }">
        @foreach ($setIndex as $group)
            <div class="uj-cs-idx-g">
                <div class="uj-cs-idx-h" x-text="$store.ui.lang==='en' ? @js($group['en']) : @js($group['ms'])">{{ $group['en'] }}</div>
                @foreach ($group['items'] as [$anchor, $labelEn, $labelMs])
                    <a href="#{{ $anchor }}" :aria-current="active === '{{ $anchor }}' ? 'true' : null" @click="active = '{{ $anchor }}'">
                        <span x-text="$store.ui.lang==='en' ? @js($labelEn) : @js($labelMs)">{{ $labelEn }}</span>
                        @if ($anchor === 'statutory' && ! $statutoryReady)<i class="uj-cs-dot" :title="$store.ui.lang==='en' ? 'Payroll is blocked' : 'Gaji disekat'"></i>@endif
                    </a>
                @endforeach
            </div>
        @endforeach
    </nav>
    @endunless

    <div class="uj-cs-col">
    @if (! $only || $only === 'profile')
    {{-- Workspace profile. Grouped as Company / Login page / Contact / Payroll journal; the
         login-page fields sit beside a live preview of what they change. --}}
    @php
        $journalSet = count(array_filter((array) ($company->journal_accounts ?? []), 'filled'));
        $journalTotal = count(\App\Services\Payroll\AccountingJournal::LINES);
        $journalOpen = $errors->has('journal_accounts') || $errors->has('journal_accounts.*');
    @endphp
    <section id="profile" class="uj-card uj-cs-sec"
             x-data="{
                name: @js(old('name', $company->name) ?? ''),
                c1: @js(old('color', $company->color) ?? ''),
                c2: @js(old('secondary_color', $company->secondary_color) ?? ''),
                msg: @js(old('welcome_message', $company->welcome_message) ?? ''),
                logo: @js($company->logo_path ? '/storage/'.$company->logo_path : null),
                dirty: false, journalOpen: @js($journalOpen),
                hex(v, fallback) { return /^#[0-9a-f]{6}$/i.test(v || '') ? v : fallback },
                initials() { return (this.name || '?').trim().split(/\s+/).slice(0, 2).map((w) => w[0]).join('').toUpperCase() },
                pick(e) { const f = e.target.files[0]; if (f) { this.logo = URL.createObjectURL(f) } },
             }">
        <div class="uj-cs-ch">
            <div>
                <h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Workspace profile' : 'Profil workspace'">Workspace profile</h3>
                <p class="uj-cs-sub" x-text="$store.ui.lang==='en' ? 'How your company appears inside the app, on documents and on your login page.' : 'Cara syarikat anda dipaparkan dalam aplikasi, pada dokumen dan pada halaman log masuk.'">How your company appears inside the app, on documents and on your login page.</p>
            </div>
        </div>
        {{-- Plan, category, workspace ID and members are set by the platform team
             (super-admin) and are read-only here — shown for reference only. --}}
        <dl class="uj-cs-facts">
            <div><dt x-text="$store.ui.lang==='en' ? 'Category' : 'Kategori'">Category</dt><dd>{{ $company->companyCategory?->name ?? '—' }}</dd></div>
            <div><dt x-text="$store.ui.lang==='en' ? 'Plan' : 'Pelan'">Plan</dt><dd>{{ $company->plan }}</dd></div>
            <div><dt x-text="$store.ui.lang==='en' ? 'Workspace ID' : 'ID workspace'">Workspace ID</dt><dd class="uj-ww-mono">{{ $company->slug }}</dd></div>
            <div><dt x-text="$store.ui.lang==='en' ? 'Members' : 'Ahli'">Members</dt><dd class="uj-ww-mono">{{ $company->users()->count() }}</dd></div>
        </dl>
        <p class="uj-cs-facts-note">{!! $lockIcon !!}<span x-text="$store.ui.lang==='en' ? 'Set by the platform team. Ask them to change your plan or category.' : 'Ditetapkan oleh pasukan platform. Minta mereka untuk menukar pelan atau kategori.'">Set by the platform team. Ask them to change your plan or category.</span></p>

        <form method="post" action="{{ route('admin.settings.update') }}" enctype="multipart/form-data" @input="dirty = true" @change="dirty = true">
            @csrf
            <div class="uj-cs-cb">
                @if ($errors->any() && ! $errors->hasAny(['work_days', 'work_days.*', 'approval_escalation_days', 'weekday', 'weeks', 'weeks.*', 'start_time', 'end_time', 'counts']))<div class="uj-alert" data-tone="error"><span class="uj-alert-msg">{{ $errors->first() }}</span></div>@endif

                <div class="uj-cs-grp">
                    <div class="uj-cs-grp-h"><h4 x-text="$store.ui.lang==='en' ? 'Company' : 'Syarikat'">Company</h4></div>
                    <div class="uj-cs-fg">
                        <div>
                            <label class="uj-cs-lab" for="set-name" x-text="$store.ui.lang==='en' ? 'Company name' : 'Nama syarikat'">Company name</label>
                            <input id="set-name" name="name" x-model="name" value="{{ old('name', $company->name) }}" required class="uj-cs-inp" />
                            <p class="uj-cs-hint" x-text="$store.ui.lang==='en' ? 'Shown to everyone in the app and on documents.' : 'Dipaparkan kepada semua orang dalam aplikasi dan pada dokumen.'">Shown to everyone in the app and on documents.</p>
                        </div>
                        <div>
                            <label class="uj-cs-lab" for="set-industry"><span x-text="$store.ui.lang==='en' ? 'Industry' : 'Industri'">Industry</span> <span class="uj-cs-opt" x-text="$store.ui.lang==='en' ? 'Optional' : 'Pilihan'">Optional</span></label>
                            <input id="set-industry" name="industry" value="{{ old('industry', $company->industry) }}" class="uj-cs-inp" />
                        </div>
                    </div>
                </div>

                <div class="uj-cs-grp">
                    <div class="uj-cs-grp-h"><h4 x-text="$store.ui.lang==='en' ? 'Login page' : 'Halaman log masuk'">Login page</h4><span x-text="$store.ui.lang==='en' ? 'What your staff see before they sign in' : 'Apa yang staf lihat sebelum log masuk'">What your staff see before they sign in</span></div>
                    <div class="uj-cs-brand">
                        <div class="uj-cs-fg">
                            <div class="uj-cs-full">
                                <span class="uj-cs-lab" x-text="$store.ui.lang==='en' ? 'Company logo' : 'Logo syarikat'">Company logo</span>
                                <div class="uj-cs-logo">
                                    <div class="uj-cs-logo-box" :class="{ 'has': logo }">
                                        <template x-if="logo"><img :src="logo" :alt="$store.ui.lang==='en' ? 'Current company logo' : 'Logo syarikat semasa'" x-on:error="logo = null"></template>
                                        <template x-if="! logo"><span class="uj-cs-mark" :style="'background:' + hex(c1, '#d6232b')" x-text="initials()"></span></template>
                                    </div>
                                    <div class="uj-cs-logo-meta">
                                        <label class="uj-btn-ghost uj-cs-upload">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 16V4M6 10l6-6 6 6M4 20h16"/></svg>
                                            <span x-text="logo ? ($store.ui.lang==='en' ? 'Replace logo' : 'Tukar logo') : ($store.ui.lang==='en' ? 'Upload logo' : 'Muat naik logo')">Upload logo</span>
                                            <input type="file" name="logo" accept="image/png,image/jpeg" class="uj-sr-only" @change="pick($event)" />
                                        </label>
                                        <p class="uj-cs-hint" x-text="$store.ui.lang==='en' ? 'PNG or JPG, up to 2 MB. Square works best.' : 'PNG atau JPG, sehingga 2 MB. Bentuk segi empat sama paling sesuai.'">PNG or JPG, up to 2 MB. Square works best.</p>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <label class="uj-cs-lab" for="set-color" x-text="$store.ui.lang==='en' ? 'Brand colour' : 'Warna jenama'">Brand colour</label>
                                <div class="uj-cs-clr">
                                    <input type="color" :value="hex(c1, '#d6232b')" @input="c1 = $event.target.value" :aria-label="$store.ui.lang==='en' ? 'Pick brand colour' : 'Pilih warna jenama'" />
                                    <input id="set-color" name="color" x-model="c1" value="{{ old('color', $company->color) }}" placeholder="#d6232b" maxlength="7" spellcheck="false" />
                                </div>
                            </div>
                            <div>
                                <label class="uj-cs-lab" for="set-color2" x-text="$store.ui.lang==='en' ? 'Secondary colour' : 'Warna sekunder'">Secondary colour</label>
                                <div class="uj-cs-clr">
                                    <input type="color" :value="hex(c2, '#1f1e1a')" @input="c2 = $event.target.value" :aria-label="$store.ui.lang==='en' ? 'Pick secondary colour' : 'Pilih warna sekunder'" />
                                    <input id="set-color2" name="secondary_color" x-model="c2" value="{{ old('secondary_color', $company->secondary_color) }}" placeholder="#1f1e1a" maxlength="7" spellcheck="false" />
                                </div>
                            </div>
                            <div class="uj-cs-full">
                                <label class="uj-cs-lab" for="set-welcome"><span x-text="$store.ui.lang==='en' ? 'Welcome message' : 'Mesej alu-aluan'">Welcome message</span> <span class="uj-cs-opt" x-text="$store.ui.lang==='en' ? 'Optional' : 'Pilihan'">Optional</span></label>
                                <input id="set-welcome" name="welcome_message" x-model="msg" value="{{ old('welcome_message', $company->welcome_message) }}" :placeholder="$store.ui.lang==='en' ? 'A line to greet your team' : 'Satu baris untuk menyambut pasukan anda'" class="uj-cs-inp" />
                            </div>
                        </div>

                        <div class="uj-cs-pv" aria-hidden="true">
                            <div class="uj-cs-pv-cap"><span x-text="$store.ui.lang==='en' ? 'Preview' : 'Pratonton'">Preview</span></div>
                            <div class="uj-cs-pv-stage">
                                <div class="uj-cs-pv-band" :style="'background:' + hex(c2, '#1f1e1a')">
                                    <span class="uj-cs-pv-mk" :style="'color:' + hex(c1, '#d6232b')">
                                        <template x-if="logo"><img :src="logo" alt=""></template>
                                        <template x-if="! logo"><span x-text="initials()"></span></template>
                                    </span>
                                    <b x-text="name || ($store.ui.lang==='en' ? 'Company name' : 'Nama syarikat')"></b>
                                </div>
                                <p class="uj-cs-pv-msg" :class="{ 'empty': ! msg }" x-text="msg || ($store.ui.lang==='en' ? 'No welcome message. The login page shows just the form.' : 'Tiada mesej alu-aluan. Halaman log masuk hanya memaparkan borang.')"></p>
                                <i class="uj-cs-pv-f"></i><i class="uj-cs-pv-f"></i>
                                <i class="uj-cs-pv-b" :style="'background:' + hex(c1, '#d6232b')"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="uj-cs-grp">
                    <div class="uj-cs-grp-h"><h4 x-text="$store.ui.lang==='en' ? 'Contact' : 'Hubungan'">Contact</h4><span x-text="$store.ui.lang==='en' ? 'Address and phone are also printed on CP21, CP22 and PCB II' : 'Alamat dan telefon juga dicetak pada CP21, CP22 dan PCB II'">Address and phone are also printed on CP21, CP22 and PCB II</span></div>
                    <div class="uj-cs-fg">
                        <div>
                            <label class="uj-cs-lab" for="set-phone" x-text="$store.ui.lang==='en' ? 'Contact number' : 'Nombor telefon'">Contact number</label>
                            <input id="set-phone" type="tel" name="contact_number" value="{{ old('contact_number', $company->contact_number) }}" class="uj-cs-inp" />
                        </div>
                        <div>
                            <label class="uj-cs-lab" for="set-email" x-text="$store.ui.lang==='en' ? 'Email' : 'Emel'">Email</label>
                            <input id="set-email" type="email" name="email" value="{{ old('email', $company->email) }}" class="uj-cs-inp" />
                        </div>
                        <div class="uj-cs-full">
                            <label class="uj-cs-lab" for="set-address" x-text="$store.ui.lang==='en' ? 'Address' : 'Alamat'">Address</label>
                            <input id="set-address" name="address" value="{{ old('address', $company->address) }}" class="uj-cs-inp" />
                        </div>
                        <div class="uj-cs-full">
                            <label class="uj-cs-lab" for="set-website"><span x-text="$store.ui.lang==='en' ? 'Website' : 'Laman web'">Website</span> <span class="uj-cs-opt" x-text="$store.ui.lang==='en' ? 'Optional' : 'Pilihan'">Optional</span></label>
                            <input id="set-website" name="website" value="{{ old('website', $company->website) }}" placeholder="https://" class="uj-cs-inp" />
                        </div>
                    </div>
                </div>

                <div class="uj-cs-grp">
                    <div class="uj-cs-disc" :class="{ 'open': journalOpen }">
                        <button type="button" class="uj-cs-disc-btn" @click="journalOpen = ! journalOpen" :aria-expanded="journalOpen ? 'true' : 'false'" aria-controls="set-journal">
                            <span class="t">
                                <b x-text="$store.ui.lang==='en' ? 'Payroll journal account codes' : 'Kod akaun jurnal gaji'">Payroll journal account codes</b>
                                <span x-text="$store.ui.lang==='en' ? 'Codes from your accounting software for the journal CSV' : 'Kod daripada perisian perakaunan anda untuk CSV jurnal'">Codes from your accounting software for the journal CSV</span>
                            </span>
                            <span class="uj-stamp" x-text="$store.ui.lang==='en' ? @js($journalSet.' of '.$journalTotal.' set') : @js($journalSet.' daripada '.$journalTotal.' diisi')">{{ $journalSet }} of {{ $journalTotal }} set</span>
                            <svg class="chev" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
                        </button>
                        <div class="uj-cs-disc-p" id="set-journal"><div>
                            {{-- Laid out like the journal itself: what is charged on the left, what is owed on the right. --}}
                            <div class="uj-cs-jr">
                                @foreach (['debit' => ['Debit', 'Debit'], 'credit' => ['Credit', 'Kredit']] as $journalSide => [$sideEn, $sideMs])
                                    <div>
                                        <h5 x-text="$store.ui.lang==='en' ? '{{ $sideEn }}' : '{{ $sideMs }}'">{{ $sideEn }}</h5>
                                        @foreach (\App\Services\Payroll\AccountingJournal::LINES as $key => [$lineName, $side])
                                            @continue($side !== $journalSide)
                                            <label>
                                                <span>{{ $lineName }}</span>
                                                <input name="journal_accounts[{{ $key }}]" value="{{ old('journal_accounts.'.$key, $company->journal_accounts[$key] ?? '') }}" maxlength="40" placeholder="—" class="uj-cs-inp uj-ww-mono" />
                                            </label>
                                        @endforeach
                                    </div>
                                @endforeach
                            </div>
                            <p class="uj-cs-hint uj-cs-jr-hint" x-text="$store.ui.lang==='en' ? 'Left blank, the journal CSV leaves that code empty.' : 'Jika kosong, CSV jurnal membiarkan kod itu kosong.'">Left blank, the journal CSV leaves that code empty.</p>
                        </div></div>
                    </div>
                </div>
            </div>

            <div class="uj-cs-cf">
                <span class="uj-cs-state"><i x-show="dirty" x-cloak></i><span x-text="dirty ? ($store.ui.lang==='en' ? 'Unsaved changes' : 'Perubahan belum disimpan') : ''"></span></span>
                <button type="submit" class="uj-btn-primary uj-cs-save"><span x-text="$store.ui.lang==='en' ? 'Save changes' : 'Simpan perubahan'">Save changes</span></button>
            </div>
        </form>
    </section>
    @endif

    @if (! $only || $only === 'statutory')
    {{-- Statutory & tax: every KWSP, PERKESO, LHDN, HRD Corp and zakat file is keyed on
         these numbers. Saved on its own so a profile save can't blank them. Shapes are
         only warned about: older registrations don't all follow today's format. --}}
    @php $sb = $errors->statutory; @endphp
    <section id="statutory" class="uj-card uj-cs-sec"
         x-data="{
            tin: @js(old('employer_tin', $company->employer_tin) ?? ''),
            epf: @js(old('epf_employer_no', $company->epf_employer_no) ?? ''),
            socso: @js(old('socso_employer_code', $company->socso_employer_code) ?? ''),
            dirty: false,
            clean(v) { return (v || '').replace(/\s+/g, '').toUpperCase(); },
            missing() {
                const en = this.$store.ui.lang === 'en', m = [];
                if (! this.clean(this.tin)) { m.push(en ? 'the E number' : 'nombor E') }
                if (! this.clean(this.epf)) { m.push(en ? 'the KWSP number' : 'nombor KWSP') }
                if (! this.clean(this.socso)) { m.push(en ? 'the PERKESO code' : 'kod PERKESO') }
                return m;
            },
            readyText() {
                const en = this.$store.ui.lang === 'en', m = this.missing();
                if (! m.length) { return en ? 'Ready for payroll. All three required numbers are filled in.' : 'Sedia untuk gaji. Ketiga-tiga nombor wajib sudah diisi.' }
                const list = m.length > 1 ? m.slice(0, -1).join(', ') + (en ? ' and ' : ' dan ') + m.at(-1) : m[0];
                return en ? 'Payroll runs are blocked until ' + list + (m.length > 1 ? ' are' : ' is') + ' filled in.' : 'Run gaji disekat sehingga ' + list + ' diisi.';
            },
         }">
        <div class="uj-cs-ch">
            <div>
                <h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Statutory & tax' : 'Berkanun & cukai'">Statutory &amp; tax</h3>
                <p class="uj-cs-sub" x-text="$store.ui.lang==='en' ? 'Every KWSP, PERKESO, LHDN, HRD Corp and zakat file carries these numbers. Saved on its own, so a profile save never blanks them.' : 'Setiap fail KWSP, PERKESO, LHDN, HRD Corp dan zakat membawa nombor ini. Disimpan berasingan, jadi simpanan profil tidak mengosongkannya.'">Every KWSP, PERKESO, LHDN, HRD Corp and zakat file carries these numbers.</p>
            </div>
        </div>
        <div class="uj-cs-ready" :data-ok="missing().length === 0 ? 'true' : 'false'" data-ok="{{ $statutoryReady ? 'true' : 'false' }}" role="status">
            <svg x-show="missing().length === 0" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--success)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/></svg>
            <svg x-show="missing().length > 0" x-cloak width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--amber)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3 2 20h20L12 3Z"/><path d="M12 10v4M12 17h.01"/></svg>
            <span class="msg" x-text="readyText()"></span>
            <span class="chips">
                <span class="uj-stamp" :data-tone="clean(tin) ? 'success' : 'amber'" x-text="$store.ui.lang==='en' ? 'E number' : 'Nombor E'">E number</span>
                <span class="uj-stamp" :data-tone="clean(epf) ? 'success' : 'amber'">KWSP</span>
                <span class="uj-stamp" :data-tone="clean(socso) ? 'success' : 'amber'">PERKESO</span>
            </span>
        </div>
        <form method="post" action="{{ route('admin.settings.statutory') }}" @input="dirty = true" @change="dirty = true">
            @csrf
            <div class="uj-cs-cb">
                @if ($sb->any())<div class="uj-alert" data-tone="error"><span class="uj-alert-msg">{{ $sb->first() }}</span></div>@endif

                <div class="uj-cs-agency">
                    <div><h4>LHDN</h4><p x-text="$store.ui.lang==='en' ? 'Form E, CP21, CP22, PCB' : 'Borang E, CP21, CP22, PCB'">Form E, CP21, CP22, PCB</p></div>
                    <div class="uj-cs-fg">
                        <div class="uj-cs-full">
                            <label class="uj-cs-lab" for="set-tin"><span x-text="$store.ui.lang==='en' ? 'Employer number (E)' : 'Nombor majikan (E)'">Employer number (E)</span> <span class="uj-cs-req" x-text="$store.ui.lang==='en' ? 'Required' : 'Wajib'">Required</span></label>
                            <div class="uj-cs-pre">
                                <span>E</span>
                                <input id="set-tin" name="employer_tin" x-model="tin" maxlength="20" class="uj-cs-inp uj-ww-mono" />
                            </div>
                            <p x-show="clean(tin) && !/^E?\d{10}$/.test(clean(tin))" x-cloak class="uj-cs-warn" x-text="$store.ui.lang==='en' ? 'An E number is usually 10 digits. Check it against your LHDN letter.' : 'Nombor E biasanya 10 digit. Semak dengan surat LHDN anda.'"></p>
                        </div>
                        <div>
                            <label class="uj-cs-lab" for="set-ecat"><span x-text="$store.ui.lang==='en' ? 'Employer category' : 'Kategori majikan'">Employer category</span> <span class="uj-cs-opt" x-text="$store.ui.lang==='en' ? 'Form E item 3' : 'Borang E item 3'">Form E item 3</span></label>
                            <select id="set-ecat" name="employer_category" class="uj-cs-inp"><option value="">-</option>@foreach (\App\Support\StatutoryOptions::EMPLOYER_CATEGORIES as $k => $v)<option value="{{ $k }}" @selected(old('employer_category', $company->employer_category) === $k)>{{ $k }} · {{ $v }}</option>@endforeach</select>
                        </div>
                        <div>
                            <label class="uj-cs-lab" for="set-estatus"><span x-text="$store.ui.lang==='en' ? 'Employer status' : 'Status majikan'">Employer status</span> <span class="uj-cs-opt" x-text="$store.ui.lang==='en' ? 'Form E item 4' : 'Borang E item 4'">Form E item 4</span></label>
                            <select id="set-estatus" name="employer_status" class="uj-cs-inp"><option value="">-</option>@foreach (\App\Support\StatutoryOptions::EMPLOYER_STATUSES as $k => $v)<option value="{{ $k }}" @selected(old('employer_status', $company->employer_status) === $k)>{{ $k }} · {{ $v }}</option>@endforeach</select>
                        </div>
                    </div>
                </div>

                <div class="uj-cs-agency">
                    <div><h4>KWSP</h4><p x-text="$store.ui.lang==='en' ? 'Monthly contribution file' : 'Fail caruman bulanan'">Monthly contribution file</p></div>
                    <div>
                        <label class="uj-cs-lab" for="set-epf"><span x-text="$store.ui.lang==='en' ? 'Employer number' : 'Nombor majikan'">Employer number</span> <span class="uj-cs-req" x-text="$store.ui.lang==='en' ? 'Required' : 'Wajib'">Required</span></label>
                        <input id="set-epf" name="epf_employer_no" x-model="epf" class="uj-cs-inp uj-cs-narrow uj-ww-mono" />
                        <p x-show="clean(epf) && !/^\d{9}$/.test(clean(epf).replace(/-/g, ''))" x-cloak class="uj-cs-warn" x-text="$store.ui.lang==='en' ? 'A KWSP employer number is usually 9 digits.' : 'Nombor majikan KWSP biasanya 9 digit.'"></p>
                    </div>
                </div>

                <div class="uj-cs-agency">
                    <div><h4>PERKESO</h4><p x-text="$store.ui.lang==='en' ? 'SOCSO and EIS' : 'PERKESO dan SIP'">SOCSO and EIS</p></div>
                    <div>
                        <label class="uj-cs-lab" for="set-socso"><span x-text="$store.ui.lang==='en' ? 'Employer code (SOCSO & EIS)' : 'Kod majikan (PERKESO & SIP)'">Employer code (SOCSO &amp; EIS)</span> <span class="uj-cs-req" x-text="$store.ui.lang==='en' ? 'Required' : 'Wajib'">Required</span></label>
                        <input id="set-socso" name="socso_employer_code" x-model="socso" placeholder="A3100000000Z" class="uj-cs-inp uj-cs-narrow uj-ww-mono" />
                        <p x-show="clean(socso) && !/^[A-Z][A-Z0-9]{11}$/.test(clean(socso))" x-cloak class="uj-cs-warn" x-text="$store.ui.lang==='en' ? 'A PERKESO employer code is usually 12 characters starting with a letter.' : 'Kod majikan PERKESO biasanya 12 aksara bermula dengan huruf.'"></p>
                    </div>
                </div>

                @if ($hrdfOn)
                    <div class="uj-cs-agency">
                        <div><h4>HRD Corp</h4><p x-text="$store.ui.lang==='en' ? 'Training levy' : 'Levi latihan'">Training levy</p></div>
                        <div>
                            <label class="uj-cs-lab" for="set-hrdf" x-text="$store.ui.lang==='en' ? 'Registration number / MyCoID' : 'Nombor pendaftaran / MyCoID'">Registration number / MyCoID</label>
                            <input id="set-hrdf" name="hrdf_registration_no" value="{{ old('hrdf_registration_no', $company->hrdf_registration_no) }}" class="uj-cs-inp uj-cs-narrow uj-ww-mono" />
                        </div>
                    </div>
                @else
                    {{-- Kept so a save while the levy is off doesn't wipe a number entered earlier. --}}
                    <input type="hidden" name="hrdf_registration_no" value="{{ $company->hrdf_registration_no }}" />
                @endif

                <div class="uj-cs-agency">
                    <div><h4>Zakat</h4><p x-text="$store.ui.lang==='en' ? 'Salary deduction file' : 'Fail potongan gaji'">Salary deduction file</p></div>
                    <div>
                        <label class="uj-cs-lab" for="set-zakat"><span x-text="$store.ui.lang==='en' ? 'Employer number' : 'Nombor majikan'">Employer number</span> <span class="uj-cs-opt" x-text="$store.ui.lang==='en' ? 'Optional' : 'Pilihan'">Optional</span></label>
                        <input id="set-zakat" name="zakat_employer_no" value="{{ old('zakat_employer_no', $company->zakat_employer_no) }}" class="uj-cs-inp uj-cs-narrow uj-ww-mono" />
                    </div>
                </div>

                <div class="uj-cs-agency">
                    <div><h4 x-text="$store.ui.lang==='en' ? 'Forms' : 'Borang'">Forms</h4><p x-text="$store.ui.lang==='en' ? 'Who signs, and the address printed' : 'Siapa menandatangan, dan alamat yang dicetak'">Who signs, and the address printed</p></div>
                    <div class="uj-cs-fg">
                        <div class="uj-cs-full">
                            <label class="uj-cs-lab" for="set-signatory" x-text="$store.ui.lang==='en' ? 'Signatory' : 'Penandatangan'">Signatory</label>
                            <select id="set-signatory" name="statutory_signatory_employee_id" class="uj-cs-inp">
                                <option value="">-</option>
                                @foreach ($signatoryOptions as $person)
                                    <option value="{{ $person->id }}" @selected((string) old('statutory_signatory_employee_id', $company->statutory_signatory_employee_id) === (string) $person->id)>{{ $person->name }}{{ $person->position ? ' · '.$person->position : '' }}</option>
                                @endforeach
                            </select>
                            <p class="uj-cs-hint" x-text="$store.ui.lang==='en' ? 'Name and designation printed on CP21, CP22, CP22A and PCB II.' : 'Nama dan jawatan yang dicetak pada CP21, CP22, CP22A dan PCB II.'">Name and designation printed on CP21, CP22, CP22A and PCB II.</p>
                        </div>
                        <div class="uj-cs-full uj-cs-addr">
                            <div>{{ $company->address ?: '-' }}</div>
                            <div class="uj-ww-mono">{{ $company->contact_number ?: '-' }}</div>
                            <a href="{{ $only ? route('app.screen', ['screen' => 'settings', 'section' => 'profile']) : '#profile' }}" x-text="$store.ui.lang==='en' ? 'Change in Workspace profile' : 'Tukar di Profil workspace'">Change in Workspace profile</a>
                        </div>
                    </div>
                </div>

                <div class="uj-cs-agency">
                    <div><h4 x-text="$store.ui.lang==='en' ? 'Payment' : 'Pembayaran'">Payment</h4><p x-text="$store.ui.lang==='en' ? 'Bank file and payroll contact' : 'Fail bank dan pegawai gaji'">Bank file and payroll contact</p></div>
                    <div class="uj-cs-fg">
                        <div><label class="uj-cs-lab" for="set-bank" x-text="$store.ui.lang==='en' ? 'Paying bank' : 'Bank pembayar'">Paying bank</label>
                            <select id="set-bank" name="paying_bank_code" class="uj-cs-inp"><option value="">-</option>@foreach (\App\Support\StatutoryOptions::BANK_CODES as $name => $code)<option value="{{ $code }}" @selected(old('paying_bank_code', $company->paying_bank_code) === $code)>{{ $name }}</option>@endforeach</select></div>
                        <div><label class="uj-cs-lab" for="set-bankacc" x-text="$store.ui.lang==='en' ? 'Paying account number' : 'No. akaun pembayar'">Paying account number</label><input id="set-bankacc" name="paying_bank_account_no" value="{{ old('paying_bank_account_no', $company->paying_bank_account_no) }}" class="uj-cs-inp uj-ww-mono" /></div>
                        <div><label class="uj-cs-lab" for="set-pcname" x-text="$store.ui.lang==='en' ? 'Payroll contact name' : 'Nama pegawai gaji'">Payroll contact name</label><input id="set-pcname" name="payroll_contact_name" value="{{ old('payroll_contact_name', $company->payroll_contact_name) }}" class="uj-cs-inp" /></div>
                        <div><label class="uj-cs-lab" for="set-pcphone" x-text="$store.ui.lang==='en' ? 'Payroll contact phone' : 'Telefon pegawai gaji'">Payroll contact phone</label><input id="set-pcphone" type="tel" name="payroll_contact_phone" value="{{ old('payroll_contact_phone', $company->payroll_contact_phone) }}" class="uj-cs-inp" /></div>
                    </div>
                </div>
            </div>

            <div class="uj-cs-cf">
                <span class="uj-cs-state"><i x-show="dirty" x-cloak></i><span x-text="dirty ? ($store.ui.lang==='en' ? 'Unsaved changes' : 'Perubahan belum disimpan') : ''"></span></span>
                <button type="submit" class="uj-btn-primary uj-cs-save"><span x-text="$store.ui.lang==='en' ? 'Save statutory details' : 'Simpan butiran berkanun'">Save statutory details</span></button>
            </div>
        </form>
    </section>
    @endif

        @if (!empty($canManageFeatures) && (! $only || $only === 'work_week'))
        {{-- Work week: which ISO weekdays (1 = Mon .. 7 = Sun) are working days, read by
             App\Support\WorkWeek. Below it, Special days (work_day_rules): a weekday that works
             only on certain weeks of the month, with its own hours. Each rule save/delete is its
             own small form post. --}}
        @php
            $wwRules = $workDayRules->map(fn ($r) => [
                'id' => $r->id, 'weekday' => (int) $r->weekday, 'ords' => array_map('intval', (array) $r->weeks),
                'start' => $r->startHhmm(), 'end' => $r->endHhmm(), 'counts' => $r->counts,
            ])->values();
            $wwErr = $errors->hasAny(['weekday', 'weeks', 'weeks.*', 'start_time', 'end_time', 'counts']);
            $wwOld = $wwErr ? [
                'id' => old('_rule_id') ? (int) old('_rule_id') : null, 'weekday' => (int) old('weekday', 6),
                'ords' => array_map('intval', (array) old('weeks', [])), 'start' => old('start_time', '09:00'),
                'end' => old('end_time', '13:00'), 'counts' => old('counts', 'half'),
            ] : null;
        @endphp
        <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('workDayRules', (cfg) => ({
                days: cfg.days, rules: cfg.rules, grace: cfg.grace, base: cfg.base,
                editing: !!cfg.old, draft: cfg.old || { ords: [] }, view: null, today: new Date(),
                DN: { en: ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'], ms: ['Isn','Sel','Rab','Kha','Jum','Sab','Ahd'] },
                DL: { en: ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'], ms: ['Isnin','Selasa','Rabu','Khamis','Jumaat','Sabtu','Ahad'] },
                MS_MONTHS: ['Januari','Februari','Mac','April','Mei','Jun','Julai','Ogos','September','Oktober','November','Disember'],
                MS_MON: ['Jan','Feb','Mac','Apr','Mei','Jun','Jul','Ogo','Sep','Okt','Nov','Dis'],
                init() { this.view = { y: this.today.getFullYear(), m: this.today.getMonth() }; },
                get ms() { return this.$store.ui.lang !== 'en'; },
                L(en, msText) { return this.ms ? msText : en; },
                dn(i) { return this.DN[this.ms ? 'ms' : 'en'][i]; },
                dl(i) { return this.DL[this.ms ? 'ms' : 'en'][i]; },
                has(n) { return this.days.includes(n); },
                toggle(n) { this.has(n) ? this.days = this.days.filter(d => d !== n) : this.days.push(n); },
                ruleOn(n) { return this.rules.some(r => r.weekday === n); },
                ordLabel(v) { return v === -1 ? this.L('Last', 'Terakhir') : this.L(v + ['st','nd','rd','th'][v - 1], 'Minggu ke-' + v); },
                takenBy(n) { return this.rules.some(r => r.weekday === n && r.id !== this.draft.id); },
                startNew() {
                    const free = [6,7,5,4,3,2,1].find(n => !this.takenBy(n)) || 6;
                    this.draft = { id: null, weekday: free, ords: [2, 4], start: '09:00', end: '13:00', counts: 'half' };
                    this.view = { y: this.today.getFullYear(), m: this.today.getMonth() }; this.editing = true;
                },
                startEdit(r) { this.draft = JSON.parse(JSON.stringify(r)); this.view = { y: this.today.getFullYear(), m: this.today.getMonth() }; this.editing = true; },
                toggleOrd(v) { const o = this.draft.ords; o.includes(v) ? this.draft.ords = o.filter(x => x !== v) : o.push(v); },
                sorted(o) { return o.slice().sort((a, b) => (a < 0 ? 9 : a) - (b < 0 ? 9 : b)); },
                ordShort(r) { return r.ords.length === 1 ? (r.ords[0] === -1 ? this.L('L', 'T') : r.ords[0]) : r.ords.length + '×'; },
                ruleTitle(r) {
                    const w = this.sorted(r.ords), day = this.dl(r.weekday - 1);
                    if (this.ms) {
                        const m = { 1: 'pertama', 2: 'kedua', 3: 'ketiga', 4: 'keempat', '-1': 'terakhir' }, p = w.map(v => m[v]);
                        return day + ' ' + (p.length > 1 ? p.slice(0, -1).join(', ') + ' dan ' + p.at(-1) : p[0]) + ' setiap bulan';
                    }
                    const m = { 1: '1st', 2: '2nd', 3: '3rd', 4: '4th', '-1': 'last' }, p = w.map(v => m[v]);
                    const list = p.length > 1 ? p.slice(0, -1).join(', ') + ' and ' + p.at(-1) : p[0];
                    return list.charAt(0).toUpperCase() + list.slice(1) + ' ' + day + ' of the month';
                },
                mins(r) { if (!r.start || !r.end) return 0; const [a, b] = r.start.split(':').map(Number), [c, d] = r.end.split(':').map(Number); return (c * 60 + d) - (a * 60 + b); },
                fmtLen(r) { const m = this.mins(r); return m <= 0 ? '—' : Math.floor(m / 60) + 'h ' + String(m % 60).padStart(2, '0') + 'm'; },
                addMin(t, n) { if (!t) return '--:--'; const [h, m] = t.split(':').map(Number), x = h * 60 + m + n; return String(Math.floor(x / 60) % 24).padStart(2, '0') + ':' + String(x % 60).padStart(2, '0'); },
                valid() { return this.draft.ords.length > 0 && this.mins(this.draft) > 0 && !this.takenBy(this.draft.weekday); },
                matches(r, date) {
                    if ((date.getDay() + 6) % 7 + 1 !== r.weekday) return false;
                    const last = new Date(date.getFullYear(), date.getMonth(), date.getDate() + 7).getMonth() !== date.getMonth();
                    return r.ords.includes(Math.ceil(date.getDate() / 7)) || (last && r.ords.includes(-1));
                },
                fmtDate(d) { return this.dn((d.getDay() + 6) % 7) + ' ' + d.getDate() + ' ' + (this.ms ? this.MS_MON[d.getMonth()] : d.toLocaleString('en-GB', { month: 'short' })); },
                nextDates(r, n) {
                    const out = [], d = new Date(this.today.getFullYear(), this.today.getMonth(), this.today.getDate());
                    if (!r.ords || !r.ords.length) return [];
                    for (let i = 0; i < 400 && out.length < n; i++) { if (this.matches(r, d)) out.push(this.fmtDate(d) + (i === 0 ? ' (' + this.L('today', 'hari ini') + ')' : '')); d.setDate(d.getDate() + 1); }
                    return out;
                },
                shift(k) { let m = this.view.m + k, y = this.view.y; if (m < 0) { m = 11; y--; } if (m > 11) { m = 0; y++; } this.view = { y, m }; },
                monthLabel() { return this.ms ? this.MS_MONTHS[this.view.m] + ' ' + this.view.y : new Date(this.view.y, this.view.m, 1).toLocaleString('en-GB', { month: 'long', year: 'numeric' }); },
                cells() {
                    const { y, m } = this.view, lead = (new Date(y, m, 1).getDay() + 6) % 7, len = new Date(y, m + 1, 0).getDate(), out = [];
                    const t = new Date(this.today.getFullYear(), this.today.getMonth(), this.today.getDate()).getTime();
                    for (let i = 0; i < lead; i++) out.push({ k: 'b' + i, cls: 'blank', d: '', t: '' });
                    for (let d = 1; d <= len; d++) {
                        const dt = new Date(y, m, d), iso = (dt.getDay() + 6) % 7 + 1;
                        let cls = 'off', txt = '';
                        if (this.editing && this.draft.ords.length && this.matches(this.draft, dt)) { cls = 'special'; txt = this.draft.start + '–' + this.draft.end; }
                        else {
                            const r = this.rules.find(x => x.id !== this.draft.id && this.matches(x, dt));
                            if (r) { cls = this.editing ? 'other' : 'special'; txt = r.start + '–' + r.end; } else if (this.days.includes(iso)) cls = 'work';
                        }
                        out.push({ k: 'd' + d, cls: cls + (dt.getTime() === t ? ' today' : ''), d, t: txt });
                    }
                    return out;
                },
            }));
        });
        </script>
        <div id="work-week" class="uj-card uj-cs-sec" style="padding:20px;"
             x-data="workDayRules({ days: @js(\App\Support\WorkWeek::for()->workingDays()), rules: @js($wwRules), grace: {{ (int) $lateGraceMinutes }}, base: @js(route('admin.workdayrules.store')), old: @js($wwOld) })">
            <h3 class="uj-card-title" style="margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Work week' : 'Minggu bekerja'">Work week</h3>
            <p style="font-size:13px;color:var(--muted);margin:0 0 14px;" x-text="$store.ui.lang==='en' ? 'Which days count as working days. Leave balances, timesheet capacity and attendance reports all follow this.' : 'Hari mana dikira sebagai hari bekerja. Baki cuti, kapasiti timesheet dan laporan kehadiran semuanya mengikut ini.'">Which days count as working days. Leave balances, timesheet capacity and attendance reports all follow this.</p>

            <form method="post" action="{{ route('admin.workweek.update') }}">
                @csrf
                @if ($errors->has('work_days') || $errors->has('work_days.*'))<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12.5px;border-radius:8px;padding:9px 12px;margin-bottom:12px;" x-text="$store.ui.lang==='en' ? 'Pick at least one working day.' : 'Pilih sekurang-kurangnya satu hari bekerja.'">Pick at least one working day.</div>@endif

                <div class="uj-ww-days" role="group" :aria-label="L('Working days', 'Hari bekerja')">
                    <template x-for="n in [1,2,3,4,5,6,7]" :key="n">
                        <button type="button" class="uj-ww-day" :class="{'has-rule': !has(n) && ruleOn(n)}" @click="toggle(n)" :aria-pressed="has(n)">
                            <span class="d" x-text="dn(n-1)"></span>
                            <span class="s" x-text="has(n) ? L('Every week','Setiap minggu') : (ruleOn(n) ? L('Some weeks','Minggu tertentu') : L('Off','Cuti'))"></span>
                        </button>
                    </template>
                </div>
                <template x-for="d in days" :key="'wd'+d"><input type="hidden" name="work_days[]" :value="d"></template>

                @include('partials.hint', ['en' => 'Hours on these days come from each branch, client site or the WFH policy.', 'ms' => 'Waktu bekerja pada hari-hari ini mengikut cawangan, tapak pelanggan atau polisi WFH.'])
                @include('partials.hint', ['en' => 'Applies from today. Past records are not recalculated.', 'ms' => 'Berkuat kuasa dari hari ini. Rekod lepas tidak dikira semula.'])

                <div class="uj-ww-savebar" x-show="!editing">
                    <button type="submit" class="uj-btn-primary" style="height:38px;padding:0 18px;font-size:13px;" :disabled="days.length === 0"><span x-text="$store.ui.lang==='en' ? 'Save work week' : 'Simpan minggu bekerja'">Save work week</span></button>
                    <span style="font-size:12.5px;color:var(--muted);" x-text="days.length + ' ' + ($store.ui.lang==='en' ? (days.length === 1 ? 'working day' : 'working days') : 'hari bekerja')">5 working days</span>
                </div>
            </form>

            <div class="uj-ww-sep"></div>

            <div class="uj-ww-sech">
                <h4 x-text="L('Special days','Hari khas')">Special days</h4>
                <button type="button" class="uj-btn-ghost uj-ww-add" x-show="!editing" @click="startNew()">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                    <span x-text="L('Add special day','Tambah hari khas')">Add special day</span>
                </button>
            </div>
            <p style="font-size:13px;color:var(--muted);margin:0;max-width:60ch;" x-text="L('A day that works only on certain weeks of the month, with its own hours. On that date clock in and clock out use these hours instead of the branch\'s.', 'Hari yang bekerja pada minggu tertentu dalam sebulan, dengan waktunya sendiri. Pada tarikh itu, daftar masuk dan keluar guna waktu ini, bukan waktu cawangan.')"></p>

            <div class="uj-ww-rules">
                <template x-for="r in rules" :key="r.id">
                    <div class="uj-ww-rule">
                        <div class="uj-ww-date"><span class="n" x-text="ordShort(r)"></span><span class="w" x-text="dn(r.weekday-1)"></span></div>
                        <div style="min-width:0;">
                            <p style="font-size:14px;font-weight:500;margin:0;color:var(--ink);" x-text="ruleTitle(r)"></p>
                            <p class="uj-ww-meta">
                                <span class="uj-ww-mono" x-text="r.start + ' – ' + r.end"></span><i></i>
                                <span class="uj-ww-mono" x-text="fmtLen(r)"></span>
                                <template x-if="nextDates(r,1)[0]"><i></i></template>
                                <template x-if="nextDates(r,1)[0]"><span x-text="L('Next ','Akan datang ') + nextDates(r,1)[0]"></span></template>
                            </p>
                        </div>
                        <span class="uj-stamp" :data-tone="r.counts==='half' ? 'amber' : 'success'" x-text="r.counts==='half' ? L('HALF DAY','SEPARUH HARI') : L('FULL DAY','SEHARI PENUH')"></span>
                        <div class="uj-ww-acts">
                            <button type="button" class="uj-ww-ico" :aria-label="L('Edit special day','Sunting hari khas')" @click="startEdit(r)"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20h4L19 9l-4-4L4 16v4Z"/><path d="m13.5 6.5 4 4"/></svg></button>
                            <form method="post" :action="base + '/' + r.id" @submit="if (!confirm(L('Remove this special day?','Buang hari khas ini?'))) $event.preventDefault()" style="margin:0;">
                                @csrf @method('DELETE')
                                <button type="submit" class="uj-ww-ico" :aria-label="L('Remove special day','Buang hari khas')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/></svg></button>
                            </form>
                        </div>
                    </div>
                </template>
                <div class="uj-ww-empty" x-show="rules.length===0" x-cloak x-text="L('No special days. Every working day uses branch hours.','Tiada hari khas. Setiap hari bekerja guna waktu cawangan.')"></div>
            </div>

            <div class="uj-ww-ed" :class="{open: editing}">
                <div>
                    <form method="post" :action="draft.id ? base + '/' + draft.id : base" class="uj-ww-edin" x-show="editing" x-cloak>
                        @csrf
                        <template x-if="draft.id"><input type="hidden" name="_method" value="PUT"></template>
                        <template x-if="draft.id"><input type="hidden" name="_rule_id" :value="draft.id"></template>
                        <template x-for="o in draft.ords" :key="'o'+o"><input type="hidden" name="weeks[]" :value="o"></template>
                        <h4 style="font-size:14px;font-weight:600;margin:0 0 14px;" x-text="draft.id ? L('Edit special day','Sunting hari khas') : L('New special day','Hari khas baharu')"></h4>

                        @if ($wwErr)<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12.5px;border-radius:8px;padding:9px 12px;margin-bottom:14px;">{{ $errors->first() }}</div>@endif

                        <div class="uj-ww-grid">
                            <div>
                                <span class="uj-ww-lab" x-text="L('Which week','Minggu yang mana')">Which week</span>
                                <div class="uj-ww-segw" role="group" :aria-label="L('Which week of the month','Minggu dalam bulan')">
                                    <template x-for="v in [1,2,3,4,-1]" :key="v">
                                        <button type="button" :aria-pressed="draft.ords.includes(v)" @click="toggleOrd(v)" x-text="ordLabel(v)"></button>
                                    </template>
                                </div>
                                <div class="uj-ww-err" x-show="draft.ords.length===0" x-cloak x-text="L('Pick at least one week.','Pilih sekurang-kurangnya satu minggu.')"></div>
                            </div>
                            <div>
                                <label class="uj-ww-lab" for="wdr-day" x-text="L('Day','Hari')">Day</label>
                                <select id="wdr-day" name="weekday" class="uj-ww-inp" x-model.number="draft.weekday">
                                    <template x-for="n in [1,2,3,4,5,6,7]" :key="n"><option :value="n" x-text="dl(n-1) + (takenBy(n) ? ' · ' + L('has a rule','sudah ada') : '')" :disabled="takenBy(n)" :selected="n===draft.weekday"></option></template>
                                </select>
                            </div>
                            <div class="uj-ww-full">
                                <span class="uj-ww-lab" x-text="L('Hours','Waktu')">Hours</span>
                                <div class="uj-ww-times">
                                    <input type="time" name="start_time" class="uj-ww-inp" :aria-label="L('Start','Mula')" x-model="draft.start" required>
                                    <span style="color:var(--muted);font-size:12.5px;" x-text="L('to','hingga')">to</span>
                                    <input type="time" name="end_time" class="uj-ww-inp" :aria-label="L('End','Tamat')" x-model="draft.end" required>
                                    <div class="uj-ww-len"><b x-text="fmtLen(draft)"></b><span x-text="L('worked','bekerja')">worked</span></div>
                                </div>
                                <div class="uj-ww-err" x-show="mins(draft)<=0" x-cloak x-text="L('End time must be after the start time.','Masa tamat mesti selepas masa mula.')"></div>
                                <div class="uj-ww-note">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                                    <span x-show="!ms">Late after <b class="uj-ww-mono" x-text="addMin(draft.start, grace)"></b> (the <span x-text="grace"></span> min grace). Clocking out before <b class="uj-ww-mono" x-text="draft.end"></b> asks for a reason.</span>
                                    <span x-show="ms" x-cloak>Dikira lewat selepas <b class="uj-ww-mono" x-text="addMin(draft.start, grace)"></b> (tempoh bertolak ansur <span x-text="grace"></span> minit). Daftar keluar sebelum <b class="uj-ww-mono" x-text="draft.end"></b> perlu alasan.</span>
                                </div>
                            </div>
                            <div class="uj-ww-full">
                                <span class="uj-ww-lab" x-text="L('Counts as','Dikira sebagai')">Counts as</span>
                                <div class="uj-ww-segw" role="group" :aria-label="L('Counts as','Dikira sebagai')">
                                    <button type="button" :aria-pressed="draft.counts==='half'" @click="draft.counts='half'" x-text="L('Half day','Separuh hari')">Half day</button>
                                    <button type="button" :aria-pressed="draft.counts==='full'" @click="draft.counts='full'" x-text="L('Full day','Sehari penuh')">Full day</button>
                                </div>
                                <input type="hidden" name="counts" :value="draft.counts">
                                <p style="font-size:12.5px;color:var(--muted);margin:6px 0 0;" x-text="draft.counts==='half' ? L('Leave on this day costs 0.5. Timesheet capacity is 50%.','Cuti pada hari ini ditolak 0.5. Kapasiti timesheet 50%.') : L('Leave on this day costs 1. Timesheet capacity is 100%.','Cuti pada hari ini ditolak 1. Kapasiti timesheet 100%.')"></p>
                            </div>
                        </div>

                        <div class="uj-ww-cal">
                            <div class="uj-ww-calh">
                                <button type="button" class="uj-ww-ico" :aria-label="L('Previous month','Bulan sebelumnya')" @click="shift(-1)"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m15 6-6 6 6 6"/></svg></button>
                                <strong x-text="monthLabel()"></strong>
                                <button type="button" class="uj-ww-ico" :aria-label="L('Next month','Bulan seterusnya')" @click="shift(1)"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m9 6 6 6-6 6"/></svg></button>
                            </div>
                            <div class="uj-ww-calg">
                                <template x-for="i in [0,1,2,3,4,5,6]" :key="'h'+i"><div class="uj-ww-calwd" x-text="dn(i)"></div></template>
                                <template x-for="c in cells()" :key="c.k">
                                    <div class="uj-ww-cell" :class="c.cls"><span class="n" x-text="c.d"></span><span class="t" x-text="c.t"></span></div>
                                </template>
                            </div>
                            <div class="uj-ww-legend">
                                <span><i class="uj-ww-sw" style="background:#fff;"></i><span x-text="L('Working day, branch hours','Hari bekerja, waktu cawangan')"></span></span>
                                <span><i class="uj-ww-sw" style="background:color-mix(in srgb,var(--amber) 12%,#fff);border-color:color-mix(in srgb,var(--amber) 34%,#fff);"></i><span x-text="L('This special day','Hari khas ini')"></span></span>
                                <span x-show="rules.some(r => r.id !== draft.id)"><i class="uj-ww-sw" style="background:var(--shelf);border-color:var(--shelf-line);"></i><span x-text="L('Another special day','Hari khas lain')"></span></span>
                                <span><i class="uj-ww-sw" style="background:transparent;"></i><span x-text="L('Off','Cuti')"></span></span>
                            </div>
                            <div class="uj-ww-next"><span style="font-size:12.5px;color:var(--muted);margin-right:2px;" x-text="L('Next:','Akan datang:')"></span><template x-for="d in nextDates(draft,4)" :key="d"><span class="chip" x-text="d"></span></template></div>
                        </div>

                        <div class="uj-ww-foot">
                            <span style="font-size:12.5px;color:var(--muted);max-width:44ch;line-height:1.45;" x-text="L('Applies from today. Past attendance and leave are not recalculated.','Berkuat kuasa dari hari ini. Kehadiran dan cuti lepas tidak dikira semula.')"></span>
                            <div class="acts">
                                <button type="button" class="uj-btn-ghost uj-ww-btn" @click="editing=false" x-text="L('Cancel','Batal')">Cancel</button>
                                <button type="submit" class="uj-btn-primary uj-ww-btn" :disabled="!valid()" x-text="draft.id ? L('Save special day','Simpan hari khas') : L('Add special day','Tambah hari khas')"></button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        @endif

    @if (!empty($canManageFeatures) && (! $only || $only === 'approvals'))
    {{-- Approval shortcut: after this many days unverified, HR / a director may approve a
         leave or claim request directly (RoutesApprovalsByReportingLine). Blank = off, which
         the switch submits as an empty value. --}}
    @php $escDays = old('approval_escalation_days', $company->approval_escalation_days); @endphp
    <section id="approvals" class="uj-card uj-cs-sec" x-data="{ on: @js(filled($escDays)), days: @js(filled($escDays) ? (string) $escDays : '3') }">
        <form method="post" action="{{ route('admin.approval-escalation.update') }}">
            @csrf
            <div class="uj-cs-ch">
                <div>
                    <h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Approval shortcut' : 'Pintasan kelulusan'">Approval shortcut</h3>
                    <p class="uj-cs-sub" x-text="$store.ui.lang==='en' ? 'For leave and claim requests stuck with a manager who has not verified them.' : 'Untuk permohonan cuti dan tuntutan yang tersekat pada pengurus yang belum mengesahkannya.'">For leave and claim requests stuck with a manager who has not verified them.</p>
                </div>
                <label class="uj-switch"><input type="checkbox" x-model="on" :aria-label="$store.ui.lang==='en' ? 'Approval shortcut' : 'Pintasan kelulusan'"><i></i></label>
            </div>
            <div class="uj-cs-cb">
                @error('approval_escalation_days')<div class="uj-alert" data-tone="error"><span class="uj-alert-msg">{{ $message }}</span></div>@enderror
                <div class="uj-cs-sentence" :class="{ 'off': ! on }">
                    <span x-text="$store.ui.lang==='en' ? 'When a request has waited' : 'Apabila permohonan sudah menunggu'">When a request has waited</span>
                    <input id="approval_escalation_days" type="number" min="1" max="60" x-model="days" :name="on ? 'approval_escalation_days' : null" :disabled="! on" class="uj-cs-inp uj-ww-mono" :aria-label="$store.ui.lang==='en' ? 'Days to wait' : 'Hari menunggu'" />
                    <span x-text="$store.ui.lang==='en' ? 'calendar days without a manager check, HR or a director can approve it directly.' : 'hari kalendar tanpa semakan pengurus, HR atau pengarah boleh meluluskannya terus.'">calendar days without a manager check, HR or a director can approve it directly.</span>
                </div>
                <template x-if="! on"><input type="hidden" name="approval_escalation_days" value=""></template>
                <p class="uj-cs-hint" x-text="on ? ($store.ui.lang==='en' ? 'Counted from when the request was sent.' : 'Dikira dari masa permohonan dihantar.') : ($store.ui.lang==='en' ? 'Off. Every request waits for its manager first.' : 'Dimatikan. Setiap permohonan menunggu pengurusnya dahulu.')"></p>
            </div>
            <div class="uj-cs-cf">
                <span></span>
                <button type="submit" class="uj-btn-primary uj-cs-save"><span x-text="$store.ui.lang==='en' ? 'Save' : 'Simpan'">Save</span></button>
            </div>
        </form>
    </section>
    @endif

    @if (! $only || $only === 'branches')
    {{-- Branches: name + state CRUD, plus the geofence and hours the attendance clock checks. --}}
    <section id="branches" class="uj-card uj-cs-sec" @if ($canManageFeatures) x-data="{ adding:false, editId:null }" @endif>
        <div class="uj-cs-ch">
            <div>
                <h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Branches' : 'Cawangan'">Branches</h3>
                <p class="uj-cs-sub" x-text="$store.ui.lang==='en' ? 'Each branch carries its own clock-in geofence and working hours.' : 'Setiap cawangan ada geofence daftar masuk dan waktu bekerjanya sendiri.'">Each branch carries its own clock-in geofence and working hours.</p>
            </div>
            @if ($canManageFeatures)
                <button type="button" @click="adding=!adding;editId=null" class="uj-btn-ghost uj-cs-add-btn">
                    <span x-text="adding ? ($store.ui.lang==='en'?'Cancel':'Batal') : ($store.ui.lang==='en'?'+ Add':'+ Tambah')">+ Add</span>
                </button>
            @endif
        </div>
        @include('partials.coachmark', [
            'key' => 'guide-branches',
            'when' => "\$store.guide.current === 'branches'",
            'anchor' => 'button.uj-btn-ghost',
            'en' => ['title' => 'Add your first branch', 'body' => 'Click + Add, give the branch a name and address, then click Add branch. The map pin can wait; it comes up in the attendance step.'],
            'ms' => ['title' => 'Tambah cawangan pertama anda', 'body' => 'Klik + Tambah, beri nama dan alamat cawangan, kemudian klik Tambah cawangan. Pin peta boleh ditunggu; ia muncul dalam langkah kehadiran.'],
        ])

        <div class="uj-cs-cb">
            @if ($canManageFeatures)
                @php $bfs = 'height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:12.5px;outline:none;background:#fff;color:var(--ink);min-width:0;'; @endphp
                <form x-show="adding" x-cloak method="post" action="{{ route('admin.branches.store') }}" class="uj-cs-panel">
                    @csrf
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:8px;">
                        <input name="name" required :placeholder="$store.ui.lang==='en'?'Branch name *':'Nama cawangan *'" style="{{ $bfs }}" />
                        <input name="code" placeholder="Code" style="{{ $bfs }}" />
                        <select name="type" style="{{ $bfs }}"><option value="">Type…</option>@foreach ($locationTypes as $t)<option value="{{ $t }}">{{ $t }}</option>@endforeach</select>
                        <input name="state" :placeholder="$store.ui.lang==='en'?'State':'Negeri'" style="{{ $bfs }}" />
                        <input name="contact_number" placeholder="Contact" style="{{ $bfs }}" />
                        <input name="email" type="email" placeholder="Email" style="{{ $bfs }}" />
                        <select name="status" style="{{ $bfs }}"><option value="active">Active</option><option value="inactive">Inactive</option></select>
                        <input name="effective_date" type="date" style="{{ $bfs }}" />
                    </div>
                    <input name="address" placeholder="Address" style="{{ $bfs }}width:100%;margin-top:8px;" />
                    {{-- Geofence + working hours: the attendance clock checks a punch against these. --}}
                    <div style="display:flex;flex-wrap:wrap;align-items:end;gap:8px;margin-top:8px;">
                        <input id="lat-newbranch" name="latitude" placeholder="Latitude" style="{{ $bfs }}width:120px;font-family:var(--font-mono);" />
                        <input id="lng-newbranch" name="longitude" placeholder="Longitude" style="{{ $bfs }}width:120px;font-family:var(--font-mono);" />
                        <button type="button" x-data @click="window.dispatchEvent(new CustomEvent('open-map-picker', { detail: { latId: 'lat-newbranch', lngId: 'lng-newbranch', title: 'New branch' } }))" class="uj-btn-ghost uj-cs-map"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s-7-5.6-7-11a7 7 0 0 1 14 0c0 5.4-7 11-7 11Z"/><circle cx="12" cy="10" r="2.5"/></svg><span x-text="$store.ui.lang==='en'?'Map':'Peta'">Map</span></button>
                        <input name="radius_m" type="number" min="20" max="5000" value="200" placeholder="Radius (m)" style="{{ $bfs }}width:96px;font-family:var(--font-mono);" />
                        <input name="work_start" type="time" style="{{ $bfs }}width:118px;" />
                        <input name="work_end" type="time" style="{{ $bfs }}width:118px;" />
                        <input name="min_hours" type="number" step="0.5" min="0" max="24" placeholder="Min hrs" style="{{ $bfs }}width:88px;font-family:var(--font-mono);" />
                    </div>
                    <button type="submit" class="uj-btn-primary" style="height:36px;padding:0 16px;font-size:12.5px;margin-top:8px;"><span x-text="$store.ui.lang==='en'?'Add branch':'Tambah cawangan'">Add branch</span></button>
                </form>
            @endif

            <div class="uj-cs-list">
            @forelse ($branches as $b)
                <div class="uj-cs-item">
                    <div @if ($canManageFeatures) x-show="editId !== {{ $b->id }}" @endif class="uj-cs-row">
                        <span class="uj-cs-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 21h18M5 21V7l7-4 7 4v14M9 21v-6h6v6"/></svg></span>
                        <span class="uj-cs-nm">{{ $b->name }}
                            <small>{{ collect([$b->state, $b->type ? $b->type.($b->code ? ' ('.$b->code.')' : '') : ($b->code ?: null)])->filter()->implode(' · ') }}@if ($b->work_start && $b->work_end)<span class="uj-ww-mono"> · {{ substr($b->work_start, 0, 5) }}–{{ substr($b->work_end, 0, 5) }}</span>@endif</small>
                        </span>
                        @if (filled($b->latitude) && filled($b->longitude))
                            <span class="uj-stamp" data-tone="success">Geofence {{ $b->radius_m ?? 200 }} m</span>
                        @else
                            <span class="uj-stamp" data-tone="amber" x-text="$store.ui.lang==='en' ? 'No geofence' : 'Tiada geofence'">No geofence</span>
                        @endif
                        @if ($canManageFeatures)
                            <span class="uj-cs-acts">
                                <button type="button" class="uj-ww-ico" @click="editId={{ $b->id }};adding=false" :aria-label="$store.ui.lang==='en' ? 'Edit {{ e(addslashes($b->name)) }}' : 'Sunting {{ e(addslashes($b->name)) }}'">{!! $editIcon !!}</button>
                                <button type="submit" form="del-branch-{{ $b->id }}" class="uj-ww-ico uj-cs-del" :aria-label="$store.ui.lang==='en' ? 'Delete {{ e(addslashes($b->name)) }}' : 'Padam {{ e(addslashes($b->name)) }}'">{!! $deleteIcon !!}</button>
                            </span>
                        @endif
                    </div>
                    @if ($canManageFeatures)
                        <form x-show="editId === {{ $b->id }}" x-cloak method="post" action="{{ route('admin.branches.update', $b) }}" class="uj-cs-panel">
                            @csrf
                            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:8px;">
                                <input name="name" value="{{ $b->name }}" required style="{{ $bfs }}" />
                                <input name="code" value="{{ $b->code }}" placeholder="Code" style="{{ $bfs }}" />
                                <select name="type" style="{{ $bfs }}"><option value="">Type…</option>@foreach ($locationTypes as $t)<option value="{{ $t }}" @selected($b->type === $t)>{{ $t }}</option>@endforeach</select>
                                <input name="state" value="{{ $b->state }}" placeholder="State" style="{{ $bfs }}" />
                                <input name="contact_number" value="{{ $b->contact_number }}" placeholder="Contact" style="{{ $bfs }}" />
                                <input name="email" type="email" value="{{ $b->email }}" placeholder="Email" style="{{ $bfs }}" />
                                <select name="status" style="{{ $bfs }}"><option value="active" @selected(($b->status ?? 'active')==='active')>Active</option><option value="inactive" @selected($b->status==='inactive')>Inactive</option></select>
                                <input name="effective_date" type="date" value="{{ optional($b->effective_date)->toDateString() }}" style="{{ $bfs }}" />
                            </div>
                            <input name="address" value="{{ $b->address }}" placeholder="Address" style="{{ $bfs }}width:100%;margin-top:8px;" />
                            {{-- Geofence + working hours: the attendance clock checks a punch against these. --}}
                            <div style="display:flex;flex-wrap:wrap;align-items:end;gap:8px;margin-top:8px;">
                                <input id="lat-branch-{{ $b->id }}" name="latitude" value="{{ $b->latitude }}" placeholder="Latitude" style="{{ $bfs }}width:120px;font-family:var(--font-mono);" />
                                <input id="lng-branch-{{ $b->id }}" name="longitude" value="{{ $b->longitude }}" placeholder="Longitude" style="{{ $bfs }}width:120px;font-family:var(--font-mono);" />
                                <button type="button" x-data @click="window.dispatchEvent(new CustomEvent('open-map-picker', { detail: { latId: 'lat-branch-{{ $b->id }}', lngId: 'lng-branch-{{ $b->id }}', title: @js($b->name) } }))" class="uj-btn-ghost uj-cs-map"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s-7-5.6-7-11a7 7 0 0 1 14 0c0 5.4-7 11-7 11Z"/><circle cx="12" cy="10" r="2.5"/></svg><span x-text="$store.ui.lang==='en'?'Map':'Peta'">Map</span></button>
                                <input name="radius_m" type="number" min="20" max="5000" value="{{ $b->radius_m ?? 200 }}" placeholder="Radius (m)" style="{{ $bfs }}width:96px;font-family:var(--font-mono);" />
                                <input name="work_start" type="time" value="{{ $b->work_start ? substr($b->work_start, 0, 5) : '' }}" style="{{ $bfs }}width:118px;" />
                                <input name="work_end" type="time" value="{{ $b->work_end ? substr($b->work_end, 0, 5) : '' }}" style="{{ $bfs }}width:118px;" />
                                <input name="min_hours" type="number" step="0.5" min="0" max="24" value="{{ $b->min_hours }}" placeholder="Min hrs" style="{{ $bfs }}width:88px;font-family:var(--font-mono);" />
                            </div>
                            <div class="uj-cs-panel-acts">
                                <button type="submit" class="uj-btn-primary" style="height:34px;padding:0 14px;font-size:12px;"><span x-text="$store.ui.lang==='en'?'Save':'Simpan'">Save</span></button>
                                <button type="button" @click="editId=null" class="uj-cs-cancel" x-text="$store.ui.lang==='en'?'Cancel':'Batal'">Cancel</button>
                            </div>
                        </form>
                    @endif
                </div>
            @empty
                <p class="uj-cs-empty" x-text="$store.ui.lang==='en'?'No branches yet.':'Tiada cawangan lagi.'">No branches yet.</p>
            @endforelse
            </div>

            @if ($canManageFeatures)
                @foreach ($branches as $b)
                    <form id="del-branch-{{ $b->id }}" method="post" action="{{ route('admin.branches.delete', $b) }}" onsubmit="return confirm('Delete {{ addslashes($b->name) }}?')">@csrf</form>
                @endforeach
            @endif
        </div>
        {{-- One map-picker modal for every branch geofence row on this screen. --}}
        @if ($canManageFeatures)
            @include('partials.map-picker')
        @endif
    </section>
    @endif

    @if (! $only || in_array($only, ['departments', 'staff-levels'], true))
    <div class="{{ $only ? '' : 'uj-cs-duo' }}">
    @if (! $only || $only === 'departments')
    {{-- Departments: name CRUD. employees_count is shown for context; delete is blocked while in use. --}}
    <section id="departments" class="uj-card uj-cs-sec" @if ($canManageFeatures) x-data="{ adding:false, editId:null }" @endif>
        <div class="uj-cs-ch">
            <div>
                <h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Departments' : 'Jabatan'">Departments</h3>
                <p class="uj-cs-sub" x-text="$store.ui.lang==='en' ? 'Staff are grouped under these.' : 'Staf dikumpulkan di bawah ini.'">Staff are grouped under these.</p>
            </div>
            @if ($canManageFeatures)
                <button type="button" @click="adding=!adding;editId=null" class="uj-btn-ghost uj-cs-add-btn">
                    <span x-text="adding ? ($store.ui.lang==='en'?'Cancel':'Batal') : ($store.ui.lang==='en'?'+ Add':'+ Tambah')">+ Add</span>
                </button>
            @endif
        </div>
        @include('partials.coachmark', [
            'key' => 'guide-departments',
            'when' => "\$store.guide.current === 'departments'",
            'anchor' => 'button.uj-btn-ghost',
            'en' => ['title' => 'Add a department', 'body' => 'Click + Add, type the department name and click Add. One is enough to start; staff are grouped under these.'],
            'ms' => ['title' => 'Tambah jabatan', 'body' => 'Klik + Tambah, taip nama jabatan dan klik Tambah. Satu sudah cukup untuk mula; staf dikumpulkan di bawah ini.'],
        ])

        <div class="uj-cs-cb">
            @if ($canManageFeatures)
                <form x-show="adding" x-cloak method="post" action="{{ route('admin.departments.store') }}" class="uj-cs-panel uj-cs-inline">
                    @csrf
                    <input name="name" required :placeholder="$store.ui.lang==='en'?'Department name':'Nama jabatan'" class="uj-cs-inp" style="flex:1;" />
                    <button type="submit" class="uj-btn-primary uj-cs-inline-btn"><span x-text="$store.ui.lang==='en'?'Add':'Tambah'">Add</span></button>
                </form>
            @endif

            <div class="uj-cs-list">
            @forelse ($departments as $d)
                <div class="uj-cs-item">
                    <div @if ($canManageFeatures) x-show="editId !== {{ $d->id }}" @endif class="uj-cs-row">
                        <span class="uj-cs-nm">{{ $d->name }}</span>
                        <span class="uj-cs-num" title="{{ $d->employees_count }} {{ __('employees') }}">{{ $d->employees_count }}</span>
                        @if ($canManageFeatures)
                            <span class="uj-cs-acts">
                                <button type="button" class="uj-ww-ico" @click="editId={{ $d->id }};adding=false" :aria-label="$store.ui.lang==='en' ? 'Edit {{ e(addslashes($d->name)) }}' : 'Sunting {{ e(addslashes($d->name)) }}'">{!! $editIcon !!}</button>
                                <button type="submit" form="del-dept-{{ $d->id }}" class="uj-ww-ico uj-cs-del" @disabled($d->employees_count > 0)
                                        @if ($d->employees_count > 0) :title="$store.ui.lang==='en' ? 'Move its staff to another department first' : 'Pindahkan stafnya ke jabatan lain dahulu'" @endif
                                        :aria-label="$store.ui.lang==='en' ? 'Delete {{ e(addslashes($d->name)) }}' : 'Padam {{ e(addslashes($d->name)) }}'">{!! $deleteIcon !!}</button>
                            </span>
                        @endif
                    </div>
                    @if ($canManageFeatures)
                        <form x-show="editId === {{ $d->id }}" x-cloak method="post" action="{{ route('admin.departments.update', $d) }}" class="uj-cs-panel uj-cs-inline">
                            @csrf
                            <input name="name" value="{{ $d->name }}" required class="uj-cs-inp" style="flex:1;" />
                            <button type="submit" class="uj-btn-primary uj-cs-inline-btn"><span x-text="$store.ui.lang==='en'?'Save':'Simpan'">Save</span></button>
                            <button type="button" @click="editId=null" class="uj-cs-cancel" x-text="$store.ui.lang==='en'?'Cancel':'Batal'">Cancel</button>
                        </form>
                    @endif
                </div>
            @empty
                <p class="uj-cs-empty" x-text="$store.ui.lang==='en'?'No departments yet.':'Tiada jabatan lagi.'">No departments yet.</p>
            @endforelse
            </div>

            @if ($canManageFeatures)
                @foreach ($departments as $d)
                    <form id="del-dept-{{ $d->id }}" method="post" action="{{ route('admin.departments.delete', $d) }}" onsubmit="return confirm('Delete {{ addslashes($d->name) }}?')">@csrf</form>
                @endforeach
            @endif
        </div>
    </section>
    @endif

    @if (! $only || $only === 'staff-levels')
    {{-- Staff levels (grades): name + optional code, ordered by rank. Blocked from delete while in use. --}}
    <section id="staff-levels" class="uj-card uj-cs-sec" @if ($canManageFeatures) x-data="{ adding:false, editId:null }" @endif>
        <div class="uj-cs-ch">
            <div>
                <h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Staff levels' : 'Tahap staf'">Staff levels</h3>
                <p class="uj-cs-sub" x-text="$store.ui.lang==='en' ? 'Ordered by seniority, 1 first.' : 'Disusun mengikut kekananan, 1 dahulu.'">Ordered by seniority, 1 first.</p>
            </div>
            @if ($canManageFeatures)
                <button type="button" @click="adding=!adding" class="uj-btn-ghost uj-cs-add-btn">
                    <span x-text="adding ? ($store.ui.lang==='en'?'Cancel':'Batal') : ($store.ui.lang==='en'?'+ Add':'+ Tambah')">+ Add</span>
                </button>
            @endif
        </div>
        <div class="uj-cs-cb">
            @if ($canManageFeatures)
                <form x-show="adding" x-cloak method="post" action="{{ route('admin.staff-levels.store') }}" class="uj-cs-panel uj-cs-inline">
                    @csrf
                    <input name="name" required :placeholder="$store.ui.lang==='en'?'Level (e.g. L3)':'Tahap (cth. L3)'" class="uj-cs-inp" style="flex:2;" />
                    <input name="code" :placeholder="$store.ui.lang==='en'?'Code':'Kod'" class="uj-cs-inp" style="flex:1;" />
                    <input name="rank" type="number" min="0" max="65535" :placeholder="$store.ui.lang==='en'?'Seniority (1=most senior)':'Kekananan (1=paling kanan)'" class="uj-cs-inp" style="flex:1.4;" />
                    <button type="submit" class="uj-btn-primary uj-cs-inline-btn"><span x-text="$store.ui.lang==='en'?'Add':'Tambah'">Add</span></button>
                </form>
                <p x-show="adding" x-cloak class="uj-cs-hint" style="margin:-4px 0 12px;" x-text="$store.ui.lang==='en'?'A smaller number means more senior. Staff can open the full profile of anyone on a more junior level.':'Nombor lebih kecil bermaksud lebih kanan. Staf boleh membuka profil penuh sesiapa di tahap yang lebih rendah.'">A smaller number means more senior. Staff can open the full profile of anyone on a more junior level.</p>
            @endif
            <div class="uj-cs-list">
            @forelse ($staffLevels as $lv)
                <div class="uj-cs-item">
                    <div @if ($canManageFeatures) x-show="editId !== {{ $lv->id }}" @endif class="uj-cs-row">
                        <span class="uj-cs-rank uj-ww-mono" title="{{ __('Seniority') }}">{{ $lv->rank ?? '–' }}</span>
                        <span class="uj-cs-nm">{{ $lv->name }}@if ($lv->code)<small class="uj-ww-mono">{{ $lv->code }}</small>@endif</span>
                        @if ($canManageFeatures)
                            <span class="uj-cs-acts">
                                <button type="button" class="uj-ww-ico" @click="editId={{ $lv->id }};adding=false" :aria-label="$store.ui.lang==='en' ? 'Edit {{ e(addslashes($lv->name)) }}' : 'Sunting {{ e(addslashes($lv->name)) }}'">{!! $editIcon !!}</button>
                                <button type="submit" form="del-lv-{{ $lv->id }}" class="uj-ww-ico uj-cs-del" :aria-label="$store.ui.lang==='en' ? 'Delete {{ e(addslashes($lv->name)) }}' : 'Padam {{ e(addslashes($lv->name)) }}'">{!! $deleteIcon !!}</button>
                            </span>
                        @endif
                    </div>
                    @if ($canManageFeatures)
                        <form x-show="editId === {{ $lv->id }}" x-cloak method="post" action="{{ route('admin.staff-levels.update', $lv) }}" class="uj-cs-panel uj-cs-inline">
                            @csrf
                            <input name="name" value="{{ $lv->name }}" required class="uj-cs-inp" style="flex:2;" />
                            <input name="code" value="{{ $lv->code }}" :placeholder="$store.ui.lang==='en'?'Code':'Kod'" class="uj-cs-inp" style="flex:1;" />
                            <input name="rank" type="number" min="0" max="65535" value="{{ $lv->rank }}" :placeholder="$store.ui.lang==='en'?'Seniority (1=most senior)':'Kekananan (1=paling kanan)'" class="uj-cs-inp" style="flex:1.4;" />
                            <button type="submit" class="uj-btn-primary uj-cs-inline-btn"><span x-text="$store.ui.lang==='en'?'Save':'Simpan'">Save</span></button>
                            <button type="button" @click="editId=null" class="uj-cs-cancel" x-text="$store.ui.lang==='en'?'Cancel':'Batal'">Cancel</button>
                        </form>
                        <p x-show="editId === {{ $lv->id }}" x-cloak class="uj-cs-hint" style="margin:-4px 0 8px;" x-text="$store.ui.lang==='en'?'A smaller number means more senior. Staff can open the full profile of anyone on a more junior level.':'Nombor lebih kecil bermaksud lebih kanan. Staf boleh membuka profil penuh sesiapa di tahap yang lebih rendah.'">A smaller number means more senior. Staff can open the full profile of anyone on a more junior level.</p>
                    @endif
                </div>
            @empty
                <p class="uj-cs-empty" x-text="$store.ui.lang==='en'?'No staff levels yet.':'Tiada tahap staf lagi.'">No staff levels yet.</p>
            @endforelse
            </div>
            @if ($canManageFeatures)
                @foreach ($staffLevels as $lv)
                    <form id="del-lv-{{ $lv->id }}" method="post" action="{{ route('admin.staff-levels.delete', $lv) }}" onsubmit="return confirm('Delete {{ addslashes($lv->name) }}?')">@csrf</form>
                @endforeach
            @endif
        </div>
    </section>
    @endif
    </div>
    @endif

    @if (! $only || $only === 'employment-types')
    {{-- Employment types: Full-time, Contract, Part-time, etc. No clock-in types skip reminders and late/absent. --}}
    <section id="employment-types" class="uj-card uj-cs-sec" @if ($canManageFeatures) x-data="{ adding:false, editId:null }" @endif>
        <div class="uj-cs-ch">
            <div>
                <h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Employment types' : 'Jenis pekerjaan'">Employment types</h3>
                <p class="uj-cs-sub" x-text="$store.ui.lang==='en' ? 'Permanent, contract, intern and so on.' : 'Tetap, kontrak, pelatih dan sebagainya.'">Permanent, contract, intern and so on.</p>
            </div>
            @if ($canManageFeatures)
                <button type="button" @click="adding=!adding" class="uj-btn-ghost uj-cs-add-btn">
                    <span x-text="adding ? ($store.ui.lang==='en'?'Cancel':'Batal') : ($store.ui.lang==='en'?'+ Add':'+ Tambah')">+ Add</span>
                </button>
            @endif
        </div>
        <div class="uj-cs-cb">
            @if ($canManageFeatures)
                <form x-show="adding" x-cloak method="post" action="{{ route('admin.employment-types.store') }}" class="uj-cs-panel uj-cs-inline">
                    @csrf
                    <input name="name" required :placeholder="$store.ui.lang==='en'?'Type (e.g. Full-time)':'Jenis (cth. Sepenuh masa)'" class="uj-cs-inp" style="flex:2;" />
                    <input name="code" :placeholder="$store.ui.lang==='en'?'Code':'Kod'" class="uj-cs-inp" style="flex:1;" />
                    <label class="uj-cs-check" :title="$store.ui.lang==='en'?'Staff on this type keep their own hours: no clock-in reminders, never late or absent':'Staf jenis ini ikut masa sendiri: tiada peringatan, tidak dikira lewat atau tidak hadir'"><input type="checkbox" name="clock_exempt" value="1" /> <span x-text="$store.ui.lang==='en'?'No clock-in':'Tiada clock-in'">No clock-in</span></label>
                    <button type="submit" class="uj-btn-primary uj-cs-inline-btn"><span x-text="$store.ui.lang==='en'?'Add':'Tambah'">Add</span></button>
                </form>
            @endif
            <div class="uj-cs-list">
            @forelse ($employmentTypes as $et)
                <div class="uj-cs-item">
                    <div @if ($canManageFeatures) x-show="editId !== {{ $et->id }}" @endif class="uj-cs-row">
                        <span class="uj-cs-nm">{{ $et->name }}@if ($et->code)<small class="uj-ww-mono">{{ $et->code }}</small>@endif</span>
                        @if ($et->clock_exempt)<span class="uj-stamp" :title="$store.ui.lang==='en'?'No clock-in reminders, never late or absent':'Tiada peringatan, tidak dikira lewat atau tidak hadir'" x-text="$store.ui.lang==='en'?'No clock-in':'Tiada clock-in'">No clock-in</span>@endif
                        @if ($canManageFeatures)
                            <span class="uj-cs-acts">
                                <button type="button" class="uj-ww-ico" @click="editId={{ $et->id }};adding=false" :aria-label="$store.ui.lang==='en' ? 'Edit {{ e(addslashes($et->name)) }}' : 'Sunting {{ e(addslashes($et->name)) }}'">{!! $editIcon !!}</button>
                                <button type="submit" form="del-et-{{ $et->id }}" class="uj-ww-ico uj-cs-del" :aria-label="$store.ui.lang==='en' ? 'Delete {{ e(addslashes($et->name)) }}' : 'Padam {{ e(addslashes($et->name)) }}'">{!! $deleteIcon !!}</button>
                            </span>
                        @endif
                    </div>
                    @if ($canManageFeatures)
                        <form x-show="editId === {{ $et->id }}" x-cloak method="post" action="{{ route('admin.employment-types.update', $et) }}" class="uj-cs-panel uj-cs-inline">
                            @csrf
                            <input name="name" value="{{ $et->name }}" required class="uj-cs-inp" style="flex:2;" />
                            <input name="code" value="{{ $et->code }}" :placeholder="$store.ui.lang==='en'?'Code':'Kod'" class="uj-cs-inp" style="flex:1;" />
                            <label class="uj-cs-check"><input type="checkbox" name="clock_exempt" value="1" @checked($et->clock_exempt) /> <span x-text="$store.ui.lang==='en'?'No clock-in':'Tiada clock-in'">No clock-in</span></label>
                            <button type="submit" class="uj-btn-primary uj-cs-inline-btn"><span x-text="$store.ui.lang==='en'?'Save':'Simpan'">Save</span></button>
                            <button type="button" @click="editId=null" class="uj-cs-cancel" x-text="$store.ui.lang==='en'?'Cancel':'Batal'">Cancel</button>
                        </form>
                    @endif
                </div>
            @empty
                <p class="uj-cs-empty" x-text="$store.ui.lang==='en'?'No employment types yet.':'Tiada jenis pekerjaan lagi.'">No employment types yet.</p>
            @endforelse
            </div>
            @if ($canManageFeatures)
                @foreach ($employmentTypes as $et)
                    <form id="del-et-{{ $et->id }}" method="post" action="{{ route('admin.employment-types.delete', $et) }}" onsubmit="return confirm('Delete {{ addslashes($et->name) }}?')">@csrf</form>
                @endforeach
            @endif
        </div>
    </section>
    @endif


    @if (!empty($canManageFeatures) && (! $only || $only === 'features'))
    <section id="features" class="uj-card uj-cs-sec">
        <form method="post" action="{{ route('admin.features.update') }}">
            @csrf
            <input type="hidden" name="features_present" value="1">
            <div class="uj-cs-ch">
                <div>
                    <h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Features' : 'Ciri'">Features</h3>
                    <p class="uj-cs-sub" x-text="$store.ui.lang==='en' ? 'Turn modules on or off for this company and tune how they behave. Locked ones are set by the platform team.' : 'Hidup atau matikan modul untuk syarikat ini dan laras tingkah lakunya. Yang dikunci ditetapkan oleh pasukan platform.'">Turn modules on or off for this company and tune how they behave. Locked ones are set by the platform team.</p>
                </div>
            </div>
            <div class="uj-cs-cb">
                @include('partials.hint', ['en' => 'Disabling a module hides it from the menu for everyone and blocks its screens. Locked features are controlled centrally by the platform team.', 'ms' => 'Mematikan modul akan menyembunyikannya dari menu untuk semua orang dan menyekat skrinnya. Ciri yang dikunci dikawal secara berpusat oleh pasukan platform.'])

                {{-- Grouped by sidebar section so each toggle maps to where it lives in the
                     nav. Section heading + the per-toggle "Controls:" caption come from
                     AppController::navScreenIndex(). --}}
                @foreach ($featureRows['modules'] as $group)
                    <h4 class="uj-cs-modg" x-text="$store.ui.lang==='en' ? @js($group['section']) : @js($group['section_ms'])">{{ $group['section'] }}</h4>
                    <div class="uj-cs-mods">
                        @foreach ($group['rows'] as $row)
                            @php
                                $navEn = implode(' · ', array_map(fn ($n) => $n['en'], $row['nav_items']));
                                $navMs = implode(' · ', array_map(fn ($n) => $n['ms'], $row['nav_items']));
                                $showNav = count($row['nav_items']) > 1;
                            @endphp
                            <label class="uj-cs-mod" style="cursor:{{ $row['locked'] ? 'not-allowed' : 'pointer' }};">
                                <span class="t">
                                    <b>
                                        <span x-text="$store.ui.lang==='en' ? @js($row['label']) : @js($row['label_ms'])">{{ $row['label'] }}</span>
                                        @if ($row['locked'])<span class="uj-stamp">{!! $lockIcon !!}<span x-text="$store.ui.lang==='en' ? 'Locked' : 'Dikunci'">Locked</span></span>@endif
                                    </b>
                                    @if ($showNav)
                                        <span x-text="$store.ui.lang==='en' ? @js('Controls: '.$navEn) : @js('Mengawal: '.$navMs)">Controls: {{ $navEn }}</span>
                                    @endif
                                </span>
                                <span class="uj-switch">
                                    <input type="checkbox" name="features[{{ $row['key'] }}]" value="1"
                                        @checked(\App\Support\Features::asBool($row['value']))
                                        @disabled($row['locked'])><i></i>
                                </span>
                            </label>
                        @endforeach
                    </div>
                @endforeach

                <h4 class="uj-cs-modg uj-cs-modg--settings" x-text="$store.ui.lang==='en' ? 'Settings' : 'Tetapan'">Settings</h4>
                @foreach ($featureRows['settings'] as $row)
                    <div class="uj-cs-setr">
                        <div>
                            <b>
                                <span x-text="$store.ui.lang==='en' ? @js($row['label']) : @js($row['label_ms'])">{{ $row['label'] }}</span>
                                @if ($row['locked'])<span class="uj-stamp">{!! $lockIcon !!}<span x-text="$store.ui.lang==='en' ? 'Locked' : 'Dikunci'">Locked</span></span>@endif
                            </b>
                            @if (!empty($row['help']))<span x-text="$store.ui.lang==='en' ? @js($row['help']) : @js($row['help_ms'])">{{ $row['help'] }}</span>@endif
                        </div>
                        <div>
                            @if ($row['type'] === 'enum')
                                <select name="features[{{ $row['key'] }}]" @disabled($row['locked']) class="uj-cs-inp uj-cs-inp--sm" :aria-label="$store.ui.lang==='en' ? @js($row['label']) : @js($row['label_ms'])">
                                    @foreach ($row['options'] as $val => $optLabel)
                                        <option value="{{ $val }}" @selected((string) $row['value'] === (string) $val) x-text="$store.ui.lang==='en' ? @js($optLabel) : @js($row['options_ms'][$val] ?? $optLabel)">{{ $optLabel }}</option>
                                    @endforeach
                                </select>
                            @elseif ($row['type'] === 'number')
                                <input type="number" name="features[{{ $row['key'] }}]" value="{{ $row['value'] }}" @disabled($row['locked'])
                                    step="1" min="{{ $row['min'] ?? 0 }}" @if (! is_null($row['max']))max="{{ $row['max'] }}"@endif
                                    class="uj-cs-inp uj-cs-inp--sm uj-ww-mono" :aria-label="$store.ui.lang==='en' ? @js($row['label']) : @js($row['label_ms'])">
                            @else
                                <label class="uj-cs-check" style="cursor:{{ $row['locked'] ? 'not-allowed' : 'pointer' }};">
                                    <span class="uj-switch">
                                        <input type="checkbox" name="features[{{ $row['key'] }}]" value="1"
                                            @checked(\App\Support\Features::asBool($row['value']))
                                            @disabled($row['locked'])><i></i>
                                    </span>
                                    <span x-text="$store.ui.lang==='en' ? 'Enabled' : 'Dihidupkan'">Enabled</span>
                                </label>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="uj-cs-cf">
                <span></span>
                <button type="submit" class="uj-btn-primary uj-cs-save"><span x-text="$store.ui.lang==='en' ? 'Save features' : 'Simpan ciri'">Save features</span></button>
            </div>
            @include('partials.coachmark', [
                'key' => 'guide-modules',
                'when' => "\$store.guide.current === 'modules'",
                'en' => ['title' => 'Switch on what you use', 'body' => 'Tick the modules your company uses, then click Save features. Untick the ones you don\'t need.'],
                'ms' => ['title' => 'Hidupkan yang anda guna', 'body' => 'Tandakan modul yang syarikat anda guna, kemudian klik Simpan ciri. Buang tanda pada modul yang tidak perlu.'],
            ])
        </form>
    </section>
    @endif

    @if (! $only || $only === 'greetings')
    {{-- CR-33: rotating dashboard greeting bank. HR approves/edits/deletes; any
         employee can suggest a line from the dashboard picker. --}}
    <div id="greetings" class="uj-cs-sec">
    @include('partials.line-bank', [
        'title_en' => 'Dashboard greetings', 'title_ms' => 'Ucapan papan pemuka',
        'hint_en' => 'These lines rotate on everyone\'s dashboard greeting. Use {name} where the person\'s first name should go.',
        'hint_ms' => 'Baris ini berputar pada ucapan papan pemuka semua orang. Guna {name} di tempat nama pertama orang itu patut muncul.',
        'empty_en' => 'No greeting lines yet.', 'empty_ms' => 'Tiada ucapan lagi.',
        'field' => 'trigger', 'routes' => 'admin.greetings',
        'lines' => $greetingLines, 'pending' => $greetingPending, 'categories' => $greetingTriggers,
        'buckets' => ['personal' => ['Personal', 'Peribadi'], 'situation' => ['Situation', 'Situasi'], 'day' => ['Day', 'Hari'], 'time' => ['Time', 'Masa']],
        'defaultBucket' => 'situation', 'canManage' => $canManageFeatures,
    ])
    </div>
    @endif

    @if (! $only || $only === 'eggs')
    {{-- CR-31: dashboard/board easter-egg bank, same card as the greetings above. --}}
    @php
        $eggKindLabels = [
            'friday_late' => ['Friday after 5', 'Jumaat selepas 5'], 'inbox_zero' => ['Inbox zero', 'Inbox kosong'],
            'late_night' => ['Late night', 'Lewat malam'], 'tab_collector' => ['Tab collector', 'Pengumpul tab'], 'holiday_eve' => ['Holiday eve', 'Malam cuti'],
        ];
    @endphp
    <div id="eggs" class="uj-cs-sec">
    @include('partials.line-bank', [
        'title_en' => 'Dashboard easter eggs', 'title_ms' => 'Telur Paskah papan pemuka',
        'hint_en' => 'Small surprises shown on the dashboard or board, at most once a day per person. Never blocks anything.',
        'hint_ms' => 'Kejutan kecil yang dipapar pada papan pemuka atau board, paling banyak sekali sehari bagi setiap orang. Tidak menyekat apa-apa.',
        'empty_en' => 'No easter eggs yet.', 'empty_ms' => 'Tiada telur Paskah lagi.',
        'field' => 'kind', 'routes' => 'admin.eggs', 'lines' => $easterEggs,
        'categories' => collect($easterEggKinds)->mapWithKeys(fn ($k) => [$k => ['label_en' => $eggKindLabels[$k][0] ?? $k, 'label_ms' => $eggKindLabels[$k][1] ?? $k]])->all(),
        'canManage' => $canManageFeatures,
    ])
    </div>
    @endif

    @if (!empty($canManageFeatures) && (! $only || $only === 'reactions'))
    {{-- CR-30: the tenant's reaction set. Add or retire, never rename, ten active at most. --}}
    @php
        $reactionSet = \App\Models\Reaction::set();
        $activeReactions = $reactionSet->whereNull('retired_at')->count();
        $maxReactions = \App\Models\Reaction::MAX_ACTIVE;
    @endphp
    <section id="reactions" class="uj-card uj-cs-sec" x-data="{ adding:false }">
        <div class="uj-cs-ch">
            <div>
                <h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Reactions' : 'Reaksi'">Reactions</h3>
                <p class="uj-cs-sub" x-text="$store.ui.lang==='en' ? 'Retiring one keeps it on the items it was already given. Nothing is ever renamed.' : 'Reaksi yang dibersarakan kekal pada item lama. Tiada yang dinamakan semula.'">Retiring one keeps it on the items it was already given. Nothing is ever renamed.</p>
            </div>
            @if ($activeReactions < $maxReactions)
                <button type="button" @click="adding=!adding" class="uj-btn-ghost uj-cs-add-btn"><span x-text="adding ? ($store.ui.lang==='en'?'Cancel':'Batal') : ($store.ui.lang==='en' ? '+ Add reaction' : '+ Tambah reaksi')">+ Add reaction</span></button>
            @endif
        </div>
        <div class="uj-cs-cb">
            <div class="uj-cs-meter">
                <span class="bar" aria-hidden="true">@for ($i = 0; $i < $maxReactions; $i++)<i class="{{ $i < $activeReactions ? 'on' : '' }}"></i>@endfor</span>
                <span><span class="uj-ww-mono" style="color:var(--ink);">{{ $activeReactions }}</span> <span x-text="$store.ui.lang==='en' ? 'of' : 'daripada'">of</span> <span class="uj-ww-mono">{{ $maxReactions }}</span> <span x-text="$store.ui.lang==='en' ? 'active' : 'aktif'">active</span></span>
            </div>
            @php $rfs = 'height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:12.5px;outline:none;background:#fff;color:var(--ink);min-width:0;'; @endphp
            <form x-show="adding" x-cloak method="post" action="{{ route('admin.reactions.store') }}" class="uj-cs-panel uj-cs-inline">
                @csrf
                <input name="icon" required maxlength="16" placeholder="Icon (emoji or 1-2 letters)" style="{{ $rfs }}width:200px;" />
                <input name="label" required maxlength="60" placeholder="Label, e.g. GOAT" style="{{ $rfs }}width:200px;" />
                <input name="key" required maxlength="40" pattern="[a-z][a-z0-9_]*" placeholder="key, e.g. goat" style="{{ $rfs }}width:160px;" />
                <button type="submit" class="uj-btn-primary" style="height:36px;padding:0 16px;font-size:12.5px;"><span x-text="$store.ui.lang==='en'?'Add':'Tambah'">Add</span></button>
            </form>
            <div class="uj-cs-react">
                @foreach ($reactionSet as $r)
                    <div class="uj-cs-rc {{ $r->retired_at ? 'retired' : '' }}" data-reaction-row="{{ $r->key }}">
                        <span class="e" aria-hidden="true">{{ $r->icon }}</span>
                        <span class="t">{{ $r->label }}<small>{{ $r->key }}</small></span>
                        @if ($r->retired_at)
                            <span class="uj-stamp" x-text="$store.ui.lang==='en' ? 'Retired' : 'Bersara'">Retired</span>
                        @else
                            <form method="post" action="{{ route('admin.reactions.retire', $r->key) }}" onsubmit="return confirm('Retire {{ addslashes($r->label) }}? Old items keep showing it.')" style="margin:0;">@csrf<button type="submit" class="uj-ww-ico" :title="$store.ui.lang==='en' ? 'Retire' : 'Bersarakan'" :aria-label="($store.ui.lang==='en' ? 'Retire ' : 'Bersarakan ') + @js($r->label)"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="5" rx="1"/><path d="M5 9v10h14V9M10 13h4"/></svg></button></form>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    </section>
    @endif
    </div>
</div>
@endsection
