<?php

namespace App\Domain\CurriculumDelivery\Application\Retention;

use App\Domain\AcademicStructure\Application\Retention\AcademicYearRetention;
use App\Models\School;
use App\Support\Retention\RetentionBatch;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * E21.3D (E21.2G A1, project-adopted, pending legal ratification): a
 * Curriculum Delivery (a Section's coverage of a syllabus unit) is School
 * teaching evidence, kept 7 calendar years after the end of its
 * authoritative Academic Year (`curriculum_deliveries.academic_year_id`,
 * pinned by its composite foreign keys to its Section and SubjectOffering;
 * the clock is AcademicYearRetention's), then deleted. Only
 * `platform:academic-retention-prune` calls this, never a request.
 *
 * - Nothing references a delivery today; any row added later that does
 *   keeps it (`dependency_blocked`). It holds no Employee reference and owns
 *   no Document. Syllabus units are School academic configuration and stay
 *   (tenant lifetime).
 * - Not tied to any Student's exit or Employee's separation, and never to
 *   `created_at`, a status or the current year: a current or future year
 *   is never past its period.
 * - Bounded batches in id order (RetentionBatch); a held School is counted
 *   only. Counts only, never content.
 */
final class CurriculumDeliveryRetentionService
{
    private const TABLE = 'curriculum_deliveries';

    public function __construct(
        private readonly TenantContext $context,
        private readonly RetentionBatch $batches,
    ) {}

    /** @return array{eligible: int, deleted: int, held: int, unresolved: int, dependency_blocked: int, errors: int} */
    public function prune(School $school, string $cutoffDate, int $batch, bool $dryRun, bool $held): array
    {
        return $this->context->withSchool($school, fn (): array => $this->batches->prune(
            self::TABLE,
            fn () => AcademicYearRetention::endedBefore(DB::table(self::TABLE.' as t')->where('t.school_id', $school->id), 't', $cutoffDate),
            $batch,
            $dryRun,
            $held,
        ));
    }
}
