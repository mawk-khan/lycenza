<?php

namespace App\Domain\HR\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\EmployeeCertificationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Phase 8A.6 -- one of an Employee's 1:N Restricted-tier professional
 * certifications/licences (docs/modules/HR.md). The only sanctioned
 * write path is App\Domain\HR\Application\EmployeeCertificationService
 * -- never create/update this model directly outside a test, since the
 * service verifies Employee ownership, strips caller-controlled
 * `school_id`/`employee_id`, and owns the verification-reset rule.
 *
 * `credential_number` is deliberately not unique at any scope -- see
 * the migration's docblock.
 *
 * @property string $id
 * @property string $school_id
 * @property string $employee_id
 * @property string $name
 * @property string $issuer
 * @property string|null $credential_number
 * @property Carbon|null $issued_on
 * @property Carbon|null $expires_on
 * @property string $verification_status unverified|verified|rejected
 * @property Carbon|null $verified_at
 * @property Carbon|null $archived_at
 * @property string|null $archived_by_user_id
 * @property string|null $archive_reason
 */
class EmployeeCertification extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory;

    protected $table = 'employee_certifications';

    /** HR.R1-C13 (GOV-6): descriptive fields only. The owner, parent and state columns come from its writer. */
    protected $fillable = ['name', 'issuer', 'credential_number', 'issued_on', 'expires_on'];

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
            'issued_on' => 'date',
            'expires_on' => 'date',
            'verified_at' => 'datetime',
        ];
    }

    protected static function newFactory(): EmployeeCertificationFactory
    {
        return EmployeeCertificationFactory::new();
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
