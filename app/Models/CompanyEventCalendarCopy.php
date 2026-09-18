<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One non-attending employee's copy of a company event in their Google Calendar. Not
 * tenant-scoped via the trait: it is written from queued jobs that run without a
 * tenant context, and always looked up by company_event_id / employee_id.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $company_event_id
 * @property int $employee_id
 * @property string|null $google_event_id
 * @property string|null $calendar_version
 * @property string|null $sync_error
 */
class CompanyEventCalendarCopy extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<CompanyEvent, $this> */
    public function companyEvent(): BelongsTo
    {
        return $this->belongsTo(CompanyEvent::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withoutGlobalScopes();
    }
}
