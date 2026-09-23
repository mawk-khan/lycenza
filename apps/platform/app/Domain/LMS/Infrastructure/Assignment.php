<?php

namespace App\Domain\LMS\Infrastructure;

use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\AssignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Tenant-owned staff-authored unit of work -- Phase 0I.3, the second
 * concrete LMS fact (ADR 0037, docs/modules/LMS.md). Title,
 * instructions, an optional due date, optional resource attachments
 * (via the Documents `assignment_id` owner arm), and a lifecycle that a
 * SubjectOffering's roster is expected to complete. Never itself a
 * grade-bearing record -- no mark/score/grade field exists here or may
 * ever be added without a reviewed architecture decision (proven by
 * Tests\Feature\LMS\AssignmentArchitectureGuardTest).
 *
 * Persistence + relationship only; App\Domain\LMS\Application\
 * AssignmentService is the sole sanctioned write path -- required here
 * (unlike SyllabusUnit's thin controller) because the lifecycle state
 * machine plus its aggregate-local lock are rule 76's literal trigger,
 * the identical shape LearningContentService/GradeScaleService already
 * established.
 *
 * ONE PARENT. `subject_offering_id` already implies AcademicYear,
 * Campus, GradeLevel and Subject, so none of those is duplicated here
 * -- the same reasoning `LearningContent`/`SyllabusUnit` already
 * record.
 *
 * NO AUTHOR COLUMN. No `created_by_employee_id` or `teacher_id` exists
 * here -- ADR 0037 decision 6 is explicit that no teacher-to-Offering
 * ownership record exists in this codebase yet; authorization is
 * capability-only in v1 (`lms.assignments.view`/`lms.assignments.manage`),
 * matching every sibling academic entity.
 *
 * @property string $id
 * @property string $school_id
 * @property string $subject_offering_id
 * @property string $title
 * @property string|null $instructions
 * @property Carbon|null $due_on School-local calendar date; nullable while draft, required before publish()
 * @property string $status draft|published|closed
 */
class Assignment extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    /**
     * The complete, closed status vocabulary. Mirrored by the
     * database's own `assignments_status_check` CHECK constraint,
     * which is the authoritative guarantee.
     */
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_PUBLISHED, self::STATUS_CLOSED];

    protected $table = 'assignments';

    protected $fillable = ['school_id', 'subject_offering_id', 'title', 'instructions', 'due_on', 'status'];

    protected function casts(): array
    {
        return ['due_on' => 'date'];
    }

    protected static function newFactory(): AssignmentFactory
    {
        return AssignmentFactory::new();
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    /** @return BelongsTo<SubjectOffering, $this> */
    public function subjectOffering(): BelongsTo
    {
        return $this->belongsTo(SubjectOffering::class, 'subject_offering_id');
    }
}
