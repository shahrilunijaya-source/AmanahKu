<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>New company · Amanahku Admin</title>
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
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:28px;">
            <div style="width:30px;height:30px;border-radius:7px;background:var(--red);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:16px;">A</div>
            <span style="font-weight:600;font-size:18px;color:var(--ink);">Amanah<span style="color:var(--red);">ku</span></span>
            <span style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.6px;color:var(--muted);background:var(--hairline-soft);padding:4px 9px;border-radius:9999px;margin-left:4px;">Super Admin</span>
        </div>

        <h1 style="font-weight:400;font-size:26px;letter-spacing:-0.5px;color:var(--ink);margin:0 0 6px;">Provision a new company</h1>
        <p style="font-size:14px;color:var(--muted);margin:0 0 24px;">Creates the workspace and seeds its first HR admin with a one-time password.</p>

        @if ($errors->any())
            <div style="background:#fbeaeb;border:1px solid #f3c6c8;color:#a81820;border-radius:10px;padding:12px 16px;margin-bottom:18px;font-size:13.5px;">Please fix the highlighted fields below.</div>
        @endif

        <form method="POST" action="{{ route('superadmin.companies.store') }}" style="background:var(--surface,#fff);border:1px solid var(--hairline,#e6e6ec);border-radius:14px;padding:24px;">
            @csrf

            <div class="sect">Company</div>
            <div class="fld">
                <label>Company name</label>
                <input name="company_name" value="{{ old('company_name') }}" placeholder="Acme Sdn Bhd" required>
                @error('company_name')<div class="err">{{ $message }}</div>@enderror
            </div>
            <div class="fld">
                <label>Category <span class="hint">sets the default feature package — change anytime</span></label>
                <select name="company_category_id" required>
                    @foreach ($categories as $cat)
                        <option value="{{ $cat->id }}" @selected((int) old('company_category_id', $categories->firstWhere('level', 1)?->id) === $cat->id)>{{ $cat->name }}</option>
                    @endforeach
                </select>
                @error('company_category_id')<div class="err">{{ $message }}</div>@enderror
            </div>
            <div class="row">
                <div class="fld">
                    <label>Plan</label>
                    <select name="plan" required>
                        @foreach ($plans as $p)
                            <option value="{{ $p }}" @selected(old('plan')===$p)>{{ $p }}</option>
                        @endforeach
                    </select>
                    @error('plan')<div class="err">{{ $message }}</div>@enderror
                </div>
                <div class="fld">
                    <label>Registration no. <span class="hint">optional</span></label>
                    <input name="registration_number" value="{{ old('registration_number') }}" placeholder="202301012345">
                    @error('registration_number')<div class="err">{{ $message }}</div>@enderror
                </div>
            </div>
            <div class="row">
                <div class="fld">
                    <label>Company code <span class="hint">optional, unique</span></label>
                    <input name="company_code" value="{{ old('company_code') }}" placeholder="ACME">
                    @error('company_code')<div class="err">{{ $message }}</div>@enderror
                </div>
                <div class="fld">
                    <label>Industry <span class="hint">optional</span></label>
                    <input name="industry" value="{{ old('industry') }}" placeholder="Retail">
                    @error('industry')<div class="err">{{ $message }}</div>@enderror
                </div>
            </div>
            <div class="row">
                <div class="fld">
                    <label>Brand colour <span class="hint">hex</span></label>
                    <input name="color" value="{{ old('color', '#d6232b') }}" placeholder="#d6232b">
                    @error('color')<div class="err">{{ $message }}</div>@enderror
                </div>
                <div class="fld">
                    <label>Secondary colour <span class="hint">hex, optional</span></label>
                    <input name="secondary_color" value="{{ old('secondary_color') }}" placeholder="#1f1e1a">
                    @error('secondary_color')<div class="err">{{ $message }}</div>@enderror
                </div>
            </div>
            <div class="fld">
                <label>Welcome message <span class="hint">shown on the company login page</span></label>
                <input name="welcome_message" value="{{ old('welcome_message') }}" placeholder="Welcome to the Acme workspace.">
                @error('welcome_message')<div class="err">{{ $message }}</div>@enderror
            </div>

            <div class="sect">Contact &amp; subscription <span class="hint" style="text-transform:none;letter-spacing:0;font-weight:400;">optional</span></div>
            <div class="fld">
                <label>Address</label>
                <input name="address" value="{{ old('address') }}" placeholder="Level 10, Menara Acme, Kuala Lumpur">
                @error('address')<div class="err">{{ $message }}</div>@enderror
            </div>
            <div class="row">
                <div class="fld">
                    <label>Contact number</label>
                    <input name="contact_number" value="{{ old('contact_number') }}" placeholder="+60 3-1234 5678">
                    @error('contact_number')<div class="err">{{ $message }}</div>@enderror
                </div>
                <div class="fld">
                    <label>Email</label>
                    <input type="email" name="email" value="{{ old('email') }}" placeholder="hello@acme.com">
                    @error('email')<div class="err">{{ $message }}</div>@enderror
                </div>
            </div>
            <div class="fld">
                <label>Website</label>
                <input name="website" value="{{ old('website') }}" placeholder="https://acme.com">
                @error('website')<div class="err">{{ $message }}</div>@enderror
            </div>
            <div class="row">
                <div class="fld">
                    <label>Subscription start</label>
                    <input type="date" name="subscription_start" value="{{ old('subscription_start') }}">
                    @error('subscription_start')<div class="err">{{ $message }}</div>@enderror
                </div>
                <div class="fld">
                    <label>Subscription end</label>
                    <input type="date" name="subscription_end" value="{{ old('subscription_end') }}">
                    @error('subscription_end')<div class="err">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="sect">First branch & department</div>
            <div class="row">
                <div class="fld">
                    <label>Branch name</label>
                    <input name="branch_name" value="{{ old('branch_name', 'Head Office') }}" required>
                    @error('branch_name')<div class="err">{{ $message }}</div>@enderror
                </div>
                <div class="fld">
                    <label>State <span class="hint">optional</span></label>
                    <input name="branch_state" value="{{ old('branch_state') }}" placeholder="Selangor">
                    @error('branch_state')<div class="err">{{ $message }}</div>@enderror
                </div>
            </div>
            <div class="fld">
                <label>Department</label>
                <input name="department_name" value="{{ old('department_name', 'Human Resources') }}" required>
                @error('department_name')<div class="err">{{ $message }}</div>@enderror
            </div>

            <div class="sect">First HR admin</div>
            <div class="fld">
                <label>Full name</label>
                <input name="admin_name" value="{{ old('admin_name') }}" placeholder="Siti Aminah" required>
                @error('admin_name')<div class="err">{{ $message }}</div>@enderror
            </div>
            <div class="fld">
                <label>Email</label>
                <input type="email" name="admin_email" value="{{ old('admin_email') }}" placeholder="hr@acme.com" required>
                @error('admin_email')<div class="err">{{ $message }}</div>@enderror
            </div>

            <button type="submit" class="uj-btn" style="width:100%;margin-top:8px;padding:12px;border:none;border-radius:10px;font-size:14px;font-weight:600;background:var(--red);color:#fff;cursor:pointer;">Create company</button>
        </form>

        <div style="margin-top:20px;">
            <a href="{{ route('superadmin.companies.index') }}" style="font-size:13px;color:var(--muted);text-decoration:none;">← Cancel</a>
        </div>
    </div>
</div>
</body>
</html>
