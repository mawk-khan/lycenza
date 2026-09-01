<?php

namespace App\Domain\Syllabus\Infrastructure;

use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\NormalizesCode;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\SyllabusUnitFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned unit of instructional content a SubjectOffering is
 * expected to cover (Phase 0H.3A -- the first concrete Academics
 * fact). Persistence + relationship only; the controller is the
 * sanctioned write path (CLAUDE.md rule 76 -- this entity has no
 * date-range invariant, no overlap validation, no lock, no multi-row
 * mutation and no state machine, so a dedicated Application service
 * would be ceremony, not architecture).
 *
 * EXPECTED content, not delivered content. A SyllabusUnit says what
 * ought to be taught; it records nothing about what actually was (a
 * future Curriculum Delivery checkpoint), nothing about an individual
 * lesson (future Lesson Planning) and nothing about any Student.
 * Grading/marks/grade scales are Examinations' scope; assignments and
 * submissions are LMS's. See docs/modules/ACADEMICS.md.
 *
 * ONE PARENT. `subject_offering_id` already implies AcademicYear,
 * Campus, GradeLevel and Subject, so none of those is duplicated here
 * (see the migration docblock for why the composite-context pattern
 * does not apply to a single-parent table).
 *
 * NO LIFECYCLE COMMANDS. `status` moves through the ordinary update
 * operation, exactly like Section/SubjectOffering/Room/Subject/
 * GradeLevel. Entities that DO get activate()/deactivate() in this
 * codebase (AcademicYear, TimetablePeriod, TimetableEntry) all
 * re-validate a real invariant on activation -- overlap, conflict, or
 * one-active-per-School. Reactivating a SyllabusUnit can conflict with
 * nothing: its only constraint is
 * `syllabus_units_offering_code_ci_unique`, which is unconditional, so
 * an inactive unit already reserves its code and reactivation is
 * always safe. There is likewise no delete route -- a retired unit is
 * marked `inactive` and kept (CLAUDE.md rule 73), because a future
 * Curriculum Delivery or Examinations row may reference it.
 *
 * @property string $id
 * @property string $school_id
 * @property string $subject_offering_id
 * @property string $code uppercased/trimmed on assignment
 * @property string $title
 * @property int $sequence teaching order within the Offering; NOT unique
 * @property string $status active|inactive
 */
class SyllabusUnit extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory, NormalizesCode;

    /**
     * The complete, closed status vocabulary. Mirrored by the
     * database's own `syllabus_units_status_check` CHECK constraint,
     * which is the authoritative guarantee.
     */
    public const STATUSES = ['active', 'inactive'];

    protected $table = 'syllabus_units';

    protected $fillable = ['school_id', 'subject_offering_id', 'code', 'title', 'sequence', 'status'];

    protected function casts(): array
    {
        return ['sequence' => 'integer'];
    }

    protected static function newFactory(): SyllabusUnitFactory
    {
        return SyllabusUnitFactory::new();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** @return BelongsTo<SubjectOffering, $this> */
    public function subjectOffering(): BelongsTo
    {
        return $this->belongsTo(SubjectOffering::class, 'subject_offering_id');
    }
}
