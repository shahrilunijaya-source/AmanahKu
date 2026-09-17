<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Support\PersonalOptions;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Personal tab save. HR/management edit anyone; a person edits their own record minus identity documents. */
class PersonalRecordController extends Controller
{
    public function update(Request $request, Employee $employee): RedirectResponse
    {
        abort_unless($employee->tenant_id === app(CurrentTenant::class)->id(), 403);
        $own = $request->attributes->get('employee');
        $isHr = $this->hasTenantRole($request, ['management', 'hr']);
        abort_unless($isHr || ($own && $own->id === $employee->id), 403);

        $rules = [
            'first_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'full_name_ic' => ['nullable', 'string', 'max:200'],
            'nickname' => ['nullable', 'string', 'max:60'],
            'religion' => ['nullable', Rule::in(PersonalOptions::RELIGIONS)],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', 'in:male,female'],
            'marital_status' => ['nullable', Rule::in(array_keys(PersonalOptions::MARITAL))],
            'race' => ['nullable', Rule::in(PersonalOptions::RACES)],
            'nationality' => ['nullable', Rule::in(PersonalOptions::NATIONALITIES)],
            'blood_type' => ['nullable', Rule::in(PersonalOptions::BLOOD_TYPES)],
            'phone' => ['nullable', 'string', 'max:40'],
            'personal_email' => ['nullable', 'email', 'max:190'],
            'address' => ['nullable', 'string', 'max:500'],
            'address_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postcode' => ['nullable', 'string', 'max:12'],
            'country' => ['nullable', 'string', 'max:80'],
            'emergency_contact_name' => ['nullable', 'string', 'max:160'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:40'],
            'emergency_contact_relationship' => ['nullable', 'string', 'max:60'],
        ];
        if ($isHr) {
            $rules += [
                'nric' => ['nullable', 'string', 'max:20'],
                'passport_no' => ['nullable', 'string', 'max:40'],
                'passport_expiry' => ['nullable', 'date'],
                'permit_no' => ['nullable', 'string', 'max:60'],
                'permit_expiry' => ['nullable', 'date'],
            ];
        }

        $validator = validator($request->all(), $rules);
        if ($validator->fails()) {
            return back()->withInput()->withErrors($validator)->with('form', 'personal');
        }

        // Only keys that were actually sent change; validated() already drops anything not in
        // $rules, which is what silently strips nric/passport from a self-edit.
        $data = array_intersect_key($validator->validated(), $request->all());
        $employee->update(array_map(fn ($v) => $v === '' ? null : $v, $data));

        AuditLog::record('Updated personal details', $employee->name);

        return redirect(route('app.screen', 'profile').'?emp='.$employee->id.'&tab=personal')->with('ok', 'Personal details saved.');
    }
}
