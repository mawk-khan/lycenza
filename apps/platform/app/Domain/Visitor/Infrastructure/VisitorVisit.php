<?php

namespace App\Domain\Visitor\Infrastructure;

use App\Domain\HR\Infrastructure\Employee;
use App\Models\Campus;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\VisitorVisitFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A Visitor's check-in/check-out visit to a Campus
 * (docs/modules/VISITOR.md "Visit lifecycle"). Every relationship uses
 * an explicit foreign key -- never Laravel's default
 * `snake_case(ClassName)_id` convention (the exact Phase 10B
 * `TransportRoute` relationship-naming lesson).
 *
 * @property string $id
 * @property string $school_id
 * @property string $visitor_id
 * @property string $campus_id
 * @property string|null $host_employee_id
 * @property string $purpose
 * @property string|null $gate_pass_number
 * @property string $status checked_in|checked_out
 * @property Carbon $checked_in_at
 * @property Carbon|null $checked_out_at
 */
class VisitorVisit extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $table = 'visitor_visits';

    protected $fillable = [
        'school_id', 'visitor_id', 'campus_id', 'host_employee_id',
        'purpose', 'gate_pass_number', 'status', 'checked_in_at', 'checked_out_at',
    ];

    protected static function newFactory(): VisitorVisitFactory
    {
        return VisitorVisitFactory::new();
    }

    protected function casts(): array
    {
        return [
            'checked_in_at' => 'datetime',
            'checked_out_at' => 'datetime',
        ];
    }

    public function isCheckedIn(): bool
    {
        return $this->status === 'checked_in';
    }

    /** @return BelongsTo<Visitor, $this> */
    public function visitor(): BelongsTo
    {
        return $this->belongsTo(Visitor::class, 'visitor_id');
    }

    /** @return BelongsTo<Campus, $this> */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class, 'campus_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function hostEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'host_employee_id');
    }
}
