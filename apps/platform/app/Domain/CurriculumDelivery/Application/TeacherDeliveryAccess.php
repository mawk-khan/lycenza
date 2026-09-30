<?php

namespace App\Domain\CurriculumDelivery\Application;

use App\Domain\HR\Application\ActingEmployeeResolver;
use App\Domain\TeachingAssignments\Application\TeachingOwnership;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\AuthorizesCapability;

/**
 * TCH.3 (ADR 0063 section 11, Tier 2) -- the owned-teacher authorization
 * path for Curriculum Delivery, the first adopter of ADR 0063:
 *
 *   authenticated User (route) AND trusted School context (route)
 *   AND `curriculum.delivery.teacher`          -- capability, never a role key
 *   AND a verified ActingEmployee today        -- HR ActingEmployeeResolver
 *   AND a TeachingAssignment of that Employee for the exact Section +
 *       SubjectOffering on every date the operation involves
 *
 * Every part is required; any missing part fails closed. It asks nothing
 * about role names -- any role carrying the capability works the same --
 * and nothing about the timetable.
 *
 * Two dates are kept apart: the ACTOR must be an eligible Employee today
 * (School-local), and OWNERSHIP is judged on the delivery's own dates
 * (TeacherDeliveryScope for reads, TeacherDeliveryGuard for writes) --
 * never today and never created_at.
 *
 * This is not a second Curriculum Delivery implementation: reads filter
 * the same model, and writes run CurriculumDeliveryService with a
 * TeacherDeliveryGuard.
 */
class TeacherDeliveryAccess
{
    use AuthorizesCapability;

    public const string CAPABILITY = 'curriculum.delivery.teacher';

    public function __construct(
        private readonly ActingEmployeeResolver $identities,
        private readonly TeachingOwnership $ownership,
    ) {}

    /**
     * For reads: the capability, then a fresh ActingEmployee, then the
     * Employee's ownership periods. Nothing is cached.
     */
    public function scope(User $actor, School $school): TeacherDeliveryScope
    {
        $this->authorizeCapabilityFor($actor, self::CAPABILITY, $school);

        $acting = $this->identities->resolve($actor, $school);

        return new TeacherDeliveryScope($acting->employeeId, $acting->asOf, $this->ownership->periods($school, $acting->employeeId));
    }

    /** For writes: a guard to pass to CurriculumDeliveryService. */
    public function guard(User $actor): TeacherDeliveryGuard
    {
        return new TeacherDeliveryGuard($actor, $this->identities, $this->ownership);
    }
}
