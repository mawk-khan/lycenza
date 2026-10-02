<?php

namespace App\Domain\Admissions\Application\Retention;

use App\Domain\Students\Application\Retention\StudentCoreParticipant;
use App\Models\School;
use App\Support\Retention\ReferencingRows;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * E21.3B (E21.2G AD1, E21-D7 core, project-adopted, pending legal
 * ratification): a CONVERTED admission application, and its applicant,
 * are supporting history of the Student it became. They are kept with that
 * Student's core record (25 calendar years after final exit) and go with
 * it, in the core purge's one-Student transaction. They are never aged
 * from the application's creation or its conversion date.
 *
 * - The linkage is authoritative: AdmissionConversionService creates the
 *   Student and sets `converted_student_id` and
 *   `converted_student_enrollment_id` in the same transaction, and
 *   `admission_applications_conversion_provenance_check` ties
 *   `status = 'converted'` to both. Only those rows of THIS Student go.
 * - The applicant goes too, but only once no application of it remains: an
 *   applicant who also has a rejected, withdrawn or live application keeps
 *   its identity for that application (E21.3C decides rejected/withdrawn
 *   expiry; live ones are working state).
 * - Any other row referencing the application or the released applicant,
 *   and an application converted into another Student that names one of
 *   this Student's placements, keeps the Student (`dependency_blocked`).
 * - No Admissions row owns a Document or a Finance record.
 */
final class ConvertedApplicationRetentionService implements StudentCoreParticipant
{
    private const TABLE = 'admission_applications';

    public function __construct(private readonly ReferencingRows $references) {}

    public function tables(): array
    {
        return [self::TABLE];
    }

    public function blocker(string $schoolId, string $studentId, array $enrollmentIds, array $cleared, array $unitTables): ?string
    {
        if ($enrollmentIds !== [] && DB::table(self::TABLE)->where('school_id', $schoolId)->whereIn('converted_student_enrollment_id', $enrollmentIds)
            ->where('converted_student_id', '!=', $studentId)->exists()) {
            return self::TABLE;
        }

        return $this->references->first(self::TABLE, $schoolId, $this->converted($schoolId, $studentId)->pluck('id')->all(), $unitTables)
            ?? $this->references->first('applicants', $schoolId, $this->released($schoolId, $studentId), [self::TABLE]);
    }

    public function purge(School $school, string $studentId, string $cutoffDate): void
    {
        $applicants = $this->released($school->id, $studentId);
        $this->converted($school->id, $studentId)->delete();
        // Re-checked at delete time: an application created meanwhile keeps its applicant.
        DB::table('applicants')->where('school_id', $school->id)->whereIn('id', $applicants)
            ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from(self::TABLE.' as a')->whereColumn('a.applicant_id', 'applicants.id'))
            ->delete();
    }

    private function converted(string $schoolId, string $studentId): Builder
    {
        return DB::table(self::TABLE)->where('school_id', $schoolId)->where('status', 'converted')->where('converted_student_id', $studentId);
    }

    /**
     * Applicants of this Student's converted applications that have no other
     * application, so they leave with it.
     *
     * @return list<string>
     */
    private function released(string $schoolId, string $studentId): array
    {
        return DB::table('applicants as p')->where('p.school_id', $schoolId)
            ->whereIn('p.id', $this->converted($schoolId, $studentId)->select('applicant_id'))
            ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from(self::TABLE.' as a')->whereColumn('a.applicant_id', 'p.id')
                ->where(fn (Builder $other) => $other->where('a.status', '!=', 'converted')->orWhere('a.converted_student_id', '!=', $studentId)))
            ->orderBy('p.id')->pluck('p.id')->all();
    }
}
