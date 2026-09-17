<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\EmployeeAward;
use App\Models\EmployeeCertificate;
use App\Models\EmployeeEducation;
use App\Models\EmployeeLanguage;
use App\Models\EmployeeWorkHistory;

/** Experience tab record types and their fixed option lists. */
final class ExperienceOptions
{
    /** type slug => [model class, EN heading, MS heading, Employee relation name]. */
    public const TYPES = [
        'work' => [EmployeeWorkHistory::class, 'Previous Employment', 'Pekerjaan Terdahulu', 'workHistories'],
        'education' => [EmployeeEducation::class, 'Education', 'Pendidikan', 'educations'],
        'certificate' => [EmployeeCertificate::class, 'Certificates', 'Sijil', 'certificates'],
        'award' => [EmployeeAward::class, 'Awards / Scholarship', 'Anugerah / Biasiswa', 'awards'],
        'language' => [EmployeeLanguage::class, 'Languages', 'Bahasa', 'languages'],
    ];

    public const SALARY_TYPES = ['monthly', 'weekly', 'daily'];

    public const QUALIFICATIONS = ['high_school' => 'High School', 'vocational' => 'Vocational', 'associate' => 'Associate', 'bachelor' => 'Bachelor', 'master' => 'Master', 'doctorate' => 'Doctorate'];

    public const HONOURS = ['first' => 'First class', 'second' => 'Second class', 'third' => 'Third class', 'none' => 'None'];

    public const PROFICIENCY = ['basic', 'intermediate', 'fluent', 'native'];
}
