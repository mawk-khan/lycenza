<?php

namespace App\Domain\Hostel\Infrastructure;

use App\Domain\Students\Infrastructure\Student;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\HostelResidencyAssignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A Student's Hostel residency assignment to a Bed
 * (docs/modules/HOSTEL.md "ResidencyAssignment lifecycle"). Every
 * relationship uses an explicit foreign key -- never Laravel's default
 * `snake_case(ClassName)_id` convention (the Phase 10B `TransportRoute`
 * relationship-naming lesson).
 *
 * @property string $id
 * @property string $school_id
 * @property string $student_id
 * @property string $hostel_bed_id
 * @property string $status active|ended
 * @property Carbon $starts_on
 * @property Carbon|null $ends_on
 */
class HostelResidencyAssignment extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $table = 'hostel_residency_assignments';

    protected $fillable = ['school_id', 'student_id', 'hostel_bed_id', 'status', 'starts_on', 'ends_on'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'datetime',
            'ends_on' => 'datetime',
        ];
    }

    protected static function newFactory(): HostelResidencyAssignmentFactory
    {
        return HostelResidencyAssignmentFactory::new();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    /** @return BelongsTo<HostelBed, $this> */
    public function bed(): BelongsTo
    {
        return $this->belongsTo(HostelBed::class, 'hostel_bed_id');
    }
}
