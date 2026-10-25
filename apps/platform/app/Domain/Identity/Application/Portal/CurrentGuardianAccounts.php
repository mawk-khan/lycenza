<?php

namespace App\Domain\Identity\Application\Portal;

use App\Domain\Guardians\Application\GuardianStudentScope;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * ORG.9D-2 (ADR 0076 §74): the set-based form of ActingGuardianResolver's
 * identity chain -- "which User accounts are, right now, a current Guardian
 * of each of these Students in this School?":
 *
 *   an eligible Guardian <-> Student relationship (GuardianStudentScope:
 *   is_legal_guardian, active Student, active Guardian -- the same
 *   fail-closed POR predicate the Guardian portal uses)
 *   -> that Guardian's ONE active account link (sgal_one_active_per_guardian)
 *   -> its ACTIVE SchoolMembership in this School
 *   -> an enabled User.
 *
 * Never inferred from a name, email, phone, address or emergency contact;
 * never a Student account (a Student link has no guardian_id); never a
 * revoked link, a suspended membership or a disabled User. A Guardian
 * without an account simply has no entry. Returns each Student's User ids
 * (sorted), each with the Guardian persona it reaches them as -- one per
 * User in a School (sgal_one_active_per_membership) -- so a caller can apply
 * that Guardian's own communication choices. Bounded reads, in chunks, under
 * the School's TenantContext.
 */
final class CurrentGuardianAccounts
{
    /** Ids per bounded read (far below PostgreSQL's bind-parameter limit). */
    private const int CHUNK = 5000;

    public function __construct(
        private readonly TenantContext $context,
        private readonly GuardianStudentScope $scope,
    ) {}

    /**
     * @param  list<string>  $studentIds
     * @return array<string, array<string, string>> studentId => [userId => guardianId] (a Student with none is absent)
     */
    public function byStudent(School $school, array $studentIds): array
    {
        $guardiansByStudent = $this->scope->eligibleGuardiansByStudent($school, $studentIds);
        if ($guardiansByStudent === []) {
            return [];
        }

        $guardianIds = array_values(array_unique(array_merge(...array_values($guardiansByStudent))));

        $userByGuardian = $this->context->withSchool($school, function () use ($school, $guardianIds): array {
            $links = [];
            foreach (array_chunk($guardianIds, self::CHUNK) as $chunk) {
                $links += DB::table('student_guardian_account_links')
                    ->where('school_id', $school->id)
                    ->where('status', 'active')
                    ->whereIn('guardian_id', $chunk)
                    ->pluck('school_membership_id', 'guardian_id')
                    ->all();
            }
            if ($links === []) {
                return [];
            }

            $memberships = [];
            foreach (array_chunk(array_values($links), self::CHUNK) as $chunk) {
                $memberships += DB::table('school_memberships')
                    ->where('school_id', $school->id)
                    ->where('status', 'active')
                    ->whereIn('id', $chunk)
                    ->pluck('user_id', 'id')
                    ->all();
            }
            $enabled = [];
            foreach (array_chunk(array_values($memberships), self::CHUNK) as $chunk) {
                $enabled += array_flip(DB::table('users')
                    ->whereIn('id', $chunk)
                    ->where('is_disabled', false)
                    ->pluck('id')->map(fn ($id) => (string) $id)->all());
            }

            $map = [];
            foreach ($links as $guardianId => $membershipId) {
                $userId = isset($memberships[$membershipId]) ? (string) $memberships[$membershipId] : null;
                if ($userId !== null && isset($enabled[$userId])) {
                    $map[(string) $guardianId] = $userId;
                }
            }

            return $map;
        });

        $result = [];
        foreach ($guardiansByStudent as $studentId => $guardians) {
            $users = [];
            foreach ($guardians as $guardianId) {
                if (isset($userByGuardian[$guardianId])) {
                    $users[$userByGuardian[$guardianId]] = $guardianId;
                }
            }
            if ($users !== []) {
                ksort($users);
                $result[$studentId] = $users;
            }
        }

        return $result;
    }
}
