<?php

declare(strict_types=1);

namespace App\Support;

/** Fixed option lists for the Personal and Family tabs (Worksy lists). Not tenant-editable. */
final class PersonalOptions
{
    public const RELIGIONS = ['Islam', 'Buddhism', 'Christianity', 'Hinduism', 'Sikhism', 'Taoism', 'Other', 'None'];

    public const RACES = ['Malay', 'Chinese', 'Indian', 'Bumiputera Sabah', 'Bumiputera Sarawak', 'Orang Asli', 'Eurasian', 'Other'];

    public const BLOOD_TYPES = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

    /** value => [en, ms]. Matches WelcomeWizardController plus Worksy's "Living Together". */
    public const MARITAL = [
        'single' => ['Single', 'Bujang'],
        'married' => ['Married', 'Berkahwin'],
        'living_together' => ['Living Together', 'Tinggal Bersama'],
        'divorced' => ['Divorced', 'Bercerai'],
        'widowed' => ['Widowed', 'Balu/Duda'],
    ];

    public const RELATIONS = ['father', 'mother', 'spouse', 'child', 'dependent'];

    public const OCCUPATIONS = ['student', 'unemployed', 'working'];

    public const EDUCATION = ['None', 'Pre-school', 'Primary', 'Secondary', 'Certificate', 'Diploma', 'Degree', 'Master', 'PhD'];

    public const NATIONALITIES = [
        'Malaysian', 'Afghan', 'Albanian', 'Algerian', 'American', 'Argentine', 'Australian', 'Austrian', 'Bangladeshi', 'Belgian', 'Bhutanese', 'Bosnian', 'Brazilian', 'British', 'Bruneian', 'Bulgarian', 'Cambodian', 'Cameroonian', 'Canadian', 'Chilean', 'Chinese', 'Colombian', 'Croatian', 'Cuban', 'Czech', 'Danish', 'Dutch', 'Egyptian', 'Emirati', 'Ethiopian', 'Filipino', 'Finnish', 'French', 'German', 'Ghanaian', 'Greek', 'Hong Konger', 'Hungarian', 'Indian', 'Indonesian', 'Iranian', 'Iraqi', 'Irish', 'Israeli', 'Italian', 'Japanese', 'Jordanian', 'Kazakh', 'Kenyan', 'Korean', 'Kuwaiti', 'Laotian', 'Lebanese', 'Libyan', 'Macanese', 'Maldivian', 'Mexican', 'Mongolian', 'Moroccan', 'Myanmar', 'Nepalese', 'New Zealander', 'Nigerian', 'Norwegian', 'Omani', 'Pakistani', 'Palestinian', 'Peruvian', 'Polish', 'Portuguese', 'Qatari', 'Romanian', 'Russian', 'Saudi', 'Senegalese', 'Serbian', 'Singaporean', 'Slovak', 'Somali', 'South African', 'Spanish', 'Sri Lankan', 'Sudanese', 'Swedish', 'Swiss', 'Syrian', 'Taiwanese', 'Tanzanian', 'Thai', 'Timorese', 'Tunisian', 'Turkish', 'Ugandan', 'Ukrainian', 'Uzbek', 'Venezuelan', 'Vietnamese', 'Yemeni', 'Zimbabwean', 'Other',
    ];
}
