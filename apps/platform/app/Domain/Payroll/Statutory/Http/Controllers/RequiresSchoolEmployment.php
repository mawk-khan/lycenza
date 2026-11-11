<?php

namespace App\Domain\Payroll\Statutory\Http\Controllers;

use App\Domain\HR\Application\EmploymentRoster;
use App\Models\School;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * HR.R1-C24 (WO-3, D2-R10 to R18, D2-3): the statutory API's "this employment exists in this School" check,
 * answered by HR's reader (`EmploymentRoster::span()`), never the HR model.
 *
 * Every status counts, ended included, exactly as the model lookup did. An unknown id and another School's id
 * get the same 404 with the same body. The body is fixed: it names no HR class, table or id (D2-3). It runs
 * where the lookup ran, after the route's capability check and before the service, so the order is unchanged.
 */
trait RequiresSchoolEmployment
{
    public const EMPLOYMENT_NOT_FOUND = 'No query results for the employment.';

    private function requireEmployment(School $school, string $employmentRecordId): void
    {
        if (app(EmploymentRoster::class)->span($school, $employmentRecordId) === null) {
            throw new ModelNotFoundException(self::EMPLOYMENT_NOT_FOUND);
        }
    }
}
