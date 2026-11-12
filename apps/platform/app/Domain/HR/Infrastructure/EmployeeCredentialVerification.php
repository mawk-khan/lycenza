<?php

namespace App\Domain\HR\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * HR.R1-C29 (C14-N5, C29-B): one Verify or Reject decision on one revision of a qualification or certification.
 * Append-only: the runtime role inserts it and nothing updates it, and the insert itself sets the credential's
 * verification (`trg_employee_credential_verifications_apply`). It records which revision was decided, never a
 * copy of the credential's fields. Written only by
 * App\Domain\HR\Application\Credentials\CredentialVerificationDecisions; it leaves with its credential.
 *
 * @property string $id
 * @property int $sequence
 * @property string $school_id
 * @property string $employee_id
 * @property string $credential_kind qualification or certification
 * @property string|null $qualification_id
 * @property string|null $certification_id
 * @property int $revision
 * @property string $decision verified or rejected
 * @property string $decided_by_user_id
 * @property Carbon $decided_at
 */
class EmployeeCredentialVerification extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const QUALIFICATION = 'qualification';

    public const CERTIFICATION = 'certification';

    public const VERIFIED = 'verified';

    public const REJECTED = 'rejected';

    protected $table = 'employee_credential_verifications';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'revision' => 'integer', 'decided_at' => 'datetime'];
    }
}
