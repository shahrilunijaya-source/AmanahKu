<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Support\ExperienceOptions;
use App\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Experience tab rows: previous employment, education, certificates, awards, languages.
 * One controller for all five; the {type} route segment picks the model and rules.
 * HR/management for anyone; a person for their own record.
 */
class ExperienceRecordController extends Controller
{
    public function store(Request $request, Employee $employee, string $type): RedirectResponse
    {
        $this->guard($request, $employee);
        $data = $this->validated($request, $type, $employee);
        $row = $employee->{ExperienceOptions::TYPES[$type][3]}()->create($data + ['tenant_id' => $employee->tenant_id]);
        AuditLog::record('Added '.$type.' record', $employee->name.' · '.self::label($row));

        return $this->back($employee);
    }

    public function update(Request $request, string $type, int $id): RedirectResponse
    {
        $row = $this->find($type, $id);
        $employee = $this->guard($request, $row->employee);
        $row->update($this->validated($request, $type, $employee));
        AuditLog::record('Updated '.$type.' record', $employee->name.' · '.self::label($row));

        return $this->back($employee);
    }

    public function destroy(Request $request, string $type, int $id): RedirectResponse
    {
        $row = $this->find($type, $id);
        $employee = $this->guard($request, $row->employee);
        $label = self::label($row);
        $row->delete();
        AuditLog::record('Removed '.$type.' record', $employee->name.' · '.$label);

        return $this->back($employee);
    }

    private function find(string $type, int $id): Model
    {
        /** @var class-string<Model> $class */
        $class = ExperienceOptions::TYPES[$type][0];

        return $class::query()->findOrFail($id); // tenant global scope → 404 across tenants
    }

    private function guard(Request $request, ?Employee $employee): Employee
    {
        abort_unless($employee && $employee->tenant_id === app(CurrentTenant::class)->id(), 404);
        $own = $request->attributes->get('employee');
        abort_unless($this->hasTenantRole($request, ['management', 'hr']) || ($own && $own->id === $employee->id), 403);

        return $employee;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, string $type, Employee $employee): array
    {
        $attachment = ['document_id' => ['nullable', 'integer', Rule::exists('employee_documents', 'id')->where('employee_id', $employee->id)]];
        // "Not before <other field>" only when the other field was actually sent; Laravel's
        // after_or_equal/gte fail (or throw) against a null sibling.
        $notBefore = fn (string $other, string $message): Closure => function (string $attr, mixed $value, Closure $fail) use ($request, $other, $message): void {
            if ($request->filled($other) && $value !== null && strtotime((string) $value) < strtotime((string) $request->input($other))) {
                $fail($message);
            }
        };
        $yearNotBefore = fn (string $other, string $message): Closure => function (string $attr, mixed $value, Closure $fail) use ($request, $other, $message): void {
            if ($request->filled($other) && $value !== null && (int) $value < (int) $request->input($other)) {
                $fail($message);
            }
        };

        $rules = match ($type) {
            'work' => [
                'company' => ['required', 'string', 'max:160'],
                'address' => ['nullable', 'string', 'max:255'],
                'joined_on' => ['nullable', 'date'],
                'joined_as' => ['nullable', 'string', 'max:120'],
                'resigned_on' => ['nullable', 'date', $notBefore('joined_on', 'Resigned date cannot be before the joined date.')],
                'position_held' => ['nullable', 'string', 'max:120'],
                'last_drawn_salary' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
                'salary_type' => ['nullable', Rule::in(ExperienceOptions::SALARY_TYPES)],
                'industry' => ['nullable', 'string', 'max:120'],
                'reason_to_leave' => ['nullable', 'string', 'max:255'],
            ],
            'education' => [
                'qualification_type' => ['required', Rule::in(array_keys(ExperienceOptions::QUALIFICATIONS))],
                'major' => ['nullable', 'string', 'max:160'],
                'institute' => ['nullable', 'string', 'max:160'],
                'from_year' => ['nullable', 'integer', 'min:1950', 'max:2100'],
                'to_year' => ['nullable', 'integer', 'min:1950', 'max:2100', $yearNotBefore('from_year', 'To year cannot be before from year.')],
                'honours' => ['nullable', Rule::in(array_keys(ExperienceOptions::HONOURS))],
                'cgpa' => ['nullable', 'numeric', 'min:0', 'max:4'],
                'remark' => ['nullable', 'string', 'max:500'],
            ] + $attachment,
            'certificate' => [
                'name' => ['required', 'string', 'max:160'],
                'category' => ['nullable', 'string', 'max:120'],
                'awarded_on' => ['nullable', 'date'],
                'expires_on' => ['nullable', 'date', $notBefore('awarded_on', 'Expiry cannot be before the award date.')],
                'awarded_by' => ['nullable', 'string', 'max:160'],
                'remark' => ['nullable', 'string', 'max:500'],
            ] + $attachment,
            'award' => [
                'title' => ['required', 'string', 'max:160'],
                'year' => ['nullable', 'integer', 'min:1950', 'max:2100'],
                'remark' => ['nullable', 'string', 'max:500'],
            ] + $attachment,
            'language' => [
                'language' => ['required', 'string', 'max:80'],
                'speaking' => ['nullable', Rule::in(ExperienceOptions::PROFICIENCY)],
                'reading' => ['nullable', Rule::in(ExperienceOptions::PROFICIENCY)],
                'writing' => ['nullable', Rule::in(ExperienceOptions::PROFICIENCY)],
            ],
        };
        $rules['_row'] = ['nullable', 'integer'];

        $validator = validator($request->all(), $rules);
        if ($validator->fails()) {
            // Flashed before the throw so the Experience tab reopens the right form with the errors.
            session()->flash('form', 'experience:'.$type);
            throw new ValidationException($validator);
        }
        $data = $validator->validated();
        unset($data['_row']);

        return array_map(fn ($v) => $v === '' ? null : $v, $data);
    }

    private static function label(Model $row): string
    {
        return (string) ($row->company ?? $row->institute ?? $row->name ?? $row->title ?? $row->language ?? $row->getKey());
    }

    private function back(Employee $employee): RedirectResponse
    {
        return redirect(route('app.screen', 'profile').'?emp='.$employee->id.'&tab=experience')->with('ok', 'Experience record saved.');
    }
}
