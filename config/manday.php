<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Manday / Manhour Costing Constants
|--------------------------------------------------------------------------
|
| Charge-out rates are derived from a position's MAX salary band, never from
| an employee's real salary. The formula, fixed company-wide:
|
|     manday rate  = (max_salary * loading_factor) / days_per_month
|     manhour rate = manday rate / hours_per_day
|
| e.g. max 5,000 -> (5000 * 1.8) / 20 = 450/day -> 450 / 8 = 56.25/hour.
|
*/

return [
    // Overhead loading applied on top of the band salary.
    'loading_factor' => 1.8,

    // Working days assumed per month.
    'days_per_month' => 20,

    // Working hours assumed per day (manday -> manhour conversion).
    'hours_per_day' => 8,

    // CR-03: a day must be submitted by this time (HH:MM, app timezone) on the next
    // working day, or it is flagged late.
    'day_submit_deadline' => env('TIMESHEET_DAY_DEADLINE', '10:00'),

    // CR-03: how many working days back a staffer may still edit a day without a
    // manager unlock.
    'edit_window_working_days' => 3,
];
