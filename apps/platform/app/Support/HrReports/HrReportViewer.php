<?php

namespace App\Support\HrReports;

use App\Models\School;
use App\Models\User;
use App\Support\Authorization\Campus\CampusScopeResolver;

/**
 * HR.14: what one viewer holds, resolved fresh for one request (never cached
 * across requests, never stored in a view or preference). Whole-School checks
 * go through the Campus scope resolver, so a Campus-only grant of an adopted
 * HR key (hr.employees.view, hr.employees.assignments.view) is never
 * mistaken for whole-School authority.
 */
final class HrReportViewer
{
    /** @var array<string, bool> */
    private array $held = [];

    public function __construct(
        public readonly User $actor,
        public readonly School $school,
        private readonly CampusScopeResolver $resolver,
    ) {}

    public function holdsWholeSchool(string $capability): bool
    {
        return $this->held[$capability] ??= $this->resolver->campusScope($this->actor, $capability, $this->school)->isWholeSchool();
    }

    /** @param  list<string>  $capabilities */
    public function holdsAll(array $capabilities): bool
    {
        foreach ($capabilities as $capability) {
            if (! $this->holdsWholeSchool($capability)) {
                return false;
            }
        }

        return true;
    }

    public function maySee(HrReportColumn $column): bool
    {
        return $this->holdsAll($column->requires);
    }
}
