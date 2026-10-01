<?php

namespace App\Support\Retention\Erasure\Subjects;

use App\Models\School;
use App\Support\Retention\Erasure\ErasureCategory;
use App\Support\Retention\Erasure\ErasureSubjectAdapter;
use App\Support\Retention\RetentionHolds;
use Illuminate\Support\Facades\DB;

/**
 * E21.2F (E21-D10): a reviewed PLATFORM-scope erasure case for one User
 * identity. NOTHING is executable yet. A User is never hard-deleted because
 * a case exists:
 * - audit actor references (D1), authority history (D6), Employee links
 *   and School memberships still need it;
 * - no adopted basis exists to erase or minimise a User identity (E21.2G);
 * - while any School membership is active, the identity is
 *   `dependency_blocked`.
 *
 * Each School's own records about the person are planned only by a
 * School-scope case for THAT School (Student, Guardian or Employee), never
 * from here, so one School's request never reaches another School.
 * Retention never unlinks or suspends anything as a side effect.
 */
final class UserErasureAdapter implements ErasureSubjectAdapter
{
    public function __construct(private readonly RetentionHolds $holds) {}

    public function subjectType(): string
    {
        return 'user';
    }

    public function exists(?School $school, string $subjectId): bool
    {
        return $school === null && DB::table('users')->where('id', $subjectId)->exists();
    }

    public function plan(?School $school, string $subjectId): array
    {
        if (! $this->exists($school, $subjectId)) {
            return [new ErasureCategory('user_identity', ErasureCategory::COMPLETED, 'subject_absent')];
        }

        $schoolRecords = new ErasureCategory('school_records', ErasureCategory::OUTSIDE_SCOPE, 'requires_school_case');

        if ($this->holds->platformHeld()) {
            return [new ErasureCategory('user_identity', ErasureCategory::LEGAL_HOLD, 'platform_hold'), $schoolRecords];
        }

        $activeMemberships = DB::table('school_memberships')->where('user_id', $subjectId)->where('status', 'active')->exists();

        return [
            $activeMemberships
                ? new ErasureCategory('user_identity', ErasureCategory::DEPENDENCY_BLOCKED, 'active_membership')
                : new ErasureCategory('user_identity', ErasureCategory::POLICY_UNRESOLVED, 'no_adopted_basis'),
            $schoolRecords,
        ];
    }

    public function execute(?School $school, string $subjectId): array
    {
        return $this->plan($school, $subjectId);
    }
}
