<?php

namespace App\Domain\Communications\Infrastructure;

use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Models\User;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * ORG.9D-3 (ADR 0076 §75) -- one School calendar Event: informational
 * only, never Attendance, closure, timetable or exam truth. Written only by
 * App\Domain\Communications\Application\Events\CommunicationEventService;
 * once published its terms are database-immutable and it can only be
 * cancelled (communication_events_published_guard).
 *
 * @property string $id
 * @property string $school_id
 * @property string $created_by_user_id
 * @property string $title
 * @property string $body
 * @property string|null $location
 * @property string $date_mode
 * @property Carbon|null $starts_on
 * @property Carbon|null $ends_on
 * @property CarbonImmutable|null $starts_at
 * @property CarbonImmutable|null $ends_at
 * @property string $audience_type
 * @property string|null $grade_level_id
 * @property string|null $section_id
 * @property string $status
 * @property CarbonImmutable|null $published_at
 * @property string|null $published_by_user_id
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancelled_by_user_id
 */
class CommunicationEvent extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const ALL_DAY = 'all_day';

    public const TIMED = 'timed';

    /** The existing Communications audience types an Event may use (v1). */
    public const AUDIENCES = ['school_wide', 'staff', 'grade', 'section'];

    /** Editable states: an edit to an approved Event returns it to draft. */
    public const EDITABLE = ['draft', 'rejected', 'approved'];

    protected $table = 'communication_events';

    protected $fillable = [
        'school_id', 'created_by_user_id', 'title', 'body', 'location', 'date_mode', 'starts_on', 'ends_on', 'starts_at', 'ends_at',
        'audience_type', 'grade_level_id', 'section_id', 'status',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date:Y-m-d',
            'ends_on' => 'date:Y-m-d',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
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

    public function isAllDay(): bool
    {
        return $this->date_mode === self::ALL_DAY;
    }

    public function isEditable(): bool
    {
        return in_array($this->status, self::EDITABLE, true);
    }

    public function wasPublished(): bool
    {
        return $this->published_at !== null;
    }
}
