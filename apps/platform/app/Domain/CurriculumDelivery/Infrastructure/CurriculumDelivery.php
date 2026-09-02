<?php

namespace App\Domain\CurriculumDelivery\Infrastructure;

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Syllabus\Infrastructure\SyllabusUnit;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\CurriculumDeliveryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Tenant-owned record that one Section has covered one SyllabusUnit --
 * when that Section began it, and when, if yet, it finished (Phase
 * 0H.3B, the second concrete Academics fact).
 *
 * ACTUAL instructional coverage by a COHORT, the counterpart to
 * Phase 0H.3A's `SyllabusUnit` catalogue of EXPECTED content. It
 * records nothing about an individual lesson (future Lesson Planning),
 * nothing about who taught it (no teacher identity anywhere -- see
 * below), and nothing about any Student. Grading/marks/grade scales
 * are Examinations' scope; assignments, submissions and learning
 * content are LMS's. See docs/modules/ACADEMICS.md.
 *
 * `App\Domain\CurriculumDelivery\Application\CurriculumDeliveryService`
 * is the ONLY sanctioned write path (CLAUDE.md rule 76 -- unlike
 * SyllabusUnit, this entity has real invariants: multi-parent
 * consistency, School-local date validation against the AcademicYear,
 * a two-state machine with expected-status compare-and-swap, and a row
 * lock). Neither controller writes it directly; proven by
 * Tests\Feature\CurriculumDelivery\CurriculumDeliveryArchitectureGuardTest.
 *
 * INTEGRITY PINS, NOT CLIENT DATA. `subject_offering_id`,
 * `academic_year_id`, `campus_id` and `grade_level_id` exist solely as
 * components of the three composite foreign keys that make a
 * cross-context reference structurally impossible (see the migration
 * docblock). They are derived server-side by the service from the
 * resolved Section and SyllabusUnit, and are deliberately absent from
 * `$fillable` so no mass assignment anywhere can set them from request
 * input.
 *
 * TWO STORED STATES. `in_progress` and `completed` only;
 * `not_started` is never stored, because the ABSENCE of a row already
 * means not started. Anything needing "every unit with its state"
 * LEFT JOINs from `syllabus_units` -- see
 * App\Http\Controllers\App\CurriculumDelivery\CurriculumDeliveryController
 * for the canonical projection. Legal transitions are exactly
 * in_progress -> completed and completed -> in_progress.
 *
 * NO DELETE and no archive/activate/deactivate. These rows are
 * historical instructional activity (CLAUDE.md rule 73); a mistake is
 * corrected through the ordinary date correction or a reopen, never by
 * erasing history.
 *
 * @property string $id
 * @property string $school_id
 * @property string $section_id
 * @property string $syllabus_unit_id
 * @property string $subject_offering_id integrity pin
 * @property string $academic_year_id integrity pin
 * @property string $campus_id integrity pin
 * @property string $grade_level_id integrity pin
 * @property Carbon $started_on School-local calendar date
 * @property Carbon|null $completed_on School-local calendar date; NULL iff in_progress
 * @property string $status in_progress|completed
 */
class CurriculumDelivery extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    /**
     * The complete, closed status vocabulary. Mirrored by the
     * database's own `curriculum_deliveries_status_check`, which is the
     * authoritative guarantee. `not_started` is deliberately NOT a
     * member -- it is the absence of a row, never a stored value.
     */
    public const STATUSES = [self::STATUS_IN_PROGRESS, self::STATUS_COMPLETED];

    protected $table = 'curriculum_deliveries';

    /**
     * Only the caller-chosen facts and the state the service computes.
     * The four integrity pins are deliberately excluded -- the service
     * sets them with forceFill() from resolved parents, so no request
     * payload can ever reach them (CLAUDE.md rule 19's principle
     * applied to structural context, not just school_id).
     */
    protected $fillable = ['school_id', 'section_id', 'syllabus_unit_id', 'started_on', 'completed_on', 'status'];

    protected function casts(): array
    {
        return [
            'started_on' => 'date',
            'completed_on' => 'date',
        ];
    }

    protected static function newFactory(): CurriculumDeliveryFactory
    {
        return CurriculumDeliveryFactory::new();
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /** @return BelongsTo<Section, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'section_id');
    }

    /** @return BelongsTo<SyllabusUnit, $this> */
    public function syllabusUnit(): BelongsTo
    {
        return $this->belongsTo(SyllabusUnit::class, 'syllabus_unit_id');
    }

    /** @return BelongsTo<SubjectOffering, $this> */
    public function subjectOffering(): BelongsTo
    {
        return $this->belongsTo(SubjectOffering::class, 'subject_offering_id');
    }
}
