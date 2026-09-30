<?php

namespace App\Domain\LMS\Infrastructure;

use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\LearningContentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant-owned School-authored instructional resource -- Phase 0I.2,
 * the first concrete LMS fact (ADR 0039, docs/modules/LMS.md). A
 * reading, a link, a note, or an attached file (via the Documents
 * `learning_content` owner arm) belonging to one SubjectOffering.
 * Persistence + relationship only; App\Domain\LMS\Application\
 * LearningContentService is the sole sanctioned write path (this
 * entity DOES need a dedicated Application service, unlike SyllabusUnit
 * -- CLAUDE.md rule 76's trigger is the lifecycle state machine plus
 * the aggregate-local lock its transitions need, the identical shape
 * ADR 0035's GradeScale already established).
 *
 * ONE PARENT. `subject_offering_id` already implies AcademicYear,
 * Campus, GradeLevel and Subject, so none of those is duplicated here
 * -- the identical reasoning `SyllabusUnit`'s own migration docblock
 * records for the same single-parent shape.
 *
 * NO AUTHOR COLUMN. No `created_by_employee_id` or `teacher_id` exists
 * here -- ADR 0039 decision 6 is explicit that no teacher-to-Offering
 * ownership record exists in this codebase yet; authorization is
 * capability-only in v1 (`lms.content.view`/`lms.content.manage`),
 * matching SyllabusUnit/CurriculumDelivery/Examination/ExaminationPaper.
 *
 * @property string $id
 * @property string $school_id
 * @property string $subject_offering_id
 * @property string $title
 * @property string|null $description
 * @property int $sequence display order within the Offering; NOT unique
 * @property string $status draft|published|archived
 */
class LearningContent extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    /**
     * The complete, closed status vocabulary. Mirrored by the
     * database's own `learning_content_status_check` CHECK constraint,
     * which is the authoritative guarantee.
     */
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_PUBLISHED, self::STATUS_ARCHIVED];

    protected $table = 'learning_content';

    protected $fillable = ['school_id', 'subject_offering_id', 'title', 'description', 'sequence', 'status'];

    protected function casts(): array
    {
        return ['sequence' => 'integer'];
    }

    protected static function newFactory(): LearningContentFactory
    {
        return LearningContentFactory::new();
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function isArchived(): bool
    {
        return $this->status === self::STATUS_ARCHIVED;
    }

    /** @return BelongsTo<SubjectOffering, $this> */
    public function subjectOffering(): BelongsTo
    {
        return $this->belongsTo(SubjectOffering::class, 'subject_offering_id');
    }

    /**
     * TCH.5B: the Section audience of a teacher-owned row (none for an
     * Offering-wide row). `owner_employee_id` is deliberately not
     * fillable: it is written only at creation and never changes.
     */
    public function sectionAudiences(): HasMany
    {
        return $this->hasMany(LearningContentSectionAudience::class, 'learning_content_id');
    }
}
