<?php

namespace App\Domain\Examinations\Infrastructure;

use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\ExaminationPaperFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Tenant-owned scheduling fact (Phase 0H.4B): one SubjectOffering
 * assessed within one Examination, with its scheduled sitting (date and
 * time range) and maximum obtainable marks.
 *
 * An Examination CHILD and an OFFERING-WIDE scheduling fact -- NOT a
 * physical uploaded question-paper file, NOT Section-specific, NOT a
 * Student attempt, NOT a mark/result, NOT an LMS assignment.
 *
 * `App\Domain\Examinations\Application\ExaminationPaperService` is the
 * ONLY sanctioned write path. `examination_id` and `subject_offering_id`
 * are immutable after create -- deliberately absent from `$fillable`, so
 * no mass-assignment path can reach them; the service force-fills every
 * context/pin field.
 *
 * @property string $id
 * @property string $school_id
 * @property string $examination_id
 * @property string $subject_offering_id
 * @property string $academic_year_id internal integrity pin, never exposed
 * @property string $campus_id internal integrity pin, never exposed
 * @property string $grade_level_id internal integrity pin, never exposed
 * @property Carbon $scheduled_on School calendar date; within the Examination's window
 * @property string $starts_at School-local wall-clock time, HH:MM:SS
 * @property string $ends_at School-local wall-clock time, HH:MM:SS; > starts_at
 * @property string $max_marks decimal string, > 0
 * @property string $status active|inactive
 */
class ExaminationPaper extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /**
     * The complete, closed status vocabulary, mirrored by the database's
     * own `examination_papers_status_check` CHECK constraint. Not a
     * draft/scheduled/completed/closed/cancelled/published state
     * machine -- see docs/modules/EXAMINATIONS.md.
     */
    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE];

    protected $table = 'examination_papers';

    /**
     * `examination_id`, `subject_offering_id` and every integrity pin
     * (`academic_year_id`, `campus_id`, `grade_level_id`) are
     * deliberately excluded -- all are force-filled by
     * ExaminationPaperService from trusted, server-resolved parents,
     * never from request input (CLAUDE.md rules 19/68).
     */
    protected $fillable = ['school_id', 'scheduled_on', 'starts_at', 'ends_at', 'max_marks', 'status'];

    protected function casts(): array
    {
        return [
            'scheduled_on' => 'date',
            'max_marks' => 'decimal:2',
        ];
    }

    protected static function newFactory(): ExaminationPaperFactory
    {
        return ExaminationPaperFactory::new();
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** @return BelongsTo<Examination, $this> */
    public function examination(): BelongsTo
    {
        return $this->belongsTo(Examination::class);
    }

    /** @return BelongsTo<SubjectOffering, $this> */
    public function subjectOffering(): BelongsTo
    {
        return $this->belongsTo(SubjectOffering::class);
    }
}
