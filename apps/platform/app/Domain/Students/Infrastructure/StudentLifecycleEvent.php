<?php

namespace App\Domain\Students\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ORG.8E (ADR 0076 §63): one high-impact Student lifecycle action -- its
 * closed reason and actor. Append-only; `occurred_at` is the database's
 * transaction timestamp (never set by the application), which is what binds
 * an Enrollment withdrawal or cancellation to its event.
 *
 * @property string $id
 * @property string $school_id
 * @property string $student_id
 * @property string|null $student_enrollment_id
 * @property string $action
 * @property string $reason_code
 * @property string $acted_by_user_id
 * @property Carbon $occurred_at
 */
class StudentLifecycleEvent extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public $timestamps = false;

    protected $table = 'student_lifecycle_events';

    protected $fillable = ['school_id', 'student_id', 'student_enrollment_id', 'action', 'reason_code', 'acted_by_user_id'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }
}
