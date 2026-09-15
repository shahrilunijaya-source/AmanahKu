<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeFamilyMember;
use App\Support\PersonalOptions;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Family tab rows. HR/management for anyone; a person for their own record. */
class FamilyMemberController extends Controller
{
    public function store(Request $request, Employee $employee): RedirectResponse
    {
        $this->guard($request, $employee);
        $data = $this->validated($request, $employee);

        $employee->familyMembers()->create($data + ['tenant_id' => $employee->tenant_id]);
        AuditLog::record('Added family member', $employee->name.' · '.$data['name']);

        return $this->back($employee);
    }

    public function update(Request $request, EmployeeFamilyMember $member): RedirectResponse
    {
        $employee = $this->guard($request, $member->employee);
        $member->update($this->validated($request, $employee, $member));
        AuditLog::record('Updated family member', $employee->name.' · '.$member->name);

        return $this->back($employee);
    }

    public function destroy(Request $request, EmployeeFamilyMember $member): RedirectResponse
    {
        $employee = $this->guard($request, $member->employee);
        $name = $member->name;
        $member->delete();
        AuditLog::record('Removed family member', $employee->name.' · '.$name);

        return $this->back($employee);
    }

    private function guard(Request $request, ?Employee $employee): Employee
    {
        abort_unless($employee && $employee->tenant_id === app(CurrentTenant::class)->id(), 404);
        $own = $request->attributes->get('employee');
        abort_unless($this->hasTenantRole($request, ['management', 'hr']) || ($own && $own->id === $employee->id), 403);

        return $employee;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, Employee $employee, ?EmployeeFamilyMember $self = null): array
    {
        $validator = validator($request->all(), [
            'relation' => ['required', Rule::in(PersonalOptions::RELATIONS), function (string $attr, mixed $value, \Closure $fail) use ($employee, $self): void {
                // Worksy: one father, one mother. Spouse/child/dependent may repeat.
                if (in_array($value, ['father', 'mother'], true)
                    && $employee->familyMembers()->where('relation', $value)->when($self, fn ($q) => $q->whereKeyNot($self->id))->exists()) {
                    $fail('This person already has a '.$value.' on record.');
                }
            }],
            'name' => ['required', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'date_of_birth' => ['nullable', 'date'],
            'nric' => ['nullable', 'string', 'max:40'],
            'occupation' => ['nullable', Rule::in(PersonalOptions::OCCUPATIONS)],
            'employer_name' => ['nullable', 'string', 'max:160'],
            'marriage_date' => ['nullable', 'date'],
            'education' => ['nullable', Rule::in(PersonalOptions::EDUCATION)],
            'gender' => ['nullable', 'in:male,female'],
            'nationality' => ['nullable', Rule::in(PersonalOptions::NATIONALITIES)],
            'deceased' => ['nullable', 'boolean'],
            'address' => ['nullable', 'string', 'max:255'],
            'remark' => ['nullable', 'string', 'max:2000'],
            '_member' => ['nullable', 'integer'],
        ]);
        if ($validator->fails()) {
            // Flashed before the throw so the Family tab (not the generic profile modal) reopens with the errors.
            session()->flash('form', 'family');
            throw new ValidationException($validator);
        }
        $data = $validator->validated();
        unset($data['_member']);
        $data['deceased'] = (bool) ($data['deceased'] ?? false);

        return array_map(fn ($v) => $v === '' ? null : $v, $data);
    }

    private function back(Employee $employee): RedirectResponse
    {
        return redirect(route('app.screen', 'profile').'?emp='.$employee->id.'&tab=family')->with('ok', 'Family details saved.');
    }
}
