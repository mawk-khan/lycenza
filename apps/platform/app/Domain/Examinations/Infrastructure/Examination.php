<?php

namespace App\Domain\Examinations\Infrastructure;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\NormalizesCode;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\ExaminationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Tenant-owned named assessment WINDOW that a School holds within one
 * AcademicYear (Phase 0H.4A -- the first Examinations fact).
 *
 * A WINDOW / CONTAINER, NOT A PAPER. One row means "this School has
 * scheduled this named examination window inside this AcademicYear, over
 * these dates" -- for example "Mid-Term Examination 2026-27, 10 to 20
 * September". It is NOT one Subject's sitting. The per-Subject entity (a
 * future `ExaminationPaper`, Phase 0H.4B, behind its own architecture
 * gate) is what will carry SubjectOffering, a per-paper date/time and
 * max marks.
 *
 * IT OWNS: its code, its name, the AcademicYear it belongs to, its start
 * and end dates, and whether it is active.
 *
 * IT DOES NOT OWN: any Subject, SubjectOffering, Section, paper,
 * per-paper sitting date/time or max marks; any Student, enrollment,
 * teacher or invigilator; any mark, grade, result, publication state,
 * report card or transcript. Grading/marks/grade scales/results are
 * later Examinations checkpoints; learning content, assignments and
 * submissions are LMS's. See docs/modules/EXAMINATIONS.md.
 *
 * `App\Domain\Examinations\Application\ExaminationService` is the ONLY
 * sanctioned write path (CLAUDE.md rule 76 -- this entity has a real
 * date-range invariant validated against its parent AcademicYear, which
 * rule 76 names explicitly as a service trigger and which no database
 * CHECK can express because it requires a parent lookup). Neither
 * controller writes it directly; proven by
 * Tests\Feature\Examinations\ExaminationArchitectureGuardTest.
 *
 * NO `academic_term_id`. "Midterm Examination" is a NAME, not a term
 * reference -- see the migration docblock for the full reasoning.
 *
 * FUTURE DATES ARE PERMITTED AND EXPECTED, and OVERLAPPING WINDOWS ARE
 * PERMITTED -- both deliberately the opposite of `CurriculumDelivery`,
 * because an examination is scheduled ahead and examinations partition
 * nothing. There is therefore no lock, no overlap check and no
 * concurrency invariant anywhere in this module.
 *
 * NO LIFECYCLE COMMANDS. `status` moves through the ordinary update,
 * exactly like Section/SubjectOffering/Room/Subject/GradeLevel/
 * SyllabusUnit. Reactivating an Examination can conflict with nothing:
 * its only uniqueness constraint is
 * `examinations_year_code_ci_unique`, which is unconditional, so an
 * inactive Examination already reserves its code. There is likewise no
 * delete route -- a cancelled or mistaken Examination is marked
 * `inactive` and kept (CLAUDE.md rule 73), because a future
 * ExaminationPaper or mark will reference it.
 *
 * @property string $id
 * @property string $school_id
 * @property string $academic_year_id
 * @property string $code uppercased/trimmed on assignment
 * @property string $name human label; deliberately NOT unique
 * @property Carbon $starts_on School calendar date
 * @property Carbon $ends_on School calendar date; >= starts_on
 * @property string $status active|inactive
 */
class Examination extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory, NormalizesCode;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /**
     * The complete, closed status vocabulary. Mirrored by the database's
     * own `examinations_status_check` CHECK constraint, which is the
     * authoritative guarantee. Deliberately NOT a draft/active/closed
     * state machine: every concern such a machine would serve (papers
     * frozen after closure, marks frozen, results published, archived)
     * belongs to a checkpoint that does not exist yet.
     */
    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE];

    protected $table = 'examinations';

    /**
     * `academic_year_id` is deliberately excluded -- the parent is fixed
     * at creation from the trusted nested route and can never be
     * reassigned, so no mass-assignment path may reach it. `school_id`
     * is filled by BelongsToSchool from TenantContext, never from
     * request input (CLAUDE.md rule 19).
     */
    protected $fillable = ['school_id', 'code', 'name', 'starts_on', 'ends_on', 'status'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    protected static function newFactory(): ExaminationFactory
    {
        return ExaminationFactory::new();
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** @return BelongsTo<AcademicYear, $this> */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'academic_year_id');
    }
}
