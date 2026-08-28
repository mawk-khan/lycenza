<?php

namespace App\Domain\AcademicStructure\Application;

use App\Domain\AcademicStructure\Application\Exceptions\DuplicateElectiveGroupCodeException;
use App\Domain\AcademicStructure\Application\Exceptions\ElectiveGroupAssignmentLockedException;
use App\Domain\AcademicStructure\Application\Exceptions\ElectiveGroupContextMismatchException;
use App\Domain\AcademicStructure\Application\Exceptions\RequiredSubjectOfferingGroupAssignmentException;
use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\ElectiveGroup;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Students\Infrastructure\StudentSubjectEnrollment;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Phase 1F.3: the ONLY sanctioned write path for ElectiveGroup creation
 * and SubjectOffering-to-ElectiveGroup configuration -- mirrors
 * AcademicYearService's exact shape (validate -> write -> audit, inside
 * one transaction). Deliberately authorization-neutral, matching every
 * other Application service in this codebase (CLAUDE.md rule 45) -- a
 * future controller must call `authorizeCapability('academics.subjects.manage', ...)`
 * before ever reaching this service.
 *
 * Creates and configures ONLY -- does not implement group deletion,
 * name/code updates, or any auto-grouping/backfill of existing
 * Offerings (architecture doc §17/§18A, checkpoint brief §33-36).
 *
 * ## The one deliberate cross-domain read (checkpoint brief §4/§21-23)
 *
 * `assertNoParticipationHistory()` below queries
 * `App\Domain\Students\Infrastructure\StudentSubjectEnrollment` --
 * Students/SIS-owned data -- directly. This is a narrow, intentional
 * exception to CLAUDE.md rule 4 ("never reads another module's
 * Eloquent models or tables directly"), scoped as tightly as possible:
 *
 * - It is a single read-only `exists()` check, never a write, never a
 *   join into Students' business logic, never a call into
 *   `App\Domain\Students\Application\StudentSubjectEnrollmentService`
 *   (which owns Student PARTICIPATION decisions -- a different
 *   question from "does history exist for this Offering").
 * - It is scoped by the already-locked SubjectOffering's own
 *   authoritative `school_id` -- never a caller-supplied value.
 * - The alternative (introducing an actual AcademicStructure ->
 *   Students Application-service *call*) would be no better: Students
 *   already depends on AcademicStructure (`docs/architecture/DOMAIN-MAP.md`),
 *   so ANY AcademicStructure -> Students dependency -- model or
 *   service -- creates the same bidirectional coupling rule 4 forbids.
 *   A denormalized "has-history" flag on `subject_offerings`, kept in
 *   sync by a Students-side event listener, would avoid the read but
 *   introduces real eventual-consistency risk for a configuration-
 *   immutability invariant that must be exact, not eventually
 *   consistent -- overengineering for this checkpoint (CLAUDE.md rule 2).
 *
 * This exception is deliberately narrow and permanent, not a shortcut
 * to be "cleaned up later" -- if a genuine two-way business
 * relationship ever emerges between these domains, that would warrant
 * revisiting the domain boundary itself in a dedicated ADR, not
 * expanding this one query.
 */
class ElectiveGroupService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * Creates a new ElectiveGroup. `$year`/`$campus`/`$gradeLevel` must
     * each belong to `$school` -- checked here, before the database's
     * own composite FKs would reject the same mismatch, so this never
     * surfaces as a raw QueryException in normal application flow.
     */
    public function create(School $school, AcademicYear $year, Campus $campus, GradeLevel $gradeLevel, string $name, string $code, ?User $actor = null): ElectiveGroup
    {
        $this->assertSameSchool($school, $year, $campus, $gradeLevel);

        return $this->context->withSchool($school, function () use ($school, $year, $campus, $gradeLevel, $name, $code, $actor) {
            try {
                return DB::transaction(function () use ($school, $year, $campus, $gradeLevel, $name, $code, $actor) {
                    $group = ElectiveGroup::query()->create([
                        'school_id' => $school->id,
                        'academic_year_id' => $year->id,
                        'campus_id' => $campus->id,
                        'grade_level_id' => $gradeLevel->id,
                        'name' => $name,
                        'code' => $code,
                    ]);

                    $this->audit->school($school, 'elective_group.created', actor: $actor, subject: $group, metadata: [
                        'electiveGroupId' => $group->id,
                        'academicYearId' => $year->id,
                        'campusId' => $campus->id,
                        'gradeLevelId' => $gradeLevel->id,
                        'code' => $group->code,
                    ]);

                    return $group;
                });
            } catch (UniqueConstraintViolationException $e) {
                throw $this->translateUniqueViolation($e);
            }
        });
    }

    /**
     * Assigns (or changes) a SubjectOffering's ElectiveGroup. Locks and
     * reloads the target SubjectOffering FIRST (`SELECT ... FOR
     * UPDATE`) -- the exact shared serialization point
     * `StudentSubjectEnrollmentService::enroll()`/`transfer()` already
     * take before reading the same row's `elective_group_id`
     * (architecture doc §16A) -- so a concurrent configuration write
     * and a concurrent participation write always serialize against
     * each other on this one row, never racing to an inconsistent
     * outcome.
     *
     * Idempotent: assigning an Offering already in the exact same
     * ElectiveGroup is a no-op (no audit) -- this is allowed even when
     * participation history exists, because no configuration state
     * actually changes (checkpoint brief §24).
     */
    public function assignOffering(ElectiveGroup $group, SubjectOffering $offering, ?User $actor = null): SubjectOffering
    {
        return $this->context->withSchool($offering->school, function () use ($group, $offering, $actor) {
            return DB::transaction(function () use ($group, $offering, $actor) {
                $lockedOffering = $this->lockOffering($offering);
                $freshGroup = $this->resolveGroup($group, $lockedOffering->school_id);

                $this->assertSameContext($freshGroup, $lockedOffering);

                if ($lockedOffering->is_required) {
                    throw new RequiredSubjectOfferingGroupAssignmentException;
                }

                if ($lockedOffering->elective_group_id === $freshGroup->id) {
                    return $lockedOffering;
                }

                $this->assertNoParticipationHistory($lockedOffering);

                $previousGroupId = $lockedOffering->elective_group_id;
                $lockedOffering->update(['elective_group_id' => $freshGroup->id]);

                $this->audit->school($lockedOffering->school, 'subject_offering.elective_group_assigned', actor: $actor, subject: $lockedOffering, metadata: [
                    'subjectOfferingId' => $lockedOffering->id,
                    'fromElectiveGroupId' => $previousGroupId,
                    'toElectiveGroupId' => $freshGroup->id,
                ]);

                return $lockedOffering->refresh();
            });
        });
    }

    /**
     * Removes a SubjectOffering's ElectiveGroup assignment (-> NULL,
     * ungrouped). Idempotent: an already-ungrouped Offering is a no-op
     * (no audit) -- mirrors `assignOffering()`'s identical idempotency
     * treatment (checkpoint brief §25).
     */
    public function removeOffering(SubjectOffering $offering, ?User $actor = null): SubjectOffering
    {
        return $this->context->withSchool($offering->school, function () use ($offering, $actor) {
            return DB::transaction(function () use ($offering, $actor) {
                $lockedOffering = $this->lockOffering($offering);

                if ($lockedOffering->elective_group_id === null) {
                    return $lockedOffering;
                }

                $this->assertNoParticipationHistory($lockedOffering);

                $previousGroupId = $lockedOffering->elective_group_id;
                $lockedOffering->update(['elective_group_id' => null]);

                $this->audit->school($lockedOffering->school, 'subject_offering.elective_group_removed', actor: $actor, subject: $lockedOffering, metadata: [
                    'subjectOfferingId' => $lockedOffering->id,
                    'fromElectiveGroupId' => $previousGroupId,
                ]);

                return $lockedOffering->refresh();
            });
        });
    }

    private function assertSameSchool(School $school, AcademicYear $year, Campus $campus, GradeLevel $gradeLevel): void
    {
        if ($year->school_id !== $school->id || $campus->school_id !== $school->id || $gradeLevel->school_id !== $school->id) {
            throw new ElectiveGroupContextMismatchException;
        }
    }

    /**
     * Scoped by the Offering's OWN `school_id` identity field (never a
     * caller-supplied value) -- the same tenant-safety discipline
     * `StudentSubjectEnrollmentService::lockOffering()` already
     * establishes.
     */
    private function lockOffering(SubjectOffering $offering): SubjectOffering
    {
        return SubjectOffering::query()
            ->where('school_id', $offering->school_id)
            ->whereKey($offering->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * A fresh, tenant-safe re-fetch of the ElectiveGroup -- NOT a
     * `lockForUpdate()` (checkpoint brief §14: no independent group
     * mutation writer exists yet, so there is no concurrency reason to
     * lock it). Scoped by `$schoolId`, which callers always pass as the
     * JUST-LOCKED SubjectOffering's own school_id -- so a caller-held
     * `$group` model claiming a different (foreign or stale) School can
     * never be used to bypass the context check below; it simply fails
     * to resolve.
     */
    private function resolveGroup(ElectiveGroup $group, string $schoolId): ElectiveGroup
    {
        $fresh = ElectiveGroup::query()
            ->where('school_id', $schoolId)
            ->whereKey($group->id)
            ->first();

        if ($fresh === null) {
            throw new ElectiveGroupContextMismatchException;
        }

        return $fresh;
    }

    private function assertSameContext(ElectiveGroup $group, SubjectOffering $offering): void
    {
        if ($group->school_id !== $offering->school_id
            || $group->academic_year_id !== $offering->academic_year_id
            || $group->campus_id !== $offering->campus_id
            || $group->grade_level_id !== $offering->grade_level_id) {
            throw new ElectiveGroupContextMismatchException;
        }
    }

    /**
     * See this class's docblock ("The one deliberate cross-domain
     * read") for the full architectural justification. Every status
     * counts -- `active`, `withdrawn`, `cancelled`, AND `transferred`
     * -- this is historical immutability, not current occupancy
     * (checkpoint brief §18-21). A legacy row whose own
     * `elective_group_id` snapshot is NULL still counts as history.
     */
    private function assertNoParticipationHistory(SubjectOffering $offering): void
    {
        $hasHistory = StudentSubjectEnrollment::query()
            ->where('school_id', $offering->school_id)
            ->where('subject_offering_id', $offering->id)
            ->exists();

        if ($hasHistory) {
            throw new ElectiveGroupAssignmentLockedException;
        }
    }

    private function translateUniqueViolation(UniqueConstraintViolationException $e): Throwable
    {
        return match ($e->index) {
            'elective_groups_code_unique' => new DuplicateElectiveGroupCodeException,
            default => $e,
        };
    }
}
