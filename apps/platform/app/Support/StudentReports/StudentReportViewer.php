<?php

namespace App\Support\StudentReports;

use App\Models\School;
use App\Models\User;
use App\Support\Authorization\Campus\CampusScope;
use App\Support\Authorization\Campus\CampusScopeResolver;

/**
 * SIS 11.1: what one viewer holds, resolved fresh for one request (never
 * cached across requests). Whole-School checks go through the Campus scope
 * resolver, so a Campus-only grant of an adopted key is never mistaken for
 * whole-School authority.
 */
final class StudentReportViewer
{
    /** @var array<string, CampusScope> */
    private array $scopes = [];

    public function __construct(
        public readonly User $actor,
        private readonly School $school,
        private readonly CampusScopeResolver $resolver,
    ) {}

    public function scope(string $capability): CampusScope
    {
        return $this->scopes[$capability] ??= $this->resolver->campusScope($this->actor, $capability, $this->school);
    }

    public function holdsWholeSchool(string $capability): bool
    {
        return $this->scope($capability)->isWholeSchool();
    }

    public function holdsAnywhere(string $capability): bool
    {
        return ! $this->scope($capability)->isNone();
    }

    /** @param  list<string>  $capabilities */
    public function holdsAllWholeSchool(array $capabilities): bool
    {
        foreach ($capabilities as $capability) {
            if (! $this->holdsWholeSchool($capability)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether this viewer may see a column: a RESTRICTED column's
     * requirements are judged under the report's own scope policy (whole
     * School for a WHOLE_SCHOOL report, anywhere for a CAMPUS_AWARE one,
     * whose read path then applies the Campus scope to the column's data).
     */
    public function maySee(StudentReportColumn $column, string $scope): bool
    {
        foreach ($column->requires as $capability) {
            if (! ($scope === StudentReportDefinition::WHOLE_SCHOOL ? $this->holdsWholeSchool($capability) : $this->holdsAnywhere($capability))) {
                return false;
            }
        }

        return true;
    }
}
