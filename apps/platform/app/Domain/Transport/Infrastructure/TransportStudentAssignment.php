<?php

namespace App\Domain\Transport\Infrastructure;

use App\Domain\Students\Infrastructure\Student;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\TransportStudentAssignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One Student's Transport assignment -- a Route plus an optional
 * pickup Stop and an optional drop-off Stop, both structurally
 * required to belong to the assigned Route (see the migration's
 * composite FK). Never write directly; the sole sanctioned write path
 * is App\Domain\Transport\Application\TransportStudentAssignmentService.
 * See docs/modules/TRANSPORT.md "Student assignment model".
 *
 * @property string $id
 * @property string $school_id
 * @property string $student_id
 * @property string $route_id
 * @property string|null $pickup_stop_id
 * @property string|null $dropoff_stop_id
 * @property string $status active|ended
 * @property Carbon $starts_on
 * @property Carbon|null $ends_on
 */
class TransportStudentAssignment extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $table = 'transport_student_assignments';

    protected $fillable = ['school_id', 'student_id', 'route_id', 'pickup_stop_id', 'dropoff_stop_id', 'status', 'starts_on', 'ends_on'];

    protected static function newFactory(): TransportStudentAssignmentFactory
    {
        return TransportStudentAssignmentFactory::new();
    }

    protected function casts(): array
    {
        return [
            'starts_on' => 'datetime',
            'ends_on' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<TransportRoute, $this> */
    public function route(): BelongsTo
    {
        return $this->belongsTo(TransportRoute::class, 'route_id');
    }

    /** @return BelongsTo<TransportStop, $this> */
    public function pickupStop(): BelongsTo
    {
        return $this->belongsTo(TransportStop::class, 'pickup_stop_id');
    }

    /** @return BelongsTo<TransportStop, $this> */
    public function dropoffStop(): BelongsTo
    {
        return $this->belongsTo(TransportStop::class, 'dropoff_stop_id');
    }
}
