<?php

namespace App\Domain\HR\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\EmployeeQualificationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Phase 8A.6 -- one of an Employee's 1:N Restricted-tier academic
 * qualifications (docs/modules/HR.md). The only sanctioned write path
 * is App\Domain\HR\Application\EmployeeQualificationService -- never
 * create/update this model directly outside a test, since the service
 * verifies Employee ownership, strips caller-controlled
 * `school_id`/`employee_id`, and owns the verification-reset rule (a
 * material edit to a verified/rejected record resets
 * `verification_status` back to 'unverified').
 *
 * @property string $id
 * @property string $school_id
 * @property string $employee_id
 * @property string $qualification_type secondary|higher_secondary|diploma|bachelors|masters|doctorate|professional|other
 * @property string $qualification_name
 * @property string|null $specialization
 * @property string $institution
 * @property string|null $awarding_body
 * @property string|null $country_code
 * @property Carbon|null $starts_on
 * @property Carbon|null $completed_on
 * @property string|null $grade_or_result
 * @property string $verification_status unverified|verified|rejected
 * @property Carbon|null $verified_at
 */
class EmployeeQualification extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $table = 'employee_qualifications';

    protected $fillable = [
        'school_id',
        'employee_id',
        'qualification_type',
        'qualification_name',
        'specialization',
        'institution',
        'awarding_body',
        'country_code',
        'starts_on',
        'completed_on',
        'grade_or_result',
        'verification_status',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'completed_on' => 'date',
            'verified_at' => 'datetime',
        ];
    }

    protected static function newFactory(): EmployeeQualificationFactory
    {
        return EmployeeQualificationFactory::new();
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
