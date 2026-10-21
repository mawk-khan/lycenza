<?php

namespace App\Domain\Guardians\Application;

use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * ORG.8A-R1 (ADR 0076 §59): is this User a Guardian of this Student in this
 * School -- any Guardian identity that belongs to them
 * (`GuardianAccountOwnership`, active or revoked links) with a CURRENT
 * `student_guardian_relationships` row to the Student, of any relationship
 * type? Structural ids only, never a name or contact. Not portal
 * eligibility (that stays `GuardianStudentScope`, unchanged). Used for a
 * decider's beneficiary conflict; it grants nothing.
 */
final class GuardianBeneficiaryRelationship
{
    public function __construct(
        private readonly GuardianAccountOwnership $ownership,
        private readonly TenantContext $context,
    ) {}

    public function isGuardianOf(User $user, School $school, string $studentId): bool
    {
        $guardianIds = $this->ownership->guardianIdsOwnedBy($user, $school);
        if ($guardianIds === []) {
            return false;
        }

        return $this->context->withSchool($school, fn (): bool => DB::table('student_guardian_relationships')
            ->where('school_id', $school->id)
            ->where('student_id', $studentId)
            ->whereIn('guardian_id', $guardianIds)
            ->exists());
    }
}
