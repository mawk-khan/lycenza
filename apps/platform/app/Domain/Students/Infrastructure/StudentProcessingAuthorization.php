<?php

namespace App\Domain\Students\Infrastructure;

use App\Domain\Guardians\Infrastructure\StudentGuardianRelationship;
use App\Domain\Students\Domain\ProcessingAuthorizationBasisType;
use App\Domain\Students\Domain\ProcessingAuthorizationPurpose;
use App\Domain\Students\Domain\ProcessingAuthorizationStatus;
use App\Models\User;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Phase 0H.4D-P2 -- one append-only fact: a School's processing-
 * authorization decision for one Student and one purpose. Never
 * updated or deleted (database-enforced,
 * App\Support\Tenancy\TenantRls::makeAppendOnly -- see the creating
 * migration's docblock for the full design rationale). "Current"
 * qualifying state is derived, never stored, by
 * StudentProcessingAuthorizationReadService.
 *
 * @property string $id
 * @property string $school_id
 * @property string $student_id
 * @property ProcessingAuthorizationPurpose $purpose
 * @property ProcessingAuthorizationBasisType $basis_type
 * @property ProcessingAuthorizationStatus $status
 * @property string|null $terminates_authorization_id
 * @property string|null $student_guardian_relationship_id
 * @property Carbon $recorded_at
 * @property string $recorded_by_user_id
 * @property string|null $note
 */
class StudentProcessingAuthorization extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    protected $table = 'student_processing_authorizations';

    protected $fillable = [
        'school_id', 'student_id', 'purpose', 'basis_type', 'status',
        'terminates_authorization_id', 'student_guardian_relationship_id',
        'recorded_at', 'recorded_by_user_id', 'note',
    ];

    protected function casts(): array
    {
        return [
            'purpose' => ProcessingAuthorizationPurpose::class,
            'basis_type' => ProcessingAuthorizationBasisType::class,
            'status' => ProcessingAuthorizationStatus::class,
            'recorded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<StudentGuardianRelationship, $this> */
    public function studentGuardianRelationship(): BelongsTo
    {
        return $this->belongsTo(StudentGuardianRelationship::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    /** @return BelongsTo<self, $this> */
    public function terminatesAuthorization(): BelongsTo
    {
        return $this->belongsTo(self::class, 'terminates_authorization_id');
    }

    public function isGrant(): bool
    {
        return $this->status === ProcessingAuthorizationStatus::Recorded;
    }
}
