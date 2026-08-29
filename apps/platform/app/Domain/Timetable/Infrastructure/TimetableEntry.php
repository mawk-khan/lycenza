<?php

namespace App\Domain\Timetable\Infrastructure;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\Room;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\HR\Infrastructure\Employee;
use App\Models\Campus;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\TimetableEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned single scheduled slot (Phase 0H): a SubjectOffering
 * taught to a Section by a teacher (HR Employee) in an optional Room
 * during a TimetablePeriod on a given day-of-week. Persistence +
 * relationships only -- `App\Domain\Timetable\Application\TimetableScheduleService`
 * is the sole write path, including every validation/conflict rule
 * documented on that class.
 *
 * `academic_year_id`/`campus_id`/`grade_level_id` are always DERIVED
 * from the resolved SubjectOffering by the service, never accepted
 * from caller input directly -- see TimetableScheduleService's own
 * docblock. They exist on this row so the database's own composite FKs
 * (see the `create_timetable_entries_table` migration) can structurally
 * pin both the SubjectOffering and the Section references to the exact
 * same context (CLAUDE.md rule 70).
 *
 * @property string $id
 * @property string $school_id
 * @property string $academic_year_id
 * @property string $campus_id
 * @property string $grade_level_id
 * @property string $subject_offering_id
 * @property string $section_id
 * @property string $teacher_id
 * @property string|null $room_id
 * @property string $period_id
 * @property int $day_of_week 1 (Monday) .. 7 (Sunday)
 * @property string $status active|inactive
 */
class TimetableEntry extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $table = 'timetable_entries';

    protected $fillable = [
        'school_id', 'academic_year_id', 'campus_id', 'grade_level_id',
        'subject_offering_id', 'section_id', 'teacher_id', 'room_id', 'period_id',
        'day_of_week', 'status',
    ];

    protected static function newFactory(): TimetableEntryFactory
    {
        return TimetableEntryFactory::new();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** @return BelongsTo<AcademicYear, $this> */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /** @return BelongsTo<Campus, $this> */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    /** @return BelongsTo<SubjectOffering, $this> */
    public function subjectOffering(): BelongsTo
    {
        return $this->belongsTo(SubjectOffering::class, 'subject_offering_id');
    }

    /** @return BelongsTo<Section, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'section_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'teacher_id');
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id');
    }

    /** @return BelongsTo<TimetablePeriod, $this> */
    public function period(): BelongsTo
    {
        return $this->belongsTo(TimetablePeriod::class, 'period_id');
    }
}
