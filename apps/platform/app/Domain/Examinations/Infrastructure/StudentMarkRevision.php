<?php

namespace App\Domain\Examinations\Infrastructure;

use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * RES.2 (ADR 0068 §19.3 a): the insert-only value history of one StudentMark
 * -- one row per write, pre-lock included. Written ONLY by the database
 * (`student_marks_record_revision`); a direct insert is refused, and the
 * runtime role can neither update nor delete it. Read-only here.
 *
 * @property string $student_mark_id
 * @property int $revision
 * @property string|null $previous_status
 * @property string|null $previous_value
 * @property string $new_status
 * @property string|null $new_value
 * @property string $processing_authorization_id
 * @property string $recorded_by_user_id
 */
class StudentMarkRevision extends Model
{
    use BelongsToSchool;

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'student_mark_revisions';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'previous_value' => 'decimal:2',
            'new_value' => 'decimal:2',
            'recorded_at' => 'immutable_datetime',
        ];
    }
}
