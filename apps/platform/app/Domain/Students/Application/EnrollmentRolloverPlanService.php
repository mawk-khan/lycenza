<?php

namespace App\Domain\Students\Application;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\Students\Application\Exceptions\CrossSchoolRolloverPlanException;
use App\Domain\Students\Application\Exceptions\InvalidRolloverPlanYearsException;
use App\Domain\Students\Application\Exceptions\OpenRolloverPlanConflictException;
use App\Domain\Students\Infrastructure\EnrollmentRolloverPlan;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Phase 1B.7A: the ONLY sanctioned write path for an
 * EnrollmentRolloverPlan's header in this checkpoint --
 * `createDraft()` is deliberately the sole method. Mapping/item
 * creation, dry-run, and execution have NO sanctioned service yet
 * (Phase 1B.7B/1B.7C) -- see docs/modules/STUDENT-ENROLLMENT.md
 * ("Academic-Year Rollover & Promotion — Architecture Decision (Phase
 * 1B.7)"). Never create/update an EnrollmentRolloverPlan row directly
 * from a future controller/import.
 *
 * Deliberately authorization-neutral, matching every other Application
 * service in this codebase -- a future controller must call
 * `Gate::authorize`/`authorizeCapability` before ever reaching this
 * service; no capability check lives here.
 */
class EnrollmentRolloverPlanService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * Creates a new plan in `draft` status for the given source/target
     * AcademicYear pair. Does NOT validate chronology (which year is
     * "earlier") -- the accepted architecture explicitly defers that to
     * a future dry-run step rather than guessing at calendar
     * conventions here; it DOES enforce that both years belong to the
     * same School as the plan and that they are not identical, exactly
     * like `StudentEnrollmentService::enroll()`'s own same-School check
     * runs before any write is attempted.
     */
    public function createDraft(School $school, AcademicYear $sourceYear, AcademicYear $targetYear, ?User $actor = null): EnrollmentRolloverPlan
    {
        if ($sourceYear->school_id !== $school->id || $targetYear->school_id !== $school->id) {
            throw new CrossSchoolRolloverPlanException;
        }

        if ($sourceYear->id === $targetYear->id) {
            throw new InvalidRolloverPlanYearsException;
        }

        return $this->context->withSchool($school, function () use ($school, $sourceYear, $targetYear, $actor) {
            try {
                return DB::transaction(function () use ($school, $sourceYear, $targetYear, $actor) {
                    $plan = EnrollmentRolloverPlan::query()->create([
                        'school_id' => $school->id,
                        'source_academic_year_id' => $sourceYear->id,
                        'target_academic_year_id' => $targetYear->id,
                        'status' => 'draft',
                        'configuration_version' => 1,
                    ]);

                    $this->audit->school($school, 'enrollment_rollover_plan.created', actor: $actor, subject: $plan, metadata: [
                        'sourceAcademicYearId' => $sourceYear->id,
                        'targetAcademicYearId' => $targetYear->id,
                    ]);

                    return $plan;
                });
            } catch (UniqueConstraintViolationException $e) {
                throw $this->translateUniqueViolation($e);
            }
        });
    }

    private function translateUniqueViolation(UniqueConstraintViolationException $e): Throwable
    {
        return match ($e->index) {
            'enrollment_rollover_plans_one_open_per_year_pair' => new OpenRolloverPlanConflictException,
            default => $e,
        };
    }
}
