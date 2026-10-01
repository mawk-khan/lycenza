<?php

namespace App\Domain\CurriculumDelivery\Application;

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\CurriculumDelivery\Application\Exceptions\DeliveryOutsideTeachingAssignmentException;
use App\Domain\CurriculumDelivery\Infrastructure\CurriculumDelivery;
use App\Domain\HR\Application\ActingEmployee;
use App\Domain\HR\Application\ActingEmployeeResolver;
use App\Domain\TeachingAssignments\Application\TeachingOwnership;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\AuthorizesCapability;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * TCH.3: the Tier 2 write check, run by CurriculumDeliveryService INSIDE
 * its transaction (DeliveryWriteGuard). In order:
 *
 *   1. `curriculum.delivery.teacher` (CapabilityResolver, whose cache the
 *      role grant/revoke path already clears);
 *   2. ActingEmployeeResolver::hold() for today -- School, membership,
 *      User, Employee, EmploymentRecord FOR SHARE (once per transaction);
 *   3. visibility: the class, and for a change the delivery itself, must
 *      be inside the teacher's ownership (TeacherDeliveryScope) -- else the
 *      same not-found as a missing row (ADR 0063 section 18);
 *   4. TeachingOwnership::hold() for EVERY date the operation writes or
 *      replaces -- the covering TeachingAssignment FOR SHARE, so ending it
 *      either commits first (and this refuses) or waits for the write.
 *
 * Only then does the service insert or lock the delivery row: School ->
 * membership -> User -> Employee -> EmploymentRecord -> TeachingAssignment
 * -> curriculum_deliveries, the ADR 0063 section 20 order.
 */
final class TeacherDeliveryGuard implements DeliveryWriteGuard
{
    use AuthorizesCapability;

    private ?ActingEmployee $acting = null;

    public function __construct(
        private readonly User $actor,
        private readonly ActingEmployeeResolver $identities,
        private readonly TeachingOwnership $ownership,
    ) {}

    public function beforeStart(School $school, Section $section, SubjectOffering $offering, string $startedOn): void
    {
        $acting = $this->holdActor($school);

        if (! $this->scope($school, $acting)->ownsContext($section->id, $offering->id)) {
            throw (new ModelNotFoundException)->setModel(CurriculumDelivery::class);
        }

        $this->holdOwnership($school, $acting, $section->id, $offering->id, [$startedOn]);
    }

    public function beforeChange(School $school, CurriculumDelivery $delivery, array $dates): void
    {
        $acting = $this->holdActor($school);

        if (! $this->scope($school, $acting)->canSee($delivery)) {
            throw (new ModelNotFoundException)->setModel(CurriculumDelivery::class);
        }

        $this->holdOwnership($school, $acting, $delivery->section_id, $delivery->subject_offering_id, $dates);
    }

    private function holdActor(School $school): ActingEmployee
    {
        $this->authorizeCapabilityFor($this->actor, TeacherDeliveryAccess::CAPABILITY, $school);

        return $this->acting ??= $this->identities->hold($this->actor, $school);
    }

    private function scope(School $school, ActingEmployee $acting): TeacherDeliveryScope
    {
        return new TeacherDeliveryScope($acting->employeeId, $acting->asOf, $this->ownership->periods($school, $acting->employeeId));
    }

    /** @param  list<string>  $dates */
    private function holdOwnership(School $school, ActingEmployee $acting, string $sectionId, string $subjectOfferingId, array $dates): void
    {
        foreach (array_unique($dates) as $date) {
            if (! $this->ownership->hold($school, $acting->employeeId, $sectionId, $subjectOfferingId, $date)) {
                throw new DeliveryOutsideTeachingAssignmentException;
            }
        }
    }
}
