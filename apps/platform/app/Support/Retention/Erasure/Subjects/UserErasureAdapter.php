<?php

namespace App\Support\Retention\Erasure\Subjects;

use App\Domain\Identity\Application\Minimization\UserMinimizationService;
use App\Models\School;
use App\Models\User;
use App\Support\Retention\Erasure\ErasureCategory;
use App\Support\Retention\Erasure\ErasureSubjectAdapter;
use App\Support\Retention\Erasure\UserActivePurposes;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * E21.2F (E21-D10) / E21.4 (E21-L1 project-adopted, India-aligned
 * development position, pending qualified ratification): a reviewed
 * PLATFORM-scope erasure case for one User identity. A User is never
 * hard-deleted: F1 removed the runtime role's DELETE on `users`, and
 * physical destruction is not authorized.
 *
 * `user_identity` (the person's identity and credentials):
 * - `legal_hold`: the platform hold, or a hold on any School the User ever
 *   belonged to;
 * - `policy_unresolved` (`unclassified_user_reference`): the live schema
 *   has a foreign key to `users` that UserReferenceCatalog does not
 *   classify (fail closed);
 * - `dependency_blocked`, one entry per current purpose anywhere
 *   (UserActivePurposes: membership, Employee link, account link, platform
 *   or Group grant, elevation, owned automation): another School, or the
 *   platform, still uses this identity;
 * - `eligible` (`no_active_purpose`): execution minimizes it through
 *   UserMinimizationService, after locking the User FOR UPDATE and proving
 *   all of the above again under that lock;
 * - `completed` (`minimized`): nothing left to remove; a rerun changes
 *   nothing and restores nothing.
 *
 * `user_record` (the row itself) is always kept: retained audit, authority,
 * membership, Employee, Finance and Payroll history reference it as a stable
 * non-login actor (`physical_deletion_not_authorized`).
 *
 * Each School's own records about the person are planned only by a
 * School-scope case for THAT School (Student, Guardian or Employee), never
 * from here, so one School's request never reaches another School.
 * Minimization is never scheduled: only an approved case executes it.
 */
final class UserErasureAdapter implements ErasureSubjectAdapter
{
    public function __construct(
        private readonly UserActivePurposes $purposes,
        private readonly UserMinimizationService $minimization,
    ) {}

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

        $minimized = DB::table('users')->where('id', $subjectId)->whereNotNull('minimized_at')->exists();

        return [
            ...($minimized ? [new ErasureCategory('user_identity', ErasureCategory::COMPLETED, 'minimized')] : $this->identity($subjectId)),
            new ErasureCategory('user_record', ErasureCategory::OUTSIDE_SCOPE, 'physical_deletion_not_authorized'),
            new ErasureCategory('school_records', ErasureCategory::OUTSIDE_SCOPE, 'requires_school_case'),
        ];
    }

    public function execute(?School $school, string $subjectId): array
    {
        $plan = $this->plan($school, $subjectId);
        if (! collect($plan)->contains(fn (ErasureCategory $c) => $c->category === 'user_identity' && $c->outcome === ErasureCategory::ELIGIBLE)) {
            return $plan;
        }

        try {
            DB::transaction(function () use ($subjectId): void {
                $user = User::query()->whereKey($subjectId)->lockForUpdate()->first();
                // Recheck under the lock: a membership, grant or link that committed first wins.
                if ($user === null || $user->isMinimized() || $this->identity($subjectId)[0]->outcome !== ErasureCategory::ELIGIBLE) {
                    return;
                }
                $this->minimization->minimizeLocked($user);
            });
        } catch (QueryException $e) {
            Log::warning('retention.user_minimization.failed', ['error' => $e::class]);

            return [new ErasureCategory('user_identity', ErasureCategory::ERROR, 'minimization_failed'), ...array_slice($plan, 1)];
        }

        return $this->plan($school, $subjectId);
    }

    /** @return non-empty-list<ErasureCategory> the identity's state for a User that is not minimized */
    private function identity(string $userId): array
    {
        $holds = $this->purposes->holds($userId);
        if ($holds !== []) {
            return array_map(fn (string $hold) => new ErasureCategory('user_identity', ErasureCategory::LEGAL_HOLD, $hold), $holds);
        }
        if ($this->purposes->unclassifiedReferences() !== []) {
            return [new ErasureCategory('user_identity', ErasureCategory::POLICY_UNRESOLVED, 'unclassified_user_reference')];
        }
        $purposes = $this->purposes->purposes($userId);
        if ($purposes !== []) {
            return array_map(fn (string $purpose) => new ErasureCategory('user_identity', ErasureCategory::DEPENDENCY_BLOCKED, $purpose), $purposes);
        }

        return [new ErasureCategory('user_identity', ErasureCategory::ELIGIBLE, 'no_active_purpose')];
    }
}
