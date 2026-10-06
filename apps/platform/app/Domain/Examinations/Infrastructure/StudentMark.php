<?php

namespace App\Domain\Examinations\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * RES.2 (ADR 0068 §6, §19): one Student's recorded status and mark on one
 * ExaminationPaper -- Highly Sensitive children's educational data, written
 * only by App\Domain\Examinations\Application\Marks\StudentMarkService.
 *
 * It keeps the context it was written under (the P3 placement, the
 * eligibility source, the elective row, the ADR 0038 authorization). No
 * remark, grade, percentage, pass/fail, rank or publication field exists,
 * and none may be added (StudentMarkArchitectureGuardTest). Every write is
 * copied to StudentMarkRevision by the database.
 *
 * @property string $id
 * @property string $school_id
 * @property string $examination_paper_id
 * @property string $academic_year_id
 * @property string $student_id
 * @property string $student_enrollment_id
 * @property string $eligibility_source
 * @property string|null $student_subject_enrollment_id
 * @property string $processing_authorization_id
 * @property string $status
 * @property string|null $value
 * @property int $version
 * @property string $recorded_by_user_id
 */
class StudentMark extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const STATUS_PRESENT = 'present';

    public const STATUS_ABSENT = 'absent';

    public const STATUS_EXEMPT = 'exempt';

    public const STATUSES = [self::STATUS_PRESENT, self::STATUS_ABSENT, self::STATUS_EXEMPT];

    protected $table = 'student_marks';

    /** Written only through StudentMarkService (forceFill); nothing is mass-assignable. */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'version' => 'integer',
        ];
    }
}
