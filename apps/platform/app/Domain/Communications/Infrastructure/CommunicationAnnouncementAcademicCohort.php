<?php

namespace App\Domain\Communications\Infrastructure;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Communications\Domain\CommunicationAcademicCohortRecipientKind;
use App\Domain\Communications\Domain\CommunicationAcademicCohortType;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 5B.3 -- the authored academic-cohort audience definition for
 * one Announcement (`audience_type = grade`/`section`/`subject_offering`,
 * Phase 5C.1 adds the third). Exactly one of `grade_level_id`/
 * `section_id`/`subject_offering_id` is set per row (database-enforced),
 * tied to `cohort_type`. See the creating migration's docblock.
 *
 * @property string $id
 * @property string $school_id
 * @property string $announcement_id
 * @property string $cohort_type
 * @property string $academic_year_id
 * @property string|null $grade_level_id
 * @property string|null $section_id
 * @property string|null $subject_offering_id
 * @property string $recipient_kind
 */
class CommunicationAnnouncementAcademicCohort extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    protected $table = 'communication_announcement_academic_cohorts';

    protected $fillable = [
        'school_id', 'announcement_id', 'cohort_type', 'academic_year_id',
        'grade_level_id', 'section_id', 'subject_offering_id', 'recipient_kind',
    ];

    /** @return BelongsTo<CommunicationAnnouncement, $this> */
    public function announcement(): BelongsTo
    {
        return $this->belongsTo(CommunicationAnnouncement::class, 'announcement_id');
    }

    /** @return BelongsTo<AcademicYear, $this> */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /** @return BelongsTo<GradeLevel, $this> */
    public function gradeLevel(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class);
    }

    /** @return BelongsTo<Section, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /** @return BelongsTo<SubjectOffering, $this> */
    public function subjectOffering(): BelongsTo
    {
        return $this->belongsTo(SubjectOffering::class);
    }

    public function cohortTypeEnum(): CommunicationAcademicCohortType
    {
        return CommunicationAcademicCohortType::from($this->cohort_type);
    }

    public function recipientKindEnum(): CommunicationAcademicCohortRecipientKind
    {
        return CommunicationAcademicCohortRecipientKind::from($this->recipient_kind);
    }
}
