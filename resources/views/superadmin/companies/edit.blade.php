<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit {{ $company->name }} · Amanahku Admin</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        .fld{display:block;margin-bottom:18px;}
        .fld label{display:block;font-size:13px;font-weight:600;color:var(--ink);margin-bottom:6px;}
        .fld .hint{font-size:12px;color:var(--muted);font-weight:400;margin-left:6px;}
        .fld input,.fld select{width:100%;padding:11px 13px;border:1px solid var(--hairline,#e6e6ec);border-radius:10px;font-size:14px;background:#fff;color:var(--ink);font-family:inherit;}
        .fld input:focus,.fld select:focus{outline:none;border-color:var(--red);box-shadow:0 0 0 3px rgba(214,35,43,.12);}
        .err{color:var(--red);font-size:12.5px;margin-top:5px;}
        .sect{font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:var(--muted);font-weight:700;margin:26px 0 12px;}
        .row{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
    </style>
</head>
<body>
<div style="min-height:100vh;background:var(--canvas);padding:48px 24px;">
    <div style="max-width:560px;margin:0 auto;">
        <a href="{{ route('superadmin.companies.show', $company) }}" style="font-size:13px;color:var(--muted);text-decoration:none;">← {{ $company->name }}</a>

        <h1 style="font-weight:400;font-size:26px;letter-spacing:-0.5px;color:var(--ink);margin:18px 0 6px;">Edit company</h1>
        <p style="font-size:14px;color:var(--muted);margin:0 0 24px;">Super-admin owns every field here, including slug, status and subscription. Category is changed from the company page.</p>

        @if ($errors->any())
            <div style="background:#fbeaeb;border:1px solid #f3c6c8;color:#a81820;border-radius:10px;padding:12px 16px;margin-bottom:18px;font-size:13.5px;">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('superadmin.companies.update', $company) }}" style="background:var(--surface,#fff);border:1px solid var(--hairline,#e6e6ec);border-radius:14px;padding:24px;">
            @csrf

            <div class="sect">Company</div>
            <div class="fld">
                <label>Company name</label>
                <input name="company_name" value="{{ old('company_name', $company->name) }}" required>
                @error('company_name')<div class="err">{{ $message }}</div>@enderror
            </div>
            <div class="row">
                <div class="fld">
                    <label>Tenant slug <span class="hint">login URL</span></label>
                    <input name="slug" value="{{ old('slug', $company->slug) }}" required>
                    @error('slug')<div class="err">{{ $message }}</div>@enderror
                </div>
                <div class="fld">
                    <label>Status</label>
                    <select name="status" required>
                        <option value="active" @selected(old('status', $company->status)==='active')>Active</option>
                        <option value="suspended" @selected(old('status', $company->status)==='suspended')>Suspended</option>
                    </select>
                    @error('status')<div class="err">{{ $message }}</div>@enderror
                </div>
            </div>
            <div class="row">
                <div class="fld">
                    <label>Plan</label>
                    <select name="plan" required>
                        @foreach ($plans as $p)
                            <option value="{{ $p }}" @selected(old('plan', $company->plan)===$p)>{{ $p }}</option>
                        @endforeach
                    </select>
                    @error('plan')<div class="err">{{ $message }}</div>@enderror
                </div>
                <div class="fld">
                    <label>Registration no. <span class="hint">optional</span></label>
                    <input name="registration_number" value="{{ old('registration_number', $company->registration_number) }}">
                    @error('registration_number')<div class="err">{{ $message }}</div>@enderror
                </div>
            </div>
            <div class="row">
                <div class="fld">
                    <label>Company code <span class="hint">unique</span></label>
                    <input name="company_code" value="{{ old('company_code', $company->company_code) }}">
                    @error('company_code')<div class="err">{{ $message }}</div>@enderror
                </div>
                <div class="fld">
                    <label>Industry</label>
                    <input name="industry" value="{{ old('industry', $company->industry) }}">
                    @error('industry')<div class="err">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="sect">Branding</div>
            <div class="row">
                <div class="fld">
                    <label>Brand colour <span class="hint">hex</span></label>
                    <input name="color" value="{{ old('color', $company->color) }}">
                    @error('color')<div class="err">{{ $message }}</div>@enderror
                </div>
                <div class="fld">
                    <label>Secondary colour <span class="hint">hex</span></label>
                    <input name="secondary_color" value="{{ old('secondary_color', $company->secondary_color) }}">
                    @error('secondary_color')<div class="err">{{ $message }}</div>@enderror
                </div>
            </div>
            <div class="fld">
                <label>Welcome message</label>
                <input name="welcome_message" value="{{ old('welcome_message', $company->welcome_message) }}">
                @error('welcome_message')<div class="err">{{ $message }}</div>@enderror
            </div>

            <div class="sect">Contact &amp; subscription</div>
            <div class="fld">
                <label>Address</label>
                <input name="address" value="{{ old('address', $company->address) }}">
                @error('address')<div class="err">{{ $message }}</div>@enderror
            </div>
            <div class="row">
                <div class="fld">
                    <label>Contact number</label>
                    <input name="contact_number" value="{{ old('contact_number', $company->contact_number) }}">
                    @error('contact_number')<div class="err">{{ $message }}</div>@enderror
                </div>
                <div class="fld">
                    <label>Email</label>
                    <input type="email" name="email" value="{{ old('email', $company->email) }}">
                    @error('email')<div class="err">{{ $message }}</div>@enderror
                </div>
            </div>
            <div class="fld">
                <label>Website</label>
                <input name="website" value="{{ old('website', $company->website) }}">
                @error('website')<div class="err">{{ $message }}</div>@enderror
            </div>
            <div class="row">
                <div class="fld">
                    <label>Subscription start</label>
                    <input type="date" name="subscription_start" value="{{ old('subscription_start', optional($company->subscription_start)->toDateString()) }}">
                    @error('subscription_start')<div class="err">{{ $message }}</div>@enderror
                </div>
                <div class="fld">
                    <label>Subscription end</label>
                    <input type="date" name="subscription_end" value="{{ old('subscription_end', optional($company->subscription_end)->toDateString()) }}">
                    @error('subscription_end')<div class="err">{{ $message }}</div>@enderror
                </div>
            </div>

            <button type="submit" class="uj-btn" style="width:100%;margin-top:8px;padding:12px;border:none;border-radius:10px;font-size:14px;font-weight:600;background:var(--red);color:#fff;cursor:pointer;">Save changes</button>
        </form>
    </div>
</div>
</body>
</html>
